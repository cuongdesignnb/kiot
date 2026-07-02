<?php

namespace Tests\Feature\Payroll;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use App\Models\Employee;
use App\Models\Paysheet;
use App\Models\Payslip;
use App\Models\PayslipAdjustment;
use App\Models\EmployeeSalarySetting;
use Carbon\Carbon;

/**
 * HOTFIX 24.12B — Payroll adjustment override.
 *
 * When the user opens the allowance/bonus/deduction popup and removes every
 * row, the saved total must be 0 (the user's explicit intent) instead of
 * silently falling back to the auto-computed amount. The bulk endpoint sets
 * `details.manual_overrides[type] = true` so recalcSlipWithAdjustments() and
 * performRecalculation() honour the empty list. OT remains additive.
 */
class Step2412BPayrollAdjustmentOverrideTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 2412B',
            'email'    => 'admin-2412b-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function makeEmployee(float $baseSalary = 26000000): Employee
    {
        $emp = Employee::create([
            'code'      => 'NV-2412B-' . uniqid(),
            'name'      => 'NV 2412B',
            'is_active' => true,
        ]);
        EmployeeSalarySetting::create([
            'employee_id' => $emp->id,
            'base_salary' => $baseSalary,
            'salary_type' => 'by_workday',
        ]);
        return $emp;
    }

    private function makePaysheet(string $status = 'calculated'): Paysheet
    {
        $start = Carbon::create(2026, 4, 1);
        $end   = Carbon::create(2026, 4, 30);
        return Paysheet::create([
            'code'                  => Paysheet::nextCode(),
            'name'                  => 'BL 2412B',
            'pay_period'            => 'monthly',
            'period_start'          => $start->toDateString(),
            'period_end'            => $end->toDateString(),
            'standard_working_days' => 26,
            'scope'                 => 'all',
            'status'                => $status,
        ]);
    }

    private function makePayslip(Paysheet $sheet, Employee $emp, array $overrides = []): Payslip
    {
        return Payslip::create(array_merge([
            'code'         => 'PL-2412B-' . uniqid(),
            'paysheet_id'  => $sheet->id,
            'employee_id'  => $emp->id,
            'base_salary'  => 10000000,
            'allowances'   => 500000,
            'bonus'        => 300000,
            'deductions'   => 100000,
            'ot_pay'       => 200000,
            'commission'   => 0,
            'total_salary' => 10900000,
            'paid_amount'  => 0,
            'remaining'    => 10900000,
            'work_units'   => 26,
            'details'      => ['standard_work_units' => 26],
        ], $overrides));
    }

    private function makeAdjustment(Payslip $slip, string $type, int $amount, string $name = 'Mục cũ'): PayslipAdjustment
    {
        return PayslipAdjustment::create([
            'payslip_id' => $slip->id,
            'type'       => $type,
            'name'       => $name,
            'amount'     => $amount,
            'notes'      => null,
            'meta'       => null,
        ]);
    }

    /** TC-01: empty bulk for allowance → allowance = 0, manual_overrides flag set. */
    public function test_bulk_empty_allowance_persists_zero_and_sets_override_flag(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp);
        $this->makeAdjustment($slip, 'allowance', 500000, 'Ăn trưa');

        $res = $this->actingAs($admin)->putJson(
            "/api/paysheets/{$sheet->id}/payslips/{$slip->id}/adjustments/allowance/bulk",
            ['items' => []]
        );

        $res->assertOk()->assertJsonPath('success', true);

        $fresh = $slip->fresh();
        $this->assertSame(0, (int) $fresh->allowances, 'allowances must be 0 after empty bulk save');
        $this->assertTrue((bool) ($fresh->details['manual_overrides']['allowance'] ?? false));
        $this->assertSame(0, PayslipAdjustment::where('payslip_id', $slip->id)->where('type', 'allowance')->count());
    }

    /** TC-02: bulk with items replaces existing rows entirely. */
    public function test_bulk_replaces_existing_adjustment_rows(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp);
        $this->makeAdjustment($slip, 'allowance', 500000, 'Ăn trưa');
        $this->makeAdjustment($slip, 'allowance', 200000, 'Đi lại');

        $res = $this->actingAs($admin)->putJson(
            "/api/paysheets/{$sheet->id}/payslips/{$slip->id}/adjustments/allowance/bulk",
            ['items' => [
                ['name' => 'Phụ cấp cơm', 'amount' => 800000],
            ]]
        );

        $res->assertOk();
        $rows = PayslipAdjustment::where('payslip_id', $slip->id)->where('type', 'allowance')->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Phụ cấp cơm', $rows->first()->name);
        $this->assertSame(800000, (int) $rows->first()->amount);
        $this->assertSame(800000, (int) $slip->fresh()->allowances);
    }

    /** TC-03: reset-default removes adjustments + clears the manual flag. */
    public function test_reset_default_clears_override_and_rows(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp, [
            'details' => ['standard_work_units' => 26, 'manual_overrides' => ['allowance' => true]],
        ]);
        $this->makeAdjustment($slip, 'allowance', 999000, 'Tự đặt');

        $res = $this->actingAs($admin)->postJson(
            "/api/paysheets/{$sheet->id}/payslips/{$slip->id}/adjustments/allowance/reset-default"
        );
        $res->assertOk();

        $fresh = $slip->fresh();
        $this->assertSame(0, PayslipAdjustment::where('payslip_id', $slip->id)->where('type', 'allowance')->count());
        $this->assertArrayNotHasKey('allowance', $fresh->details['manual_overrides'] ?? []);
    }

    /** TC-04: empty bulk for bonus → bonus = 0 + override flag. */
    public function test_bulk_empty_bonus_persists_zero(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp);
        $this->makeAdjustment($slip, 'bonus', 300000, 'KPI');

        $this->actingAs($admin)->putJson(
            "/api/paysheets/{$sheet->id}/payslips/{$slip->id}/adjustments/bonus/bulk",
            ['items' => []]
        )->assertOk();

        $fresh = $slip->fresh();
        $this->assertSame(0, (int) $fresh->bonus);
        $this->assertTrue((bool) ($fresh->details['manual_overrides']['bonus'] ?? false));
    }

    /**
     * TC-05 (updated by HOTFIX 24.12C): when the user empties the deduction
     * popup, the override total is the final number — auto late_penalty is
     * NOT silently re-added. (Old 24.12B policy kept late_penalty even under
     * override; 24.12C spec §C Mode 1 explicitly removes it. Late_penalty
     * comes back via reset-default.)
     */
    public function test_bulk_empty_deduction_wipes_to_zero_including_auto_late_penalty(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp, [
            'details' => [
                'standard_work_units' => 26,
                'late_penalty'        => 50000,
            ],
        ]);
        $this->makeAdjustment($slip, 'deduction', 200000, 'BHXH');

        $this->actingAs($admin)->putJson(
            "/api/paysheets/{$sheet->id}/payslips/{$slip->id}/adjustments/deduction/bulk",
            ['items' => []]
        )->assertOk();

        $fresh = $slip->fresh();
        $this->assertSame(0, PayslipAdjustment::where('payslip_id', $slip->id)->where('type', 'deduction')->count());
        $this->assertTrue((bool) ($fresh->details['manual_overrides']['deduction'] ?? false));
        $this->assertSame(0, (int) $fresh->deductions, 'override = 0 must include late_penalty wipe (24.12C policy)');
    }

    /** TC-06: OT stays additive — bulk OT does not set override flag, OT auto value still counted. */
    public function test_bulk_ot_stays_additive_no_override_flag(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        // recalcSlipWithAdjustments reads scalar `details.ot_pay` (+ optional holiday_pay).
        $slip  = $this->makePayslip($sheet, $emp, [
            'details' => [
                'standard_work_units' => 26,
                'ot_pay'              => 100000,
            ],
        ]);

        $this->actingAs($admin)->putJson(
            "/api/paysheets/{$sheet->id}/payslips/{$slip->id}/adjustments/ot/bulk",
            ['items' => [
                ['name' => 'OT chủ nhật bổ sung', 'amount' => 400000],
            ]]
        )->assertOk();

        $fresh = $slip->fresh();
        $this->assertArrayNotHasKey('ot', $fresh->details['manual_overrides'] ?? []);
        // auto 100k + manual 400k = 500k
        $this->assertSame(500000, (int) $fresh->ot_pay);
    }

    /** TC-07: locked paysheet rejects bulk update. */
    public function test_locked_paysheet_rejects_bulk_save(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet('locked');
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp);

        $this->actingAs($admin)->putJson(
            "/api/paysheets/{$sheet->id}/payslips/{$slip->id}/adjustments/allowance/bulk",
            ['items' => [['name' => 'X', 'amount' => 100000]]]
        )->assertStatus(422);
    }

    /** TC-08: locked paysheet rejects reset-default. */
    public function test_locked_paysheet_rejects_reset_default(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet('locked');
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp);

        $this->actingAs($admin)->postJson(
            "/api/paysheets/{$sheet->id}/payslips/{$slip->id}/adjustments/allowance/reset-default"
        )->assertStatus(422);
    }

    /** TC-09: bulk endpoint validates type. */
    public function test_bulk_endpoint_rejects_unknown_type(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp);

        $this->actingAs($admin)->putJson(
            "/api/paysheets/{$sheet->id}/payslips/{$slip->id}/adjustments/foobar/bulk",
            ['items' => []]
        )->assertStatus(422);
    }

    /** TC-10: response carries the recomputed slip so the FE can splice it in. */
    public function test_bulk_response_returns_recomputed_slip(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp);

        $res = $this->actingAs($admin)->putJson(
            "/api/paysheets/{$sheet->id}/payslips/{$slip->id}/adjustments/allowance/bulk",
            ['items' => [['name' => 'Phụ cấp cơm', 'amount' => 700000]]]
        );

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('slip.id', $slip->id)
            ->assertJsonPath('slip.allowances', 700000);
    }
}
