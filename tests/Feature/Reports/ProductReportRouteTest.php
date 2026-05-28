<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductReportRouteTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin Test',
            'email'    => 'admin-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    public function test_product_report_route_status_and_component(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin)->get('/reports/products');
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page->component('Reports/ProductReport'));
    }

    public function test_product_report_route_with_query_params(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin)->get('/reports/products?concern=sales&period=custom&date_from=2026-04-01&date_to=2026-04-30&view=report');
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page->component('Reports/ProductReport'));
    }
}
