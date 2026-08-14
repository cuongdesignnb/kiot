<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceDevice;
use App\Models\AttendanceLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeSalarySetting;
use App\Models\EmployeeWorkSchedule;
use App\Models\Shift;
use App\Models\TimekeepingRecord;
use App\Models\TimekeepingSetting;
use App\Models\User;
use App\Services\SalaryCalculationService;
use App\Services\TimekeepingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SplitShiftTimekeepingTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Timekeeping admin',
            'email' => 'timekeeping-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
    }

    private function environment(bool $inferSingleInOut = false, string $salaryType = 'by_workday'): array
    {
        $branch = Branch::create(['name' => 'Split shift '.uniqid()]);
        $employee = Employee::create([
            'code' => 'NV-'.uniqid(),
            'name' => 'Split shift employee',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);

        EmployeeSalarySetting::create([
            'employee_id' => $employee->id,
            'base_salary' => $salaryType === 'hourly' ? 50000 : 10000000,
            'salary_type' => $salaryType,
            'has_overtime' => true,
            'overtime_rate' => 150,
        ]);

        $morning = Shift::create([
            'branch_id' => $branch->id,
            'name' => 'Morning',
            'start_time' => '08:30:00',
            'end_time' => '12:00:00',
            'status' => 'active',
        ]);
        $afternoon = Shift::create([
            'branch_id' => $branch->id,
            'name' => 'Afternoon',
            'start_time' => '13:30:00',
            'end_time' => '18:00:00',
            'status' => 'active',
        ]);

        $date = '2026-05-25';
        $morningSchedule = EmployeeWorkSchedule::create([
            'employee_id' => $employee->id,
            'branch_id' => $branch->id,
            'work_date' => $date,
            'shift_id' => $morning->id,
            'start_time' => '08:30:00',
            'end_time' => '12:00:00',
            'slot' => 1,
            'status' => 'approved',
        ]);
        $afternoonSchedule = EmployeeWorkSchedule::create([
            'employee_id' => $employee->id,
            'branch_id' => $branch->id,
            'work_date' => $date,
            'shift_id' => $afternoon->id,
            'start_time' => '13:30:00',
            'end_time' => '18:00:00',
            'slot' => 2,
            'status' => 'approved',
        ]);

        TimekeepingSetting::create([
            'branch_id' => $branch->id,
            'standard_hours_per_day' => 8,
            'use_shift_allowances' => true,
            'allow_multiple_shifts_one_inout' => $inferSingleInOut,
            'status' => 'active',
        ]);

        $device = AttendanceDevice::create([
            'branch_id' => $branch->id,
            'name' => 'Split shift device',
            'device_id' => 'DEV-'.uniqid(),
            'status' => 'online',
            'ip_address' => '127.0.0.1',
            'tcp_port' => 4370,
        ]);

        return compact('branch', 'employee', 'morningSchedule', 'afternoonSchedule', 'device', 'date');
    }

    private function punch(Employee $employee, string $date, string $time, int $deviceId): AttendanceLog
    {
        return AttendanceLog::create([
            'attendance_device_id' => $deviceId,
            'employee_id' => $employee->id,
            'device_user_id' => $employee->attendance_code ?: (string) $employee->id,
            'punched_at' => $date.' '.$time,
            'event_type' => 'punch',
            'raw' => ['time' => $time],
        ]);
    }

    private function recalculate(array $environment): void
    {
        app(TimekeepingService::class)->recalculateForRange(
            Carbon::parse($environment['date'])->startOfDay(),
            Carbon::parse($environment['date'])->endOfDay(),
            $environment['employee']->id
        );
    }

    public function test_four_punches_split_shift_create_two_disjoint_intervals_and_one_workday(): void
    {
        $environment = $this->environment();
        foreach (['08:30:00', '12:00:00', '13:30:00', '18:00:00'] as $time) {
            $this->punch($environment['employee'], $environment['date'], $time, $environment['device']->id);
        }

        $this->recalculate($environment);

        $records = TimekeepingRecord::with('intervals')
            ->where('employee_id', $environment['employee']->id)
            ->orderBy('slot')
            ->get();

        $this->assertCount(2, $records);
        $this->assertSame([210, 270], $records->pluck('worked_minutes')->map(fn ($value) => (int) $value)->all());
        $this->assertSame([210, 270], $records->pluck('regular_minutes')->map(fn ($value) => (int) $value)->all());
        $this->assertSame([0, 0], $records->pluck('needs_review')->map(fn ($value) => (int) $value)->all());
        $this->assertSame([0.5, 0.5], $records->pluck('work_units')->map(fn ($value) => (float) $value)->all());
        $this->assertSame([210, 270], $records->map(fn ($record) => (int) $record->intervals->first()->worked_minutes)->all());
        $this->assertSame(['complete', 'complete'], $records->map(fn ($record) => $record->intervals->first()->status)->all());

        $payroll = app(SalaryCalculationService::class)->calculateForEmployee(
            $environment['employee']->fresh(),
            Carbon::parse($environment['date']),
            Carbon::parse($environment['date']),
            26
        );

        $this->assertSame(480, $payroll['total_worked_minutes']);
        $this->assertSame(480, $payroll['total_regular_minutes']);
        $this->assertSame(1.0, (float) $payroll['normal_work_units']);
        $this->assertSame(1.0, (float) $payroll['work_units']);
    }

    public function test_two_punches_are_inferred_across_split_slots_only_when_enabled(): void
    {
        $environment = $this->environment(true);
        $this->punch($environment['employee'], $environment['date'], '08:30:00', $environment['device']->id);
        $this->punch($environment['employee'], $environment['date'], '18:00:00', $environment['device']->id);

        $this->recalculate($environment);

        $records = TimekeepingRecord::with('intervals')
            ->where('employee_id', $environment['employee']->id)
            ->orderBy('slot')
            ->get();

        $this->assertSame([210, 270], $records->pluck('worked_minutes')->map(fn ($value) => (int) $value)->all());
        $this->assertSame(['inferred', 'inferred'], $records->map(fn ($record) => $record->intervals->first()->source)->all());
        $this->assertTrue($records->every(fn ($record) => ! $record->needs_review));
    }

    public function test_two_punches_without_inference_are_reviewable_and_do_not_use_schedule_boundaries(): void
    {
        $environment = $this->environment(false);
        $this->punch($environment['employee'], $environment['date'], '08:30:00', $environment['device']->id);
        $this->punch($environment['employee'], $environment['date'], '18:00:00', $environment['device']->id);

        $this->recalculate($environment);

        $records = TimekeepingRecord::with('intervals')
            ->where('employee_id', $environment['employee']->id)
            ->orderBy('slot')
            ->get();

        $this->assertSame([0, 0], $records->pluck('worked_minutes')->map(fn ($value) => (int) $value)->all());
        $this->assertTrue($records->every(fn ($record) => (bool) $record->needs_review));
        $this->assertTrue($records->every(fn ($record) => $record->intervals->first()->status === 'needs_review'));
    }

    public function test_missing_split_shift_in_punch_is_reviewable_without_reusing_the_checkout(): void
    {
        $environment = $this->environment(false);
        foreach (['12:00:00', '13:30:00', '18:00:00'] as $time) {
            $this->punch($environment['employee'], $environment['date'], $time, $environment['device']->id);
        }

        $this->recalculate($environment);

        $records = TimekeepingRecord::with('intervals')
            ->where('employee_id', $environment['employee']->id)
            ->orderBy('slot')
            ->get();

        $this->assertSame([0, 270], $records->pluck('worked_minutes')->map(fn ($value) => (int) $value)->all());
        $this->assertTrue((bool) $records->first()->needs_review);
        $this->assertFalse((bool) $records->last()->needs_review);
        $this->assertSame(12, $records->first()->intervals->first()->check_out_at->hour);
        $this->assertNull($records->first()->intervals->first()->check_in_at);
    }

    public function test_manual_split_shift_intervals_use_half_day_units_per_slot(): void
    {
        $environment = $this->environment();
        $admin = $this->admin();

        foreach ([
            [$environment['morningSchedule'], '08:30', '12:00'],
            [$environment['afternoonSchedule'], '13:30', '18:00'],
        ] as [$schedule, $checkIn, $checkOut]) {
            $response = $this->actingAs($admin)->postJson('/api/timekeeping-records', [
                'employee_work_schedule_id' => $schedule->id,
                'attendance_type' => 'work',
                'intervals' => [[
                    'check_in_time' => $checkIn,
                    'check_out_time' => $checkOut,
                ]],
            ]);

            $response->assertOk();
        }

        $this->assertSame([0.5, 0.5], TimekeepingRecord::where('employee_id', $environment['employee']->id)
            ->orderBy('slot')
            ->pluck('work_units')
            ->map(fn ($value) => (float) $value)
            ->all());
    }

    public function test_legacy_manual_reverse_pair_is_rejected_in_vietnamese(): void
    {
        $environment = $this->environment();

        $response = $this->actingAs($this->admin())->postJson('/api/timekeeping-records', [
            'employee_work_schedule_id' => $environment['morningSchedule']->id,
            'attendance_type' => 'work',
            'check_in_time' => '11:00',
            'check_out_time' => '08:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['intervals']);
        $this->assertDatabaseMissing('timekeeping_records', [
            'employee_work_schedule_id' => $environment['morningSchedule']->id,
        ]);
    }

    public function test_hourly_payroll_uses_regular_minutes_and_pays_overtime_separately(): void
    {
        $environment = $this->environment(false, 'hourly');
        $this->assertSame('hourly', $environment['employee']->fresh()->salarySetting->salary_type);
        TimekeepingRecord::create([
            'employee_id' => $environment['employee']->id,
            'employee_work_schedule_id' => $environment['morningSchedule']->id,
            'branch_id' => $environment['branch']->id,
            'shift_id' => null,
            'work_date' => $environment['date'],
            'slot' => 1,
            'worked_minutes' => 540,
            'regular_minutes' => 480,
            'ot_minutes' => 60,
            'work_units' => 1,
            'attendance_type' => 'work',
            'source' => 'manual',
        ]);

        $payroll = app(SalaryCalculationService::class)->calculateForEmployee(
            $environment['employee']->fresh(),
            Carbon::parse($environment['date']),
            Carbon::parse($environment['date']),
            26
        );

        $this->assertEquals(400000, $payroll['base']);
        $this->assertSame(480, $payroll['total_regular_minutes']);
        $this->assertSame(60, $payroll['total_overtime_minutes']);
        $this->assertEquals(75000, $payroll['ot_pay']);
    }

    public function test_legacy_record_is_read_without_automatic_interval_backfill(): void
    {
        $environment = $this->environment();
        TimekeepingRecord::create([
            'employee_id' => $environment['employee']->id,
            'employee_work_schedule_id' => $environment['morningSchedule']->id,
            'branch_id' => $environment['branch']->id,
            'shift_id' => $environment['morningSchedule']->shift_id,
            'work_date' => $environment['date'],
            'slot' => 1,
            'check_in_at' => $environment['date'].' 08:30:00',
            'check_out_at' => $environment['date'].' 12:00:00',
            'worked_minutes' => 210,
            'work_units' => 0.5,
            'attendance_type' => 'work',
            'source' => 'device',
        ]);

        $this->punch($environment['employee'], $environment['date'], '08:30:00', $environment['device']->id);
        $this->punch($environment['employee'], $environment['date'], '12:00:00', $environment['device']->id);
        $this->recalculate($environment);

        $this->assertDatabaseCount('timekeeping_intervals', 0);
        $this->assertSame(210, (int) TimekeepingRecord::where('employee_work_schedule_id', $environment['morningSchedule']->id)->value('worked_minutes'));
    }

    public function test_manual_overlapping_intervals_are_rejected_without_persisting_a_record(): void
    {
        $environment = $this->environment();

        $response = $this->actingAs($this->admin())->postJson('/api/timekeeping-records', [
            'employee_work_schedule_id' => $environment['morningSchedule']->id,
            'attendance_type' => 'work',
            'intervals' => [
                ['check_in_time' => '08:30', 'check_out_time' => '10:00'],
                ['check_in_time' => '09:30', 'check_out_time' => '12:00'],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['intervals']);
        $this->assertDatabaseMissing('timekeeping_records', [
            'employee_work_schedule_id' => $environment['morningSchedule']->id,
        ]);
    }

    public function test_manual_reverse_interval_on_day_shift_is_rejected_in_vietnamese(): void
    {
        $environment = $this->environment();

        $response = $this->actingAs($this->admin())->postJson('/api/timekeeping-records', [
            'employee_work_schedule_id' => $environment['morningSchedule']->id,
            'attendance_type' => 'work',
            'intervals' => [
                ['check_in_time' => '11:00', 'check_out_time' => '08:00'],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['intervals'])
            ->assertJsonPath('errors.intervals.0', 'Thời gian kết thúc phải sau thời gian bắt đầu, trừ ca qua ngày.');
        $this->assertDatabaseMissing('timekeeping_records', [
            'employee_work_schedule_id' => $environment['morningSchedule']->id,
        ]);
    }

    public function test_duplicate_punches_are_kept_in_raw_data_and_marked_for_review(): void
    {
        $environment = $this->environment();
        $secondDevice = AttendanceDevice::create([
            'branch_id' => $environment['branch']->id,
            'name' => 'Split shift duplicate device',
            'device_id' => 'DEV-'.uniqid(),
            'status' => 'online',
            'ip_address' => '127.0.0.2',
            'tcp_port' => 4370,
        ]);
        $this->punch($environment['employee'], $environment['date'], '08:30:00', $environment['device']->id);
        $this->punch($environment['employee'], $environment['date'], '12:00:00', $environment['device']->id);
        $this->punch($environment['employee'], $environment['date'], '12:00:00', $secondDevice->id);
        $this->punch($environment['employee'], $environment['date'], '18:00:00', $environment['device']->id);

        $this->recalculate($environment);

        $record = TimekeepingRecord::where('employee_work_schedule_id', $environment['afternoonSchedule']->id)->firstOrFail();
        $this->assertTrue((bool) $record->needs_review);
        $this->assertCount(4, $record->raw['log_ids']);
        $this->assertCount(2, array_unique($record->raw['unmatched_log_ids']));
    }

    public function test_overnight_manual_interval_uses_next_day_without_boundary_fallback(): void
    {
        $environment = $this->environment();
        $overnightShift = Shift::create([
            'branch_id' => $environment['branch']->id,
            'name' => 'Overnight',
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'status' => 'active',
        ]);
        $schedule = EmployeeWorkSchedule::create([
            'employee_id' => $environment['employee']->id,
            'branch_id' => $environment['branch']->id,
            'work_date' => $environment['date'],
            'shift_id' => $overnightShift->id,
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
            'slot' => 3,
            'status' => 'approved',
        ]);

        $attributes = app(TimekeepingService::class)->buildManualRecordAttributes(
            $schedule->load('shift'),
            'work',
            '22:00',
            '06:00'
        );

        $this->assertSame(480, $attributes['worked_minutes']);
        $this->assertSame('complete', $attributes['_intervals']->first()['status']);
        $this->assertFalse($attributes['needs_review']);
    }

    public function test_manual_ot_is_not_added_twice_when_it_matches_interval_overtime(): void
    {
        $environment = $this->environment();

        $attributes = app(TimekeepingService::class)->buildManualRecordAttributes(
            $environment['afternoonSchedule']->load('shift'),
            'work',
            '13:30',
            '19:00',
            60
        );

        $this->assertSame(330, $attributes['worked_minutes']);
        $this->assertSame(270, $attributes['regular_minutes']);
        $this->assertSame(60, $attributes['ot_minutes']);
    }
}
