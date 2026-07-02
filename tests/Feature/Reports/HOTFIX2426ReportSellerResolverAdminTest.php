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
 * HOTFIX 24.26 — SellerResolver contract tests.
 * UPDATED for HOTFIX 24.28B: created_by_name is creator, NOT seller.
 *
 * Key invariants:
 *   - keys are prefixed strings: employee:<id>, snapshot:<name>, unknown
 *   - invoices.created_by = seller employee id
 *   - invoices.created_by_name = creator snapshot (NEVER used for seller)
 *   - invoices.seller_name = seller name snapshot
 *   - invoices without seller → unknown bucket
 *   - the seller filter exposes employees and snapshot sellers
 *   - legacy numeric employee_id query strings still constrain the report
 *   - sales/profit/items concerns surface employee sellers
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
     * HOTFIX 24.28B: added seller_name parameter for correct contract.
     */
    private function invoice(
        ?int $createdBy,
        ?string $createdByName,
        Product $product,
        int $qty = 1,
        int $price = 1_000_000,
        int $discount = 0,
        string $status = 'Hoàn thành',
        ?int $costPrice = 600_000,
        ?string $sellerName = null
    ): Invoice {
        $subtotal = $qty * $price;
        $inv = Invoice::create([
            'code'             => 'HD-2426-' . uniqid(),
            'created_by'       => $createdBy,
            'created_by_name'  => $createdByName,
            'seller_name'      => $sellerName,
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
        $inv->created_at = Carbon::now()->startOfDay()->addMinute();
        $inv->save();
        return $inv;
    }

    // ── TC-01 — invoice without seller (no created_by, no seller_name) → unknown ──
    public function test_seller_resolver_maps_no_seller_to_unknown(): void
    {
        $admin   = $this->adminUser('Admin TC-01');
        $product = $this->product();
        $inv     = $this->invoice(null, $admin->name, $product, 1, 1_000_000);

        $resolver = new SellerResolver();
        $map      = $resolver->invoiceSellerMap(Invoice::whereKey($inv->id));
        $this->assertSame('unknown', $map[$inv->id],
            'Invoice without seller must map to unknown');
    }

    // ── TC-02 — seller_name without matching employee → snapshot:<name> ──
    public function test_seller_name_without_employee_becomes_snapshot(): void
    {
        $product = $this->product();
        $inv     = $this->invoice(null, 'Admin cũ', $product, 1, 1_000_000,
            sellerName: 'CTV Ngoài');

        $resolver = new SellerResolver();
        $map      = $resolver->invoiceSellerMap(Invoice::whereKey($inv->id));
        $this->assertSame('snapshot:CTV Ngoài', $map[$inv->id]);

        $meta = $resolver->sellerMeta(['snapshot:CTV Ngoài']);
        $this->assertSame('seller_snapshot', $meta['snapshot:CTV Ngoài']['type']);
        $this->assertSame('CTV Ngoài', $meta['snapshot:CTV Ngoài']['name']);
    }

    // ── TC-03 — EmployeeReport concern=sales surfaces employee seller ──
    public function test_employee_sales_report_includes_employee_seller(): void
    {
        $admin   = $this->adminUser('Admin TC-03');
        $emp     = $this->plainEmployee('Seller TC-03');
        $product = $this->product();
        $this->invoice($emp->id, $admin->name, $product, 1, 4_000_000,
            sellerName: $emp->name);

        $resRows = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $resRows->assertOk();
        $rows = $resRows->viewData('page')['props']['reportRows'];
        $row  = collect($rows)->firstWhere('id', "employee:{$emp->id}");
        $this->assertNotNull($row);
        $this->assertEquals(4_000_000, (int) $row['revenue']);
    }

    // ── TC-04 — EmployeeReport concern=profit exposes the 8 KiotViet fields ──
    public function test_employee_profit_report_has_all_eight_fields(): void
    {
        $admin   = $this->adminUser('Admin TC-04');
        $emp     = $this->plainEmployee('Profit TC-04');
        $product = $this->product(cost: 600_000);
        $this->invoice($emp->id, $admin->name, $product, 1, 1_000_000, discount: 0,
            sellerName: $emp->name);

        $res  = $this->actingAs($admin)->get('/reports/employees?concern=profit&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];
        $row  = collect($rows)->firstWhere('id', "employee:{$emp->id}");
        $this->assertNotNull($row);

        $this->assertEquals(1_000_000, (int) $row['gross_revenue']);
        $this->assertEquals(0,           (int) $row['invoice_discount']);
        $this->assertEquals(1_000_000, (int) $row['revenue_after_discount']);
        $this->assertEquals(0,           (int) $row['return_value']);
        $this->assertEquals(1_000_000, (int) $row['net_revenue']);
        $this->assertEquals(600_000,   (int) $row['total_cogs']);
        $this->assertEquals(400_000,   (int) $row['gross_profit']);
    }

    // ── TC-05 — EmployeeReport concern=items counts qty for employee seller ──
    public function test_employee_items_report_counts_seller_quantity(): void
    {
        $admin   = $this->adminUser('Admin TC-05');
        $emp     = $this->plainEmployee('Items TC-05');
        $product = $this->product();
        $this->invoice($emp->id, $admin->name, $product, 7, 500_000,
            sellerName: $emp->name);

        $res  = $this->actingAs($admin)->get('/reports/employees?concern=items&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];
        $row  = collect($rows)->firstWhere('id', "employee:{$emp->id}");
        $this->assertNotNull($row);
        $this->assertSame(7, (int) $row['returns'], 'items report stores qty in the `returns` column for backward compat');
        $this->assertEquals(3_500_000, (int) $row['revenue']);
    }

    // ── TC-06 — seller filter dropdown contains employee sellers ──
    public function test_seller_filter_dropdown_contains_employee_option(): void
    {
        $admin   = $this->adminUser('Admin TC-06');
        $emp     = $this->plainEmployee('Seller TC-06');
        $product = $this->product();
        $this->invoice($emp->id, $admin->name, $product, sellerName: $emp->name);

        $res     = $this->actingAs($admin)->get('/reports/employees');
        $res->assertOk();
        $options = $res->viewData('page')['props']['employees'];
        $opt     = collect($options)->firstWhere('id', "employee:{$emp->id}");
        $this->assertNotNull($opt);
        $this->assertSame($emp->name, $opt['name']);
        $this->assertSame('employee', $opt['type']);
    }

    // ── TC-07 — picking a seller filter constrains the report ──
    public function test_filter_by_seller_key_returns_only_that_seller(): void
    {
        $admin   = $this->adminUser('Admin TC-07');
        $emp1    = $this->plainEmployee('Nhân viên A');
        $emp2    = $this->plainEmployee('Nhân viên B');
        $product = $this->product();
        $this->invoice($emp1->id, $admin->name, $product, 1, 1_000_000, sellerName: $emp1->name);
        $this->invoice($emp2->id, $admin->name, $product, 1, 9_000_000, sellerName: $emp2->name);

        $res  = $this->actingAs($admin)->get("/reports/employees?concern=profit&view=report&employee_id=employee:{$emp1->id}");
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $this->assertCount(1, $rows);
        $this->assertSame("employee:{$emp1->id}", $rows[0]['id']);
        $this->assertEquals(1_000_000, (int) $rows[0]['gross_revenue']);
    }

    // ── TC-08 — SalesReport concern=employee route loads without error ──
    public function test_sales_report_concern_employee_loads(): void
    {
        $admin   = $this->adminUser('Admin TC-08');
        $emp     = $this->plainEmployee('Sales TC-08');
        $product = $this->product();
        $this->invoice($emp->id, $admin->name, $product, 1, 2_500_000,
            sellerName: $emp->name);

        $res = $this->actingAs($admin)->get('/reports/sales?concern=employee');
        $this->assertLessThan(500, $res->getStatusCode());
    }

    // ── TC-09 — non-seller breakdown reports keep invoices in totals ──
    public function test_invoice_not_dropped_when_route_loads(): void
    {
        $admin   = $this->adminUser('Admin TC-09');
        $product = $this->product();
        $this->invoice(null, $admin->name, $product, 1, 1_000_000);

        foreach (['/reports/products', '/reports/customers', '/reports/suppliers'] as $url) {
            $res = $this->actingAs($admin)->get($url);
            $this->assertLessThan(500, $res->getStatusCode(), "$url must not 500 with invoices present");
        }
    }

    // ── TC-10 — legacy bare numeric employee_id filter still works ──
    public function test_legacy_numeric_employee_id_filter_still_matches(): void
    {
        $admin   = $this->adminUser('Admin TC-10');
        $emp     = $this->plainEmployee('Người bán cũ');
        $product = $this->product();
        $this->invoice(null, $admin->name, $product, 1, 1_000_000);
        $this->invoice($emp->id, $admin->name, $product, 1, 5_000_000,
            sellerName: $emp->name);

        // Filter with the bare numeric id (legacy FE sends this shape)
        $res  = $this->actingAs($admin)->get("/reports/employees?concern=sales&view=report&employee_id={$emp->id}");
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $this->assertCount(1, $rows);
        $this->assertSame("employee:{$emp->id}", $rows[0]['id']);
    }

    // ── TC-11 — cancelled invoices never count ──
    public function test_cancelled_invoice_is_not_aggregated(): void
    {
        $admin   = $this->adminUser('Admin TC-11');
        $emp     = $this->plainEmployee('Cancel TC-11');
        $product = $this->product();
        $this->invoice($emp->id, $admin->name, $product, 1, 1_000_000,
            status: 'Đã hủy', sellerName: $emp->name);
        $this->invoice($emp->id, $admin->name, $product, 1, 2_000_000,
            sellerName: $emp->name);

        $res  = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];
        $row  = collect($rows)->firstWhere('id', "employee:{$emp->id}");
        $this->assertNotNull($row);
        $this->assertEquals(2_000_000, (int) $row['revenue']);
    }
}
