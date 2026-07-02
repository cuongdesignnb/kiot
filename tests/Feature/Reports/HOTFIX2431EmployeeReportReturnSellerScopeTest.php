<?php

namespace Tests\Feature\Reports;

use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\User;
use App\Support\Reports\SellerResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX 24.31 — Employee report must scope returns by selected seller.
 *
 * Bug: $returnQ was only filtered by date/branch/status. When the report
 * was filtered by seller A, returns of seller B still leaked through
 * aggregateReturnsBySeller() / cogsReturnedBySeller(), causing seller B
 * to appear in the report with a negative net.
 */
class HOTFIX2431EmployeeReportReturnSellerScopeTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 2431',
            'email'    => 'admin-2431-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function employee(string $name, ?int $userId = null, bool $active = true): Employee
    {
        return Employee::create([
            'code'      => 'NV-2431-' . uniqid(),
            'name'      => $name,
            'is_active' => $active,
            'user_id'   => $userId,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'sku'                  => 'SKU-2431-' . uniqid(),
            'name'                 => 'SP 2431',
            'cost_price'           => 500_000,
            'retail_price'         => 1_000_000,
            'stock_quantity'       => 1000,
            'inventory_total_cost' => 500_000_000,
            'has_serial'           => false,
        ]);
    }

    private function invoice(Employee $seller, Product $product, int $total, ?string $channel = 'Bán trực tiếp'): Invoice
    {
        $inv = Invoice::create([
            'code'             => 'HD-2431-' . uniqid(),
            'created_by'       => $seller->id,
            'seller_name'      => $seller->name,
            'created_by_name'  => 'Tester',
            'subtotal'         => $total,
            'discount'         => 0,
            'total'            => $total,
            'customer_paid'    => $total,
            'status'           => 'Hoàn thành',
            'sales_channel'    => $channel,
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

    private function returnFor(Invoice $invoice, int $total): OrderReturn
    {
        $ret = OrderReturn::create([
            'code'        => 'TR-2431-' . uniqid(),
            'invoice_id'  => $invoice->id,
            'subtotal'    => $total,
            'discount'    => 0,
            'fee'         => 0,
            'total'       => $total,
            'status'      => 'Hoàn thành',
            'seller_name' => $invoice->seller_name,
        ]);
        ReturnItem::create([
            'return_id'   => $ret->id,
            'product_id'  => $invoice->items->first()->product_id ?? Product::first()->id,
            'quantity'    => 1,
            'price'       => $total,
            'cost_price'  => 500_000,
            'import_price' => 500_000,
        ]);
        $ret->created_at = Carbon::now()->startOfDay()->addHour();
        $ret->save();
        return $ret;
    }

    // ── Test 1 — sales report filter A doesn't pull return of B ──
    public function test_sales_report_seller_filter_excludes_other_seller_returns(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $empB  = $this->employee('Seller B');
        $product = $this->product();
        $this->invoice($empA, $product, 5_000_000);
        $invB = $this->invoice($empB, $product, 10_000_000);
        $this->returnFor($invB, 13_600_000);

        $res = $this->actingAs($admin)->get("/reports/employees?concern=sales&view=report&employee_id=employee:{$empA->id}");
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $keys = collect($rows)->pluck('id')->all();
        $this->assertContains("employee:{$empA->id}", $keys);
        $this->assertNotContains("employee:{$empB->id}", $keys,
            'Filtering by seller A must not surface seller B from returns');

        $rowA = collect($rows)->firstWhere('id', "employee:{$empA->id}");
        $this->assertEquals(0, (int) $rowA['returns'], 'Seller A has no return → returns=0');
        $this->assertEquals(5_000_000, (int) $rowA['net'], 'Seller A net is its own revenue');
    }

    // ── Test 2 — profit report filter A: return_value/cogsReturned of B don't leak ──
    public function test_profit_report_seller_filter_excludes_other_seller_returns(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Profit A');
        $empB  = $this->employee('Profit B');
        $product = $this->product();
        $this->invoice($empA, $product, 5_000_000);
        $invB = $this->invoice($empB, $product, 10_000_000);
        $this->returnFor($invB, 13_600_000);

        $res = $this->actingAs($admin)->get("/reports/employees?concern=profit&view=report&employee_id=employee:{$empA->id}");
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $keys = collect($rows)->pluck('id')->all();
        $this->assertNotContains("employee:{$empB->id}", $keys);

        $rowA = collect($rows)->firstWhere('id', "employee:{$empA->id}");
        $this->assertNotNull($rowA);
        $this->assertEquals(0, (int) $rowA['return_value'], 'return_value of A must be 0');
        // total_cogs = cogsSold(A) - cogsReturned(A). cogsReturned must be 0.
        $this->assertEquals(500_000, (int) $rowA['total_cogs'],
            'cogsReturned of B must not be subtracted from A');
        $this->assertEquals(4_500_000, (int) $rowA['gross_profit']);
    }

    // ── Test 3 — filter seller B shows B's own return ──
    public function test_seller_filter_b_shows_b_returns(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A3');
        $empB  = $this->employee('Seller B3');
        $product = $this->product();
        $this->invoice($empA, $product, 5_000_000);
        $invB = $this->invoice($empB, $product, 10_000_000);
        $this->returnFor($invB, 13_600_000);

        $res = $this->actingAs($admin)->get("/reports/employees?concern=sales&view=report&employee_id=employee:{$empB->id}");
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $rowB = collect($rows)->firstWhere('id', "employee:{$empB->id}");
        $this->assertNotNull($rowB);
        $this->assertEquals(13_600_000, (int) $rowB['returns']);
        $this->assertEquals(-3_600_000, (int) $rowB['net'],
            'If returns > revenue, net is negative — this is real data, not a leak');
    }

    // ── Test 4 — no seller filter: returns group by their own seller ──
    public function test_no_filter_returns_group_by_own_seller(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('All A');
        $empB  = $this->employee('All B');
        $product = $this->product();
        $this->invoice($empA, $product, 5_000_000);
        $invB = $this->invoice($empB, $product, 10_000_000);
        $this->returnFor($invB, 13_600_000);

        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $rowA = collect($rows)->firstWhere('id', "employee:{$empA->id}");
        $rowB = collect($rows)->firstWhere('id', "employee:{$empB->id}");
        $this->assertNotNull($rowA);
        $this->assertNotNull($rowB);
        $this->assertEquals(0, (int) $rowA['returns'], 'A had no returns');
        $this->assertEquals(13_600_000, (int) $rowB['returns'], "B's return belongs to B");
    }

    // ── Test 5 — sales_channel filter scopes returns via invoice channel ──
    public function test_sales_channel_filter_excludes_returns_of_other_channel(): void
    {
        $admin = $this->admin();
        $emp   = $this->employee('Channel emp');
        $product = $this->product();
        // Invoice on direct channel + return on it
        $invDirect = $this->invoice($emp, $product, 5_000_000, 'Bán trực tiếp');
        $this->returnFor($invDirect, 1_000_000);
        // Invoice + return on another channel
        $invOnline = $this->invoice($emp, $product, 8_000_000, 'Shopee');
        $this->returnFor($invOnline, 2_000_000);

        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report&sales_channel=Shopee');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $row = collect($rows)->firstWhere('id', "employee:{$emp->id}");
        $this->assertNotNull($row);
        $this->assertEquals(8_000_000, (int) $row['revenue'], 'Only Shopee invoices counted');
        $this->assertEquals(2_000_000, (int) $row['returns'],
            'Only returns of Shopee invoices counted; direct-channel return must not leak');
    }

    // ── Test 6 — non-admin user without employee is NOT in seller options ──
    // HOTFIX 24.32 changed the rule for super admins (they DO appear via
    // admin_user:<id>); regular users still must not appear.
    public function test_non_admin_user_without_employee_is_not_in_seller_options(): void
    {
        $role = \App\Models\Role::firstOrCreate(
            ['name' => 'staff-2431-test'],
            ['display_name' => 'Staff 2431 Test', 'permissions' => ['invoices.view']]
        );
        $role->permissions = ['invoices.view'];
        $role->save();
        $staff = User::create([
            'name'     => 'Staff Plain 2431',
            'email'    => 'staff-2431-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => $role->id,
            'status'   => 'active',
        ]);

        $resolver = new SellerResolver();
        $options = $resolver->buildInvoiceSellerOptions();

        $opt = collect($options)->firstWhere('key', "admin_user:{$staff->id}");
        $this->assertNull($opt, 'Non-admin user without employee must not surface');
    }

    // ── Test 7 — admin user with active linked employee IS in seller options ──
    public function test_admin_user_with_linked_active_employee_appears(): void
    {
        $admin = $this->admin();
        $emp = $this->employee('Some Name', $admin->id, true);
        // After rename: user gets new name
        $admin->name = 'Trần Văn Tiến 31';
        $admin->save();

        $resolver = new SellerResolver();
        $options = $resolver->buildInvoiceSellerOptions();

        $byKey = collect($options)->firstWhere('key', "employee:{$emp->id}");
        $this->assertNotNull($byKey, 'Linked employee must appear');
        $this->assertSame('Trần Văn Tiến 31', $byKey['name'],
            'Display follows linked user current name (Hướng A)');
    }

    // ── Test 8 — created_by_name is never used as seller ──
    public function test_created_by_name_never_used_as_seller(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $inv = Invoice::create([
            'code'             => 'HD-2431-CN-' . uniqid(),
            'created_by'       => null,
            'seller_name'      => null,
            'created_by_name'  => 'Admin',
            'subtotal'         => 1_000_000,
            'discount'         => 0,
            'total'            => 1_000_000,
            'customer_paid'    => 1_000_000,
            'status'           => 'Hoàn thành',
            'sales_channel'    => 'Bán trực tiếp',
        ]);
        InvoiceItem::create([
            'invoice_id' => $inv->id,
            'product_id' => $product->id,
            'quantity'   => 1,
            'price'      => 1_000_000,
            'cost_price' => 500_000,
        ]);
        $inv->created_at = Carbon::now()->startOfDay()->addMinute();
        $inv->save();

        $resolver = new SellerResolver();
        $map = $resolver->invoiceSellerMap(Invoice::whereKey($inv->id));

        $this->assertSame('unknown', $map[$inv->id],
            'created_by_name=Admin must NOT promote the invoice to a seller');
    }
}
