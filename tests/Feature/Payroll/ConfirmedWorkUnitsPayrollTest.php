<?php

namespace Tests\Feature\Payroll;

use App\Http\Controllers\PaysheetController;
use App\Models\Employee;
use App\Models\EmployeeSalarySetting;
use App\Models\Paysheet;
use App\Models\Payslip;
use App\Models\Setting;
use App\Models\TimekeepingRecord;
use App\Services\PayrollCalculationGuard;
use App\Services\PayrollConfirmedAttendance;
use App\Services\PayrollPayslipCalculator;
use App\Services\SalaryCalculationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConfirmedWorkUnitsPayrollTest extends TestCase
{
    use DatabaseTransactions;

    private function employee(string $type = 'by_workday', int $rate = 2600000): Employee
    {
        $employee = Employee::create(['code' => 'QA-'.uniqid(), 'name' => 'Synthetic payroll employee', 'is_active' => true]);
        EmployeeSalarySetting::create(['employee_id' => $employee->id, 'salary_type' => $type, 'base_salary' => $rate]);
        return $employee;
    }

    private function record(Employee $employee, string $date, int $minutes, float $units, array $extra = []): TimekeepingRecord
    {
        return TimekeepingRecord::create(array_merge([
            'employee_id' => $employee->id, 'work_date' => $date, 'slot' => 1,
            'attendance_type' => 'work', 'worked_minutes' => $minutes,
            'regular_minutes' => $minutes, 'work_units' => $units, 'source' => 'manual',
        ], $extra));
    }

    private function calculate(Employee $employee, float $standard = 26): array
    {
        return app(SalaryCalculationService::class)->calculateForEmployee($employee->fresh(), Carbon::parse('2030-06-01'), Carbon::parse('2030-06-30'), $standard);
    }

    private function sheet(Employee $employee): array
    {
        $sheet = Paysheet::create(['code' => 'QA-S-'.uniqid(), 'name' => 'Synthetic payroll', 'period_start' => '2030-06-01', 'period_end' => '2030-06-30', 'standard_working_days' => 26, 'status' => 'calculated']);
        $slip = Payslip::create(['code' => 'QA-P-'.uniqid(), 'paysheet_id' => $sheet->id, 'employee_id' => $employee->id]);
        $slip->update(app(PayrollPayslipCalculator::class)->preview($sheet, $slip));
        return [$sheet->fresh(), $slip->fresh()];
    }

    public function test_confirmed_units_survive_every_old_minute_boundary(): void
    {
        $employee = $this->employee();
        foreach ([480, 481, 568, 599, 600] as $index => $minutes) {
            $this->record($employee, '2030-06-'.sprintf('%02d', $index + 1), $minutes, 1);
        }
        $result = $this->calculate($employee);
        $this->assertEquals(5, $result['work_units']);
        $this->assertEquals(500000, $result['base']);
        $this->assertSame('ready', $result['validation']['status']);
        $this->assertEquals(520000, $this->calculate($employee, 25)['base']);
    }

    public function test_half_units_multiple_shifts_leave_and_holiday_are_counted_once(): void
    {
        $rows = collect([
            new TimekeepingRecord(['work_date' => '2030-06-01', 'attendance_type' => 'work', 'work_units' => .5]),
            new TimekeepingRecord(['work_date' => '2030-06-01', 'attendance_type' => 'work', 'work_units' => .5]),
            new TimekeepingRecord(['work_date' => '2030-06-02', 'attendance_type' => 'leave_paid', 'work_units' => .5]),
            new TimekeepingRecord(['work_date' => '2030-06-03', 'attendance_type' => 'work', 'work_units' => 1, 'is_holiday' => true]),
        ]);
        $result = app(PayrollConfirmedAttendance::class)->summarize($rows, ['2030-06-01'], 2, 3);
        $this->assertEquals(2, $result['normal']);
        $this->assertEquals(5, $result['weighted']);
        $this->assertEquals(.5, $result['leave']);
        $this->assertEmpty($result['issues']);
    }

    public function test_conflicting_shifts_and_unreviewed_records_block_payroll(): void
    {
        $employee = $this->employee();
        $this->record($employee, '2030-06-01', 300, 1);
        $this->record($employee, '2030-06-01', 300, 1, ['slot' => 2, 'needs_review' => true]);
        $this->assertSame('blocked', $this->calculate($employee)['validation']['status']);
    }

    public function test_fixed_and_hourly_keep_their_own_formulas(): void
    {
        $fixed = $this->employee('fixed', 1700000);
        $this->assertEquals(1700000, $this->calculate($fixed)['base']);
        $hourly = $this->employee('hourly', 30000);
        $this->record($hourly, '2030-06-01', 240, .5, ['regular_minutes' => 210, 'ot_minutes' => 30]);
        $this->record($hourly, '2030-06-01', 120, .5, ['slot' => 2]);
        $this->assertEquals(165000, $this->calculate($hourly)['base']);
    }

    public function test_missing_salary_and_missing_attendance_are_not_valid_zero_salary(): void
    {
        $employee = $this->employee();
        $this->assertSame('blocked', $this->calculate($employee)['validation']['status']);
        $employee->salarySetting()->delete();
        $this->assertSame('blocked', $this->calculate($employee)['validation']['status']);
    }

    public function test_bulk_source_change_is_detected_even_without_recalc_flag(): void
    {
        $employee = $this->employee();
        $record = $this->record($employee, '2030-06-01', 568, 1);
        [$sheet] = $this->sheet($employee);
        app(PayrollCalculationGuard::class)->assertReady($sheet);
        DB::table('timekeeping_records')->where('id', $record->id)->update(['work_units' => .5]);
        $this->assertFalse((bool) $sheet->fresh()->needs_recalc);
        $this->expectException(ValidationException::class);
        app(PayrollCalculationGuard::class)->assertReady($sheet->fresh());
    }

    public function test_preview_preserves_adjustments_and_settlement_and_does_not_write(): void
    {
        $employee = $this->employee();
        $record = $this->record($employee, '2030-06-01', 568, 1);
        [$sheet, $slip] = $this->sheet($employee);
        $slip->update(['paid_amount' => 10000, 'applied_advance' => 20000, 'details' => array_merge($slip->details, ['direct_overrides' => ['bonus' => 5000], 'manual_overrides' => ['allowance' => true]])]);
        $slip->adjustments()->create(['type' => 'ot', 'name' => 'Synthetic adjustment', 'amount' => 1000]);
        $before = $slip->fresh()->toArray();
        $recordBefore = $record->fresh()->toArray();
        $a = app(PayrollPayslipCalculator::class)->preview($sheet, $slip);
        $b = app(PayrollPayslipCalculator::class)->preview($sheet, $slip);
        $this->assertSame($a, $b);
        $this->assertEquals(106000, $a['total_salary']);
        $this->assertEquals(76000, $a['remaining']);
        $this->assertSame($before, $slip->fresh()->toArray());
        $this->assertSame($recordBefore, $record->fresh()->toArray());
    }

    public function test_zero_salary_needs_reason_and_cannot_bypass_missing_configuration(): void
    {
        $employee = $this->employee('fixed', 0);
        [$sheet, $slip] = $this->sheet($employee);
        try {
            app(PayrollCalculationGuard::class)->assertReady($sheet);
            $this->fail('Zero salary without reason must be blocked.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $details = $slip->details;
        $details['zero_salary_confirmation'] = ['reason' => 'Synthetic unpaid period', 'input_fingerprint' => $details['input_fingerprint']];
        $slip->update(['details' => $details]);
        app(PayrollCalculationGuard::class)->assertReady($sheet->fresh());
        $employee->salarySetting()->delete();
        $this->expectException(ValidationException::class);
        app(PayrollCalculationGuard::class)->assertReady($sheet->fresh());
    }

    public function test_recalculation_uses_recorded_units_and_leaves_locked_sheet_untouched(): void
    {
        $employee = $this->employee();
        $record = $this->record($employee, '2030-06-01', 568, 1);
        [$sheet, $slip] = $this->sheet($employee);
        $method = new \ReflectionMethod(PaysheetController::class, 'performRecalculation');
        $method->invoke(app(PaysheetController::class), $sheet);
        $this->assertEquals(100000, $slip->fresh()->base_salary);
        $this->assertEquals(1, $record->fresh()->work_units);
        $sheet->update(['status' => 'locked']);
        $before = $slip->fresh()->toArray();
        try {
            $method->invoke(app(PaysheetController::class), $sheet);
            $this->fail('Locked sheet must reject recalculation.');
        } catch (ValidationException $e) {
            $this->assertSame($before, $slip->fresh()->toArray());
        }
    }

    public function test_create_recalculate_and_export_share_the_same_amounts(): void
    {
        $employee = $this->employee();
        $record = $this->record($employee, '2030-06-01', 568, 1);
        $request = \Illuminate\Http\Request::create('/api/paysheets', 'POST', [
            'pay_period' => 'monthly', 'period_start' => '2030-06-01', 'period_end' => '2030-06-30',
            'scope' => 'custom', 'employee_ids' => [$employee->id],
        ]);
        $controller = app(PaysheetController::class);
        $response = $controller->store($request)->getData(true);
        $sheet = Paysheet::findOrFail($response['data']['id']);
        $before = $sheet->payslips->first()->only(['base_salary', 'total_salary', 'work_units', 'remaining']);
        $recalculated = $controller->recalculate($sheet->id)->getData(true);
        $this->assertEquals($before['base_salary'], $recalculated['data']['payslips'][0]['base_salary']);
        $this->assertEquals($before, $sheet->fresh()->payslips->first()->only(array_keys($before)));
        $this->assertEquals(1, $record->fresh()->work_units);
        $this->assertEquals($before['total_salary'], $sheet->fresh()->total_salary);
        $csv = $controller->export(\Illuminate\Http\Request::create('/', 'GET', ['search' => $sheet->code]));
        ob_start();
        $csv->sendContent();
        $content = ob_get_clean();
        $this->assertStringContainsString($sheet->code, $content);
        $this->assertStringContainsString((string) $before['total_salary'], $content);
    }

    public function test_viewing_stale_sheet_does_not_write_or_recalculate(): void
    {
        $employee = $this->employee();
        $record = $this->record($employee, '2030-06-01', 568, 1);
        [$sheet, $slip] = $this->sheet($employee);
        $record->update(['work_units' => .5]);
        $before = $slip->fresh()->toArray();
        $response = app(PaysheetController::class)->show($sheet->id)->getData(true);
        $this->assertFalse($response['auto_recalculated']);
        $this->assertTrue($response['data']['needs_recalc']);
        $this->assertSame($before, $slip->fresh()->toArray());
    }

    public function test_lock_posts_once_and_rejects_stale_inputs_before_any_ledger_write(): void
    {
        $employee = $this->employee();
        $record = $this->record($employee, '2030-06-01', 568, 1);
        [$sheet] = $this->sheet($employee);
        $posting = app(\App\Services\PayrollPostingService::class);
        $posting->lock($sheet);
        $posting->lock($sheet->fresh());
        $this->assertEquals(1, DB::table('employee_salary_ledger_entries')->where('paysheet_id', $sheet->id)->count());
        [$other] = $this->sheet($employee);
        DB::table('timekeeping_records')->where('id', $record->id)->update(['work_units' => .5]);
        try {
            $posting->lock($other);
            $this->fail('Stale calculation must not post.');
        } catch (ValidationException $e) {
            $this->assertEquals(0, DB::table('employee_salary_ledger_entries')->where('paysheet_id', $other->id)->count());
            $this->assertEquals('calculated', $other->fresh()->status);
        }
    }

    public function test_inline_override_survives_recalculate_and_popup_reset_clears_it(): void
    {
        $employee = $this->employee();
        $this->record($employee, '2030-06-01', 568, 1);
        [$sheet, $slip] = $this->sheet($employee);
        $controller = app(PaysheetController::class);
        $controller->updatePayslip(\Illuminate\Http\Request::create('/', 'PUT', ['base_salary' => 120000, 'allowances' => 2500]), $sheet->id, $slip->id);
        $controller->recalculate($sheet->id);
        $this->assertEquals(120000, $slip->fresh()->base_salary);
        $this->assertEquals(2500, $slip->fresh()->allowances);
        $controller->bulkSaveAdjustments(\Illuminate\Http\Request::create('/', 'PUT', ['items' => []]), $sheet->id, $slip->id, 'allowance');
        $controller->recalculate($sheet->id);
        $this->assertEquals(0, $slip->fresh()->allowances);
        $this->assertEquals(120000, $slip->fresh()->base_salary);
    }
}
