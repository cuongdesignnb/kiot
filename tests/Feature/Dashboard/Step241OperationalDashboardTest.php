<?php

namespace Tests\Feature\Dashboard;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Role;
use App\Models\Product;
use App\Models\Category;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SerialImei;
use App\Models\Task;
use App\Models\StockTransfer;
use App\Models\Warranty;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Services\TaskService;
use App\Support\Reports\OperationalDashboardService;
use Carbon\Carbon;

/**
 * STEP 24.1 — Operational Dashboard.
 */
class Step241OperationalDashboardTest extends TestCase
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
        $role = Role::firstOrCreate(['name' => 'admin241'], [
            'display_name' => 'Admin 241',
            'permissions' => ['*'],
            'is_system' => true,
        ]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    // ═══ TC-01 ═══

    public function test_dashboard_requires_dashboard_view_permission(): void
    {
        $user = $this->userWith(['tasks.view']);
        $this->actingAs($user);
        $res = $this->get('/');
        $this->assertContains($res->status(), [302, 403]);
    }

    // ═══ TC-02 ═══

    public function test_admin_can_view_dashboard(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        $res = $this->get('/');
        $res->assertOk();
    }

    // ═══ TC-03 ═══

    public function test_dashboard_includes_operational_props(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        $res = $this->get('/');
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->has('serialControl')
            ->has('stockTransferControl')
            ->has('repairControl')
            ->has('warrantyControl')
            ->has('inventoryRisk')
            ->has('financeControl')
            ->has('highRiskActivities')
            ->has('canViewAuditLog')
        );
    }

    // ═══ TC-04 ═══

    public function test_serial_control_counts_statuses(): void
    {
        $cat = Category::firstOrCreate(['name' => 'C']);
        $product = Product::create([
            'sku' => 'P-' . uniqid(), 'name' => 'P',
            'cost_price' => 1000, 'retail_price' => 1500,
            'stock_quantity' => 0, 'inventory_total_cost' => 0,
            'has_serial' => true, 'category_id' => $cat->id,
        ]);
        SerialImei::create(['product_id' => $product->id, 'serial_number' => 'A', 'status' => 'in_transit', 'cost_price' => 1000]);
        SerialImei::create(['product_id' => $product->id, 'serial_number' => 'B', 'status' => 'used_for_repair', 'cost_price' => 1000]);
        SerialImei::create(['product_id' => $product->id, 'serial_number' => 'C', 'status' => 'dismantled', 'cost_price' => 1000]);
        SerialImei::create(['product_id' => $product->id, 'serial_number' => 'D', 'status' => 'defective', 'cost_price' => 1000]);

        $svc = app(OperationalDashboardService::class);
        $data = $svc->getSerialControl();
        $this->assertEquals(1, $data['in_transit_count']);
        $this->assertEquals(1, $data['used_for_repair_count']);
        $this->assertEquals(1, $data['dismantled_count']);
        $this->assertEquals(1, $data['defective_count']);
    }

    // ═══ TC-05 ═══

    public function test_inventory_risk_detects_serial_stock_mismatch(): void
    {
        $cat = Category::firstOrCreate(['name' => 'C']);
        $product = Product::create([
            'sku' => 'P-MM-' . uniqid(), 'name' => 'Mismatch',
            'cost_price' => 1000, 'retail_price' => 1500,
            'stock_quantity' => 5, // Khai báo 5
            'inventory_total_cost' => 5000,
            'has_serial' => true, 'category_id' => $cat->id, 'is_active' => true,
        ]);
        // Chỉ có 2 serial in_stock → mismatch +3
        SerialImei::create(['product_id' => $product->id, 'serial_number' => 'X1', 'status' => 'in_stock', 'cost_price' => 1000]);
        SerialImei::create(['product_id' => $product->id, 'serial_number' => 'X2', 'status' => 'in_stock', 'cost_price' => 1000]);

        $svc = app(OperationalDashboardService::class);
        $data = $svc->getInventoryRisk();
        $this->assertGreaterThan(0, $data['serial_stock_mismatch_count']);
        $found = collect($data['serial_mismatch_products'])->firstWhere('id', $product->id);
        $this->assertNotNull($found);
        $this->assertEquals(3, $found['diff']);
    }

    // ═══ TC-06 ═══

    public function test_stock_transfer_control_counts_transferring_and_aging(): void
    {
        $a = Branch::create(['name' => 'A', 'phone' => '0']);
        $b = Branch::create(['name' => 'B', 'phone' => '0']);

        // Old transfer (>72h)
        $old = StockTransfer::create([
            'code' => 'CH-OLD', 'from_branch_id' => $a->id, 'to_branch_id' => $b->id,
            'status' => 'transferring', 'total_quantity' => 1, 'total_price' => 0,
            'sent_date' => Carbon::now()->subHours(80),
        ]);
        $old->created_at = Carbon::now()->subHours(80);
        $old->save();

        // Recent transferring (<24h)
        StockTransfer::create([
            'code' => 'CH-NEW', 'from_branch_id' => $a->id, 'to_branch_id' => $b->id,
            'status' => 'transferring', 'total_quantity' => 1, 'total_price' => 0,
            'sent_date' => Carbon::now()->subHours(2),
        ]);

        $svc = app(OperationalDashboardService::class);
        $data = $svc->getStockTransferControl();
        $this->assertEquals(2, $data['transferring_count']);
        $this->assertEquals(1, $data['transferring_over_24h_count']);
        $this->assertEquals(1, $data['transferring_over_72h_count']);
    }

    // ═══ TC-07 ═══

    public function test_repair_control_counts_open_external_and_repair_debt(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        $customer = Customer::create(['code' => 'KH-' . uniqid(), 'name' => 'KH', 'phone' => '0', 'is_customer' => true]);

        $service = app(TaskService::class);
        $task = $service->createTask([
            'type' => Task::TYPE_REPAIR,
            'external' => true,
            'customer_id' => $customer->id,
            'customer_name' => 'KH',
            'created_by' => $admin->id,
            'issue_description' => 'open',
        ]);
        // Set debt manually for assertion (skipping full complete flow)
        Task::where('id', $task->id)->update(['debt_amount' => 500000]);

        $svc = app(OperationalDashboardService::class);
        $data = $svc->getRepairControl();
        $this->assertGreaterThanOrEqual(1, $data['external_open_count']);
        $this->assertGreaterThanOrEqual(500000, $data['repair_debt_total']);
    }

    // ═══ TC-08 ═══

    public function test_warranty_control_counts_expiring_warranties(): void
    {
        $cat = Category::firstOrCreate(['name' => 'C']);
        $product = Product::create([
            'sku' => 'P-' . uniqid(), 'name' => 'P',
            'cost_price' => 1000, 'retail_price' => 1500,
            'stock_quantity' => 0, 'inventory_total_cost' => 0,
            'has_serial' => false, 'category_id' => $cat->id,
        ]);

        Warranty::create(['invoice_code' => 'HD-1', 'product_id' => $product->id, 'warranty_period' => 12,
            'purchase_date' => Carbon::now()->subYears(2), 'warranty_end_date' => Carbon::now()->subMonth()]); // expired
        Warranty::create(['invoice_code' => 'HD-2', 'product_id' => $product->id, 'warranty_period' => 12,
            'purchase_date' => Carbon::now()->subYear(), 'warranty_end_date' => Carbon::now()->addDays(5)]); // expiring 7d
        Warranty::create(['invoice_code' => 'HD-3', 'product_id' => $product->id, 'warranty_period' => 12,
            'purchase_date' => Carbon::now(), 'warranty_end_date' => Carbon::now()->addYear()]); // valid far

        $svc = app(OperationalDashboardService::class);
        $data = $svc->getWarrantyControl();
        $this->assertGreaterThanOrEqual(1, $data['expired_count']);
        $this->assertGreaterThanOrEqual(1, $data['expiring_7_days_count']);
        $this->assertGreaterThanOrEqual(1, $data['expiring_30_days_count']);
        $this->assertGreaterThanOrEqual(2, $data['valid_count']);
    }

    // ═══ TC-09 ═══

    public function test_high_risk_activities_hidden_without_system_audit_view(): void
    {
        // User có dashboard.view nhưng KHÔNG có system.audit.view
        $user = $this->userWith(['dashboard.view']);
        $this->actingAs($user);
        ActivityLog::create(['action' => ActivityLog::ACTION_INVOICE_CANCEL, 'description' => 'Hủy HD',
            'user_id' => $user->id, 'ip_address' => '1.1.1.1']);

        $res = $this->get('/');
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->where('canViewAuditLog', false)
            ->where('highRiskActivities.visible', false)
            ->where('highRiskActivities.latest_logs', [])
        );
    }

    // ═══ TC-10 ═══

    public function test_high_risk_activities_visible_with_system_audit_view(): void
    {
        $user = $this->userWith(['dashboard.view', 'system.audit.view']);
        $this->actingAs($user);
        ActivityLog::create(['action' => ActivityLog::ACTION_INVOICE_CANCEL, 'description' => 'Hủy HD-001',
            'user_id' => $user->id, 'ip_address' => '1.1.1.1']);

        $res = $this->get('/');
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->where('canViewAuditLog', true)
            ->where('highRiskActivities.visible', true)
            ->where('highRiskActivities.count_today', fn ($v) => $v >= 1)
        );
    }

    // ═══ TC-11 ═══

    public function test_dashboard_does_not_mutate_inventory_or_serials(): void
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
        SerialImei::create(['product_id' => $product->id, 'serial_number' => 'S1', 'status' => 'in_stock', 'cost_price' => 1000]);
        SerialImei::create(['product_id' => $product->id, 'serial_number' => 'S2', 'status' => 'in_stock', 'cost_price' => 1000]);

        $stockBefore = $product->stock_quantity;
        $serialCountBefore = SerialImei::count();
        $invCostBefore = $product->inventory_total_cost;

        $this->get('/')->assertOk();
        $this->get('/')->assertOk();

        $product->refresh();
        $this->assertEquals($stockBefore, $product->stock_quantity);
        $this->assertEquals($invCostBefore, $product->inventory_total_cost);
        $this->assertEquals($serialCountBefore, SerialImei::count());
    }
}
