<?php

namespace Tests\Feature\Payroll;

use App\Http\Controllers\Api\AttendanceAgentController;
use App\Models\AttendanceDevice;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeSalarySetting;
use App\Models\EmployeeWorkSchedule;
use App\Models\Paysheet;
use App\Models\Payslip;
use App\Models\Shift;
use App\Models\TimekeepingRecord;
use App\Models\TimekeepingSetting;
use App\Services\SalaryCalculationService;
use App\Services\TimekeepingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MissingClockInTimekeepingTest extends TestCase
{
    use DatabaseTransactions;

    private function environment(string $date = '2030-05-06', bool $scheduled = true): array
    {
        $branch = Branch::create(['name' => 'Synthetic punch QA '.uniqid()]);
        $employee = Employee::create([
            'code' => 'QA-PUNCH-'.uniqid(), 'name' => 'Synthetic attendance employee',
            'attendance_code' => 'QA-'.uniqid(), 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        EmployeeSalarySetting::create(['employee_id' => $employee->id, 'salary_type' => 'hourly', 'base_salary' => 60000]);
        $shift = Shift::create([
            'branch_id' => $branch->id, 'name' => 'Synthetic full shift',
            'start_time' => '08:30:00', 'end_time' => '18:00:00', 'status' => 'active',
        ]);
        $schedule = $scheduled ? EmployeeWorkSchedule::create([
            'employee_id' => $employee->id, 'branch_id' => $branch->id, 'work_date' => $date,
            'shift_id' => $shift->id, 'start_time' => '08:30:00', 'end_time' => '18:00:00',
            'slot' => 1, 'status' => 'approved',
        ]) : null;
        TimekeepingSetting::create(['branch_id' => $branch->id, 'standard_hours_per_day' => 8, 'status' => 'active']);
        $device = AttendanceDevice::create([
            'branch_id' => $branch->id, 'name' => 'Synthetic punch device', 'device_id' => 'QA-DEVICE-'.uniqid(),
            'status' => 'online', 'ip_address' => '127.0.0.1', 'tcp_port' => 4370,
        ]);

        return compact('employee', 'date', 'schedule', 'device');
    }

    private function punch(array $env, string $time, ?string $direction = null): AttendanceLog
    {
        return AttendanceLog::create([
            'attendance_device_id' => $env['device']->id, 'employee_id' => $env['employee']->id,
            'device_user_id' => $env['employee']->attendance_code, 'punched_at' => $env['date'].' '.$time,
            'event_type' => $direction, 'raw' => ['synthetic' => true],
        ]);
    }

    private function recalculate(array $env): TimekeepingRecord
    {
        app(TimekeepingService::class)->recalculateForRange(Carbon::parse($env['date']), Carbon::parse($env['date'])->endOfDay(), $env['employee']->id);

        return TimekeepingRecord::with('intervals')->where('employee_id', $env['employee']->id)->whereDate('work_date', $env['date'])->sole();
    }

    public function test_late_orphan_is_persisted_as_checkout_and_recalculation_keeps_raw_punch(): void
    {
        $env = $this->environment();
        $log = $this->punch($env, '18:13:00');
        $original = $log->fresh()->getRawOriginal();
        foreach ([1, 2] as $attempt) {
            $record = $this->recalculate($env);
            $this->assertNull($record->check_in_at);
            $this->assertSame('18:13', $record->check_out_at->format('H:i'));
            $this->assertSame(0, (int) $record->worked_minutes);
            $this->assertSame(0, (int) $record->ot_minutes);
            $this->assertEquals(0, $record->work_units);
            $this->assertTrue($record->needs_review);
            $this->assertCount(1, $record->intervals);
            $this->assertNull($record->intervals->first()->check_in_at);
            $this->assertSame('missing_check_in', $record->intervals->first()->raw['review_reason']);
            $this->assertSame([$log->id], $record->raw['unmatched_log_ids']);
            $this->assertSame($original, $log->fresh()->getRawOriginal());
        }
        $pay = app(SalaryCalculationService::class)->calculateForEmployee($env['employee']->fresh(), Carbon::parse($env['date']), Carbon::parse($env['date']), 26);
        $this->assertEquals(0, $pay['base']);
        $this->assertSame('blocked', $pay['validation']['status']);
    }

    public function test_later_synced_entry_completes_the_pair_without_manual_or_payroll_changes(): void
    {
        $env = $this->environment();
        $this->punch($env, '18:13:00', 'out');
        $this->recalculate($env);
        $this->punch($env, '08:30:00', 'in');
        $record = $this->recalculate($env);
        $this->assertSame('08:30', $record->check_in_at->format('H:i'));
        $this->assertSame('18:13', $record->check_out_at->format('H:i'));
        $this->assertSame(583, (int) $record->worked_minutes);
        $this->assertFalse($record->needs_review);
        $this->assertSame([], $record->raw['unmatched_log_ids']);
    }

    public function test_manual_confirmation_and_locked_paid_payroll_are_not_overwritten(): void
    {
        $env = $this->environment();
        $sheet = Paysheet::create([
            'code' => 'QA-LOCK-'.uniqid(), 'name' => 'Synthetic locked payroll',
            'period_start' => $env['date'], 'period_end' => $env['date'], 'status' => 'locked', 'needs_recalc' => false,
        ]);
        $slip = Payslip::create([
            'code' => 'QA-PAID-'.uniqid(), 'paysheet_id' => $sheet->id, 'employee_id' => $env['employee']->id,
            'base_salary' => 600000, 'total_salary' => 600000, 'paid_amount' => 600000, 'remaining' => 0,
        ]);
        $originalSheet = $sheet->fresh()->getRawOriginal();
        $originalSlip = $slip->fresh()->getRawOriginal();
        $this->punch($env, '18:13:00', 'out');
        $record = $this->recalculate($env);
        $record->update(app(TimekeepingService::class)->buildManualRecordAttributes($env['schedule'], 'work', '08:45', '18:13', 0, 'Synthetic verified entry'));
        $originalRecord = $record->fresh()->getRawOriginal();
        $this->recalculate($env);
        $this->assertSame($originalRecord, $record->fresh()->getRawOriginal());
        $this->assertSame($originalSheet, $sheet->fresh()->getRawOriginal());
        $this->assertSame($originalSlip, $slip->fresh()->getRawOriginal());
    }

    public function test_rest_day_orphan_uses_the_same_direction_rules_and_does_not_invent_overtime(): void
    {
        $env = $this->environment('2030-05-05', false);
        $this->punch($env, '18:13:00', 'out');
        $record = $this->recalculate($env);
        $this->assertNull($record->check_in_at);
        $this->assertSame('18:13', $record->check_out_at->format('H:i'));
        $this->assertTrue($record->needs_review);
        $this->assertSame(0, (int) $record->worked_minutes);
        $this->assertSame(0, (int) $record->ot_minutes);
        $this->assertEquals(0, $record->work_units);
        $this->assertCount(1, $record->intervals);
    }

    public function test_rest_day_complete_pair_is_still_paid_and_has_intervals(): void
    {
        $env = $this->environment('2030-05-05', false);
        $this->punch($env, '08:30:00', 'in');
        $this->punch($env, '18:00:00', 'out');
        $record = $this->recalculate($env);
        $this->assertSame(570, (int) $record->worked_minutes);
        $this->assertFalse($record->needs_review);
        $this->assertTrue($record->is_holiday);
        $this->assertEquals(1, $record->work_units);
        $this->assertCount(1, $record->intervals);
    }

    public function test_agent_sync_retains_device_checkout_direction_and_is_idempotent(): void
    {
        $env = $this->environment();
        $this->travelTo(Carbon::parse($env['date'].' 20:00:00'));
        $request = Request::create('/api/attendance-agent/push-logs', 'POST', [
            'device_id' => $env['device']->device_id,
            'logs' => [[
                'device_user_id' => $env['employee']->attendance_code,
                'punched_at' => $env['date'].' 10:00:00', 'event_type' => 'out', 'raw' => ['synthetic' => true],
            ]],
        ]);
        foreach ([1, 2] as $attempt) {
            $response = app(AttendanceAgentController::class)->pushLogs($request);
            $this->assertTrue($response->getData(true)['success']);
        }
        $record = TimekeepingRecord::where('employee_work_schedule_id', $env['schedule']->id)->sole();
        $this->assertNull($record->check_in_at);
        $this->assertSame('10:00', $record->check_out_at->format('H:i'));
        $this->assertTrue($record->needs_review);
        $this->assertSame(0, (int) $record->worked_minutes);
        $this->assertSame(1, AttendanceLog::where('employee_id', $env['employee']->id)->count());
    }

    public function test_production_audit_prints_before_and_preview_without_changing_any_rows(): void
    {
        $env = $this->environment();
        $log = $this->punch($env, '18:13:00');
        $record = TimekeepingRecord::create([
            'employee_id' => $env['employee']->id, 'employee_work_schedule_id' => $env['schedule']->id,
            'work_date' => $env['date'], 'check_in_at' => $log->punched_at, 'check_out_at' => null,
            'worked_minutes' => 0, 'needs_review' => true, 'source' => 'device', 'attendance_type' => 'work',
        ]);
        $beforeRecord = $record->fresh()->getRawOriginal();
        $beforeLog = $log->fresh()->getRawOriginal();
        $this->assertSame(0, Artisan::call('attendance:audit-punch-directions', ['--date' => $env['date'], '--employee' => $env['employee']->code]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['summary']['projection_mismatches']);
        $this->assertTrue($report['summary']['audit_read_only']);
        $this->assertSame('NO', $report['summary']['production_business_data_mutation']);
        $this->assertNull($report['findings'][0]['preview']['check_in_at']);
        $this->assertSame($env['date'].' 18:13:00', $report['findings'][0]['preview']['check_out_at']);
        $this->assertSame(['missing_check_in'], $report['findings'][0]['review_reasons']);
        $this->assertSame($beforeRecord, $record->fresh()->getRawOriginal());
        $this->assertSame($beforeLog, $log->fresh()->getRawOriginal());
        $this->assertSame(0, $record->intervals()->count());
    }
}
