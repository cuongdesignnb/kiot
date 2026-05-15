<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX 24.22 — EmployeeReport must include admin / user sellers.
 *
 * `invoices.created_by` is `employees.id` per the POSController contract
 * (POSController.php@135). When admin runs a sale without picking an
 * Employee, `created_by` is left NULL and `created_by_name` carries the
 * admin's display name. The previous report controller filtered those
 * orphan rows out entirely and only resolved names against `employees`,
 * so admin invoices disappeared from every report.
 *
 * This suite pins the new contract: orphan invoices get folded into a
 * user-id bucket via a name lookup, the seller filter exposes that user,
 * and the row label says "Admin" (or the admin's name) instead of
 * "Nhân viên #N".
 */
class HOTFIX2422EmployeeReportIncludesAdminTest extends TestCase
{
    use DatabaseTransactions;

    private function adminUser(string $name = 'Admin 2422'): User
    {
        // role_id = null → User::isAdmin() returns true (matches the
        // legacy convention in User.php@69).
        return User::create([
            'name'     => $name,
            'email'    => 'admin-2422-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function employeeUser(string $name, User $user): Employee
    {
        return Employee::create([
            'code'      => 'NV-2422-' . uniqid(),
            'name'      => $name,
            'user_id'   => $user->id,
            'is_active' => true,
        ]);
    }

    private function plainEmployee(string $name = 'Nhân viên A'): Employee
    {
        return Employee::create([
            'code'      => 'NV-A-2422-' . uniqid(),
            'name'      => $name,
            'is_active' => true,
        ]);
    }

    private function product(int $cost = 1_000_000, int $retail = 1_500_000): Product
    {
        return Product::create([
            'sku'                  => 'SKU-2422-' . uniqid(),
            'name'                 => 'Sản phẩm 2422',
            'cost_price'           => $cost,
            'retail_price'         => $retail,
            'stock_quantity'       => 100,
            'inventory_total_cost' => $cost * 100,
            'has_serial'           => false,
        ]);
    }

    /**
     * Create an invoice with a fixed `created_by` / `created_by_name`
     * shape so the seller-resolver can be exercised both for admin
     * (NULL created_by, named) and for plain employees (created_by set).
     */
    private function invoice(?int $createdBy, string $createdByName, Product $product, int $qty = 1, int $price = 1_500_000): Invoice
    {
        $inv = Invoice::create([
            'code'             => 'HD-2422-' . uniqid(),
            'created_by'       => $createdBy,
            'created_by_name'  => $createdByName,
            'subtotal'         => $qty * $price,
            'discount'         => 0,
            'total'            => $qty * $price,
            'customer_paid'    => $qty * $price,
            'status'           => 'Hoàn thành',
            'sales_channel'    => 'Bán trực tiếp',
        ]);
        InvoiceItem::create([
            'invoice_id' => $inv->id,
            'product_id' => $product->id,
            'quantity'   => $qty,
            'price'      => $price,
            'cost_price' => 1_000_000,
        ]);
        // The report filters by `created_at` between [startOfMonth, endOfDay] —
        // make sure these test rows fall inside the default this_month window.
        $inv->created_at = Carbon::now()->startOfDay()->addMinute();
        $inv->save();
        return $inv;
    }

    // ── TC-01 — profit report includes the admin seller ──
    // HOTFIX 24.26 contract: seller ids are prefixed strings (`user:<id>`,
    // `employee:<id>`, `orphan:<name>`) so employees.id can't collide with
    // users.id. Updated the row-id assertions to match.
    public function test_profit_report_includes_admin_seller(): void
    {
        $admin   = $this->adminUser();
        $product = $this->product();
        $this->invoice(null, $admin->name, $product, 2, 1_500_000); // 3M revenue, 2M cost

        $res = $this->actingAs($admin)->get('/reports/employees?concern=profit');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $adminKey = "user:{$admin->id}";
        $adminRow = collect($rows)->firstWhere('id', $adminKey);
        $this->assertNotNull($adminRow, 'admin row must appear in profit report');
        $this->assertSame($admin->name, $adminRow['name'], 'admin row label must be the admin user name (not "Nhân viên #N")');
        $this->assertSame('admin', $adminRow['seller_type']);
        // 24.26 profit rows expose the KiotViet 8-field shape; legacy
        // `revenue`/`returns`/`net` aliases still resolve to the right
        // numbers (net_revenue / total_cogs / gross_profit).
        $this->assertEquals(3_000_000, (int) $adminRow['revenue']);
        $this->assertEquals(2_000_000, (int) $adminRow['returns']);
        $this->assertEquals(1_000_000, (int) $adminRow['net']);
    }

    // ── TC-02 — sales report includes the admin seller ──
    public function test_sales_report_includes_admin_seller(): void
    {
        $admin   = $this->adminUser();
        $product = $this->product();
        $this->invoice(null, $admin->name, $product, 1, 5_000_000);

        $res  = $this->actingAs($admin)->get('/reports/employees?concern=sales');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $adminRow = collect($rows)->firstWhere('id', "user:{$admin->id}");
        $this->assertNotNull($adminRow);
        $this->assertSame($admin->name, $adminRow['name']);
        $this->assertEquals(5_000_000, (int) $adminRow['revenue']);
        $this->assertEquals(5_000_000, (int) $adminRow['net']);
    }

    // ── TC-03 — items report includes the admin seller ──
    public function test_items_report_includes_admin_seller(): void
    {
        $admin   = $this->adminUser();
        $product = $this->product();
        $this->invoice(null, $admin->name, $product, 4, 1_000_000);

        $res  = $this->actingAs($admin)->get('/reports/employees?concern=items');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $adminRow = collect($rows)->firstWhere('id', "user:{$admin->id}");
        $this->assertNotNull($adminRow);
        $this->assertSame(4, (int) $adminRow['returns'], 'items report uses `returns` for quantity');
        $this->assertEquals(4_000_000, (int) $adminRow['revenue']);
    }

    // ── TC-04 — seller filter exposes admins / users alongside employees ──
    public function test_seller_filter_includes_admin_when_orphan_invoices_exist(): void
    {
        $admin    = $this->adminUser();
        $employee = $this->plainEmployee('Người bán B');
        $product  = $this->product();
        $this->invoice(null, $admin->name, $product);
        $this->invoice($employee->id, $employee->name, $product);

        $res = $this->actingAs($admin)->get('/reports/employees');
        $res->assertOk();
        $options = $res->viewData('page')['props']['employees'];

        $option = collect($options)->firstWhere('id', "user:{$admin->id}");
        $this->assertNotNull($option, 'admin must appear in the seller filter list');
        $this->assertSame('admin', $option['type']);
        $this->assertSame($admin->name, $option['name']);

        $employeeOption = collect($options)->firstWhere('id', "employee:{$employee->id}");
        $this->assertNotNull($employeeOption, 'plain employees stay in the list');
        $this->assertSame('employee', $employeeOption['type']);
    }

    // ── TC-05 — filtering by admin returns only admin invoices ──
    public function test_filtering_by_admin_id_returns_only_admin_invoices(): void
    {
        $admin    = $this->adminUser();
        $employee = $this->plainEmployee('Người bán C');
        $product  = $this->product();
        $this->invoice(null, $admin->name, $product, 1, 1_000_000);
        $this->invoice($employee->id, $employee->name, $product, 1, 9_000_000);

        // 24.26 still accepts legacy bare numeric ids via
        // SellerResolver::normalizeRequestedSellerKey (maps to BOTH
        // employee:N and user:N). Admin is user:<id> so only the admin
        // row stays.
        $res  = $this->actingAs($admin)->get("/reports/employees?concern=profit&employee_id={$admin->id}");
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $this->assertCount(1, $rows, 'filter must constrain rows to the admin only');
        $this->assertSame("user:{$admin->id}", $rows[0]['id']);
        $this->assertSame($admin->name, $rows[0]['name']);
    }

    // ── TC-06 — employee-with-user is not duplicated in the filter list ──
    public function test_employee_with_user_does_not_duplicate_in_filter(): void
    {
        $admin    = $this->adminUser();
        $linked   = User::create([
            'name'     => 'Linked Seller',
            'email'    => 'linked-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
        $employee = $this->employeeUser('Linked Seller', $linked);
        $product  = $this->product();
        $this->invoice($employee->id, $employee->name, $product);

        $res     = $this->actingAs($admin)->get('/reports/employees');
        $res->assertOk();
        $options = $res->viewData('page')['props']['employees'];

        // 24.26 dedupes via the employee.user_id back-reference: when an
        // employee row has user_id = $linked->id, the user:<id> option is
        // marked as already covered by the employee:<id> option.
        $matches = collect($options)->where('name', 'Linked Seller');
        $this->assertCount(1, $matches, 'linked employee+user must show up exactly once');
    }
}
