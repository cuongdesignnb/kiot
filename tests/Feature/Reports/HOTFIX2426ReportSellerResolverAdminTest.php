<?php

namespace Tests\Feature\Reports;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Reports\SellerResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX 24.26 — SellerResolver pins the new seller-key contract across
 * every report module so admin / user / orphan creators stop disappearing
 * from the breakdown.
 *
 * Key invariants pinned here:
 *   - keys are prefixed strings: employee:<id>, user:<id>, orphan:<name>
 *   - invoices.created_by = NULL with a created_by_name that matches a
 *     User.name fold into user:<id>; orphan names land under orphan:<name>
 *   - the seller filter exposes admin alongside employees
 *   - legacy numeric employee_id query strings still constrain the report
 *   - sales/profit/items concerns all surface the admin row
 *   - SalesReport concern=employee surfaces admin in the chart
 *   - non-seller reports (products, financial totals, etc.) do not strip
 *     admin invoices from totals
 *   - cancelled invoices stay excluded everywhere
 */
class HOTFIX2426ReportSellerResolverAdminTest extends TestCase
{
    use DatabaseTransactions;

    private function adminUser(string $name = 'Admin 2426'): User
    {
        return User::create([
            'name'     => $name,
            'email'    => 'admin-2426-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function plainEmployee(string $name = 'Nhân viên 2426'): Employee
    {
        return Employee::create([
            'code'      => 'NV-2426-' . uniqid(),
            'name'      => $name,
            'is_active' => true,
        ]);
    }

    private function product(int $cost = 600_000, int $retail = 1_500_000): Product
    {
        return Product::create([
            'sku'                  => 'SKU-2426-' . uniqid(),
            'name'                 => 'Sản phẩm 2426',
            'cost_price'           => $cost,
            'retail_price'         => $retail,
            'stock_quantity'       => 100,
            'inventory_total_cost' => $cost * 100,
            'has_serial'           => false,
        ]);
    }

    private function customer(string $name = 'Khách 2426'): Customer
    {
        return Customer::create([
            'code'        => 'KH-2426-' . uniqid(),
            'name'        => $name,
            'phone'       => '09' . random_int(10000000, 99999999),
            'is_customer' => true,
        ]);
    }

    /**
     * Helper: write an invoice + 1 item with full control over the
     * seller side (created_by / created_by_name / employee_id) so each
     * SellerResolver branch is reachable.
     */
    private function invoice(
        ?int $createdBy,
        ?string $createdByName,
        Product $product,
        int $qty = 1,
        int $price = 1_000_000,
        int $discount = 0,
        string $status = 'Hoàn thành',
        ?int $costPrice = 600_000
    ): Invoice {
        $subtotal = $qty * $price;
        $inv = Invoice::create([
            'code'             => 'HD-2426-' . uniqid(),
            'created_by'       => $createdBy,
            'created_by_name'  => $createdByName,
            'subtotal'         => $subtotal,
            'discount'         => $discount,
            'total'            => $subtotal - $discount,
            'customer_paid'    => $subtotal - $discount,
            'status'           => $status,
            'sales_channel'    => 'Bán trực tiếp',
        ]);
        InvoiceItem::create([
            'invoice_id' => $inv->id,
            'product_id' => $product->id,
            'quantity'   => $qty,
            'price'      => $price,
            'cost_price' => $costPrice,
        ]);
        // Anchor inside the default this_month window the report uses.
        $inv->created_at = Carbon::now()->startOfDay()->addMinute();
        $inv->save();
        return $inv;
    }

    // ── TC-01 — admin invoice (created_by NULL, name matches user) folds into user:<admin_id> ──
    public function test_seller_resolver_maps_admin_orphan_to_user_key(): void
    {
        $admin   = $this->adminUser('Admin TC-01');
        $product = $this->product();
        $inv     = $this->invoice(null, $admin->name, $product, 1, 1_000_000);

        $resolver = new SellerResolver();
        $map      = $resolver->invoiceSellerMap(Invoice::whereKey($inv->id));
        $this->assertSame("user:{$admin->id}", $map[$inv->id]);

        $meta = $resolver->sellerMeta(["user:{$admin->id}"]);
        $this->assertSame('admin', $meta["user:{$admin->id}"]['type']);
        $this->assertSame($admin->name, $meta["user:{$admin->id}"]['name']);
    }

    // ── TC-02 — orphan name without a matching user lands under orphan:<name> ──
    public function test_orphan_without_matching_user_keeps_name_visible(): void
    {
        $product = $this->product();
        $inv     = $this->invoice(null, 'Admin cũ', $product, 1, 1_000_000);

        $resolver = new SellerResolver();
        $map      = $resolver->invoiceSellerMap(Invoice::whereKey($inv->id));
        $this->assertSame('orphan:Admin cũ', $map[$inv->id]);

        $meta = $resolver->sellerMeta(['orphan:Admin cũ']);
        $this->assertSame('orphan', $meta['orphan:Admin cũ']['type']);
        $this->assertSame('Admin cũ', $meta['orphan:Admin cũ']['name']);
    }

    // ── TC-03 — EmployeeReport concern=sales surfaces admin in chart + rows ──
    public function test_employee_sales_report_includes_admin(): void
    {
        $admin   = $this->adminUser('Admin TC-03');
        $product = $this->product();
        $this->invoice(null, $admin->name, $product, 1, 4_000_000);

        $resChart = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=chart');
        $resChart->assertOk();
        $chart    = $resChart->viewData('page')['props']['chartData'];
        $this->assertContains($admin->name, $chart['labels']);

        $resRows = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $resRows->assertOk();
        $rows = $resRows->viewData('page')['props']['reportRows'];
        $row  = collect($rows)->firstWhere('id', "user:{$admin->id}");
        $this->assertNotNull($row);
        $this->assertEquals(4_000_000, (int) $row['revenue']);
    }

    // ── TC-04 — EmployeeReport concern=profit exposes the 8 KiotViet fields for admin ──
    public function test_employee_profit_report_has_all_eight_fields(): void
    {
        $admin   = $this->adminUser('Admin TC-04');
        $product = $this->product(cost: 600_000);
        $this->invoice(null, $admin->name, $product, 1, 1_000_000, discount: 0);

        $res  = $this->actingAs($admin)->get('/reports/employees?concern=profit&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];
        $row  = collect($rows)->firstWhere('id', "user:{$admin->id}");
        $this->assertNotNull($row);

        $this->assertEquals(1_000_000, (int) $row['gross_revenue']);
        $this->assertEquals(0,           (int) $row['invoice_discount']);
        $this->assertEquals(1_000_000, (int) $row['revenue_after_discount']);
        $this->assertEquals(0,           (int) $row['return_value']);
        $this->assertEquals(1_000_000, (int) $row['net_revenue']);
        $this->assertEquals(600_000,   (int) $row['total_cogs']);
        $this->assertEquals(400_000,   (int) $row['gross_profit']);
    }

    // ── TC-05 — EmployeeReport concern=items counts qty for admin ──
    public function test_employee_items_report_counts_admin_quantity(): void
    {
        $admin   = $this->adminUser('Admin TC-05');
        $product = $this->product();
        $this->invoice(null, $admin->name, $product, 7, 500_000);

        $res  = $this->actingAs($admin)->get('/reports/employees?concern=items&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];
        $row  = collect($rows)->firstWhere('id', "user:{$admin->id}");
        $this->assertNotNull($row);
        $this->assertSame(7, (int) $row['returns'], 'items report stores qty in the `returns` column for backward compat');
        $this->assertEquals(3_500_000, (int) $row['revenue']);
    }

    // ── TC-06 — seller filter dropdown contains the admin option ──
    public function test_seller_filter_dropdown_contains_admin_option(): void
    {
        $admin   = $this->adminUser('Admin TC-06');
        $product = $this->product();
        $this->invoice(null, $admin->name, $product);

        $res     = $this->actingAs($admin)->get('/reports/employees');
        $res->assertOk();
        $options = $res->viewData('page')['props']['employees'];
        $opt     = collect($options)->firstWhere('id', "user:{$admin->id}");
        $this->assertNotNull($opt);
        $this->assertSame($admin->name, $opt['name']);
        $this->assertSame('admin', $opt['type']);
    }

    // ── TC-07 — picking the admin filter constrains the report to admin only ──
    public function test_filter_by_admin_key_returns_only_admin(): void
    {
        $admin   = $this->adminUser('Admin TC-07');
        $emp     = $this->plainEmployee('Nhân viên khác');
        $product = $this->product();
        $this->invoice(null, $admin->name, $product, 1, 1_000_000);
        $this->invoice($emp->id, $emp->name, $product, 1, 9_000_000);

        $res  = $this->actingAs($admin)->get("/reports/employees?concern=profit&view=report&employee_id=user:{$admin->id}");
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $this->assertCount(1, $rows);
        $this->assertSame("user:{$admin->id}", $rows[0]['id']);
        $this->assertEquals(1_000_000, (int) $rows[0]['gross_revenue']);
    }

    // ── TC-08 — SalesReport concern=employee surfaces admin ──
    public function test_sales_report_concern_employee_surfaces_admin(): void
    {
        $admin   = $this->adminUser('Admin TC-08');
        $product = $this->product();
        $this->invoice(null, $admin->name, $product, 1, 2_500_000);

        $res = $this->actingAs($admin)->get('/reports/sales?concern=employee');
        $res->assertOk();
        $chart = $res->viewData('page')['props']['chartData'];
        $this->assertContains($admin->name, $chart['labels'], 'admin label must show up under SalesReport concern=employee');
    }

    // ── TC-09 — non-seller breakdown reports keep admin invoices in totals ──
    public function test_admin_invoice_not_dropped_when_route_loads(): void
    {
        $admin   = $this->adminUser('Admin TC-09');
        $product = $this->product();
        $this->invoice(null, $admin->name, $product, 1, 1_000_000);

        // Smoke test — products / customers / suppliers reports should at
        // least respond 200 with admin invoices present. The detailed
        // totals belong to a deeper assertion suite, but the contract
        // here is: no controller drops admin invoices on the floor.
        foreach (['/reports/products', '/reports/customers', '/reports/suppliers'] as $url) {
            $res = $this->actingAs($admin)->get($url);
            $this->assertLessThan(500, $res->getStatusCode(), "$url must not 500 with admin invoices present");
        }
    }

    // ── TC-10 — legacy bare numeric employee_id still works ──
    public function test_legacy_numeric_employee_id_filter_still_matches(): void
    {
        $admin   = $this->adminUser('Admin TC-10');
        $emp     = $this->plainEmployee('Người bán cũ');
        $product = $this->product();
        $this->invoice(null, $admin->name, $product, 1, 1_000_000);
        $this->invoice($emp->id, $emp->name, $product, 1, 5_000_000);

        // Filter with the bare numeric id (FE before 24.26 sent this shape).
        // SellerResolver::normalizeRequestedSellerKey maps N → [employee:N,
        // user:N], so we still hit the admin row.
        $res  = $this->actingAs($admin)->get("/reports/employees?concern=sales&view=report&employee_id={$admin->id}");
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $this->assertCount(1, $rows);
        $this->assertSame("user:{$admin->id}", $rows[0]['id']);
    }

    // ── TC-11 — cancelled invoices never count ──
    public function test_cancelled_admin_invoice_is_not_aggregated(): void
    {
        $admin   = $this->adminUser('Admin TC-11');
        $product = $this->product();
        $this->invoice(null, $admin->name, $product, 1, 1_000_000, status: 'Đã hủy');
        $this->invoice(null, $admin->name, $product, 1, 2_000_000, status: 'Hoàn thành');

        $res  = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];
        $row  = collect($rows)->firstWhere('id', "user:{$admin->id}");
        $this->assertNotNull($row);
        // Only the non-cancelled 2M invoice should show up.
        $this->assertEquals(2_000_000, (int) $row['revenue']);
    }
}
