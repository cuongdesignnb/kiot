<?php

namespace Tests\Feature\Payroll;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Paysheet;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaysheetCancelledVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role_id' => null]));
        $this->branch = Branch::create(['name' => 'P0 Cancelled Visibility Branch']);
        $this->employee = Employee::create([
            'code' => 'NV-P0-CANCEL-VIS',
            'name' => 'P0 Cancelled Visibility Employee',
            'branch_id' => $this->branch->id,
            'salary_balance_cache' => 0,
            'is_active' => true,
        ]);
    }

    public function test_cancelled_paysheet_is_hidden_from_default_active_list_but_visible_when_filter_cancelled(): void
    {
        $cancelled = $this->lockedPaysheet('BL-P0-CANCELLED-001', 1_000_000);
        $this->cancelPaysheet($cancelled);

        $this->getJson('/api/paysheets')
            ->assertOk()
            ->assertJsonMissing(['code' => $cancelled->code]);

        $this->getJson('/api/paysheets?status=cancelled')
            ->assertOk()
            ->assertJsonFragment(['code' => $cancelled->code])
            ->assertJsonPath('data.0.status', 'cancelled');
    }

    public function test_cancelled_paysheet_is_not_included_in_default_totals(): void
    {
        $this->lockedPaysheet('BL-P0-ACTIVE-001', 1_000_000);
        $cancelled = $this->lockedPaysheet('BL-P0-CANCELLED-002', 2_000_000);
        $this->cancelPaysheet($cancelled);

        $this->getJson('/api/paysheets')
            ->assertOk()
            ->assertJsonPath('summary.total_salary', 1_000_000)
            ->assertJsonPath('summary.total_paid', 0)
            ->assertJsonPath('summary.total_remaining', 1_000_000);
    }

    public function test_paysheet_summary_uses_same_filters_as_list(): void
    {
        $otherBranch = Branch::create(['name' => 'Other payroll branch']);
        $this->lockedPaysheet('BL-P0-FILTER-MATCH', 1_000_000, $this->branch);
        $this->lockedPaysheet('BL-P0-FILTER-OTHER-BRANCH', 2_000_000, $otherBranch);
        $this->draftPaysheet('BL-P0-FILTER-DRAFT', 3_000_000, $this->branch);

        $query = http_build_query([
            'branch_id' => $this->branch->id,
            'status' => 'locked',
            'search' => 'MATCH',
        ]);

        $this->getJson("/api/paysheets?{$query}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'BL-P0-FILTER-MATCH')
            ->assertJsonPath('summary.total_salary', 1_000_000);
    }

    public function test_cancelled_paysheet_has_no_payment_or_cancel_actions(): void
    {
        $cancelled = $this->lockedPaysheet('BL-P0-CANCELLED-ACTION', 1_000_000);
        $this->cancelPaysheet($cancelled);

        $this->getJson('/api/paysheets?status=cancelled')
            ->assertOk()
            ->assertJsonPath('data.0.can_pay', false)
            ->assertJsonPath('data.0.can_cancel', false)
            ->assertJsonPath('data.0.status_label', 'Da huy');
    }

    public function test_cancelled_paysheet_detail_is_still_viewable_for_audit(): void
    {
        $cancelled = $this->lockedPaysheet('BL-P0-CANCELLED-DETAIL', 1_000_000);
        $this->cancelPaysheet($cancelled);

        $this->getJson("/api/paysheets/{$cancelled->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.can_pay', false)
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonCount(1, 'data.payslips');
    }

    private function lockedPaysheet(string $code, int $amount, ?Branch $branch = null): Paysheet
    {
        $paysheet = $this->draftPaysheet($code, $amount, $branch);
        $this->putJson("/api/paysheets/{$paysheet->id}/lock")->assertOk();

        return $paysheet->fresh();
    }

    private function draftPaysheet(string $code, int $amount, ?Branch $branch = null): Paysheet
    {
        $branch ??= $this->branch;
        $paysheet = Paysheet::create([
            'code' => $code,
            'name' => $code,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'branch_id' => $branch->id,
            'status' => 'calculated',
            'payment_status' => 'unpaid',
            'total_salary' => $amount,
            'total_paid' => 0,
            'total_remaining' => $amount,
            'employee_count' => 1,
        ]);
        Payslip::create([
            'code' => 'PL-'.$code,
            'paysheet_id' => $paysheet->id,
            'employee_id' => $this->employee->id,
            'total_salary' => $amount,
            'paid_amount' => 0,
            'remaining' => $amount,
            'payment_status' => 'unpaid',
        ]);

        return $paysheet;
    }

    private function cancelPaysheet(Paysheet $paysheet): void
    {
        $this->postJson("/api/paysheets/{$paysheet->id}/cancel", [
            'reason' => 'Huy bang luong tao nham',
        ])->assertOk();
    }
}
