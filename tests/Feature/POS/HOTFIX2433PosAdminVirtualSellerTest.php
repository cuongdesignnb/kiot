<?php

namespace Tests\Feature\POS;

use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\Reports\SellerResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX 24.33 — POS must accept a super-admin user as the seller
 * via the admin_user:<id> virtual key, even when there's no linked
 * Employee record.
 *
 * Contract:
 *   admin_user:<id> on POS checkout writes:
 *     invoices.created_by  = NULL
 *     invoices.seller_name = user.name
 *     invoices.created_by_name = auth user name (unchanged)
 *
 *   admin_user:<id> on POS quick-order writes:
 *     orders.assigned_to_name = user.name
 *     orders.created_by_name  = auth user name
 *
 * Stock / cost / debt / cashflow logic is shared with InvoiceSaleService
 * and not affected by this hotfix.
 */
class HOTFIX2433PosAdminVirtualSellerTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(string $name = 'Admin POS 2433'): User
    {
        return User::create([
            'name'     => $name,
            'email'    => 'admin-pos-2433-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function normalUser(string $name = 'Staff POS 2433'): User
    {
        $role = Role::firstOrCreate(
            ['name' => 'staff-pos-2433-test'],
            ['display_name' => 'Staff POS 2433', 'permissions' => ['pos.use', 'invoices.view']]
        );
        $role->permissions = ['pos.use', 'invoices.view'];
        $role->save();
        return User::create([
            'name'     => $name,
            'email'    => 'staff-pos-2433-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => $role->id,
            'status'   => 'active',
        ]);
    }

    private function product(int $cost = 500_000, int $retail = 1_000_000): Product
    {
        return Product::create([
            'sku'                  => 'SKU-2433-' . uniqid(),
            'name'                 => 'SP 2433',
            'cost_price'           => $cost,
            'retail_price'         => $retail,
            'stock_quantity'       => 100,
            'inventory_total_cost' => $cost * 100,
            'has_serial'           => false,
            'is_active'            => true,
        ]);
    }

    private function checkoutPayload(Product $p, ?string $sellerKey, ?int $employeeId = null): array
    {
        return [
            'subtotal'      => 1_000_000,
            'discount'      => 0,
            'total'         => 1_000_000,
            'customer_paid' => 1_000_000,
            'customer_id'   => null,
            'seller_key'    => $sellerKey,
            'employee_id'   => $employeeId,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $p->id,
                'quantity'   => 1,
                'price'      => 1_000_000,
                'discount'   => 0,
            ]],
        ];
    }

    // ── Test 1 — POS seller options surface admin without employee ──
    public function test_pos_seller_options_include_admin_without_employee(): void
    {
        $admin = $this->admin('Trần Văn Tiến 33');
        $res = $this->actingAs($admin)->get('/pos');
        $res->assertOk();

        $opts = $res->viewData('page')['props']['sellerOptions'];
        $opt = collect($opts)->firstWhere('key', "admin_user:{$admin->id}");
        $this->assertNotNull($opt, 'Admin without employee must appear in POS seller options');
        $this->assertSame('admin_user', $opt['type']);
        $this->assertStringEndsWith(' — Admin', $opt['display_name']);
    }

    // ── Test 2 — normal user does not appear ──
    public function test_normal_user_not_in_pos_seller_options(): void
    {
        $staff = $this->normalUser('Staff Plain 33');
        // Need an admin to actually load /pos (any admin works)
        $admin = $this->admin();
        $res = $this->actingAs($admin)->get('/pos');
        $res->assertOk();
        $opts = $res->viewData('page')['props']['sellerOptions'];

        $this->assertNull(
            collect($opts)->firstWhere('key', "admin_user:{$staff->id}"),
            'Non-admin user without employee must not surface'
        );
    }

    // ── Test 3 — admin with active linked employee: no duplicate option ──
    public function test_admin_with_linked_employee_no_duplicate(): void
    {
        $admin = $this->admin('Linked Admin 33');
        Employee::create([
            'code'      => 'NV-LK-' . uniqid(),
            'name'      => 'Linked Admin 33',
            'user_id'   => $admin->id,
            'is_active' => true,
        ]);

        $res = $this->actingAs($admin)->get('/pos');
        $res->assertOk();
        $opts = $res->viewData('page')['props']['sellerOptions'];

        $this->assertNull(
            collect($opts)->firstWhere('key', "admin_user:{$admin->id}"),
            'Admin already represented by employee must NOT duplicate as admin_user'
        );
    }

    // ── Test 4 — checkout admin_user writes snapshot ──
    public function test_checkout_with_admin_user_writes_snapshot_seller(): void
    {
        $admin = $this->admin('POS Admin Seller');
        $product = $this->product();

        $res = $this->actingAs($admin)->postJson(
            '/api/pos/checkout',
            $this->checkoutPayload($product, "admin_user:{$admin->id}")
        );
        $res->assertOk();
        $this->assertTrue($res->json('success'));
        $code = $res->json('invoice_code');

        $inv = Invoice::where('code', $code)->first();
        $this->assertNotNull($inv);
        $this->assertNull($inv->created_by, 'created_by must be NULL for admin_user seller');
        $this->assertSame('POS Admin Seller', $inv->seller_name);
        $this->assertSame('POS Admin Seller', $inv->created_by_name,
            'created_by_name is the auth user (admin here is both seller and creator)');
    }

    // ── Test 5 — checkout employee key still works ──
    public function test_checkout_with_employee_key_writes_employee_seller(): void
    {
        $admin = $this->admin();
        $emp = Employee::create([
            'code'      => 'NV-POS-' . uniqid(),
            'name'      => 'Seller Real',
            'is_active' => true,
        ]);
        $product = $this->product();

        $res = $this->actingAs($admin)->postJson(
            '/api/pos/checkout',
            $this->checkoutPayload($product, "employee:{$emp->id}")
        );
        $res->assertOk();
        $code = $res->json('invoice_code');

        $inv = Invoice::where('code', $code)->first();
        $this->assertEquals($emp->id, $inv->created_by);
        $this->assertSame('Seller Real', $inv->seller_name);
    }

    // ── Test 6 — checkout rejects admin_user for normal user ──
    public function test_checkout_rejects_admin_user_for_normal_user(): void
    {
        $admin = $this->admin();
        $staff = $this->normalUser('Plain 33');
        $product = $this->product();

        $res = $this->actingAs($admin)->postJson(
            '/api/pos/checkout',
            $this->checkoutPayload($product, "admin_user:{$staff->id}")
        );
        $res->assertStatus(422);
        $this->assertFalse($res->json('success'));
        $this->assertSame(0, Invoice::where('seller_name', $staff->name)->count(),
            'No invoice must be created when the seller key is invalid');
    }

    // ── Test 7 — quick order accepts admin_user ──
    public function test_quick_order_accepts_admin_user(): void
    {
        $admin = $this->admin('Quick Admin 33');
        $product = $this->product();

        $payload = [
            'subtotal'    => 1_000_000,
            'discount'    => 0,
            'total'       => 1_000_000,
            'customer_id' => null,
            'seller_key'  => "admin_user:{$admin->id}",
            'employee_id' => null,
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 1_000_000,
            ]],
        ];

        $res = $this->actingAs($admin)->postJson('/api/pos/quick-order', $payload);
        $res->assertOk();
        $this->assertTrue($res->json('success'));
        $orderCode = $res->json('order_code');

        $order = \App\Models\Order::where('code', $orderCode)->first();
        $this->assertNotNull($order);
        $this->assertSame('Quick Admin 33', $order->assigned_to_name);
        $this->assertSame('Quick Admin 33', $order->created_by_name);

        // Stock must NOT be deducted by quickOrder
        $product->refresh();
        $this->assertEquals(100, $product->stock_quantity);
    }

    // ── Test 8 — created_by_name alone is not a seller ──
    public function test_created_by_name_never_promoted_to_seller(): void
    {
        $admin = $this->admin('Trần Văn Tiến 33B');
        $product = $this->product();
        $inv = Invoice::create([
            'code'             => 'HD-RAW-' . uniqid(),
            'created_by'       => null,
            'seller_name'      => null,
            'created_by_name'  => $admin->name,
            'subtotal'         => 1_000_000,
            'discount'         => 0,
            'total'            => 1_000_000,
            'customer_paid'    => 1_000_000,
            'status'           => 'Hoàn thành',
            'sales_channel'    => 'Bán trực tiếp',
        ]);
        \App\Models\InvoiceItem::create([
            'invoice_id' => $inv->id,
            'product_id' => $product->id,
            'quantity'   => 1,
            'price'      => 1_000_000,
            'cost_price' => 500_000,
        ]);

        $resolver = new SellerResolver();
        $map = $resolver->invoiceSellerMap(Invoice::whereKey($inv->id));
        $this->assertSame('unknown', $map[$inv->id]);
    }

    // ── Test 9 — report filter admin_user picks up POS-created invoice ──
    public function test_report_filter_admin_user_after_pos_checkout(): void
    {
        $admin = $this->admin('Report Admin POS');
        $product = $this->product();
        $this->actingAs($admin)->postJson(
            '/api/pos/checkout',
            $this->checkoutPayload($product, "admin_user:{$admin->id}")
        )->assertOk();

        $res = $this->actingAs($admin)->get(
            "/reports/employees?concern=sales&view=report&employee_id=admin_user:{$admin->id}"
        );
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $row = collect($rows)->firstWhere('id', "snapshot:{$admin->name}");
        $this->assertNotNull($row, 'POS invoice with admin_user seller must surface in employee report');
        $this->assertEquals(1_000_000, (int) $row['revenue']);
    }
}
