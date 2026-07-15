<?php

namespace Tests\Feature\Report;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DebtReconciliationReportTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Customer $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = uniqid();
        $this->admin = User::create([
            'name' => 'Debt Report Admin',
            'email' => "debt-report-{$suffix}@test.local",
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);
        $this->partner = Customer::create([
            'code' => 'DT-REPORT-'.$suffix,
            'name' => 'Debt report partner '.$suffix,
            'phone' => '09'.random_int(10000000, 99999999),
            'is_customer' => true,
            'is_supplier' => true,
            'debt_amount' => 1_200_000,
            'supplier_debt_amount' => 400_000,
            'total_spent' => 0,
        ]);
    }

    public function test_report_preserves_balance_response_contract(): void
    {
        $response = $this->actingAs($this->admin)->get('/reports/debt-reconciliation?search='.$this->partner->code);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/DebtReconciliation')
            ->where('rows.0.code', $this->partner->code)
            ->where('rows.0.receivable', 1_200_000)
            ->where('rows.0.payable', 400_000)
            ->where('rows.0.net', 800_000)
            ->where('rows.0.customer_receivable_balance', 1_200_000)
            ->where('rows.0.supplier_payable_balance', 400_000)
            ->where('rows.0.customer_screen_debt', 800_000)
            ->where('rows.0.supplier_screen_debt', -800_000)
            ->where('summary.total_receivable', 1_200_000)
            ->where('summary.total_payable', 400_000)
            ->where('summary.total_net', 800_000));
    }

    public function test_export_uses_the_same_canonical_balances(): void
    {
        $response = $this->actingAs($this->admin)->get('/reports/debt-reconciliation/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertSee(implode(',', [
            $this->partner->code,
            '"'.$this->partner->name.'"',
            $this->partner->phone,
            '1200000',
            '400000',
            '0',
            '800000',
        ]), false);
    }

    public function test_report_routes_still_require_authentication(): void
    {
        auth()->logout();

        $this->get('/reports/debt-reconciliation')->assertRedirect();
        $this->get('/reports/debt-reconciliation/export')->assertRedirect();
    }

    public function test_report_routes_keep_reports_view_permission(): void
    {
        $routes = app('router')->getRoutes();

        $this->assertContains(
            'permission:reports.view',
            $routes->getByName('reports.debt-reconciliation')->gatherMiddleware(),
        );
        $this->assertContains(
            'permission:reports.view',
            $routes->getByName('reports.debt-reconciliation.export')->gatherMiddleware(),
        );
    }
}
