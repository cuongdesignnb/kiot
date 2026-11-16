<?php

namespace Tests\Feature\InventoryApi;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\InventoryApiAccessLog;
use App\Models\InventoryApiToken;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Task;
use App\Models\User;
use App\Services\Integrations\InventoryApi\InventoryApiTokenService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicDataApiTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = '/api/public/v1/data';

    private User $admin;

    private string $since;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('integrations.inventory_api.enabled', true);
        config()->set('integrations.inventory_api.rate_limit_per_minute', 1000);
        config()->set('integrations.inventory_api.failed_auth_per_minute', 100);
        $this->admin = User::factory()->create();
        // Rows created by this test live in the future so `updated_since`
        // isolates them from whatever the shared test database already holds.
        Carbon::setTestNow(Carbon::parse('2031-03-10 09:00:00'));
        $this->since = '2031-03-10T00:00:00+07:00';
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_data_endpoints_require_data_read_ability_and_are_read_only(): void
    {
        [, $inventoryOnly] = $this->makeToken([InventoryApiToken::ABILITY_INVENTORY_READ]);
        [, $dataOnly] = $this->makeToken([InventoryApiToken::ABILITY_DATA_READ]);

        $this->getJson(self::BASE.'/invoices')->assertUnauthorized()->assertJsonPath('error.code', 'MISSING_TOKEN');
        $this->getJson(self::BASE.'/invoices', $this->bearer($inventoryOnly))
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->getJson('/api/public/v1/inventory/products?limit=1', $this->bearer($dataOnly))
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->getJson(self::BASE.'/invoices?limit=1', $this->bearer($dataOnly))->assertOk();
        $this->postJson(self::BASE.'/invoices', [], $this->bearer($dataOnly))->assertStatus(405);
        $this->getJson(self::BASE.'/invoices?limit=500', $this->bearer($dataOnly))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // Data tokens cannot use the write functions.
        $this->patchJson('/api/public/v1/write/products/ANY/technician-price', ['technician_price' => 1], $this->bearer($dataOnly))
            ->assertForbidden();
        $this->postJson('/api/public/v1/write/invoices', [], $this->bearer($dataOnly))->assertForbidden();

        $this->assertTrue(InventoryApiAccessLog::query()->where('path', '/api/public/v1/data/invoices')->where('outcome', 'forbidden')->exists());
    }

    public function test_invoices_list_and_detail_return_documented_fields_with_integer_money(): void
    {
        [, $plain] = $this->makeToken();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $invoice = Invoice::create([
            'code' => 'HDAPI'.Str::random(8),
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'subtotal' => 1500000,
            'discount' => 0,
            'total' => 1500000,
            'customer_paid' => 1000000,
            'seller_name' => 'Nhân viên Bán API',
            'created_by_name' => 'Admin API',
            'sales_channel' => 'Bán trực tiếp',
            'payment_method' => 'cash',
            'transaction_date' => now(),
        ]);
        $invoice->items()->create([
            'product_id' => $product->id, 'quantity' => 2, 'price' => 750000, 'cost_price' => 500000.4,
            'discount' => 0, 'subtotal' => 1500000, 'warranty_policy' => '12 tháng',
        ]);

        $list = $this->getJson(self::BASE.'/invoices?updated_since='.urlencode($this->since).'&customer_code='.$customer->code, $this->bearer($plain));

        $list->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $invoice->code)
            ->assertJsonPath('data.0.customer.code', $customer->code)
            ->assertJsonPath('data.0.seller.name', 'Nhân viên Bán API')
            ->assertJsonPath('data.0.created_by_raw_name', 'Admin API')
            ->assertJsonPath('data.0.total', 1500000)
            ->assertJsonPath('data.0.customer_paid', 1000000)
            ->assertJsonPath('data.0.remaining', 500000)
            ->assertJsonPath('data.0.items.0.sku', $product->sku)
            ->assertJsonPath('data.0.items.0.quantity', 2)
            ->assertJsonPath('data.0.items.0.cost_price', 500000)
            ->assertJsonPath('data.0.items.0.warranty_policy', '12 tháng')
            ->assertJsonPath('meta.has_more', false);
        $this->assertIsInt($list->json('data.0.subtotal'));

        $this->getJson(self::BASE.'/invoices/'.$invoice->code, $this->bearer($plain))
            ->assertOk()->assertJsonPath('data.code', $invoice->code)->assertJsonPath('data.items.0.price', 750000);
        $this->getJson(self::BASE.'/invoices/HD-NOT-FOUND', $this->bearer($plain))
            ->assertNotFound()->assertJsonPath('error.code', 'INVOICE_NOT_FOUND');
    }

    public function test_seller_and_creator_names_use_report_staff_aliases_but_keep_raw_names(): void
    {
        [, $plain] = $this->makeToken();
        $bot = User::factory()->create(['name' => 'Claude AI '.Str::random(4), 'status' => 'active']);
        $owner = User::factory()->create(['name' => 'Trần Văn Tiến '.Str::random(4), 'status' => 'active']);
        \App\Models\Employee::create(['code' => 'NVAL'.Str::random(5), 'name' => $owner->name, 'user_id' => $owner->id, 'is_active' => true]);
        config()->set('reports.staff_aliases', [$bot->id => $owner->id]);
        $customer = $this->makeCustomer();
        Invoice::create([
            'code' => 'HDALIAS'.Str::random(6), 'customer_id' => $customer->id, 'status' => 'Hoàn thành',
            'subtotal' => 100000, 'discount' => 0, 'total' => 100000, 'customer_paid' => 100000,
            'seller_name' => $bot->name, 'created_by_name' => $bot->name,
        ]);

        $this->getJson(self::BASE.'/invoices?customer_code='.$customer->code, $this->bearer($plain))
            ->assertOk()
            ->assertJsonPath('data.0.seller.name', $owner->name)
            ->assertJsonPath('data.0.seller.raw_name', $bot->name)
            ->assertJsonPath('data.0.created_by_name', $owner->name)
            ->assertJsonPath('data.0.created_by_raw_name', $bot->name);
    }

    public function test_invoice_cursor_pagination_returns_every_row_once_and_respects_business_dates(): void
    {
        [, $plain] = $this->makeToken();
        $customer = $this->makeCustomer();
        $codes = [];
        foreach (range(1, 5) as $day) {
            $codes[] = Invoice::create([
                'code' => 'HDPAGE'.$day.Str::random(6),
                'customer_id' => $customer->id,
                'status' => 'Hoàn thành',
                'subtotal' => 100000, 'discount' => 0, 'total' => 100000, 'customer_paid' => 100000,
                'transaction_date' => Carbon::parse('2031-03-0'.$day.' 10:00:00'),
            ])->code;
        }

        $seen = [];
        $cursor = null;
        for ($page = 0; $page < 10; $page++) {
            $response = $this->getJson(self::BASE.'/invoices?limit=2&customer_code='.$customer->code.'&updated_since='.urlencode($this->since).($cursor ? '&cursor='.urlencode($cursor) : ''), $this->bearer($plain))->assertOk();
            $this->assertLessThanOrEqual(2, count($response->json('data')));
            $seen = [...$seen, ...array_column($response->json('data'), 'code')];
            if (! $response->json('meta.has_more')) {
                break;
            }
            $cursor = $response->json('meta.next_cursor');
        }
        sort($seen);
        sort($codes);
        $this->assertSame($codes, $seen);

        $ranged = $this->getJson(self::BASE.'/invoices?customer_code='.$customer->code.'&from=2031-03-02&to=2031-03-03', $this->bearer($plain))->assertOk();
        $this->assertCount(2, $ranged->json('data'));
    }

    public function test_returns_purchases_repairs_tasks_cashflows_debts_and_overview_happy_paths(): void
    {
        [, $plain] = $this->makeToken();
        $customer = $this->makeCustomer(['debt_amount' => 0]);
        $product = $this->makeProduct();
        $invoice = Invoice::create([
            'code' => 'HDRET'.Str::random(8), 'customer_id' => $customer->id, 'status' => 'Hoàn thành',
            'subtotal' => 500000, 'discount' => 0, 'total' => 500000, 'customer_paid' => 500000,
        ]);
        $return = OrderReturn::create([
            'code' => 'TH'.Str::random(8), 'invoice_id' => $invoice->id, 'customer_id' => $customer->id,
            'status' => 'completed', 'subtotal' => 500000, 'discount' => 0, 'fee' => 0, 'total' => 500000,
            'paid_to_customer' => 500000, 'created_by_name' => 'Admin API',
        ]);
        $return->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => 500000, 'discount' => 0, 'cost_price' => 300000]);

        $supplier = $this->makeCustomer(['is_supplier' => true, 'is_customer' => false]);
        $purchase = Purchase::create([
            'code' => 'PN'.Str::random(8), 'supplier_id' => $supplier->id, 'total_amount' => 900000,
            'discount' => 0, 'paid_amount' => 900000, 'debt_amount' => 0, 'status' => 'completed', 'purchase_date' => now(),
        ]);
        $purchase->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'product_code' => $product->sku,
            'quantity' => 3, 'price' => 300000, 'discount' => 0, 'subtotal' => 900000,
        ]);

        $repair = Task::create([
            'code' => 'SC'.Str::random(8), 'type' => Task::TYPE_REPAIR, 'title' => 'Sửa máy API',
            'status' => Task::STATUS_IN_PROGRESS, 'priority' => 'normal', 'product_id' => $product->id,
            'customer_id' => $customer->id, 'labor_fee' => 200000, 'total_amount' => 200000,
        ]);
        $task = Task::create([
            'code' => 'CV'.Str::random(8), 'type' => Task::TYPE_GENERAL, 'title' => 'Gọi khách API',
            'status' => Task::STATUS_PENDING, 'priority' => 'high', 'deadline' => now()->addDays(2),
        ]);
        $cashFlow = CashFlow::create([
            'code' => 'PTAPI'.Str::random(8), 'type' => 'receipt', 'amount' => 123456, 'time' => now(),
            'category' => 'Thu khác', 'target_type' => 'Khác', 'target_name' => 'Người nộp API',
            'payment_method' => 'cash', 'description' => 'Phiếu thu API',
        ]);
        $debtor = $this->makeCustomer(['debt_amount' => 0]);
        $debtor->forceFill(['debt_amount' => 0])->save();

        $query = '?updated_since='.urlencode($this->since);
        $returns = $this->getJson(self::BASE.'/returns'.$query, $this->bearer($plain))->assertOk();
        $this->assertContains($return->code, array_column($returns->json('data'), 'code'));
        $row = collect($returns->json('data'))->firstWhere('code', $return->code);
        $this->assertSame($invoice->code, $row['invoice_code']);
        $this->assertSame(300000, $row['items'][0]['cost_price']);

        $purchases = $this->getJson(self::BASE.'/purchases'.$query, $this->bearer($plain))->assertOk();
        $row = collect($purchases->json('data'))->firstWhere('code', $purchase->code);
        $this->assertSame($supplier->code, $row['supplier']['code']);
        $this->assertSame(3, $row['items'][0]['quantity']);

        $repairs = $this->getJson(self::BASE.'/repairs'.$query, $this->bearer($plain))->assertOk();
        $codes = array_column($repairs->json('data'), 'code');
        $this->assertContains($repair->code, $codes);
        $this->assertNotContains($task->code, $codes);

        $tasks = $this->getJson(self::BASE.'/tasks'.$query.'&type=general', $this->bearer($plain))->assertOk();
        $row = collect($tasks->json('data'))->firstWhere('code', $task->code);
        $this->assertSame('Gọi khách API', $row['title']);
        $this->assertNotNull($row['deadline']);

        $cashflows = $this->getJson(self::BASE.'/cashflows'.$query.'&type=receipt', $this->bearer($plain))->assertOk();
        $row = collect($cashflows->json('data'))->firstWhere('code', $cashFlow->code);
        $this->assertSame(123456, $row['amount']);
        $this->assertSame('active', $row['status']);

        $this->getJson(self::BASE.'/customer-debts?limit=5', $this->bearer($plain))
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['next_cursor', 'has_more', 'customer_debt_labels']]);

        $this->getJson(self::BASE.'/overview?from=2031-03-01&to=2031-03-31', $this->bearer($plain))
            ->assertOk()
            ->assertJsonPath('data.from', '2031-03-01')
            ->assertJsonStructure(['data' => ['gross_revenue', 'net_revenue', 'gross_profit', 'cogs_net', 'expenses', 'customer_receivable_unoffset']]);
        $this->getJson(self::BASE.'/overview?from=2031-03-31&to=2031-03-01', $this->bearer($plain))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_customer_debts_lists_only_partners_with_debt_and_both_labels(): void
    {
        [, $plain] = $this->makeToken();
        $customer = $this->makeCustomer();
        // A sale on credit gives the customer a real (canonical) receivable.
        $product = $this->makeProduct();
        app(\App\Services\InvoiceSaleService::class)->createSale([
            'customer_id' => $customer->id, 'subtotal' => 700000, 'discount' => 0, 'total' => 700000,
            'customer_paid' => 200000, 'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 700000, 'discount' => 0]],
        ], ['created_by_name' => 'Test']);

        $response = $this->getJson(self::BASE.'/customer-debts?customer_code='.$customer->code, $this->bearer($plain))->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $customer->code)
            ->assertJsonPath('data.0.receivable_gross', 500000)
            ->assertJsonPath('data.0.receivable_net', 500000)
            ->assertJsonPath('data.0.receivable_gross_label', 'Nợ phải thu (chưa bù trừ)');
    }

    private function makeToken(?array $abilities = null): array
    {
        return app(InventoryApiTokenService::class)->create(
            'Data token '.Str::random(6),
            'Laptop Plus',
            $this->admin->id,
            $abilities ?? [InventoryApiToken::ABILITY_INVENTORY_READ, InventoryApiToken::ABILITY_DATA_READ],
        );
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function makeCustomer(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'KHAPI'.strtoupper(Str::random(8)),
            'name' => 'Khách API',
            'phone' => '09'.random_int(10000000, 99999999),
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ], $attributes));
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'sku' => 'API-'.strtoupper(Str::random(10)),
            'name' => 'Public API Product',
            'type' => 'standard',
            'cost_price' => 500000,
            'retail_price' => 800000,
            'stock_quantity' => 10,
            'inventory_total_cost' => 5000000,
            'has_serial' => false,
            'is_active' => true,
            'sell_directly' => true,
        ], $attributes));
    }
}
