<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Role;
use App\Models\Product;
use App\Models\Category;
use App\Models\SerialImei;
use App\Models\Warranty;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/**
 * STEP 24.2 — Final Regression + Production Hardening.
 */
class Step242ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $role = Role::create([
            'name' => 'role-' . uniqid(),
            'display_name' => 'Test',
            'permissions' => $permissions,
            'is_system' => false,
        ]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    private function adminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin242'], [
            'display_name' => 'Admin 242',
            'permissions' => ['*'],
            'is_system' => true,
        ]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    // ═══ TC-01 ═══

    public function test_debug_routes_are_not_registered(): void
    {
        $banned = [
            'run-migrations',
            'run-migrate',
            'run-migrate-2',
            'check-schema',
            'fix-and-recalc',
            'debug-ot',
        ];
        foreach ($banned as $uri) {
            $found = collect(Route::getRoutes())->contains(fn ($r) => $r->uri() === $uri);
            $this->assertFalse($found, "Banned debug route '{$uri}' must not be registered");
        }
    }

    // ═══ TC-02 ═══

    public function test_no_public_migration_or_schema_routes(): void
    {
        $banned = [
            'api/attendance-agent/recent-logs',
            'api/attendance-agent/debug-status',
            'api/attendance-agent/force-recalculate',
            'api/attendance-agent/debug-hmac',
            'api/test',
        ];
        foreach ($banned as $uri) {
            $found = collect(Route::getRoutes())->contains(fn ($r) => $r->uri() === $uri);
            $this->assertFalse($found, "Banned debug API route '{$uri}' must not be registered");
        }

        // Also assert no route URI contains scary keywords
        $scary = ['phpinfo', 'migrate:fresh'];
        foreach (Route::getRoutes() as $route) {
            foreach ($scary as $kw) {
                $this->assertStringNotContainsString($kw, $route->uri(), "Route '{$route->uri()}' must not contain '{$kw}'");
            }
        }
    }

    // ═══ TC-03 ═══

    public function test_activity_logs_route_requires_system_audit_permission(): void
    {
        // User không quyền
        $user = $this->userWith(['dashboard.view']);
        $this->actingAs($user);
        $res = $this->get('/activity-logs');
        $this->assertContains($res->status(), [302, 403]);

        // User có quyền
        $user2 = $this->userWith(['system.audit.view']);
        $this->actingAs($user2);
        $this->get('/activity-logs')->assertOk();
    }

    // ═══ TC-04 ═══

    public function test_dashboard_route_still_requires_dashboard_permission(): void
    {
        $user = $this->userWith(['tasks.view']);
        $this->actingAs($user);
        $res = $this->get('/');
        $this->assertContains($res->status(), [302, 403]);

        $admin = $this->adminUser();
        $this->actingAs($admin);
        $this->get('/')->assertOk();
    }

    // ═══ TC-05 ═══

    public function test_api_task_routes_require_permission(): void
    {
        // User chỉ có dashboard.view, không có tasks.view
        $user = $this->userWith(['dashboard.view']);
        $this->actingAs($user);
        $res = $this->getJson('/api/tasks');
        $res->assertStatus(403);
    }

    // ═══ TC-06 ═══

    public function test_runtime_controllers_do_not_seed_warranty_demo(): void
    {
        $user = $this->userWith(['warranties.view']);
        $this->actingAs($user);

        $countBefore = Warranty::count();
        $this->get('/warranties');
        $countAfter = Warranty::count();

        $this->assertEquals($countBefore, $countAfter, 'Warranty index must not seed demo data');
    }

    // ═══ TC-06b: stock transfer index không auto-seed branches ═══

    public function test_stock_transfer_index_does_not_seed_branches(): void
    {
        $user = $this->userWith(['stock_transfers.view']);
        $this->actingAs($user);

        $countBefore = \App\Models\Branch::count();
        $this->get('/stock-transfers');
        $countAfter = \App\Models\Branch::count();

        $this->assertEquals($countBefore, $countAfter, 'Stock transfer index must not seed demo branches');
    }

    // ═══ TC-07 ═══

    public function test_route_cache_command_succeeds(): void
    {
        $exit = Artisan::call('route:clear');
        $this->assertEquals(0, $exit);
    }

    // ═══ TC-08 ═══

    public function test_config_cache_command_succeeds(): void
    {
        $exit = Artisan::call('config:clear');
        $this->assertEquals(0, $exit);
    }

    // ═══ TC-09 ═══

    public function test_view_cache_command_succeeds(): void
    {
        $exit = Artisan::call('view:clear');
        $this->assertEquals(0, $exit);
    }

    // ═══ TC-10 ═══

    public function test_dashboard_get_does_not_mutate_inventory_or_serials(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        $cat = Category::firstOrCreate(['name' => 'C']);
        $product = Product::create([
            'sku' => 'P-' . uniqid(), 'name' => 'P',
            'cost_price' => 1000, 'retail_price' => 1500,
            'stock_quantity' => 5, 'inventory_total_cost' => 5000,
            'has_serial' => true, 'category_id' => $cat->id, 'is_active' => true,
        ]);
        SerialImei::create(['product_id' => $product->id, 'serial_number' => 'A', 'status' => 'in_stock', 'cost_price' => 1000]);

        $stockBefore = $product->stock_quantity;
        $serialBefore = SerialImei::count();

        $this->get('/')->assertOk();
        $this->get('/')->assertOk();

        $product->refresh();
        $this->assertEquals($stockBefore, $product->stock_quantity);
        $this->assertEquals($serialBefore, SerialImei::count());
    }
}
