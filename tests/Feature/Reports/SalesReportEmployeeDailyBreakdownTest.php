<?php

namespace Tests\Feature\Reports;

use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\User;
use App\Models\Branch;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SalesReportEmployeeDailyBreakdownTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin Test',
            'email'    => 'admin-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function employee(string $name, ?int $userId = null, bool $active = true): Employee
    {
        return Employee::create([
            'code'      => 'NV-' . uniqid(),
            'name'      => $name,
            'is_active' => $active,
            'user_id'   => $userId,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'sku'                  => 'SKU-' . uniqid(),
            'name'                 => 'SP Test',
            'cost_price'           => 500_000,
            'retail_price'         => 1_000_000,
            'stock_quantity'       => 1000,
            'inventory_total_cost' => 500_000_000,
            'has_serial'           => false,
        ]);
    }

    private function invoice(Employee $seller, Product $product, float $total, ?string $channel = 'Bán trực tiếp', ?string $date = null, ?int $branchId = null): Invoice
    {
        $inv = Invoice::create([
            'code'             => 'HD-' . uniqid(),
            'created_by'       => $seller->id,
            'seller_name'      => $seller->name,
            'created_by_name'  => 'Tester',
            'subtotal'         => $total,
            'discount'         => 0,
            'total'            => $total,
            'customer_paid'    => $total,
            'status'           => 'Hoàn thành',
            'sales_channel'    => $channel,
            'branch_id'        => $branchId,
        ]);
        InvoiceItem::create([
            'invoice_id' => $inv->id,
            'product_id' => $product->id,
            'quantity'   => 1,
            'price'      => $total,
            'cost_price' => 500_000,
        ]);
        if ($date) {
            $inv->created_at = Carbon::parse($date);
        } else {
            $inv->created_at = Carbon::now()->startOfDay()->addMinute();
        }
        $inv->save();
        return $inv;
    }

    private function returnFor(Invoice $invoice, float $total, ?string $date = null, ?int $branchId = null): OrderReturn
    {
        $ret = OrderReturn::create([
            'code'        => 'TR-' . uniqid(),
            'invoice_id'  => $invoice->id,
            'subtotal'    => $total,
            'discount'    => 0,
            'fee'         => 0,
            'total'       => $total,
            'status'      => 'Hoàn thành',
            'seller_name' => $invoice->seller_name,
            'branch_id'   => $branchId,
        ]);
        ReturnItem::create([
            'return_id'   => $ret->id,
            'product_id'  => $invoice->items->first()->product_id ?? Product::first()->id,
            'quantity'    => 1,
            'price'       => $total,
            'cost_price'  => 500_000,
            'import_price' => 500_000,
        ]);
        if ($date) {
            $ret->created_at = Carbon::parse($date);
        } else {
            $ret->created_at = Carbon::now()->startOfDay()->addHour();
        }
        $ret->save();
        return $ret;
    }

    // Test 1 — /reports/sales?concern=employee trả rows có children
    public function test_sales_report_employee_returns_daily_children(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();

        $this->invoice($empA, $product, 10000000, 'Bán trực tiếp', '2026-05-27 10:00:00');
        $this->invoice($empA, $product, 5000000, 'Bán trực tiếp', '2026-05-28 14:00:00');

        $res = $this->actingAs($admin)->get('/reports/sales?concern=employee&view=report&period=custom&date_from=2026-05-01&date_to=2026-05-31');
        $res->assertOk();
        $chartData = $res->viewData('page')['props']['chartData'];

        $this->assertArrayHasKey('rows', $chartData);
        $rowA = collect($chartData['rows'])->firstWhere('id', "employee:{$empA->id}");
        $this->assertNotNull($rowA);
        $this->assertNotEmpty($rowA['children']);

        $child27 = collect($rowA['children'])->firstWhere('date', '2026-05-27');
        $child28 = collect($rowA['children'])->firstWhere('date', '2026-05-28');

        $this->assertNotNull($child27);
        $this->assertNotNull($child28);

        $this->assertEquals(10000000, $child27['revenue']);
        $this->assertEquals(5000000, $child28['revenue']);
    }

    // Test 2 — Parent total khớp tổng children
    public function test_parent_totals_match_sum_of_children(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();

        $inv1 = $this->invoice($empA, $product, 8000000, 'Bán trực tiếp', '2026-05-20 10:00:00');
        $this->invoice($empA, $product, 4000000, 'Bán trực tiếp', '2026-05-21 14:00:00');
        $this->returnFor($inv1, 2000000, '2026-05-22 09:00:00');

        $res = $this->actingAs($admin)->get('/reports/sales?concern=employee&view=report&period=custom&date_from=2026-05-01&date_to=2026-05-31');
        $res->assertOk();
        $chartData = $res->viewData('page')['props']['chartData'];

        $rowA = collect($chartData['rows'])->firstWhere('id', "employee:{$empA->id}");
        $this->assertNotNull($rowA);

        $sumRev = collect($rowA['children'])->sum('revenue');
        $sumRet = collect($rowA['children'])->sum('returns');
        $sumNet = collect($rowA['children'])->sum('net');

        $this->assertEquals($rowA['revenue'], $sumRev);
        $this->assertEquals($rowA['returns'], $sumRet);
        $this->assertEquals($rowA['net'], $sumNet);
    }

    // Test 3 — Return group theo ngày trả và seller invoice gốc
    public function test_returns_group_by_return_date_and_invoice_seller(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();

        $inv = $this->invoice($empA, $product, 10000000, 'Bán trực tiếp', '2026-05-20 10:00:00');
        $this->returnFor($inv, 1500000, '2026-05-27 15:00:00');

        $res = $this->actingAs($admin)->get('/reports/sales?concern=employee&view=report&period=custom&date_from=2026-05-01&date_to=2026-05-31');
        $res->assertOk();
        $chartData = $res->viewData('page')['props']['chartData'];

        $rowA = collect($chartData['rows'])->firstWhere('id', "employee:{$empA->id}");
        $this->assertNotNull($rowA);

        $child27 = collect($rowA['children'])->firstWhere('date', '2026-05-27');
        $this->assertNotNull($child27);
        $this->assertEquals(0, $child27['revenue']);
        $this->assertEquals(1500000, $child27['returns']);
        $this->assertEquals(-1500000, $child27['net']);
    }

    // Test 4 — Return seller khác không leak
    public function test_returns_from_other_sellers_dont_leak(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $empB  = $this->employee('Seller B');
        $product = $this->product();

        $this->invoice($empA, $product, 5000000, 'Bán trực tiếp', '2026-05-20 10:00:00');
        $invB = $this->invoice($empB, $product, 6000000, 'Bán trực tiếp', '2026-05-20 11:00:00');
        $this->returnFor($invB, 2000000, '2026-05-25 10:00:00');

        // Check A
        $res = $this->actingAs($admin)->get('/reports/sales?concern=employee&view=report&period=custom&date_from=2026-05-01&date_to=2026-05-31');
        $res->assertOk();
        $chartData = $res->viewData('page')['props']['chartData'];

        $rowA = collect($chartData['rows'])->firstWhere('id', "employee:{$empA->id}");
        $this->assertNotNull($rowA);

        $child25 = collect($rowA['children'])->firstWhere('date', '2026-05-25');
        $this->assertNull($child25, 'Return on May 25 from Seller B must not leak into Seller A children');
    }

    // Test 5 — Sales channel filter không leak
    public function test_sales_channel_filter_excludes_other_channel_returns(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();

        $invFB = $this->invoice($empA, $product, 5000000, 'Facebook', '2026-05-20 10:00:00');
        $invSP = $this->invoice($empA, $product, 8000000, 'Shopee', '2026-05-21 10:00:00');
        $this->returnFor($invSP, 3000000, '2026-05-22 10:00:00');

        $res = $this->actingAs($admin)->get('/reports/sales?concern=employee&view=report&period=custom&date_from=2026-05-01&date_to=2026-05-31&sales_channel=Facebook');
        $res->assertOk();
        $chartData = $res->viewData('page')['props']['chartData'];

        $rowA = collect($chartData['rows'])->firstWhere('id', "employee:{$empA->id}");
        $this->assertNotNull($rowA);

        $child22 = collect($rowA['children'])->firstWhere('date', '2026-05-22');
        $this->assertNull($child22, 'Return on Shopee on May 22 must not leak under Facebook channel filter');
    }

    // Test 6 — Branch filter đúng
    public function test_branch_filter_applies_correctly(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();
        $branch1 = Branch::create(['name' => 'Chi nhánh 1']);
        $branch2 = Branch::create(['name' => 'Chi nhánh 2']);

        $this->invoice($empA, $product, 5000000, 'Bán trực tiếp', '2026-05-20 10:00:00', $branch1->id);
        $this->invoice($empA, $product, 3000000, 'Bán trực tiếp', '2026-05-21 10:00:00', $branch2->id);

        $res = $this->actingAs($admin)->get("/reports/sales?concern=employee&view=report&period=custom&date_from=2026-05-01&date_to=2026-05-31&branch_id={$branch1->id}");
        $res->assertOk();
        $chartData = $res->viewData('page')['props']['chartData'];

        $rowA = collect($chartData['rows'])->firstWhere('id', "employee:{$empA->id}");
        $this->assertNotNull($rowA);
        $this->assertEquals(5000000, $rowA['revenue']);
        
        $child21 = collect($rowA['children'])->firstWhere('date', '2026-05-21');
        $this->assertNull($child21, 'Invoice on branch 2 must be excluded when filtering by branch 1');
    }

    // Test 7 — invoice_url có đủ filter
    public function test_daily_child_invoice_url_has_all_filters(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();
        $branch = Branch::create(['name' => 'Chi nhánh A']);

        $this->invoice($empA, $product, 5000000, 'Facebook', '2026-05-20 10:00:00', $branch->id);

        $res = $this->actingAs($admin)->get("/reports/sales?concern=employee&view=report&period=custom&date_from=2026-05-01&date_to=2026-05-31&branch_id={$branch->id}&sales_channel=Facebook");
        $res->assertOk();
        $chartData = $res->viewData('page')['props']['chartData'];

        $rowA = collect($chartData['rows'])->firstWhere('id', "employee:{$empA->id}");
        $this->assertNotNull($rowA);

        $child20 = collect($rowA['children'])->firstWhere('date', '2026-05-20');
        $this->assertNotNull($child20);

        $url = $child20['invoice_url'];
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);

        $this->assertEquals('custom', $params['date_filter'] ?? null);
        $this->assertEquals('2026-05-20', $params['date_from'] ?? null);
        $this->assertEquals('2026-05-20', $params['date_to'] ?? null);
        $this->assertEquals("employee:{$empA->id}", $params['seller_key'] ?? null);
        $this->assertEquals($branch->id, $params['branch_id'] ?? null);
        $this->assertEquals('Facebook', $params['sales_channel'] ?? null);
        $this->assertEquals('created_at', $params['sort_by'] ?? null);
        $this->assertEquals('desc', $params['sort_direction'] ?? null);
    }

    // Test 8 — Chart view vẫn hoạt động
    public function test_chart_view_still_works(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();

        $this->invoice($empA, $product, 5000000, 'Bán trực tiếp', '2026-05-20 10:00:00');

        $res = $this->actingAs($admin)->get('/reports/sales?concern=employee&view=chart&period=custom&date_from=2026-05-01&date_to=2026-05-31');
        $res->assertOk();
        $chartData = $res->viewData('page')['props']['chartData'];

        $this->assertContains('Seller A', $chartData['labels']);
        $this->assertEquals(5000000, $chartData['datasets'][0]['data'][0]);
    }

    // Test 9 — Các concern khác không bị vỡ
    public function test_other_concerns_not_broken(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();

        $this->invoice($empA, $product, 5000000, 'Bán trực tiếp', '2026-05-20 10:00:00');

        $concerns = ['time', 'profit', 'discount', 'returns'];

        foreach ($concerns as $concern) {
            $res = $this->actingAs($admin)->get("/reports/sales?concern={$concern}&view=report&period=custom&date_from=2026-05-01&date_to=2026-05-31");
            $res->assertOk();
            $chartData = $res->viewData('page')['props']['chartData'];
            $this->assertArrayNotHasKey('rows', $chartData);
        }
    }

    // Test 10 — Drilldown metadata when return only
    public function test_drilldown_metadata_when_return_only(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();

        // Invoice is created on a different day (May 10)
        $inv = $this->invoice($empA, $product, 4550000, 'Bán trực tiếp', '2026-05-10 10:00:00');
        // Return is created on May 20
        $this->returnFor($inv, 4550000, '2026-05-20 12:00:00');

        $res = $this->actingAs($admin)->get('/reports/sales?concern=employee&view=report&period=custom&date_from=2026-05-01&date_to=2026-05-31');
        $res->assertOk();
        $chartData = $res->viewData('page')['props']['chartData'];

        $rowA = collect($chartData['rows'])->firstWhere('id', "employee:{$empA->id}");
        $this->assertNotNull($rowA);

        $child20 = collect($rowA['children'])->firstWhere('date', '2026-05-20');
        $this->assertNotNull($child20);

        $this->assertEquals(0, $child20['revenue']);
        $this->assertEquals(4550000, $child20['returns']);
        $this->assertEquals(-4550000, $child20['net']);
        $this->assertEquals(0, $child20['invoice_count']);
        $this->assertEquals(1, $child20['return_count']);
        $this->assertTrue($child20['has_returns']);
        $this->assertFalse($child20['has_invoices']);
        $this->assertEquals('returns', $child20['drilldown_type']);
        
        $this->assertStringStartsWith('/returns?', $child20['drilldown_url']);
        $this->assertStringStartsWith('/returns?', $child20['return_url']);
        $this->assertStringStartsWith('/invoices?', $child20['invoice_url']);
    }

    // Test 11 — Drilldown metadata when invoice only
    public function test_drilldown_metadata_when_invoice_only(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();

        $this->invoice($empA, $product, 6580000, 'Bán trực tiếp', '2026-05-25 10:00:00');

        $res = $this->actingAs($admin)->get('/reports/sales?concern=employee&view=report&period=custom&date_from=2026-05-01&date_to=2026-05-31');
        $res->assertOk();
        $chartData = $res->viewData('page')['props']['chartData'];

        $rowA = collect($chartData['rows'])->firstWhere('id', "employee:{$empA->id}");
        $child25 = collect($rowA['children'])->firstWhere('date', '2026-05-25');
        $this->assertNotNull($child25);

        $this->assertEquals(1, $child25['invoice_count']);
        $this->assertEquals(0, $child25['return_count']);
        $this->assertTrue($child25['has_invoices']);
        $this->assertFalse($child25['has_returns']);
        $this->assertEquals('invoices', $child25['drilldown_type']);
        $this->assertStringStartsWith('/invoices?', $child25['drilldown_url']);
    }

    // Test 12 — Drilldown metadata when both invoice and return
    public function test_drilldown_metadata_when_both_invoice_and_return(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();

        $inv = $this->invoice($empA, $product, 5000000, 'Bán trực tiếp', '2026-05-25 10:00:00');
        $this->returnFor($inv, 2000000, '2026-05-25 11:00:00');

        $res = $this->actingAs($admin)->get('/reports/sales?concern=employee&view=report&period=custom&date_from=2026-05-01&date_to=2026-05-31');
        $res->assertOk();
        $chartData = $res->viewData('page')['props']['chartData'];

        $rowA = collect($chartData['rows'])->firstWhere('id', "employee:{$empA->id}");
        $child25 = collect($rowA['children'])->firstWhere('date', '2026-05-25');
        $this->assertNotNull($child25);

        $this->assertEquals(1, $child25['invoice_count']);
        $this->assertEquals(1, $child25['return_count']);
        $this->assertTrue($child25['has_invoices']);
        $this->assertTrue($child25['has_returns']);
        $this->assertEquals('invoices', $child25['drilldown_type']);
        $this->assertStringStartsWith('/invoices?', $child25['drilldown_url']);
    }

    // Test 13 — return_url has all filters
    public function test_daily_child_return_url_has_all_filters(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();
        $branch = Branch::create(['name' => 'Chi nhánh A']);

        $inv = $this->invoice($empA, $product, 5000000, 'Facebook', '2026-05-20 10:00:00', $branch->id);
        $this->returnFor($inv, 3000000, '2026-05-20 11:00:00', $branch->id);

        $res = $this->actingAs($admin)->get("/reports/sales?concern=employee&view=report&period=custom&date_from=2026-05-01&date_to=2026-05-31&branch_id={$branch->id}&sales_channel=Facebook");
        $res->assertOk();
        $chartData = $res->viewData('page')['props']['chartData'];

        $rowA = collect($chartData['rows'])->firstWhere('id', "employee:{$empA->id}");
        $child20 = collect($rowA['children'])->firstWhere('date', '2026-05-20');
        $this->assertNotNull($child20);

        $url = $child20['return_url'];
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);

        $this->assertEquals('custom', $params['date_filter'] ?? null);
        $this->assertEquals('2026-05-20', $params['date_from'] ?? null);
        $this->assertEquals('2026-05-20', $params['date_to'] ?? null);
        $this->assertEquals("employee:{$empA->id}", $params['seller_key'] ?? null);
        $this->assertEquals($branch->id, $params['branch_id'] ?? null);
        $this->assertEquals('Facebook', $params['sales_channel'] ?? null);
        $this->assertEquals('created_at', $params['sort_by'] ?? null);
        $this->assertEquals('desc', $params['sort_direction'] ?? null);
    }

    // Test 14 — returns index filters by seller_key correctly
    public function test_returns_index_filters_by_seller_key_correctly(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $empB  = $this->employee('Seller B');
        $product = $this->product();

        $invA = $this->invoice($empA, $product, 5000000, 'Bán trực tiếp', '2026-05-20 10:00:00');
        $invB = $this->invoice($empB, $product, 6000000, 'Bán trực tiếp', '2026-05-20 11:00:00');

        $retA = $this->returnFor($invA, 2000000, '2026-05-20 12:00:00');
        $retB = $this->returnFor($invB, 3000000, '2026-05-20 13:00:00');

        // Request with seller_key for A
        $resA = $this->actingAs($admin)->get("/returns?seller_key=employee:{$empA->id}");
        $resA->assertOk();
        $returnsDataA = $resA->viewData('page')['props']['returns']['data'];
        $this->assertCount(1, $returnsDataA);
        $this->assertEquals($retA->id, $returnsDataA[0]['id']);

        // Request with seller_key for B
        $resB = $this->actingAs($admin)->get("/returns?seller_key=employee:{$empB->id}");
        $resB->assertOk();
        $returnsDataB = $resB->viewData('page')['props']['returns']['data'];
        $this->assertCount(1, $returnsDataB);
        $this->assertEquals($retB->id, $returnsDataB[0]['id']);
    }
}
