<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PartnerReportRouteContractTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Report Route Test',
            'email' => 'admin-report-route-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);
    }

    public function test_customer_report_filters_use_a_registered_route(): void
    {
        $admin = $this->admin();

        foreach (['/reports/customers', '/reports/customers-report'] as $path) {
            $response = $this->actingAs($admin)->get(
                $path.'?concern=sales&period=this_month&view=report'
            );

            $response->assertOk();
            $response->assertInertia(fn ($page) => $page->component('Reports/CustomerReport'));
        }
    }

    public function test_supplier_report_filters_use_a_registered_route(): void
    {
        $admin = $this->admin();

        foreach (['/reports/suppliers', '/reports/suppliers-report'] as $path) {
            $response = $this->actingAs($admin)->get(
                $path.'?concern=purchase&period=this_month&view=report'
            );

            $response->assertOk();
            $response->assertInertia(fn ($page) => $page->component('Reports/SupplierReport'));
        }
    }

    public function test_report_pages_navigate_to_the_canonical_routes(): void
    {
        $customerPage = file_get_contents(resource_path('js/Pages/Reports/CustomerReport.vue'));
        $supplierPage = file_get_contents(resource_path('js/Pages/Reports/SupplierReport.vue'));

        $this->assertStringContainsString('router.get("/reports/customers", params', $customerPage);
        $this->assertStringNotContainsString('router.get("/reports/customers-report", params', $customerPage);
        $this->assertStringContainsString('router.get("/reports/suppliers", params', $supplierPage);
        $this->assertStringNotContainsString('router.get("/reports/suppliers-report", params', $supplierPage);
    }
}
