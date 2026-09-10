<?php

namespace Tests\Feature\Payroll;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeSalarySetting;
use App\Models\Paysheet;
use App\Models\Payslip;
use App\Models\TimekeepingRecord;
use App\Services\PayrollPayslipCalculator;
use App\Services\PayrollReviewedRecalculation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ReviewedPayrollRecalculationTest extends TestCase
{
    use DatabaseTransactions;

    private function fixture(): array
    {
        $sheet = Paysheet::create(['code' => 'QA-S-'.uniqid(), 'name' => 'Synthetic reviewed payroll', 'period_start' => '2030-06-01', 'period_end' => '2030-06-30', 'standard_working_days' => 26, 'status' => 'calculated']);
        $rows = [];
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $employee = Employee::create(['code' => 'QA-E-'.uniqid(), 'name' => 'Synthetic employee', 'is_active' => true]);
            EmployeeSalarySetting::create(['employee_id' => $employee->id, 'salary_type' => 'by_workday', 'base_salary' => 2600000]);
            TimekeepingRecord::create(['employee_id' => $employee->id, 'work_date' => '2030-06-04', 'slot' => 1, 'attendance_type' => 'work', 'worked_minutes' => 568, 'regular_minutes' => 568, 'work_units' => 1, 'source' => 'manual']);
            $slip = Payslip::create(['code' => 'QA-P-'.uniqid(), 'paysheet_id' => $sheet->id, 'employee_id' => $employee->id, 'base_salary' => 0, 'total_salary' => 0, 'remaining' => 0, 'work_units' => 0]);
            $slip->refresh();
            $preview = app(PayrollPayslipCalculator::class)->preview($sheet, $slip);
            $fields = ['base_salary', 'work_units', 'bonus', 'commission', 'allowances', 'deductions', 'ot_pay', 'total_salary', 'remaining'];
            $rows[] = ['paysheet_id' => $sheet->id, 'paysheet_code' => $sheet->code, 'payslip_id' => $slip->id, 'employee_id' => $employee->id,
                'period_start' => '2030-06-01', 'period_end' => '2030-06-30', 'action' => 'RECALCULATE_AFTER_BACKUP', 'before' => $slip->only($fields),
                'preview' => array_intersect_key($preview, array_flip($fields)), 'input_fingerprint' => $preview['details']['input_fingerprint']];
            if ($i < 2) {
                $ids[] = $slip->id;
            }
        }
        $sheet->recalculateTotals();
        $report = ['contract_version' => 'payroll-confirmed-units-audit-v1', 'rows' => $rows];

        return [$sheet->fresh(), $ids, $report, hash('sha256', json_encode($report))];
    }

    private function execute(array $fixture, bool $apply = true, string $backup = 'SYNTHETIC-BACKUP'): array
    {
        [$sheet, $ids, $report, $sha] = $fixture;

        return app(PayrollReviewedRecalculation::class)->execute($report, $sha, $sheet->id, $ids, 200000, 'Synthetic QA operator', $backup, $apply);
    }

    public function test_dry_run_apply_and_replay_preserve_unselected_rows_and_record_atomic_audit(): void
    {
        $fixture = $this->fixture();
        [$sheet, $ids] = $fixture;
        $other = $sheet->payslips()->whereNotIn('id', $ids)->first();
        $beforeOther = $other->getRawOriginal();
        $logCount = ActivityLog::count();
        $this->assertSame('DRY_RUN', $this->execute($fixture, false, '')['result']);
        $this->assertSame(0, $sheet->fresh()->total_salary);
        $this->assertSame($logCount, ActivityLog::count());
        $result = $this->execute($fixture);
        $this->assertSame('APPLIED', $result['result']);
        $this->assertSame(2, $result['lines_changed']);
        $this->assertSame(200000, $sheet->fresh()->total_salary);
        $this->assertSame($beforeOther, $other->fresh()->getRawOriginal());
        $this->assertSame($logCount + 1, ActivityLog::count());
        $log = ActivityLog::where('action', 'payroll_reviewed_recalculation')->where('subject_id', $sheet->id)->firstOrFail();
        $this->assertSame('SYNTHETIC-BACKUP', $log->properties['backup_reference']);
        $this->assertCount(2, $log->properties['before_slips']);
        $this->assertCount(2, $log->properties['after_slips']);
        $this->assertSame('REPLAY', $this->execute($fixture)['result']);
        $this->assertSame(0, $this->execute($fixture)['lines_changed']);
        $this->assertSame($logCount + 1, ActivityLog::count());
    }

    public function test_changed_sources_stop_without_writes(): void
    {
        $fixture = $this->fixture();
        $slip = Payslip::findOrFail($fixture[1][0]);
        TimekeepingRecord::where('employee_id', $slip->employee_id)->update(['work_units' => 0.5]);
        $this->expectExceptionMessage('Calculation source changed');
        try {
            $this->execute($fixture);
        } finally {
            $this->assertSame(0, $slip->fresh()->base_salary);
        }
    }

    public function test_error_after_first_write_rolls_back_slips_totals_and_audit(): void
    {
        $fixture = $this->fixture();
        $real = new PayrollPayslipCalculator;
        $calls = 0;
        $mock = Mockery::mock(PayrollPayslipCalculator::class);
        $mock->shouldReceive('preview')->andReturnUsing(function ($sheet, $slip) use ($real, &$calls) {
            if (++$calls > 2) {
                throw new RuntimeException('Synthetic post-write failure');
            }

            return $real->preview($sheet, $slip);
        });
        $this->app->instance(PayrollPayslipCalculator::class, $mock);
        $logCount = ActivityLog::count();
        try {
            $this->execute($fixture);
            $this->fail('Expected rollback');
        } catch (RuntimeException $e) {
            $this->assertSame('Synthetic post-write failure', $e->getMessage());
        }
        $this->assertEquals(0, Payslip::whereIn('id', $fixture[1])->sum('base_salary'));
        $this->assertSame(0, $fixture[0]->fresh()->total_salary);
        $this->assertSame($logCount, ActivityLog::count());
    }

    public function test_locked_sheet_is_rejected(): void
    {
        $fixture = $this->fixture();
        $fixture[0]->update(['status' => 'locked']);
        $this->expectExceptionMessage('Only unlocked');
        $this->execute($fixture);
    }

    public function test_paid_slip_is_rejected(): void
    {
        $fixture = $this->fixture();
        Payslip::findOrFail($fixture[1][0])->update(['paid_amount' => 1]);
        $this->expectExceptionMessage('Payments or advances exist');
        $this->execute($fixture);
    }

    public function test_backup_is_required_to_apply(): void
    {
        $fixture = $this->fixture();
        $this->expectExceptionMessage('backup reference required');
        $this->execute($fixture, true, '');
    }

    public function test_wrong_selection_blocked_row_and_changed_preview_are_rejected(): void
    {
        $original = $this->fixture();
        foreach (['duplicate', 'missing', 'blocked', 'preview', 'stored'] as $case) {
            $fixture = $original;
            if ($case === 'duplicate') {
                $fixture[1][] = $fixture[1][0];
            } elseif ($case === 'missing') {
                $fixture[1][0] = PHP_INT_MAX;
            } elseif ($case === 'blocked') {
                $fixture[2]['rows'][0]['action'] = 'ACCOUNTING_REVIEW';
            } elseif ($case === 'preview') {
                $fixture[2]['rows'][0]['preview']['total_salary'] += 1;
            } else {
                $fixture[2]['rows'][0]['before']['base_salary'] += 1;
            }
            try {
                $this->execute($fixture);
                $this->fail('Expected guard: '.$case);
            } catch (RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
            $this->assertSame(0, $original[0]->fresh()->total_salary);
        }
    }

    public function test_cli_checks_hash_confirmation_and_dry_run(): void
    {
        [$sheet, $ids, $report] = $this->fixture();
        $file = tempnam(sys_get_temp_dir(), 'payroll-test-');
        file_put_contents($file, json_encode($report));
        try {
            $options = ['--report' => $file, '--report-sha256' => hash_file('sha256', $file), '--paysheet' => $sheet->id, '--payslip' => $ids, '--expected-delta' => 200000, '--operator' => 'Synthetic QA'];
            $this->artisan('payroll:recalculate-reviewed', $options)->assertExitCode(0);
            $this->artisan('payroll:recalculate-reviewed', array_merge($options, ['--report-sha256' => str_repeat('0', 64)]))->assertExitCode(1);
            $this->artisan('payroll:recalculate-reviewed', array_merge($options, ['--apply' => true, '--backup-reference' => 'SYNTHETIC']))->assertExitCode(1);
            $this->assertSame(0, $sheet->fresh()->total_salary);
        } finally {
            unlink($file);
        }
    }
}
