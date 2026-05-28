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

class EmployeeReportDailyBreakdownTest extends TestCase
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

    // Test 1 — Sales report trả children theo ngày
    public function test_sales_report_returns_daily_children(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();

        $this->invoice($empA, $product, 6820000, 'Bán trực tiếp', '2025-09-29 10:00:00');
        $this->invoice($empA, $product, 2700000, 'Bán trực tiếp', '2025-09-30 14:00:00');

        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report&period=custom&date_from=2025-09-01&date_to=2025-09-30');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $rowA = collect($rows)->firstWhere('id', "employee:{$empA->id}");
        $this->assertNotNull($rowA);
        $this->assertNotEmpty($rowA['children']);

        $child29 = collect($rowA['children'])->firstWhere('date', '2025-09-29');
        $child30 = collect($rowA['children'])->firstWhere('date', '2025-09-30');

        $this->assertNotNull($child29);
        $this->assertNotNull($child30);

        $this->assertEquals(6820000, $child29['revenue']);
        $this->assertEquals(2700000, $child30['revenue']);
    }

    // Test 2 — Parent totals bằng tổng children
    public function test_parent_totals_match_sum_of_children(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();

        $inv1 = $this->invoice($empA, $product, 5000000, 'Bán trực tiếp', '2025-09-20 10:00:00');
        $this->invoice($empA, $product, 3000000, 'Bán trực tiếp', '2025-09-21 14:00:00');
        
        $this->returnFor($inv1, 1500000, '2025-09-22 09:00:00');

        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report&period=custom&date_from=2025-09-01&date_to=2025-09-30');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $rowA = collect($rows)->firstWhere('id', "employee:{$empA->id}");
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

        $inv = $this->invoice($empA, $product, 5000000, 'Bán trực tiếp', '2025-09-20 10:00:00');
        $this->returnFor($inv, 1645000, '2025-09-27 15:00:00');

        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report&period=custom&date_from=2025-09-01&date_to=2025-09-30');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $rowA = collect($rows)->firstWhere('id', "employee:{$empA->id}");
        $this->assertNotNull($rowA);

        $child27 = collect($rowA['children'])->firstWhere('date', '2025-09-27');
        $this->assertNotNull($child27);
        $this->assertEquals(0, $child27['revenue']);
        $this->assertEquals(1645000, $child27['returns']);
        $this->assertEquals(-1645000, $child27['net']);
    }

    // Test 4 — Return seller khác không leak
    public function test_returns_from_other_sellers_dont_leak(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $empB  = $this->employee('Seller B');
        $product = $this->product();

        $this->invoice($empA, $product, 5000000, 'Bán trực tiếp', '2025-09-20 10:00:00');
        $invB = $this->invoice($empB, $product, 6000000, 'Bán trực tiếp', '2025-09-20 11:00:00');
        $this->returnFor($invB, 2000000, '2025-09-25 10:00:00');

        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report&period=custom&date_from=2025-09-01&date_to=2025-09-30&employee_id=employee:' . $empA->id);
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $rowA = collect($rows)->firstWhere('id', "employee:{$empA->id}");
        $this->assertNotNull($rowA);
        
        $child25 = collect($rowA['children'])->firstWhere('date', '2025-09-25');
        $this->assertNull($child25, 'Return on Sep 25 from Seller B must not leak into Seller A children');
        
        $keys = collect($rows)->pluck('id')->all();
        $this->assertNotContains("employee:{$empB->id}", $keys, 'Seller B must not be in the results when filtering by Seller A');
    }

    // Test 5 — Sales channel filter không leak return channel khác
    public function test_sales_channel_filter_excludes_other_channel_returns(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();

        $invFB = $this->invoice($empA, $product, 5000000, 'Facebook', '2025-09-20 10:00:00');
        $invSP = $this->invoice($empA, $product, 8000000, 'Shopee', '2025-09-21 10:00:00');
        $this->returnFor($invSP, 3000000, '2025-09-22 10:00:00');

        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report&period=custom&date_from=2025-09-01&date_to=2025-09-30&sales_channel=Facebook');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $rowA = collect($rows)->firstWhere('id', "employee:{$empA->id}");
        $this->assertNotNull($rowA);

        $child22 = collect($rowA['children'])->firstWhere('date', '2025-09-22');
        $this->assertNull($child22, 'Return on Shopee on Sep 22 must not leak under Facebook channel filter');
    }

    // Test 6 — Link ngày có đủ filter
    public function test_daily_child_invoice_url_has_all_filters(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();
        $branch = Branch::create(['name' => 'Chi nhánh A']);

        $this->invoice($empA, $product, 5000000, 'Facebook', '2025-09-20 10:00:00', $branch->id);

        $res = $this->actingAs($admin)->get("/reports/employees?concern=sales&view=report&period=custom&date_from=2025-09-01&date_to=2025-09-30&branch_id={$branch->id}&sales_channel=Facebook&employee_id=employee:{$empA->id}");
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $rowA = collect($rows)->firstWhere('id', "employee:{$empA->id}");
        $this->assertNotNull($rowA);

        $child20 = collect($rowA['children'])->firstWhere('date', '2025-09-20');
        $this->assertNotNull($child20);

        $url = $child20['invoice_url'];
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $params);

        $this->assertEquals('custom', $params['date_filter'] ?? null);
        $this->assertEquals('2025-09-20', $params['date_from'] ?? null);
        $this->assertEquals('2025-09-20', $params['date_to'] ?? null);
        $this->assertEquals("employee:{$empA->id}", $params['seller_key'] ?? null);
        $this->assertEquals($branch->id, $params['branch_id'] ?? null);
        $this->assertEquals('Facebook', $params['sales_channel'] ?? null);
        $this->assertEquals('created_at', $params['sort_by'] ?? null);
        $this->assertEquals('desc', $params['sort_direction'] ?? null);
    }

    // Test 7 — Profit report không bị đổi shape ngoài mong muốn
    public function test_profit_report_not_altered_incorrectly(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();

        $this->invoice($empA, $product, 5000000, 'Bán trực tiếp', '2025-09-20 10:00:00');

        $res = $this->actingAs($admin)->get('/reports/employees?concern=profit&view=report&period=custom&date_from=2025-09-01&date_to=2025-09-30');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $rowA = collect($rows)->firstWhere('id', "employee:{$empA->id}");
        $this->assertNotNull($rowA);
        $this->assertArrayHasKey('gross_revenue', $rowA);
        $this->assertArrayHasKey('invoice_discount', $rowA);
        $this->assertArrayHasKey('revenue_after_discount', $rowA);
        $this->assertArrayHasKey('return_value', $rowA);
        $this->assertArrayHasKey('net_revenue', $rowA);
        $this->assertArrayHasKey('total_cogs', $rowA);
        $this->assertArrayHasKey('gross_profit', $rowA);
    }

    // Test 8 — Items report không bị đổi shape ngoài mong muốn
    public function test_items_report_not_altered_incorrectly(): void
    {
        $admin = $this->admin();
        $empA  = $this->employee('Seller A');
        $product = $this->product();

        $this->invoice($empA, $product, 5000000, 'Bán trực tiếp', '2025-09-20 10:00:00');

        $res = $this->actingAs($admin)->get('/reports/employees?concern=items&view=report&period=custom&date_from=2025-09-01&date_to=2025-09-30');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $rowA = collect($rows)->firstWhere('id', "employee:{$empA->id}");
        $this->assertNotNull($rowA);
        $this->assertEquals(5000000, $rowA['revenue']);
        $this->assertEquals(1, $rowA['returns']); // Items quantity is returns field
    }
}
