<?php

namespace Tests\Feature\Invoices;

use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\Reports\SellerResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX 24.32 — Super admin user can be a virtual seller without
 * needing an Employee record.
 *
 * Contract:
 *   admin_user:<id> seller key:
 *     - User must exist, be active, and be admin (User::isAdmin()).
 *     - Stored as snapshot on the invoice: created_by=NULL,
 *       seller_name=user.name.
 *     - created_by_name is never touched.
 *
 * If the admin already has an active linked Employee, the option is
 * NOT offered and the controller rejects admin_user:<id> in favour of
 * employee:<id>.
 */
class HOTFIX2432AdminUserVirtualSellerTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(string $name = 'Admin 2432'): User
    {
        return User::create([
            'name'     => $name,
            'email'    => 'admin-2432-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function normalUser(string $name = 'Staff 2432'): User
    {
        $role = Role::firstOrCreate(
            ['name' => 'staff-2432-test'],
            ['display_name' => 'Staff 2432 Test', 'permissions' => ['invoices.view']]
        );
        $role->permissions = ['invoices.view'];
        $role->save();
        return User::create([
            'name'     => $name,
            'email'    => 'staff-2432-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => $role->id,
            'status'   => 'active',
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'sku'                  => 'SKU-2432-' . uniqid(),
            'name'                 => 'SP 2432',
            'cost_price'           => 500_000,
            'retail_price'         => 1_000_000,
            'stock_quantity'       => 100,
            'inventory_total_cost' => 50_000_000,
            'has_serial'           => false,
        ]);
    }

    private function invoice(?int $createdBy, ?string $sellerName, string $createdByName, Product $product, int $total = 1_000_000): Invoice
    {
        $inv = Invoice::create([
            'code'             => 'HD-2432-' . uniqid(),
            'created_by'       => $createdBy,
            'seller_name'      => $sellerName,
            'created_by_name'  => $createdByName,
            'subtotal'         => $total,
            'discount'         => 0,
            'total'            => $total,
            'customer_paid'    => $total,
            'status'           => 'Hoàn thành',
            'sales_channel'    => 'Bán trực tiếp',
        ]);
        InvoiceItem::create([
            'invoice_id' => $inv->id,
            'product_id' => $product->id,
            'quantity'   => 1,
            'price'      => $total,
            'cost_price' => 500_000,
        ]);
        $inv->created_at = Carbon::now()->startOfDay()->addMinute();
        $inv->save();
        return $inv;
    }

    // ── Test 1 — super admin without employee surfaces as virtual seller ──
    public function test_super_admin_without_employee_is_virtual_seller_option(): void
    {
        $admin = $this->admin('Trần Văn Tiến 32');
        $resolver = new SellerResolver();
        $options = $resolver->buildInvoiceSellerOptions();

        $opt = collect($options)->firstWhere('key', "admin_user:{$admin->id}");
        $this->assertNotNull($opt, 'Admin without employee must appear as admin_user option');
        $this->assertSame('admin_user', $opt['type']);
        $this->assertSame('Trần Văn Tiến 32', $opt['name']);
        $this->assertStringEndsWith(' — Admin', $opt['display_name']);
    }

    // ── Test 2 — normal user is not a seller option ──
    public function test_normal_user_is_not_a_virtual_seller_option(): void
    {
        $staff = $this->normalUser('Bình thường 32');
        $resolver = new SellerResolver();
        $options = $resolver->buildInvoiceSellerOptions();

        $opt = collect($options)->firstWhere('key', "admin_user:{$staff->id}");
        $this->assertNull($opt, 'Non-admin users must not appear as admin_user');
    }

    // ── Test 3 — admin with active linked employee: no duplicate option ──
    public function test_admin_with_linked_employee_has_no_admin_user_duplicate(): void
    {
        $admin = $this->admin('Linked Admin 32');
        Employee::create([
            'code'      => 'NV-LINK-' . uniqid(),
            'name'      => 'Linked Admin 32',
            'user_id'   => $admin->id,
            'is_active' => true,
        ]);

        $resolver = new SellerResolver();
        $options = $resolver->buildInvoiceSellerOptions();

        $adminVirtual = collect($options)->firstWhere('key', "admin_user:{$admin->id}");
        $this->assertNull($adminVirtual,
            'Admin who already has an active linked employee must NOT also appear as admin_user');
    }

    // ── Test 4 — PATCH seller to admin_user ──
    public function test_update_seller_to_admin_user(): void
    {
        $admin = $this->admin('Admin Updater');
        $emp = Employee::create([
            'code'      => 'NV-OLD-' . uniqid(),
            'name'      => 'Old seller',
            'is_active' => true,
        ]);
        $product = $this->product();
        $inv = $this->invoice($emp->id, $emp->name, 'Original Creator', $product);

        $res = $this->actingAs($admin)->patchJson("/invoices/{$inv->id}/seller", [
            'seller_key' => "admin_user:{$admin->id}",
        ]);
        $res->assertOk();
        $body = $res->json();
        $this->assertNull($body['created_by']);
        $this->assertSame('Admin Updater', $body['seller_name']);
        $this->assertSame("admin_user:{$admin->id}", $body['seller_key']);

        $inv->refresh();
        $this->assertNull($inv->created_by, 'created_by must be NULL for admin_user seller');
        $this->assertSame('Admin Updater', $inv->seller_name);
        $this->assertSame('Original Creator', $inv->created_by_name,
            'created_by_name must NEVER change');
    }

    // ── Test 5 — PATCH rejected for non-admin user id ──
    public function test_reject_admin_user_key_for_normal_user(): void
    {
        $admin = $this->admin();
        $staff = $this->normalUser('Bình thường 32B');
        $emp = Employee::create([
            'code'      => 'NV-INIT-' . uniqid(),
            'name'      => 'Init seller',
            'is_active' => true,
        ]);
        $product = $this->product();
        $inv = $this->invoice($emp->id, $emp->name, $admin->name, $product);

        $res = $this->actingAs($admin)->patchJson("/invoices/{$inv->id}/seller", [
            'seller_key' => "admin_user:{$staff->id}",
        ]);
        $res->assertStatus(422);

        $inv->refresh();
        $this->assertEquals($emp->id, $inv->created_by, 'Invoice must not change');
    }

    // ── Test 6 — report can filter by admin_user ──
    public function test_report_can_filter_by_admin_user(): void
    {
        $admin = $this->admin('Filter Admin');
        $product = $this->product();
        // Snapshot invoice: created_by NULL + seller_name = admin's name
        $inv = $this->invoice(null, $admin->name, 'Some creator', $product, 5_000_000);

        $res = $this->actingAs($admin)->get(
            "/reports/employees?concern=sales&view=report&employee_id=admin_user:{$admin->id}"
        );
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        // Resolver groups snapshot invoices under snapshot:<name>
        $row = collect($rows)->firstWhere('id', "snapshot:{$admin->name}");
        $this->assertNotNull($row, 'Report must surface admin snapshot when filtered by admin_user');
        $this->assertEquals(5_000_000, (int) $row['revenue']);
    }

    // ── Test 7 — old snapshot:Admin not merged with current admin user ──
    public function test_old_admin_snapshot_not_merged_with_renamed_user(): void
    {
        $admin = $this->admin('Trần Văn Tiến 32C');
        $product = $this->product();
        // Legacy invoice with seller_name = 'Admin' (a name the user no longer uses)
        $legacyInv = $this->invoice(null, 'Admin', 'Some creator', $product, 3_000_000);
        // New invoice with seller_name = current admin name
        $this->invoice(null, $admin->name, 'Some creator', $product, 7_000_000);

        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $oldRow = collect($rows)->firstWhere('id', 'snapshot:Admin');
        $newRow = collect($rows)->firstWhere('id', "snapshot:{$admin->name}");

        $this->assertNotNull($oldRow, 'snapshot:Admin must remain its own row');
        $this->assertNotNull($newRow, 'snapshot:<current admin name> must be its own row');
        $this->assertEquals(3_000_000, (int) $oldRow['revenue']);
        $this->assertEquals(7_000_000, (int) $newRow['revenue']);
    }

    // ── Test 8 — created_by_name never used as seller for admin_user ──
    public function test_created_by_name_never_treated_as_seller(): void
    {
        $admin = $this->admin('Trần Văn Tiến 32D');
        $product = $this->product();
        // created_by NULL, seller_name NULL, created_by_name = admin name
        $inv = $this->invoice(null, null, $admin->name, $product, 1_000_000);

        $resolver = new SellerResolver();
        $map = $resolver->invoiceSellerMap(Invoice::whereKey($inv->id));

        $this->assertSame('unknown', $map[$inv->id],
            'created_by_name alone must NOT promote invoice to a seller bucket');

        // Filter by admin_user:<id> must NOT pick up this invoice
        $res = $this->actingAs($admin)->get(
            "/reports/employees?concern=sales&view=report&employee_id=admin_user:{$admin->id}"
        );
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];
        $row = collect($rows)->firstWhere('id', "snapshot:{$admin->name}");
        $this->assertNull($row,
            'admin_user filter must not match invoices that only have created_by_name');
    }
}
