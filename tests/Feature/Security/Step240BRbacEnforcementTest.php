<?php

namespace Tests\Feature\Security;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Role;
use App\Models\Product;
use App\Models\Category;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\SerialImei;
use App\Models\Task;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\StockTake;
use App\Models\OrderReturn;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Warranty;
use App\Models\ActivityLog;
use App\Services\TaskService;
use Carbon\Carbon;

/**
 * STEP 24.0B — RBAC Permission Enforcement.
 */
class Step240BRbacEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected Role $adminRole;
    protected Role $staffRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminRole = Role::create([
            'name' => 'admin240b',
            'display_name' => 'Admin 240B',
            'permissions' => ['*'],
            'is_system' => true,
        ]);
        $this->staffRole = Role::create([
            'name' => 'staff240b',
            'display_name' => 'Staff 240B',
            'permissions' => [],
            'is_system' => false,
        ]);
    }

    private function userAs(?Role $role): User
    {
        return User::factory()->create(['role_id' => $role?->id]);
    }

    private function staffWith(array $permissions): User
    {
        $role = Role::create([
            'name' => 'role-' . uniqid(),
            'display_name' => 'Test',
            'permissions' => $permissions,
            'is_system' => false,
        ]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    // ═══ TC-01 ═══

    public function test_grant_sensitive_permissions_command_dry_run_does_not_modify_roles(): void
    {
        $before = $this->adminRole->fresh()->permissions;
        $this->artisan('permissions:grant-sensitive')->assertExitCode(0);
        $after = $this->adminRole->fresh()->permissions;
        $this->assertEquals($before, $after, 'Dry-run không được thay đổi DB');
    }

    // ═══ TC-02 ═══

    public function test_grant_sensitive_permissions_command_commit_is_idempotent(): void
    {
        // Tạo role không có '*' để có gì để cấp — dùng --role-name explicit
        $role = Role::create([
            'name' => 'manager240b',
            'display_name' => 'Manager 240B',
            'permissions' => ['tasks.view'],
        ]);

        $this->artisan('permissions:grant-sensitive', ['--role-name' => 'manager240b', '--commit' => true])->assertExitCode(0);
        $first = $role->fresh()->permissions;
        $this->assertContains('tasks.disassemble', $first);
        $this->assertContains('tasks.attach_warranty', $first);

        // Run again — không duplicate
        $this->artisan('permissions:grant-sensitive', ['--role-name' => 'manager240b', '--commit' => true])->assertExitCode(0);
        $second = $role->fresh()->permissions;
        $this->assertEquals(count($first), count($second), 'Idempotent — không duplicate');
        $this->assertEquals(count($first), count(array_unique($second)));
    }

    // ═══ TC-03 ═══

    public function test_admin_role_can_access_sensitive_routes_after_grant(): void
    {
        $user = $this->userAs($this->adminRole);
        $this->actingAs($user);

        // Smoke: lookup-warranty
        $res = $this->getJson('/api/tasks/lookup-warranty?serial_imei=NONE');
        $res->assertStatus(200); // empty list, but accessible
    }

    // ═══ TC-04 ═══

    public function test_user_without_tasks_disassemble_permission_cannot_disassemble(): void
    {
        $cat = Category::firstOrCreate(['name' => 'C']);
        $device = Product::create(['sku' => 'D-' . uniqid(), 'name' => 'D', 'cost_price' => 1000000, 'retail_price' => 1500000, 'stock_quantity' => 1, 'inventory_total_cost' => 1000000, 'has_serial' => true, 'category_id' => $cat->id]);
        $serial = SerialImei::create(['product_id' => $device->id, 'serial_number' => 'SN-' . uniqid(), 'status' => 'in_stock', 'cost_price' => 1000000]);

        // Admin tạo task để có task hợp lệ
        $admin = $this->userAs($this->adminRole);
        $this->actingAs($admin);
        $task = app(TaskService::class)->createTask([
            'type' => Task::TYPE_REPAIR,
            'serial_imei_id' => $serial->id,
            'created_by' => $admin->id,
        ]);

        $part = Product::create(['sku' => 'P-' . uniqid(), 'name' => 'P', 'cost_price' => 50000, 'retail_price' => 100000, 'stock_quantity' => 5, 'inventory_total_cost' => 250000, 'has_serial' => false, 'category_id' => $cat->id]);

        // Staff không có quyền
        $staff = $this->staffWith(['tasks.view']);
        $this->actingAs($staff);

        $res = $this->postJson("/api/tasks/{$task->id}/disassemble-part", [
            'product_id' => $part->id,
            'quantity' => 1,
            'unit_cost' => 50000,
        ]);
        $res->assertStatus(403);
    }

    // ═══ TC-05 ═══

    public function test_user_without_tasks_attach_warranty_permission_cannot_attach_warranty(): void
    {
        $cat = Category::firstOrCreate(['name' => 'C']);
        $device = Product::create(['sku' => 'D-' . uniqid(), 'name' => 'D', 'cost_price' => 1000000, 'retail_price' => 1500000, 'stock_quantity' => 0, 'inventory_total_cost' => 0, 'has_serial' => true, 'category_id' => $cat->id]);

        $admin = $this->userAs($this->adminRole);
        $this->actingAs($admin);
        $customer = Customer::create(['code' => 'KH-' . uniqid(), 'name' => 'KH', 'phone' => '0900', 'is_customer' => true]);
        $task = app(TaskService::class)->createTask([
            'type' => Task::TYPE_REPAIR,
            'external' => true,
            'customer_id' => $customer->id,
            'customer_name' => 'KH',
            'created_by' => $admin->id,
            'issue_description' => 'x',
        ]);
        $w = Warranty::create([
            'invoice_code' => 'HD-' . uniqid(),
            'product_id' => $device->id,
            'serial_imei' => 'SN-W',
            'warranty_period' => 12,
            'purchase_date' => Carbon::now()->subMonths(2),
            'warranty_end_date' => Carbon::now()->addMonths(10),
        ]);

        $staff = $this->staffWith(['tasks.view']);
        $this->actingAs($staff);

        $res = $this->postJson("/api/tasks/{$task->id}/attach-warranty", ['warranty_id' => $w->id]);
        $res->assertStatus(403);
    }

    // ═══ TC-06 ═══

    public function test_user_without_tasks_complete_external_permission_cannot_complete_external_repair(): void
    {
        $admin = $this->userAs($this->adminRole);
        $this->actingAs($admin);
        $customer = Customer::create(['code' => 'KH-' . uniqid(), 'name' => 'KH', 'phone' => '0900', 'is_customer' => true]);
        $task = app(TaskService::class)->createTask([
            'type' => Task::TYPE_REPAIR,
            'external' => true,
            'customer_id' => $customer->id,
            'customer_name' => 'KH',
            'created_by' => $admin->id,
            'issue_description' => 'x',
        ]);

        // Staff có quyền view + complete cơ bản nhưng KHÔNG có complete_external
        // Theo spec: hasAnyPermission(['tasks.complete_external', 'tasks.complete']) → cần test user thiếu cả 2
        $staff = $this->staffWith(['tasks.view']);
        $this->actingAs($staff);

        $res = $this->postJson("/api/tasks/{$task->id}/complete", [
            'labor_fee' => 100000,
            'paid_amount' => 100000,
        ]);
        // Middleware tasks.complete chặn trước khi vào controller
        $res->assertStatus(403);
    }

    // ═══ TC-07 ═══

    public function test_user_without_apply_warranty_policy_cannot_use_free_policy(): void
    {
        $admin = $this->userAs($this->adminRole);
        $this->actingAs($admin);
        $cat = Category::firstOrCreate(['name' => 'C']);
        $device = Product::create(['sku' => 'D-' . uniqid(), 'name' => 'D', 'cost_price' => 1000000, 'retail_price' => 1500000, 'stock_quantity' => 0, 'inventory_total_cost' => 0, 'has_serial' => true, 'category_id' => $cat->id]);

        $customer = Customer::create(['code' => 'KH-' . uniqid(), 'name' => 'KH', 'phone' => '0900', 'is_customer' => true]);
        $task = app(TaskService::class)->createTask([
            'type' => Task::TYPE_REPAIR,
            'external' => true,
            'customer_id' => $customer->id,
            'customer_name' => 'KH',
            'created_by' => $admin->id,
            'issue_description' => 'x',
        ]);

        // Attach valid warranty
        $w = Warranty::create([
            'invoice_code' => 'HD-' . uniqid(),
            'product_id' => $device->id,
            'serial_imei' => 'SN-W',
            'warranty_period' => 12,
            'purchase_date' => Carbon::now()->subMonths(2),
            'warranty_end_date' => Carbon::now()->addMonths(10),
        ]);
        app(TaskService::class)->attachWarranty($task, $w);
        $task->refresh();

        // Staff có complete_external nhưng KHÔNG có apply_warranty_policy
        $staff = $this->staffWith(['tasks.view', 'tasks.complete', 'tasks.complete_external']);
        $this->actingAs($staff);

        $res = $this->postJson("/api/tasks/{$task->id}/complete", [
            'labor_fee' => 100000,
            'paid_amount' => 0,
            'warranty_policy' => 'free_labor',
        ]);
        $res->assertStatus(403);
    }

    // ═══ TC-08 ═══

    public function test_user_without_stock_transfers_receive_permission_cannot_receive_transfer(): void
    {
        $branchA = Branch::create(['name' => 'A', 'phone' => '0']);
        $branchB = Branch::create(['name' => 'B', 'phone' => '0']);
        $transfer = StockTransfer::create([
            'code' => 'CH-' . uniqid(),
            'from_branch_id' => $branchA->id,
            'to_branch_id' => $branchB->id,
            'status' => 'transferring',
            'total_quantity' => 1,
            'total_price' => 0,
        ]);

        $staff = $this->staffWith(['stock_transfers.view', 'stock_transfers.create']);
        $this->actingAs($staff);
        $res = $this->postJson("/stock-transfers/{$transfer->id}/receive", [
            'items' => [],
        ]);
        $res->assertStatus(403);
    }

    // ═══ TC-09 ═══

    public function test_user_without_stock_transfers_cancel_permission_cannot_cancel_transfer(): void
    {
        $branchA = Branch::create(['name' => 'A', 'phone' => '0']);
        $branchB = Branch::create(['name' => 'B', 'phone' => '0']);
        $transfer = StockTransfer::create([
            'code' => 'CH-' . uniqid(),
            'from_branch_id' => $branchA->id,
            'to_branch_id' => $branchB->id,
            'status' => 'transferring',
            'total_quantity' => 1,
            'total_price' => 0,
        ]);

        $staff = $this->staffWith(['stock_transfers.view', 'stock_transfers.create']);
        $this->actingAs($staff);
        $res = $this->postJson("/stock-transfers/{$transfer->id}/cancel");
        $res->assertStatus(403);
    }

    // ═══ TC-10 ═══

    public function test_user_without_stock_takes_balance_permission_cannot_balance_stocktake(): void
    {
        $branch = Branch::create(['name' => 'A', 'phone' => '0']);
        // tạo phiếu kiểm kho minimal — chỉ cần record để route query
        $st = \App\Models\StockTake::create([
            'code' => 'KK-' . uniqid(),
            'branch_id' => $branch->id,
            'status' => 'draft',
            'total_difference' => 0,
            'total_quantity' => 0,
            'date' => now(),
        ]);

        $staff = $this->staffWith(['stock_takes.view', 'stock_takes.create']);
        $this->actingAs($staff);
        $res = $this->postJson("/stock-takes/{$st->id}/balance");
        $res->assertStatus(403);
    }

    // ═══ TC-11 ═══

    public function test_user_without_returns_cancel_permission_cannot_cancel_return(): void
    {
        $cat = Category::firstOrCreate(['name' => 'C']);
        $product = Product::create(['sku' => 'P-' . uniqid(), 'name' => 'P', 'cost_price' => 50000, 'retail_price' => 100000, 'stock_quantity' => 5, 'inventory_total_cost' => 250000, 'has_serial' => false, 'category_id' => $cat->id]);
        $branch = Branch::create(['name' => 'B', 'phone' => '0']);
        $customer = Customer::create(['code' => 'KH-' . uniqid(), 'name' => 'KH', 'phone' => '0900', 'is_customer' => true]);

        $return = OrderReturn::create([
            'code' => 'TH-' . uniqid(),
            'invoice_id' => null,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'status' => 'Đã trả',
            'subtotal' => 0, 'discount' => 0, 'fee' => 0, 'total' => 0, 'paid_to_customer' => 0,
        ]);

        $staff = $this->staffWith(['returns.view', 'returns.create']);
        $this->actingAs($staff);
        $res = $this->postJson("/returns/{$return->id}/cancel");
        $res->assertStatus(403);
    }

    // ═══ TC-12 ═══

    public function test_legacy_admin_user_with_role_id_null_still_can_perform_sensitive_action(): void
    {
        $legacyAdmin = User::factory()->create(['role_id' => null]);
        $this->actingAs($legacyAdmin);

        // Lookup warranty (route có middleware tasks.attach_warranty)
        $res = $this->getJson('/api/tasks/lookup-warranty?serial_imei=NONE');
        $res->assertStatus(200);
    }

    // ═══ TC-13 ═══

    public function test_activity_log_still_written_when_authorized_action_succeeds(): void
    {
        $admin = $this->userAs($this->adminRole);
        $this->actingAs($admin);
        $cat = Category::firstOrCreate(['name' => 'C']);
        $device = Product::create(['sku' => 'D-' . uniqid(), 'name' => 'D', 'cost_price' => 1000000, 'retail_price' => 1500000, 'stock_quantity' => 0, 'inventory_total_cost' => 0, 'has_serial' => true, 'category_id' => $cat->id]);

        $customer = Customer::create(['code' => 'KH-' . uniqid(), 'name' => 'KH', 'phone' => '0900', 'is_customer' => true]);
        $task = app(TaskService::class)->createTask([
            'type' => Task::TYPE_REPAIR,
            'external' => true,
            'customer_id' => $customer->id,
            'customer_name' => 'KH',
            'created_by' => $admin->id,
            'issue_description' => 'x',
        ]);

        $logsBefore = ActivityLog::count();

        $res = $this->postJson("/api/tasks/{$task->id}/complete", [
            'labor_fee' => 100000,
            'paid_amount' => 100000,
        ]);
        $res->assertOk();

        $this->assertGreaterThan($logsBefore, ActivityLog::count());
        $log = ActivityLog::where('action', ActivityLog::ACTION_TASK_COMPLETE)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertEquals($admin->id, $log->user_id);
    }

    public function test_purchase_edit_routes_require_purchase_edit_permission(): void
    {
        $supplier = Customer::create([
            'code' => 'NCC-' . uniqid(),
            'name' => 'NCC RBAC',
            'is_supplier' => true,
            'status' => 'active',
        ]);
        $purchase = Purchase::create([
            'code' => 'PNRBAC' . uniqid(),
            'supplier_id' => $supplier->id,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'debt_amount' => 100000,
            'status' => 'completed',
            'purchase_date' => now(),
        ]);

        $viewOnly = $this->staffWith(['purchases.view']);
        $this->actingAs($viewOnly);
        $this->getJson("/purchases/{$purchase->id}/edit")->assertStatus(403);
        $this->putJson("/purchases/{$purchase->id}", [])->assertStatus(403);

        $createOnly = $this->staffWith(['purchases.create']);
        $this->actingAs($createOnly);
        $this->getJson("/purchases/{$purchase->id}/edit")->assertStatus(403);
        $this->putJson("/purchases/{$purchase->id}", [])->assertStatus(403);

        $editor = $this->staffWith(['purchases.edit']);
        $this->actingAs($editor);
        $this->get("/purchases/{$purchase->id}/edit")->assertOk();
    }
}
