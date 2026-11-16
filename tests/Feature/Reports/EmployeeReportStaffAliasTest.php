<?php

namespace Tests\Feature\Reports;

use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An account logged in by mistake on a staff machine ("Claude AI") has its
 * documents shown under the real staff in the sales-by-staff report, without
 * touching any invoice or return.
 */
class EmployeeReportStaffAliasTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/reports/employees?concern=sales&view=report&period=custom&date_from=2025-09-01&date_to=2025-09-30';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_aliased_account_figures_are_merged_into_target_staff(): void
    {
        $admin = $this->user('Admin Test');
        $claude = $this->user('Claude AI '.Str::random(5));
        $tienUser = $this->user('Trần Văn Tiến '.Str::random(5));
        $tien = Employee::create(['code' => 'NV-'.uniqid(), 'name' => $tienUser->name, 'is_active' => true, 'user_id' => $tienUser->id]);
        $product = $this->product();

        // Claude AI sold as an admin without employee record (name snapshot).
        $claudeInvoice = $this->invoice(null, $claude->name, $product, 3_000_000, '2025-09-10 10:00:00');
        $this->invoice(null, $claude->name, $product, 2_000_000, '2025-09-11 10:00:00');
        $this->invoice($tien->id, $tien->name, $product, 5_000_000, '2025-09-10 15:00:00');
        $return = OrderReturn::create([
            'code' => 'TR-'.uniqid(),
            'invoice_id' => $claudeInvoice->id,
            'subtotal' => 1_000_000,
            'discount' => 0,
            'fee' => 0,
            'total' => 1_000_000,
            'status' => 'Hoàn thành',
            'seller_name' => $claude->name,
        ]);
        $return->created_at = Carbon::parse('2025-09-12 09:00:00');
        $return->save();

        // Without alias: two separate rows.
        config()->set('reports.staff_aliases', []);
        $rows = collect($this->actingAs($admin)->get(self::URL)->assertOk()->viewData('page')['props']['reportRows']);
        $this->assertEquals(4_000_000, $rows->firstWhere('id', "snapshot:{$claude->name}")['net']);
        $this->assertEquals(5_000_000, $rows->firstWhere('id', "employee:{$tien->id}")['net']);

        config()->set('reports.staff_aliases', [$claude->id => $tienUser->id]);
        $page = $this->actingAs($admin)->get(self::URL)->assertOk()->viewData('page')['props'];
        $rows = collect($page['reportRows']);

        $this->assertNull($rows->firstWhere('id', "snapshot:{$claude->name}"));
        $tienRow = $rows->firstWhere('id', "employee:{$tien->id}");
        $this->assertEquals(10_000_000, $tienRow['revenue']);
        $this->assertEquals(1_000_000, $tienRow['returns']);
        $this->assertEquals(9_000_000, $tienRow['net']);
        $this->assertEquals(8_000_000, collect($tienRow['children'])->firstWhere('date', '2025-09-10')['revenue']);
        $this->assertFalse(collect($page['employees'])->contains('key', "snapshot:{$claude->name}"));

        // Filtering by the target staff includes the aliased documents.
        $filtered = collect($this->actingAs($admin)->get(self::URL.'&employee_id=employee:'.$tien->id)
            ->assertOk()->viewData('page')['props']['reportRows']);
        $this->assertCount(1, $filtered);
        $this->assertEquals(9_000_000, $filtered->first()['net']);

        // Report-level only: the documents keep their original seller.
        $this->assertNull($claudeInvoice->fresh()->created_by);
        $this->assertSame($claude->name, $claudeInvoice->fresh()->seller_name);
    }

    public function test_env_value_is_parsed_into_alias_map(): void
    {
        putenv('REPORT_STAFF_ALIASES=19:5, 7:7 ,x:3,20:6');
        try {
            $config = require base_path('config/reports.php');
        } finally {
            putenv('REPORT_STAFF_ALIASES');
        }

        $this->assertSame([19 => 5, 20 => 6], $config['staff_aliases']);
    }

    private function user(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => 'alias-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'sku' => 'SKU-'.uniqid(),
            'name' => 'SP Test',
            'cost_price' => 500_000,
            'retail_price' => 1_000_000,
            'stock_quantity' => 1000,
            'inventory_total_cost' => 500_000_000,
            'has_serial' => false,
        ]);
    }

    private function invoice(?int $sellerId, string $sellerName, Product $product, float $total, string $date): Invoice
    {
        $invoice = Invoice::create([
            'code' => 'HD-'.uniqid(),
            'created_by' => $sellerId,
            'seller_name' => $sellerName,
            'created_by_name' => $sellerName,
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'customer_paid' => $total,
            'status' => 'Hoàn thành',
            'sales_channel' => 'Bán trực tiếp',
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $total,
            'cost_price' => 500_000,
        ]);
        $invoice->created_at = Carbon::parse($date);
        $invoice->save();

        return $invoice;
    }
}
