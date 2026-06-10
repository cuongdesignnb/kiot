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
}
