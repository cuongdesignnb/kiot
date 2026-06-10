<?php

namespace Tests\Feature\Payroll;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use App\Models\Employee;
use App\Models\Branch;
use App\Models\Shift;
use App\Models\EmployeeWorkSchedule;
use App\Models\TimekeepingRecord;
use App\Models\EmployeeSalarySetting;
use App\Models\TimekeepingSetting;
use App\Services\TimekeepingService;
use App\Services\SalaryCalculationService;
use Carbon\Carbon;

class ManualTimekeepingTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin Test',
            'email'    => 'admin-test-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
        ]);
    }

    private function setupTimekeepingEnvironment(): array
    {
        $branch = Branch::create([
            'name' => 'Branch Test ' . uniqid(),
        ]);

        $employee = Employee::create([
            'code' => 'NV-' . uniqid(),
            'name' => 'Test Employee',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);

        EmployeeSalarySetting::create([
            'employee_id' => $employee->id,
            'base_salary' => 10000000,
            'salary_type' => 'by_workday',
        ]);

        $shift = Shift::create([
            'name' => 'Ca hành chính',
            'start_time' => '08:30:00',
            'end_time' => '18:30:00',
            'branch_id' => $branch->id,
            'duration_minutes' => 600,
        ]);

        $schedule = EmployeeWorkSchedule::create([
            'employee_id' => $employee->id,
            'branch_id' => $branch->id,
            'work_date' => '2026-05-25',
            'shift_id' => $shift->id,
            'start_time' => '08:30:00',
            'end_time' => '18:30:00',
            'slot' => 1,
            'status' => 'approved',
        ]);

        TimekeepingSetting::create([
            'branch_id' => $branch->id,
            'use_shift_allowances' => true,
            'late_grace_minutes' => 0,
            'early_grace_minutes' => 0,
            'standard_hours_per_day' => 8,
            'status' => 'active',
        ]);

        return compact('branch', 'employee', 'shift', 'schedule');
    }

    public function test_manual_timekeeping_full_day_generates_one_work_unit(): void
    {
        $admin = $this->admin();
        $env = $this->setupTimekeepingEnvironment();

        $res = $this->actingAs($admin)
            ->postJson('/api/timekeeping-records', [
                'employee_work_schedule_id' => $env['schedule']->id,
                'attendance_type' => 'work',
                'check_in_time' => '08:30',
                'check_out_time' => '18:30',
                'ot_minutes' => 0,
                'notes' => 'Chấm công tay full ngày',
            ]);

        $res->assertOk();
        $res->assertJsonPath('success', true);

        $record = TimekeepingRecord::where('employee_work_schedule_id', $env['schedule']->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('manual', $record->source);
        $this->assertTrue((bool)$record->manual_override);
        $this->assertGreaterThan(0, $record->worked_minutes);
        $this->assertEquals(1.0, (float)$record->work_units);
        $this->assertEquals(0, $record->late_minutes);
        $this->assertEquals(0, $record->early_minutes);
    }

    public function test_manual_timekeeping_half_day_generates_half_work_unit(): void
    {
        $admin = $this->admin();
        $env = $this->setupTimekeepingEnvironment();

        $res = $this->actingAs($admin)
            ->postJson('/api/timekeeping-records', [
                'employee_work_schedule_id' => $env['schedule']->id,
                'attendance_type' => 'work',
                'check_in_time' => '08:30',
                'check_out_time' => '12:30', // 4 hours
                'ot_minutes' => 0,
                'notes' => 'Chấm công tay nửa ngày',
            ]);

        $res->assertOk();

        $record = TimekeepingRecord::where('employee_work_schedule_id', $env['schedule']->id)->first();
        $this->assertNotNull($record);
        $this->assertEquals(0.5, (float)$record->work_units);
    }

    public function test_manual_timekeeping_paid_leave_generates_work_unit_and_integrates_payroll(): void
    {
        $admin = $this->admin();
        $env = $this->setupTimekeepingEnvironment();

        $res = $this->actingAs($admin)
            ->postJson('/api/timekeeping-records', [
                'employee_work_schedule_id' => $env['schedule']->id,
                'attendance_type' => 'leave_paid',
                'notes' => 'Nghỉ phép có lương',
            ]);

        $res->assertOk();

        $record = TimekeepingRecord::where('employee_work_schedule_id', $env['schedule']->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('leave_paid', $record->attendance_type);
        $this->assertEquals(1.0, (float)$record->work_units);

        // Calculate payroll
        $service = app(SalaryCalculationService::class);
        $from = Carbon::create(2026, 5, 25);
        $to = Carbon::create(2026, 5, 31);
        $payroll = $service->calculateForEmployee($env['employee'], $from, $to, 26);

        $this->assertEquals(1.0, (float)$payroll['paid_leave_units']);
        $this->assertEquals(1.0, (float)$payroll['work_units']);
    }

    public function test_manual_timekeeping_unpaid_leave_does_not_generate_work_unit(): void
    {
        $admin = $this->admin();
        $env = $this->setupTimekeepingEnvironment();

        $res = $this->actingAs($admin)
            ->postJson('/api/timekeeping-records', [
                'employee_work_schedule_id' => $env['schedule']->id,
                'attendance_type' => 'leave_unpaid',
                'notes' => 'Nghỉ phép không lương',
            ]);

        $res->assertOk();

        $record = TimekeepingRecord::where('employee_work_schedule_id', $env['schedule']->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('leave_unpaid', $record->attendance_type);
        $this->assertEquals(0.0, (float)$record->work_units);
    }

    public function test_payroll_calculates_main_salary_from_manual_timekeeping(): void
    {
        $admin = $this->admin();
        $env = $this->setupTimekeepingEnvironment();

        // Save manual record of 1 full day
        $this->actingAs($admin)
            ->postJson('/api/timekeeping-records', [
                'employee_work_schedule_id' => $env['schedule']->id,
                'attendance_type' => 'work',
                'check_in_time' => '08:30',
                'check_out_time' => '18:30',
            ])->assertOk();

        $service = app(SalaryCalculationService::class);
        $from = Carbon::create(2026, 5, 25);
        $to = Carbon::create(2026, 5, 31);
        $payroll = $service->calculateForEmployee($env['employee'], $from, $to, 26);

        $this->assertEquals(1.0, (float)$payroll['work_units']);
        $expectedBase = round(10000000 * 1.0 / 26);
        $this->assertEquals($expectedBase, $payroll['base']);
        $this->assertGreaterThan(0, $payroll['total']);
    }

    public function test_recalculate_does_not_override_manual_timekeeping_records(): void
    {
        $admin = $this->admin();
        $env = $this->setupTimekeepingEnvironment();

        // Create manual override record
        $this->actingAs($admin)
            ->postJson('/api/timekeeping-records', [
                'employee_work_schedule_id' => $env['schedule']->id,
                'attendance_type' => 'work',
                'check_in_time' => '08:30',
                'check_out_time' => '18:30',
                'notes' => 'Được chấm công tay',
            ])->assertOk();

        $record = TimekeepingRecord::where('employee_work_schedule_id', $env['schedule']->id)->first();
        $this->assertTrue((bool)$record->manual_override);
        $this->assertSame('manual', $record->source);

        // Call recalculate
        $service = app(TimekeepingService::class);
        $from = Carbon::create(2026, 5, 25);
        $to = Carbon::create(2026, 5, 31);
        $service->recalculateForRange($from, $to, $env['employee']->id);

        // Assert record is untouched
        $freshRecord = $record->fresh();
        $this->assertTrue((bool)$freshRecord->manual_override);
        $this->assertSame('manual', $freshRecord->source);
        $this->assertEquals(1.0, (float)$freshRecord->work_units);
    }

    public function test_manual_attendance_durations_and_units(): void
    {
        $env = $this->setupTimekeepingEnvironment();
        $service = app(TimekeepingService::class);

        // 1. Manual attendance 08:30-18:30 = 600 mins -> work_units = 1
        $attrs1 = $service->buildManualRecordAttributes($env['schedule'], 'work', '08:30', '18:30');
        $this->assertEquals(600, $attrs1['worked_minutes']);
        $this->assertEquals(1.0, (float)$attrs1['work_units']);

        // Create a schedule with exactly 8 hours shift (e.g. 08:30 to 16:30) to test 480 minutes limit
        $schedule8 = EmployeeWorkSchedule::create([
            'employee_id' => $env['employee']->id,
            'branch_id' => $env['branch']->id,
            'work_date' => '2026-05-26',
            'start_time' => '08:30:00',
            'end_time' => '16:30:00',
            'slot' => 1,
            'status' => 'approved',
        ]);

        // 2. Manual attendance exactly 8 hours = 480 mins -> work_units = 1
        $attrs2 = $service->buildManualRecordAttributes($schedule8, 'work', '08:30', '16:30');
        $this->assertEquals(480, $attrs2['worked_minutes']);
        $this->assertEquals(1.0, (float)$attrs2['work_units']);

        // 3. Manual attendance 479 mins -> work_units = 0.5 (under 480 limit)
        $attrs3 = $service->buildManualRecordAttributes($schedule8, 'work', '08:30', '16:29');
        $this->assertEquals(479, $attrs3['worked_minutes']);
        $this->assertEquals(0.5, (float)$attrs3['work_units']);

        // 4. Manual attendance valid half day (e.g. 240 mins) -> work_units = 0.5
        $attrs4 = $service->buildManualRecordAttributes($schedule8, 'work', '08:30', '12:30');
        $this->assertEquals(240, $attrs4['worked_minutes']);
        $this->assertEquals(0.5, (float)$attrs4['work_units']);

        // 5. Missing check-in or check-out does not generate negative minutes
        $attrs5 = $service->buildManualRecordAttributes($env['schedule'], 'work', null, '18:30');
        $this->assertGreaterThanOrEqual(0, $attrs5['worked_minutes']);

        $attrs6 = $service->buildManualRecordAttributes($env['schedule'], 'work', '08:30', null);
        $this->assertGreaterThanOrEqual(0, $attrs6['worked_minutes']);
    }

    public function test_controller_store_guards(): void
    {
        $admin = $this->admin();
        $env = $this->setupTimekeepingEnvironment();

        // 1. Save new manual record (full day) successfully
        $res = $this->actingAs($admin)->postJson('/api/timekeeping-records', [
            'employee_work_schedule_id' => $env['schedule']->id,
            'attendance_type' => 'work',
            'check_in_time' => '08:30',
            'check_out_time' => '18:30',
        ]);
        $res->assertOk();

        // Verify it has override = true, source = manual
        $record = TimekeepingRecord::where('employee_work_schedule_id', $env['schedule']->id)->first();
        $this->assertTrue((bool)$record->manual_override);
        $this->assertSame('manual', $record->source);
        $this->assertEquals(1.0, (float)$record->work_units);

        // 2. Update existing record from 1.0 to 1.0 successfully (no downgrade)
        $resUpdate = $this->actingAs($admin)->postJson('/api/timekeeping-records', [
            'employee_work_schedule_id' => $env['schedule']->id,
            'attendance_type' => 'work',
            'check_in_time' => '08:00',
            'check_out_time' => '18:00',
        ]);
        $resUpdate->assertOk();

        // 3. Update from 1.0 to 0.5 without confirm -> should return 422 requires_confirmation
        $resDowngrade = $this->actingAs($admin)->postJson('/api/timekeeping-records', [
            'employee_work_schedule_id' => $env['schedule']->id,
            'attendance_type' => 'work',
            'check_in_time' => '08:30',
            'check_out_time' => '12:30', // 4 hours -> 0.5 work units
        ]);
        $resDowngrade->assertStatus(422);
        $resDowngrade->assertJsonPath('requires_confirmation', true);
        $resDowngrade->assertJsonPath('confirm_type', 'downgrade');

        // 4. Update from 1.0 to 0.5 with confirm_downgrade = true -> allowed
        $resDowngradeConfirm = $this->actingAs($admin)->postJson('/api/timekeeping-records', [
            'employee_work_schedule_id' => $env['schedule']->id,
            'attendance_type' => 'work',
            'check_in_time' => '08:30',
            'check_out_time' => '12:30',
            'confirm_downgrade' => true,
        ]);
        $resDowngradeConfirm->assertOk();
        $this->assertEquals(0.5, (float)$resDowngradeConfirm->json('data.work_units'));

        // Let's bring it back to 1.0 first with both times populated
        $resRestore = $this->actingAs($admin)->postJson('/api/timekeeping-records', [
            'employee_work_schedule_id' => $env['schedule']->id,
            'attendance_type' => 'work',
            'check_in_time' => '08:30',
            'check_out_time' => '18:30',
            'confirm_downgrade' => true,
        ]);
        $resRestore->assertOk();

        // 5. Payload clears check-out time without confirm -> should return 422 requires_confirmation
        $resClearTime = $this->actingAs($admin)->postJson('/api/timekeeping-records', [
            'employee_work_schedule_id' => $env['schedule']->id,
            'attendance_type' => 'work',
            'check_in_time' => '08:30',
            'check_out_time' => null, // clear check_out
        ]);
        $resClearTime->assertStatus(422);
        $resClearTime->assertJsonPath('requires_confirmation', true);
        $resClearTime->assertJsonPath('confirm_type', 'clear_time');

        // 6. Payload clears check-out time with confirm_clear_time = true -> allowed
        $resClearTimeConfirm = $this->actingAs($admin)->postJson('/api/timekeeping-records', [
            'employee_work_schedule_id' => $env['schedule']->id,
            'attendance_type' => 'work',
            'check_in_time' => '08:30',
            'check_out_time' => null,
            'confirm_clear_time' => true,
            'confirm_downgrade' => true, // might also drop work units, so pass both
        ]);
        $resClearTimeConfirm->assertOk();
    }

    public function test_vu_hong_nhung_regression(): void
    {
        $admin = $this->admin();
        $env = $this->setupTimekeepingEnvironment();
        $employee = $env['employee'];
        $branch = $env['branch'];

        // Let's create a shift of exactly 8 hours (480 minutes)
        $shift8 = Shift::create([
            'name' => 'Ca 8h',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'branch_id' => $branch->id,
            'duration_minutes' => 480,
        ]);

        // Create 14 schedules, each with exactly 8 hours work, generating 14.0 work units total.
        // And one additional schedule which is missing attendance (0.0 work units initially)
        $schedules = [];
        for ($i = 1; $i <= 15; $i++) {
            $dayStr = sprintf('2026-05-%02d', $i);
            $schedules[$i] = EmployeeWorkSchedule::create([
                'employee_id' => $employee->id,
                'branch_id' => $branch->id,
                'work_date' => $dayStr,
                'shift_id' => $shift8->id,
                'start_time' => '08:00:00',
                'end_time' => '16:00:00',
                'slot' => 1,
                'status' => 'approved',
            ]);

            if ($i <= 14) {
                // Populate attendance records with exactly 480 minutes (08:00 to 16:00)
                TimekeepingRecord::create([
                    'employee_id' => $employee->id,
                    'employee_work_schedule_id' => $schedules[$i]->id,
                    'branch_id' => $branch->id,
                    'shift_id' => $shift8->id,
                    'work_date' => $dayStr,
                    'check_in_at' => $dayStr . ' 08:00:00',
                    'check_out_at' => $dayStr . ' 16:00:00',
                    'worked_minutes' => 480,
                    'work_units' => 1.0,
                    'manual_override' => true,
                    'source' => 'manual',
                    'attendance_type' => 'work',
                ]);
            }
        }

        // Before adding the 15th day, verify total is 14.0
        $initialUnits = TimekeepingRecord::where('employee_id', $employee->id)->sum('work_units');
        $this->assertEquals(14.0, (float)$initialUnits);

        // Now, the user goes to screen and manually adds/saves check-in/out for the 15th day
        // (which was missing attendance before). The user checks in 08:00 and check out 16:00 (480 minutes).
        $res = $this->actingAs($admin)->postJson('/api/timekeeping-records', [
            'employee_work_schedule_id' => $schedules[15]->id,
            'attendance_type' => 'work',
            'check_in_time' => '08:00',
            'check_out_time' => '16:00',
        ]);
        $res->assertOk();

        // Let's recalculate/check the new total work units. It should be 15.0 and NOT downgrade any of the existing 480-minute days to 0.5.
        $totalUnits = TimekeepingRecord::where('employee_id', $employee->id)->sum('work_units');
        $this->assertEquals(15.0, (float)$totalUnits);
    }
}
