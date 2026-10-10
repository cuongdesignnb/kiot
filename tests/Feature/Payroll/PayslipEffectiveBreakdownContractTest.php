<?php

namespace Tests\Feature\Payroll;

use App\Http\Controllers\PaysheetController;
use App\Models\Employee;
use App\Models\EmployeeSalarySetting;
use App\Models\Paysheet;
use App\Models\Payslip;
use App\Models\User;
use App\Services\PayrollPayslipCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayslipEffectiveBreakdownContractTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    private function payroll(): array
    {
        $employee = Employee::create(['code' => uniqid('QA-BREAKDOWN-'), 'name' => 'Synthetic breakdown employee', 'is_active' => true]);
        EmployeeSalarySetting::create([
            'employee_id' => $employee->id, 'salary_type' => 'fixed', 'base_salary' => 1000000,
            'has_allowance' => true,
            'custom_allowances' => [
                ['name' => 'Synthetic office allowance', 'allowance_type' => 'fixed_per_month', 'amount' => 120000],
                ['name' => 'Synthetic travel allowance', 'allowance_type' => 'fixed_per_month', 'amount' => 30000],
            ],
            'has_bonus' => true, 'bonus_calculation' => 'total_revenue',
            'custom_bonuses' => [['role_type' => 'employee', 'revenue_from' => 0, 'bonus_value' => 80000, 'bonus_is_percentage' => false]],
        ]);
        $sheet = Paysheet::create([
            'code' => Paysheet::nextCode(), 'name' => 'Synthetic breakdown payroll', 'status' => 'calculated',
            'period_start' => '2030-06-01', 'period_end' => '2030-06-30', 'standard_working_days' => 26,
        ]);
        $slip = Payslip::create(['code' => Payslip::nextCode(), 'paysheet_id' => $sheet->id, 'employee_id' => $employee->id]);
        $slip->update(app(PayrollPayslipCalculator::class)->preview($sheet, $slip));
        $sheet->recalculateTotals();

        return [$sheet->fresh(), $slip->fresh(), $employee];
    }

    private function bulk(Paysheet $sheet, Payslip $slip, string $type, array $rows)
    {
        return $this->putJson("/api/paysheets/{$sheet->id}/payslips/{$slip->id}/adjustments/{$type}/bulk", ['items' => $rows]);
    }

    public function test_removed_item_is_absent_from_effective_api_rows_and_does_not_return_on_recalculation(): void
    {
        [$sheet, $slip] = $this->payroll();
        $this->bulk($sheet, $slip, 'allowance', [['name' => 'Synthetic travel allowance', 'amount' => 30000]])
            ->assertOk()->assertJsonPath('slip.allowances', 30000)
            ->assertJsonPath('slip.details.manual_overrides.allowance', true)
            ->assertJsonCount(1, 'slip.adjustments');
        $this->postJson("/api/paysheets/{$sheet->id}/recalculate")->assertOk();
        $shown = $this->getJson("/api/paysheets/{$sheet->id}")->assertOk()->json('data.payslips.0');
        $this->assertSame(30000, $shown['allowances']);
        $this->assertSame('Synthetic travel allowance', $shown['adjustments'][0]['name']);
        $this->assertCount(1, $shown['adjustments']);
        // The raw automatic snapshot is evidence, not the payable breakdown.
        $this->assertCount(2, $shown['details']['details']['allowances']);
        $this->assertSame(1110000, $shown['total_salary']);
        $this->assertSame($shown['total_salary'], $sheet->fresh()->total_salary);
    }

    public function test_empty_allowance_and_bonus_override_survives_view_and_recalculation(): void
    {
        [$sheet, $slip] = $this->payroll();
        foreach (['allowance', 'bonus'] as $type) {
            $this->bulk($sheet, $slip, $type, [])->assertOk();
        }
        $this->postJson("/api/paysheets/{$sheet->id}/recalculate")->assertOk();
        $this->getJson("/api/paysheets/{$sheet->id}")->assertOk()
            ->assertJsonPath('data.payslips.0.allowances', 0)
            ->assertJsonPath('data.payslips.0.bonus', 0)
            ->assertJsonPath('data.payslips.0.total_salary', 1000000)
            ->assertJsonPath('data.payslips.0.details.manual_overrides.allowance', true)
            ->assertJsonPath('data.payslips.0.details.manual_overrides.bonus', true)
            ->assertJsonCount(0, 'data.payslips.0.adjustments');
    }

    public function test_direct_total_override_preserves_payment_and_advance_and_survives_recalculation(): void
    {
        [$sheet, $slip] = $this->payroll();
        $slip->update(['paid_amount' => 10000, 'applied_advance' => 20000]);
        $this->putJson("/api/paysheets/{$sheet->id}/payslips/{$slip->id}", ['allowances' => 45000])->assertOk()
            ->assertJsonPath('data.details.direct_overrides.allowances', 45000)
            ->assertJsonPath('data.remaining', 1095000);
        $this->postJson("/api/paysheets/{$sheet->id}/recalculate")->assertOk();
        $fresh = $slip->fresh();
        $this->assertSame(45000, $fresh->allowances);
        $this->assertSame(10000, $fresh->paid_amount);
        $this->assertSame(20000, $fresh->applied_advance);
        $this->assertSame(1095000, $fresh->remaining);
    }

    public function test_reset_is_the_only_explicit_path_that_restores_automatic_allowances(): void
    {
        [$sheet, $slip] = $this->payroll();
        $this->bulk($sheet, $slip, 'allowance', [])->assertOk();
        $this->getJson("/api/paysheets/{$sheet->id}")->assertJsonPath('data.payslips.0.allowances', 0);
        $this->postJson("/api/paysheets/{$sheet->id}/payslips/{$slip->id}/adjustments/allowance/reset-default")->assertOk()
            ->assertJsonPath('slip.allowances', 150000);
        $this->assertArrayNotHasKey('allowance', $slip->fresh()->details['manual_overrides'] ?? []);
    }

    public function test_viewing_locked_payroll_does_not_mutate_its_snapshot_or_financial_history(): void
    {
        [$sheet, $slip, $employee] = $this->payroll();
        $this->bulk($sheet, $slip, 'allowance', [['name' => 'Synthetic travel allowance', 'amount' => 30000]])->assertOk();
        $sheet->update(['status' => 'locked']);
        $beforeSlip = $slip->fresh()->toArray();
        $beforeSheet = $sheet->fresh()->toArray();
        $counts = [DB::table('cash_flows')->count(), DB::table('paysheet_payments')->count(), DB::table('employee_salary_ledger_entries')->count()];
        $employee->salarySetting->update(['has_allowance' => false]);
        $this->getJson("/api/paysheets/{$sheet->id}")->assertOk()->assertJsonPath('data.payslips.0.allowances', 30000);
        app(PaysheetController::class)->edit($sheet->id);
        $this->assertSame($beforeSlip, $slip->fresh()->toArray());
        $this->assertSame($beforeSheet, $sheet->fresh()->toArray());
        $this->assertSame($counts, [DB::table('cash_flows')->count(), DB::table('paysheet_payments')->count(), DB::table('employee_salary_ledger_entries')->count()]);
        $this->bulk($sheet, $slip, 'allowance', [])->assertStatus(422);
        $this->postJson("/api/paysheets/{$sheet->id}/recalculate")->assertStatus(422);
    }

    public function test_csv_and_print_use_saved_totals_after_removing_an_allowance(): void
    {
        [$sheet, $slip] = $this->payroll();
        $this->bulk($sheet, $slip, 'allowance', [['name' => 'Synthetic travel allowance', 'amount' => 30000]])->assertOk();
        $controller = app(PaysheetController::class);
        $csv = $controller->export(Request::create('/', 'GET', ['search' => $sheet->code]));
        ob_start();
        $csv->sendContent();
        $content = ob_get_clean();
        $this->assertStringContainsString('1110000', $content);
        $printed = $controller->print($sheet->fresh())->getData()['paysheet'];
        $this->assertSame(30000, $printed->payslips->first()->allowances);
        $this->assertSame(1110000, $printed->payslips->first()->total_salary);
    }
}
