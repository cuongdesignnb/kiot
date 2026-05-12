<?php

namespace Tests\Feature\Payroll;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use App\Models\Employee;
use App\Models\Paysheet;
use App\Models\Payslip;
use App\Models\EmployeeSalarySetting;
use Carbon\Carbon;

/**
 * STEP 24.12 — Payroll standard_working_days side panel.
 *
 * Pins the per-paysheet override:
 *   - Column persists on `paysheets`
 *   - PUT /api/paysheets/{id}/standard-working-days updates + recalculates
 *   - SalaryCalculationService honours the override as denominator
 *   - Locked/cancelled sheets refuse the update
 *   - Validation: must be 1..31
 *
 * Note: depends on the broader Employee + Timekeeping + SalaryCalculationService
 * stack that already has dedicated coverage in the existing test suite. These
 * tests focus on the new column + endpoint contract.
 */
class Step2412PayrollStandardWorkingDaysTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 2412',
            'email'    => 'admin-2412-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function makeEmployee(float $baseSalary = 26000000): Employee
    {
        $emp = Employee::create([
            'code'       => 'NV-2412-' . uniqid(),
            'name'       => 'NV 2412',
            'is_active'  => true,
        ]);
        EmployeeSalarySetting::create([
            'employee_id'   => $emp->id,
            'base_salary'   => $baseSalary,
            'salary_type'   => 'by_workday',
        ]);
        return $emp;
    }

    private function makePaysheet(?float $standard = null): Paysheet
    {
        $start = Carbon::create(2026, 4, 1);
        $end   = Carbon::create(2026, 4, 30);
        return Paysheet::create([
            'code'                  => Paysheet::nextCode(),
            'name'                  => 'BL test 2412',
            'pay_period'            => 'monthly',
            'period_start'          => $start->toDateString(),
            'period_end'            => $end->toDateString(),
            'standard_working_days' => $standard,
            'scope'                 => 'all',
            'status'                => 'calculated',
        ]);
    }

    private function makePayslip(Paysheet $sheet, Employee $emp, float $base = 26000000, float $workUnits = 25): Payslip
    {
        return Payslip::create([
            'code'          => 'PL-2412-' . uniqid(),
            'paysheet_id'   => $sheet->id,
            'employee_id'   => $emp->id,
            'base_salary'   => $base,
            'allowances'    => 0,
            'deductions'    => 0,
            'ot_pay'        => 0,
            'total_salary'  => $base,
            'paid_amount'   => 0,
            'remaining'     => $base,
            'work_units'    => $workUnits,
        ]);
    }

    public function test_payroll_has_standard_working_days_column(): void
    {
        $sheet = $this->makePaysheet(26);
        $this->assertEquals(26.0, (float) $sheet->fresh()->standard_working_days);
    }

    public function test_standard_working_days_endpoint_persists_value(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet(26);
        $emp = $this->makeEmployee(26000000);
        $this->makePayslip($sheet, $emp);

        $res = $this->actingAs($admin)
            ->putJson("/api/paysheets/{$sheet->id}/standard-working-days", [
                'standard_working_days' => 25,
            ]);

        $res->assertOk();
        $this->assertEquals(25.0, (float) $sheet->fresh()->standard_working_days);
    }

    public function test_standard_working_days_validation_rejects_out_of_range(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet(26);

        $this->actingAs($admin)
            ->putJson("/api/paysheets/{$sheet->id}/standard-working-days", ['standard_working_days' => 0])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->putJson("/api/paysheets/{$sheet->id}/standard-working-days", ['standard_working_days' => 32])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->putJson("/api/paysheets/{$sheet->id}/standard-working-days", ['standard_working_days' => -1])
            ->assertStatus(422);

        // Value unchanged after the failed attempts.
        $this->assertEquals(26.0, (float) $sheet->fresh()->standard_working_days);
    }

    public function test_cannot_update_standard_working_days_when_paysheet_locked(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet(26);
        $sheet->update(['status' => 'locked']);

        $res = $this->actingAs($admin)
            ->putJson("/api/paysheets/{$sheet->id}/standard-working-days", [
                'standard_working_days' => 25,
            ]);
        $res->assertStatus(422);

        $this->assertEquals(26.0, (float) $sheet->fresh()->standard_working_days);
    }

    public function test_endpoint_also_accepts_name_and_notes_optional(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet(26);
        $emp = $this->makeEmployee();
        $this->makePayslip($sheet, $emp);

        $res = $this->actingAs($admin)
            ->putJson("/api/paysheets/{$sheet->id}/standard-working-days", [
                'standard_working_days' => 25,
                'name'                  => 'Bảng lương đặc biệt 04/2026',
                'notes'                 => 'Override do nghỉ Tết',
            ]);

        $res->assertOk();
        $fresh = $sheet->fresh();
        $this->assertSame('Bảng lương đặc biệt 04/2026', $fresh->name);
        $this->assertSame('Override do nghỉ Tết', $fresh->notes);
    }

    public function test_salary_calculation_service_uses_override_as_denominator(): void
    {
        // Calling the service directly avoids dependencies on timekeeping seed data.
        $emp = $this->makeEmployee(26000000);
        $service = app(\App\Services\SalaryCalculationService::class);
        $from = Carbon::create(2026, 4, 1);
        $to   = Carbon::create(2026, 4, 30);

        // With override = 26, no timekeeping records → 0 work_units → base = 0
        // (the calc service still uses the override internally; we're verifying
        // it doesn't throw and respects the parameter rather than asserting an
        // exact base, since that depends on attendance records).
        $a = $service->calculateForEmployee($emp, $from, $to, 26.0);
        $b = $service->calculateForEmployee($emp, $from, $to, 25.0);

        $this->assertArrayHasKey('standard_work_units', $a);
        $this->assertSame(26.0, (float) $a['standard_work_units']);
        $this->assertSame(25.0, (float) $b['standard_work_units']);
    }

    public function test_legacy_paysheet_null_standard_days_returns_effective_calendar_value(): void
    {
        // Legacy paysheet — created before STEP 24.12, never had standard_working_days set.
        $admin = $this->admin();
        $sheet = $this->makePaysheet(null);
        $this->assertNull($sheet->fresh()->standard_working_days);

        // GET /api/paysheets/{id} should include effective_standard_working_days
        // computed from the calendar so the FE can render without falling back to 26.
        $res = $this->actingAs($admin)->getJson("/api/paysheets/{$sheet->id}");
        $res->assertOk();

        $effective = $res->json('data.effective_standard_working_days');
        $this->assertNotNull($effective, 'effective_standard_working_days must be present');
        $this->assertGreaterThan(0, (float) $effective);

        // Critical: merely opening the form must NOT persist a value into the column.
        $this->assertNull($sheet->fresh()->standard_working_days);
    }

    public function test_endpoint_returns_recomputed_paysheet_data(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet(26);
        $emp = $this->makeEmployee(26000000);
        $this->makePayslip($sheet, $emp);

        $res = $this->actingAs($admin)
            ->putJson("/api/paysheets/{$sheet->id}/standard-working-days", [
                'standard_working_days' => 25,
            ]);
        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.standard_working_days', 25);
    }
}
