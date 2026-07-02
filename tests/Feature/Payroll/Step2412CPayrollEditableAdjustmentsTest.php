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
 * HOTFIX 24.12C — Editable commission / allowance / bonus / deduction.
 *
 * Extends 24.12B: commission is now editable (was read-only), and a
 * deduction override now stays at the user's exact total — auto
 * `late_penalty` is no longer silently re-added. Reset-default goes back
 * to the calendar/setting-driven auto value.
 */
class Step2412CPayrollEditableAdjustmentsTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 2412C',
            'email'    => 'admin-2412c-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function makeEmployee(float $baseSalary = 10000000): Employee
    {
        $emp = Employee::create([
            'code'      => 'NV-2412C-' . uniqid(),
            'name'      => 'NV 2412C',
            'is_active' => true,
        ]);
        EmployeeSalarySetting::create([
            'employee_id' => $emp->id,
            'base_salary' => $baseSalary,
            'salary_type' => 'by_workday',
        ]);
        return $emp;
    }

    private function makePaysheet(string $status = 'calculated', ?float $standard = 26): Paysheet
    {
        $start = Carbon::create(2026, 4, 1);
        $end   = Carbon::create(2026, 4, 30);
        return Paysheet::create([
            'code'                  => Paysheet::nextCode(),
            'name'                  => 'BL 2412C',
            'pay_period'            => 'monthly',
            'period_start'          => $start->toDateString(),
            'period_end'            => $end->toDateString(),
            'standard_working_days' => $standard,
            'scope'                 => 'all',
            'status'                => $status,
        ]);
    }

    /**
     * Build a payslip whose `details` JSON carries the auto values so the
     * recalc path (which reads from `details`) has something to compare
     * against the manual adjustments.
     */
    private function makePayslip(
        Paysheet $sheet,
        Employee $emp,
        array $auto = []
    ): Payslip {
        $auto = array_merge([
            'base'         => 10000000,
            'commission'   => 1000000,
            'bonus'        => 1000000,
            'allowances'   => 1000000,
            'deductions'   => 100000,
            'late_penalty' => 0,
            'ot_pay'       => 200000,
            'holiday_pay'  => 0,
            'work_units'   => 26,
            'standard_work_units' => 26,
        ], $auto);

        $total = $auto['base'] + $auto['commission'] + $auto['bonus'] + $auto['allowances']
            + $auto['ot_pay'] + $auto['holiday_pay']
            - $auto['deductions'] - $auto['late_penalty'];

        return Payslip::create([
            'code'         => 'PL-2412C-' . uniqid(),
            'paysheet_id'  => $sheet->id,
            'employee_id'  => $emp->id,
            'base_salary'  => $auto['base'],
            'commission'   => $auto['commission'],
            'bonus'        => $auto['bonus'],
            'allowances'   => $auto['allowances'],
            'deductions'   => $auto['deductions'] + $auto['late_penalty'],
            'ot_pay'       => $auto['ot_pay'] + $auto['holiday_pay'],
            'total_salary' => max(0, $total),
            'paid_amount'  => 0,
            'remaining'    => max(0, $total),
            'work_units'   => $auto['work_units'],
            'details'      => $auto,
        ]);
    }

    private function bulkSave(User $admin, Paysheet $sheet, Payslip $slip, string $type, array $items)
    {
        return $this->actingAs($admin)->putJson(
            "/api/paysheets/{$sheet->id}/payslips/{$slip->id}/adjustments/{$type}/bulk",
            ['items' => $items]
        );
    }

    private function resetDefault(User $admin, Paysheet $sheet, Payslip $slip, string $type)
    {
        return $this->actingAs($admin)->postJson(
            "/api/paysheets/{$sheet->id}/payslips/{$slip->id}/adjustments/{$type}/reset-default"
        );
    }

    // ── TC-01 ─────────────────────────────────────────────────────────
    public function test_commission_can_be_overridden_to_custom_rows(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp); // auto commission = 1,000,000

        $res = $this->bulkSave($admin, $sheet, $slip, 'commission', [
            ['name' => 'Hoa hồng máy in', 'amount' => 500000],
        ]);
        $res->assertOk();

        $fresh = $slip->fresh();
        $this->assertSame(500000, (int) $fresh->commission);
        $this->assertTrue((bool) ($fresh->details['manual_overrides']['commission'] ?? false));
        // base 10m + commission 500k + bonus 1m + allowance 1m + ot 200k − ded 100k = 12.6m
        $this->assertSame(12600000, (int) $fresh->total_salary);
    }

    // ── TC-02 ─────────────────────────────────────────────────────────
    public function test_commission_can_be_deleted_to_zero(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp);

        $this->bulkSave($admin, $sheet, $slip, 'commission', [])->assertOk();

        $fresh = $slip->fresh();
        $this->assertSame(0, (int) $fresh->commission);
        $this->assertTrue((bool) ($fresh->details['manual_overrides']['commission'] ?? false));
        // base 10m + 0 commission + bonus 1m + allowance 1m + ot 200k − ded 100k = 12.1m
        $this->assertSame(12100000, (int) $fresh->total_salary);
    }

    // ── TC-03 ─────────────────────────────────────────────────────────
    public function test_allowance_can_be_deleted_to_zero(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp);

        $this->bulkSave($admin, $sheet, $slip, 'allowance', [])->assertOk();
        $this->assertSame(0, (int) $slip->fresh()->allowances);
    }

    // ── TC-04 ─────────────────────────────────────────────────────────
    public function test_bonus_can_be_deleted_to_zero(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp);

        $this->bulkSave($admin, $sheet, $slip, 'bonus', [])->assertOk();
        $this->assertSame(0, (int) $slip->fresh()->bonus);
    }

    // ── TC-05 ─────────────────────────────────────────────────────────
    public function test_deduction_can_be_deleted_to_zero_even_if_setting_has_late_penalty(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp, [
            'deductions'   => 0,
            'late_penalty' => 90000,
        ]);

        // Sanity: before override, payslip carries late_penalty as part of deductions.
        $baselineTotal = (int) $slip->total_salary;

        $this->bulkSave($admin, $sheet, $slip, 'deduction', [])->assertOk();

        $fresh = $slip->fresh();
        $this->assertSame(0, (int) $fresh->deductions, 'override must wipe late_penalty too');
        $this->assertTrue((bool) ($fresh->details['manual_overrides']['deduction'] ?? false));
        // total_salary increases by exactly the late_penalty (90,000) removed.
        $this->assertSame($baselineTotal + 90000, (int) $fresh->total_salary);
    }

    // ── TC-06 ─────────────────────────────────────────────────────────
    public function test_reset_default_restores_deduction_from_settings(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp, [
            'deductions'   => 50000,
            'late_penalty' => 40000,
        ]);

        $this->bulkSave($admin, $sheet, $slip, 'deduction', [])->assertOk();
        $this->assertSame(0, (int) $slip->fresh()->deductions);

        $this->resetDefault($admin, $sheet, $slip, 'deduction')->assertOk();
        $fresh = $slip->fresh();
        // No deduction adjustment rows + no override flag → auto path → details['deductions'] (50k).
        // (late_penalty is added only when manual rows exist; on a clean reset it stays at auto.)
        $this->assertSame(50000, (int) $fresh->deductions);
        $this->assertArrayNotHasKey('deduction', $fresh->details['manual_overrides'] ?? []);
    }

    // ── TC-07 ─────────────────────────────────────────────────────────
    public function test_reset_default_restores_commission_from_settings(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp); // auto commission = 1m

        $this->bulkSave($admin, $sheet, $slip, 'commission', [])->assertOk();
        $this->assertSame(0, (int) $slip->fresh()->commission);

        $this->resetDefault($admin, $sheet, $slip, 'commission')->assertOk();
        $fresh = $slip->fresh();
        $this->assertSame(1000000, (int) $fresh->commission);
        $this->assertArrayNotHasKey('commission', $fresh->details['manual_overrides'] ?? []);
    }

    // ── TC-08 ─────────────────────────────────────────────────────────
    public function test_adjustments_survive_standard_working_days_recalculation(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp);

        $this->bulkSave($admin, $sheet, $slip, 'allowance', [])->assertOk();
        $this->bulkSave($admin, $sheet, $slip, 'commission', [
            ['name' => 'Hoa hồng KPI', 'amount' => 500000],
        ])->assertOk();

        // Trigger performRecalculation() via the standard-working-days endpoint.
        $this->actingAs($admin)->putJson(
            "/api/paysheets/{$sheet->id}/standard-working-days",
            ['standard_working_days' => 25]
        )->assertOk();

        $fresh = $slip->fresh();
        $this->assertSame(0, (int) $fresh->allowances, 'allowance override must survive recalc');
        $this->assertSame(500000, (int) $fresh->commission, 'commission override must survive recalc');
        $this->assertTrue((bool) ($fresh->details['manual_overrides']['allowance'] ?? false));
        $this->assertTrue((bool) ($fresh->details['manual_overrides']['commission'] ?? false));
    }

    // ── TC-09 ─────────────────────────────────────────────────────────
    public function test_locked_paysheet_rejects_bulk_adjustments(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet('locked');
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp);

        $this->bulkSave($admin, $sheet, $slip, 'commission', [
            ['name' => 'X', 'amount' => 100000],
        ])->assertStatus(422);

        $this->resetDefault($admin, $sheet, $slip, 'commission')->assertStatus(422);
    }

    // ── TC-10 ─────────────────────────────────────────────────────────
    public function test_ot_remains_additive_not_replace(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp); // auto ot = 200,000

        $this->bulkSave($admin, $sheet, $slip, 'ot', [
            ['name' => 'OT chủ nhật', 'amount' => 100000],
        ])->assertOk();
        $this->assertSame(300000, (int) $slip->fresh()->ot_pay);

        // OT empty: auto OT remains (additive policy means no override).
        $this->bulkSave($admin, $sheet, $slip, 'ot', [])->assertOk();
        $fresh = $slip->fresh();
        $this->assertSame(200000, (int) $fresh->ot_pay);
        // OT must not set a manual_overrides flag.
        $this->assertArrayNotHasKey('ot', $fresh->details['manual_overrides'] ?? []);
    }

    // ── TC-11 ─────────────────────────────────────────────────────────
    public function test_backend_does_not_trust_frontend_total(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp);

        // Send a maliciously-crafted payload with an inflated total field —
        // bulkSaveAdjustments doesn't accept a total_salary field, so this
        // just verifies the validator silently drops it and the recalc still
        // computes the true total from base+commission+bonus+allowance+ot−ded.
        $res = $this->actingAs($admin)->putJson(
            "/api/paysheets/{$sheet->id}/payslips/{$slip->id}/adjustments/commission/bulk",
            [
                'items'        => [['name' => 'Hoa hồng', 'amount' => 100000]],
                'total_salary' => 999999999, // ignored
            ]
        );
        $res->assertOk();

        $fresh = $slip->fresh();
        // commission = 100k → total = 10m base + 100k commission + 1m bonus + 1m allowance + 200k ot − 100k ded = 12.2m
        $this->assertSame(12200000, (int) $fresh->total_salary);
    }

    // ── TC-12 ─────────────────────────────────────────────────────────
    public function test_paysheet_totals_update_after_adjustment_save(): void
    {
        $admin = $this->admin();
        $sheet = $this->makePaysheet();
        $emp   = $this->makeEmployee();
        $slip  = $this->makePayslip($sheet, $emp);

        // Seed paysheet.total_salary with the current sum of payslip totals
        // so the assertion compares against a meaningful baseline.
        $sheet->recalculateTotals();
        $beforeTotal = (int) $sheet->fresh()->total_salary;
        $this->assertGreaterThan(0, $beforeTotal);

        $this->bulkSave($admin, $sheet, $slip, 'commission', [])->assertOk();

        $afterTotal = (int) $sheet->fresh()->total_salary;
        // Removing commission (auto 1m) lowers the paysheet total by 1m.
        $this->assertSame($beforeTotal - 1000000, $afterTotal);
    }
}
