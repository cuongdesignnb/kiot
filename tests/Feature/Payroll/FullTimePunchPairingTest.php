<?php

namespace Tests\Feature\Payroll;

use App\Http\Controllers\Api\AttendanceAgentController;
use App\Models\AttendanceDevice;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeSalarySetting;
use App\Models\EmployeeWorkSchedule;
use App\Models\Shift;
use App\Models\TimekeepingRecord;
use App\Models\TimekeepingSetting;
use App\Services\TimekeepingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Reported 2026-11-10: an office-hours employee punched 08:46 and 17:02 on
 * 2026-10-03. The device labels both punches "in" (inOutMode 0), so the
 * 17:02 exit was stored as the start of a second interval and the day was
 * worth 0 minutes. Without trusting the device label it is one interval.
 */
class FullTimePunchPairingTest extends TestCase
{
    use DatabaseTransactions;

    private string $date = '2026-10-03';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.attendance_agent.trust_device_direction' => false]);
    }

    private function environment(): array
    {
        $branch = Branch::create(['name' => 'Synthetic full-time QA '.uniqid()]);
        $employee = Employee::create([
            'code' => 'QA-FT-'.uniqid(), 'name' => 'Synthetic full-time employee',
            'attendance_code' => 'QAFT'.random_int(10000, 99999), 'branch_id' => $branch->id, 'is_active' => true,
        ]);
        EmployeeSalarySetting::create(['employee_id' => $employee->id, 'salary_type' => 'hourly', 'base_salary' => 60000]);
        $shift = Shift::create([
            'branch_id' => $branch->id, 'name' => 'Giờ hành chính',
            'start_time' => '08:30:00', 'end_time' => '18:00:00', 'status' => 'active',
        ]);
        $schedule = EmployeeWorkSchedule::create([
            'employee_id' => $employee->id, 'branch_id' => $branch->id, 'work_date' => $this->date,
            'shift_id' => $shift->id, 'start_time' => '08:30:00', 'end_time' => '18:00:00',
            'slot' => 1, 'status' => 'approved',
        ]);
        TimekeepingSetting::create(['branch_id' => $branch->id, 'standard_hours_per_day' => 8, 'status' => 'active']);
        $device = AttendanceDevice::create([
            'branch_id' => $branch->id, 'name' => 'Synthetic device', 'device_id' => 'QA-FT-DEVICE-'.uniqid(),
            'status' => 'online', 'ip_address' => '127.0.0.1', 'tcp_port' => 4370,
        ]);

        return compact('employee', 'schedule', 'device');
    }

    private function punch(array $env, string $time, ?string $direction, int $inOutMode): AttendanceLog
    {
        return AttendanceLog::create([
            'attendance_device_id' => $env['device']->id, 'employee_id' => $env['employee']->id,
            'device_user_id' => $env['employee']->attendance_code, 'punched_at' => $this->date.' '.$time,
            'event_type' => $direction, 'raw' => ['verifyMode' => 1, 'inOutMode' => $inOutMode, 'workcode' => 0],
        ]);
    }

    private function recalculate(array $env): TimekeepingRecord
    {
        app(TimekeepingService::class)->recalculateForRange(Carbon::parse($this->date), Carbon::parse($this->date)->endOfDay(), $env['employee']->id);

        return TimekeepingRecord::with('intervals')->where('employee_id', $env['employee']->id)->whereDate('work_date', $this->date)->sole();
    }

    public function test_reported_case_08_46_and_17_02_both_labelled_in_is_one_complete_interval(): void
    {
        $env = $this->environment();
        $this->punch($env, '08:46:00', 'in', 0);
        $this->punch($env, '17:02:00', 'in', 0);

        foreach ([1, 2] as $attempt) {
            $record = $this->recalculate($env);

            $this->assertSame('08:46', $record->check_in_at->format('H:i'));
            $this->assertSame('17:02', $record->check_out_at->format('H:i'));
            $this->assertSame(496, (int) $record->worked_minutes);
            $this->assertFalse((bool) $record->needs_review);
            $this->assertGreaterThan(0, (float) $record->work_units);
            $this->assertCount(1, $record->intervals, 'A full-time day must not be split into "khoảng 1 / khoảng 2".');
            $this->assertSame('complete', $record->intervals->first()->status);
        }
    }

    public function test_previously_split_record_is_repaired_by_recalculation(): void
    {
        $env = $this->environment();
        $this->punch($env, '08:46:00', 'in', 0);
        $this->punch($env, '17:02:00', 'in', 0);

        // Reproduce the stored state produced while device labels were trusted.
        config(['services.attendance_agent.trust_device_direction' => true]);
        $broken = $this->recalculate($env);
        $this->assertCount(2, $broken->intervals);
        $this->assertSame(0, (int) $broken->worked_minutes);
        $this->assertTrue((bool) $broken->needs_review);

        config(['services.attendance_agent.trust_device_direction' => false]);
        $fixed = $this->recalculate($env);
        $this->assertCount(1, $fixed->intervals);
        $this->assertSame(496, (int) $fixed->worked_minutes);
        $this->assertFalse((bool) $fixed->needs_review);
    }

    public function test_agent_sync_with_morning_out_label_produces_a_complete_day(): void
    {
        $env = $this->environment();
        $this->travelTo(Carbon::parse($this->date.' 20:00:00'));
        $request = Request::create('/api/attendance-agent/push-logs', 'POST', [
            'device_id' => $env['device']->device_id,
            'logs' => [
                ['device_user_id' => $env['employee']->attendance_code, 'punched_at' => $this->date.' 08:24:45',
                    'event_type' => 'out', 'raw' => ['inOutMode' => 4]],
                ['device_user_id' => $env['employee']->attendance_code, 'punched_at' => $this->date.' 18:12:13',
                    'event_type' => 'out', 'raw' => ['inOutMode' => 4]],
            ],
        ]);
        $this->assertTrue(app(AttendanceAgentController::class)->pushLogs($request)->getData(true)['success']);

        $record = TimekeepingRecord::with('intervals')->where('employee_work_schedule_id', $env['schedule']->id)->sole();
        $this->assertSame('08:24', $record->check_in_at->format('H:i'));
        $this->assertSame('18:12', $record->check_out_at->format('H:i'));
        $this->assertFalse((bool) $record->needs_review);
        $this->assertCount(1, $record->intervals);
    }

    public function test_range_audit_is_a_read_only_dry_run_of_the_repair(): void
    {
        $env = $this->environment();
        $this->punch($env, '08:46:00', 'in', 0);
        $this->punch($env, '17:02:00', 'in', 0);
        config(['services.attendance_agent.trust_device_direction' => true]);
        $broken = $this->recalculate($env);
        config(['services.attendance_agent.trust_device_direction' => false]);
        $before = $broken->fresh()->getRawOriginal();
        $beforeIntervals = $broken->intervals()->count();

        $this->assertSame(0, Artisan::call('attendance:audit-punch-directions', [
            '--from' => '2026-10-01', '--to' => '2026-10-31', '--employee' => $env['employee']->code,
        ]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('2026-10-01..2026-10-31', $report['summary']['work_date']);
        $this->assertSame(1, $report['summary']['projection_mismatches']);
        $this->assertSame(1, $report['summary']['review_cleared_by_recalculation']);
        $this->assertSame('NO', $report['summary']['production_business_data_mutation']);
        $finding = $report['findings'][0];
        $this->assertSame($this->date, $finding['work_date']);
        $this->assertNull($finding['stored']['check_out_at']);
        $this->assertSame($this->date.' 17:02:00', $finding['preview']['check_out_at']);
        $this->assertSame(496, $finding['preview']['worked_minutes']);
        $this->assertSame($before, $broken->fresh()->getRawOriginal());
        $this->assertSame($beforeIntervals, $broken->intervals()->count());
    }

    public function test_range_audit_rejects_mixed_or_oversized_scopes(): void
    {
        $this->assertNotSame(0, Artisan::call('attendance:audit-punch-directions', ['--date' => '2026-10-03', '--from' => '2026-10-01']));
        $this->assertNotSame(0, Artisan::call('attendance:audit-punch-directions', ['--from' => '2026-10-31', '--to' => '2026-10-01']));
        $this->assertNotSame(0, Artisan::call('attendance:audit-punch-directions', ['--from' => '2026-01-01', '--to' => '2026-12-31']));
    }
}
