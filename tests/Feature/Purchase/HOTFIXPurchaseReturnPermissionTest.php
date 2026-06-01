<?php

namespace Tests\Feature\Purchase;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\SerialImei;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * HOTFIX Follow-up: Permission test cho Purchase Return routes.
 *
 * Xác nhận rằng:
 * - User chỉ có quyền `purchases.return.create` có thể mở màn trả theo phiếu + lookup serial.
 * - User KHÔNG có quyền `purchases.return.create` bị chặn 403.
 *
 * Lưu ý: User có `role_id = null` coi như admin (full quyền), nên test phải tạo role cụ thể.
 */
class HOTFIXPurchaseReturnPermissionTest extends TestCase
{
    use DatabaseTransactions;

    private Customer $supplier;
    private Product $product;
    private Purchase $purchase;
    private SerialImei $serial;

    /** User chỉ có quyền purchases.return.create */
    private User $returnUser;

    /** User không có quyền purchases.return.create */
    private User $noPermUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Role chỉ có quyền trả hàng NCC
        $returnRole = Role::create([
            'name' => 'return_only_' . uniqid(),
            'display_name' => 'Purchase Return Only',
            'permissions' => ['purchases.return.create', 'purchases.view'],
            'is_system' => false,
        ]);

        $this->returnUser = User::create([
            'name' => 'Return Only User',
            'email' => 'return-perm-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => $returnRole->id,
            'status' => 'active',
        ]);

        // Role không có quyền trả hàng NCC
        $viewOnlyRole = Role::create([
            'name' => 'view_only_' . uniqid(),
            'display_name' => 'View Only',
            'permissions' => ['purchases.view'],
            'is_system' => false,
        ]);

        $this->noPermUser = User::create([
            'name' => 'View Only User',
            'email' => 'viewonly-perm-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => $viewOnlyRole->id,
            'status' => 'active',
        ]);

        // Setup data
        $this->supplier = Customer::create([
            'code' => 'NCC-PERM-' . uniqid(),
            'name' => 'NCC Test Permission',
            'is_supplier' => true,
            'supplier_debt_amount' => 0,
            'total_bought' => 0,
        ]);

        $category = Category::firstOrCreate(['name' => 'HOTFIX Permission']);

        $this->product = Product::create([
            'name' => 'Serial Product Perm Test',
            'sku' => 'SP-PERM-' . uniqid(),
            'has_serial' => true,
            'stock_quantity' => 5,
            'cost_price' => 1000000,
            'retail_price' => 1500000,
            'inventory_total_cost' => 5000000,
            'is_active' => true,
            'category_id' => $category->id,
        ]);

        // Admin user for creating purchase (role_id=null → admin)
        $admin = User::create([
            'name' => 'Admin Perm Test',
            'email' => 'admin-perm-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);

        $this->purchase = Purchase::create([
            'code' => 'PNPERM' . time() . rand(100, 999),
            'supplier_id' => $this->supplier->id,
            'user_id' => $admin->id,
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'debt_amount' => 1000000,
            'status' => 'completed',
            'purchase_date' => now(),
        ]);

        PurchaseItem::create([
            'purchase_id' => $this->purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'product_code' => $this->product->sku,
            'quantity' => 1,
            'price' => 1000000,
            'subtotal' => 1000000,
            'unit_cost_allocated' => 1000000,
        ]);

        $this->serial = SerialImei::create([
            'product_id' => $this->product->id,
            'serial_number' => 'SN-PERM-' . uniqid(),
            'status' => 'in_stock',
            'purchase_id' => $this->purchase->id,
            'cost_price' => 1000000,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // Case 1: User có purchases.return.create → mở được màn trả
    // ──────────────────────────────────────────────────────────────
    public function test_user_with_purchase_return_create_permission_can_open_purchase_return_create_page(): void
    {
        $response = $this->actingAs($this->returnUser)
            ->get('/purchase-returns/create?purchase_id=' . $this->purchase->id . '&serial_id=' . $this->serial->id);

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PurchaseReturns/Create')
                ->where('preselectSerialId', $this->serial->id)
                ->where('preselectProductId', $this->product->id)
                ->where('preselectWarning', null)
            );
    }

    // ──────────────────────────────────────────────────────────────
    // Case 2: User có purchases.return.create → lookup serial được
    // ──────────────────────────────────────────────────────────────
    public function test_user_with_purchase_return_create_permission_can_lookup_serial(): void
    {
        $response = $this->actingAs($this->returnUser)
            ->getJson('/purchase-returns/serial-lookup?serial=' . $this->serial->serial_number);

        $response->assertOk()
            ->assertJsonCount(1, 'matches')
            ->assertJsonPath('matches.0.serial_id', $this->serial->id);

        // return_url must exist
        $this->assertNotEmpty($response->json('matches.0.return_url'));
    }

    // ──────────────────────────────────────────────────────────────
    // Case 3: User KHÔNG có purchases.return.create → lookup bị 403
    // ──────────────────────────────────────────────────────────────
    public function test_user_without_purchase_return_create_permission_cannot_lookup_serial(): void
    {
        $response = $this->actingAs($this->noPermUser)
            ->getJson('/purchase-returns/serial-lookup?serial=' . $this->serial->serial_number);

        $response->assertStatus(403);
    }

    // ──────────────────────────────────────────────────────────────
    // Case 4: User KHÔNG có purchases.return.create → mở màn trả bị chặn
    // ──────────────────────────────────────────────────────────────
    public function test_user_without_purchase_return_create_permission_cannot_open_purchase_return_create_page(): void
    {
        $response = $this->actingAs($this->noPermUser)
            ->get('/purchase-returns/create?purchase_id=' . $this->purchase->id);

        // Middleware redirects non-JSON requests to / with error (not 403 response)
        $response->assertRedirect('/');
    }
}
