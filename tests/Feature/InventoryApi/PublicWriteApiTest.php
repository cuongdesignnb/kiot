<?php

namespace Tests\Feature\InventoryApi;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\InventoryApiAccessLog;
use App\Models\InventoryApiToken;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\PublicApiWriteLog;
use App\Models\SerialImei;
use App\Models\User;
use App\Services\Integrations\InventoryApi\InventoryApiTokenService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicWriteApiTest extends TestCase
{
    use DatabaseTransactions;

    private const PRICE = '/api/public/v1/write/products/%s/technician-price';

    private const INVOICES = '/api/public/v1/write/invoices';

    private User $admin;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('integrations.inventory_api.enabled', true);
        config()->set('integrations.inventory_api.rate_limit_per_minute', 1000);
        config()->set('integrations.inventory_api.write_rate_limit_per_minute', 1000);
        config()->set('integrations.inventory_api.failed_auth_per_minute', 100);
        $this->admin = User::factory()->create();
        $this->seller = User::factory()->create(['name' => 'Người Bán API '.Str::random(5), 'status' => 'active']);
    }

    // ───────────────────── Token abilities / settings ─────────────────────

    public function test_settings_create_token_with_selected_abilities_and_page_lists_write_logs(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/settings/integrations/inventory-api/tokens', [
            'name' => 'Sheet Doanh số',
            'abilities' => [InventoryApiToken::ABILITY_DATA_READ, InventoryApiToken::ABILITY_INVOICES_CREATE],
        ]);
        $response->assertCreated()->assertJsonPath('token.abilities', [
            InventoryApiToken::ABILITY_DATA_READ,
            InventoryApiToken::ABILITY_INVOICES_CREATE,
        ]);

        $this->actingAs($this->admin)->postJson('/settings/integrations/inventory-api/tokens', [
            'name' => 'Bad', 'abilities' => ['products:delete'],
        ])->assertStatus(422);

        [, $plain] = $this->makeToken([InventoryApiToken::ABILITY_TECHNICIAN_PRICE_WRITE]);
        $product = $this->makeProduct();
        $this->patchJson(sprintf(self::PRICE, $product->sku), ['technician_price' => 123000], $this->bearer($plain))->assertOk();

        $this->actingAs($this->admin)->get('/settings/integrations/inventory-api')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Integrations/InventoryApi')
                ->where('write_logs.0.action', PublicApiWriteLog::ACTION_TECHNICIAN_PRICE_UPDATE)
                ->where('write_logs.0.target_ref', $product->sku)
                ->where('write_logs.0.status_code', 200)
                ->has('ability_labels'));
    }

    public function test_write_routes_need_their_own_ability_and_method(): void
    {
        [, $readOnly] = $this->makeToken([InventoryApiToken::ABILITY_INVENTORY_READ, InventoryApiToken::ABILITY_DATA_READ]);
        [, $priceOnly] = $this->makeToken([InventoryApiToken::ABILITY_TECHNICIAN_PRICE_WRITE]);
        $product = $this->makeProduct();
        $path = sprintf(self::PRICE, $product->sku);

        $this->patchJson($path, ['technician_price' => 1], $this->bearer($readOnly))
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->postJson(self::INVOICES, [], $this->bearer($priceOnly) + ['Idempotency-Key' => 'key-'.Str::random(10)])
            ->assertForbidden();
        $this->getJson($path, $this->bearer($priceOnly))
            ->assertStatus(405)->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
        foreach (['postJson', 'putJson', 'deleteJson'] as $method) {
            $this->{$method}($path, ['technician_price' => 1], $this->bearer($priceOnly))
                ->assertStatus(405)->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
        }
        $this->getJson('/api/public/v1/inventory/products', $this->bearer($priceOnly))->assertForbidden();
        $this->assertSame(0.0, (float) $product->fresh()->technician_price);
        $this->assertTrue(InventoryApiAccessLog::query()->where('outcome', 'method_not_allowed')->exists());
    }

    public function test_write_calls_have_a_separate_stricter_rate_limit(): void
    {
        config()->set('integrations.inventory_api.write_rate_limit_per_minute', 2);
        [, $plain] = $this->makeToken([InventoryApiToken::ABILITY_TECHNICIAN_PRICE_WRITE]);
        $product = $this->makeProduct();
        $path = sprintf(self::PRICE, $product->sku);

        $this->patchJson($path, ['technician_price' => 1000], $this->bearer($plain))->assertOk()->assertHeader('X-RateLimit-Limit', '2');
        $this->patchJson($path, ['technician_price' => 2000], $this->bearer($plain))->assertOk();
        $this->patchJson($path, ['technician_price' => 3000], $this->bearer($plain))
            ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertSame(2000.0, (float) $product->fresh()->technician_price);
    }

    // ───────────────────── Technician price ─────────────────────

    public function test_technician_price_updates_only_that_field_and_is_logged(): void
    {
        [$token, $plain] = $this->makeToken([InventoryApiToken::ABILITY_TECHNICIAN_PRICE_WRITE]);
        $product = $this->makeProduct();
        $product->forceFill(['technician_price' => 900000])->save();
        $before = $product->fresh()->only(['retail_price', 'cost_price', 'stock_quantity', 'name']);

        $response = $this->patchJson(sprintf(self::PRICE, $product->sku), [
            'technician_price' => 950000,
            'note' => 'Sheet giá thợ dòng 12',
        ], $this->bearer($plain));

        $response->assertOk()
            ->assertJsonPath('data.sku', $product->sku)
            ->assertJsonPath('data.technician_price_old', 900000)
            ->assertJsonPath('data.technician_price_new', 950000);
        $this->assertSame(950000.0, (float) $product->fresh()->technician_price);
        $this->assertSame($before, $product->fresh()->only(['retail_price', 'cost_price', 'stock_quantity', 'name']));
        $log = PublicApiWriteLog::findOrFail($response->json('data.log_id'));
        $this->assertSame($token->id, $log->inventory_api_token_id);
        $this->assertSame(['technician_price' => 900000], $log->before);
        $this->assertSame(950000, $log->after['technician_price']);
        $this->assertSame(200, $log->status_code);
        $this->assertTrue(ActivityLog::where('action', 'public_api.technician_price_updated')->where('subject_id', $product->id)->exists());

        $this->patchJson(sprintf(self::PRICE, $product->sku), ['technician_price' => 1, 'retail_price' => 1], $this->bearer($plain))
            ->assertStatus(422)->assertJsonPath('error.code', 'UNKNOWN_FIELDS');
        $this->patchJson(sprintf(self::PRICE, $product->sku), ['technician_price' => -5], $this->bearer($plain))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->patchJson(sprintf(self::PRICE, 'NO-SUCH-SKU'), ['technician_price' => 1], $this->bearer($plain))
            ->assertNotFound()->assertJsonPath('error.code', 'PRODUCT_NOT_FOUND');
        $this->assertSame(950000.0, (float) $product->fresh()->technician_price);
        $this->assertSame(4, PublicApiWriteLog::where('inventory_api_token_id', $token->id)->count());
        $this->assertSame(404, PublicApiWriteLog::where('inventory_api_token_id', $token->id)->latest('id')->first()->status_code);
    }

    // ───────────────────── Invoice create ─────────────────────

    public function test_invoice_create_happy_path_with_new_customer_and_serial_line(): void
    {
        [$token, $plain] = $this->makeToken([InventoryApiToken::ABILITY_INVOICES_CREATE]);
        $plainProduct = $this->makeProduct(['stock_quantity' => 5]);
        $serialProduct = $this->makeProduct(['has_serial' => true, 'stock_quantity' => 1, 'inventory_total_cost' => 4000000, 'cost_price' => 4000000]);
        $serial = SerialImei::create([
            'product_id' => $serialProduct->id, 'serial_number' => 'APISN'.strtoupper(Str::random(8)),
            'status' => 'in_stock', 'cost_price' => 4000000,
        ]);
        $phone = '09'.random_int(10000000, 99999999);
        $body = [
            'items' => [
                ['sku' => $plainProduct->sku, 'quantity' => 2, 'price' => 800000, 'discount' => 100000],
                ['serial' => $serial->serial_number, 'price' => 12000000, 'warranty_policy' => '3 tháng'],
            ],
            'customer' => ['phone' => $phone, 'name' => 'Khách Sheet API'],
            'seller' => ['user_id' => $this->seller->id],
            'customer_paid' => 10000000,
            'payment_method' => 'transfer',
            'source_note' => 'Sheet Doanh số dòng 123',
            // Client totals are ignored.
            'total' => 1,
        ];
        $key = 'sheet-row-123-'.Str::random(6);

        $response = $this->postJson(self::INVOICES, $body, $this->bearer($plain) + ['Idempotency-Key' => $key]);

        $response->assertCreated()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('data.total', 13500000)
            ->assertJsonPath('data.customer_paid', 10000000)
            ->assertJsonPath('data.remaining', 3500000);
        $invoice = Invoice::with('items')->findOrFail($response->json('data.invoice_id'));
        $customer = Customer::where('phone', $phone)->firstOrFail();
        $response->assertJsonPath('data.customer_code', $customer->code)->assertJsonPath('data.invoice_code', $invoice->code);
        $this->assertSame('Khách Sheet API', $customer->name);
        $this->assertSame('API: '.$token->name, $invoice->created_by_name);
        $this->assertSame($this->seller->name, $invoice->seller_name);
        $this->assertSame('[API '.$token->name.'] Sheet Doanh số dòng 123', $invoice->note);
        $this->assertSame(13500000.0, (float) $invoice->subtotal);
        $this->assertSame(13500000.0, (float) $invoice->total);
        $this->assertSame(2, $invoice->items->count());
        $this->assertSame('sold', $serial->fresh()->status);
        $this->assertSame(3, (int) $plainProduct->fresh()->stock_quantity);
        $this->assertEqualsWithDelta(3500000.0, (float) $customer->fresh()->debt_amount, 0.01);
        $this->assertSame(1, CashFlow::where('reference_code', $invoice->code)->where('type', 'receipt')->count());
        $this->assertTrue(ActivityLog::where('action', 'public_api.invoice_created')->where('subject_id', $invoice->id)->exists());

        // Same key + same body -> replay, nothing new is created.
        $replay = $this->postJson(self::INVOICES, $body, $this->bearer($plain) + ['Idempotency-Key' => $key]);
        $replay->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('data.invoice_code', $invoice->code);
        $this->assertSame(1, Invoice::where('customer_id', $customer->id)->count());

        // Same key + other body -> conflict.
        $body['source_note'] = 'Dòng khác';
        $this->postJson(self::INVOICES, $body, $this->bearer($plain) + ['Idempotency-Key' => $key])
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame(1, Invoice::where('customer_id', $customer->id)->count());
    }

    public function test_invoice_create_rejections_are_422_and_logged_without_side_effects(): void
    {
        [$token, $plain] = $this->makeToken([InventoryApiToken::ABILITY_INVOICES_CREATE]);
        $product = $this->makeProduct(['stock_quantity' => 1]);
        $serialProduct = $this->makeProduct(['has_serial' => true, 'stock_quantity' => 1]);
        $sold = SerialImei::create([
            'product_id' => $serialProduct->id, 'serial_number' => 'APISOLD'.strtoupper(Str::random(6)), 'status' => 'sold',
        ]);
        $existing = Customer::create([
            'code' => 'KHAPI'.strtoupper(Str::random(8)), 'name' => 'Khách cũ', 'phone' => '09'.random_int(10000000, 99999999),
            'is_customer' => true, 'status' => 'active',
        ]);
        $invoiceCount = Invoice::count();
        $base = fn (array $overrides = []) => array_replace([
            'items' => [['sku' => $product->sku, 'quantity' => 1, 'price' => 500000]],
            'customer' => ['code' => $existing->code],
            'seller' => ['user_id' => $this->seller->id],
            'customer_paid' => 500000,
        ], $overrides);
        $post = fn (array $body, ?string $key = null) => $this->postJson(
            self::INVOICES,
            $body,
            $this->bearer($plain) + ($key === '' ? [] : ['Idempotency-Key' => $key ?? 'k-'.Str::random(16)]),
        );

        $post($base(), '')->assertStatus(422)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
        $post($base(['items' => [['sku' => $product->sku, 'quantity' => 2, 'price' => 500000]], 'customer_paid' => 1000000]))
            ->assertStatus(422)->assertJsonPath('error.code', 'STOCK_INSUFFICIENT');
        $post($base(['items' => [['sku' => $serialProduct->sku, 'quantity' => 1, 'price' => 500000]]]))
            ->assertStatus(422)->assertJsonPath('error.code', 'SERIAL_REQUIRED');
        $post($base(['items' => [['serial' => $sold->serial_number, 'price' => 500000]]]))
            ->assertStatus(422)->assertJsonPath('error.code', 'SERIAL_NOT_AVAILABLE');
        $post($base(['items' => [['sku' => 'NO-SUCH', 'price' => 1]]]))
            ->assertStatus(422)->assertJsonPath('error.code', 'PRODUCT_NOT_FOUND');
        $post($base(['seller' => ['name' => 'Không Có Người Này '.Str::random(4)]]))
            ->assertStatus(422)->assertJsonPath('error.code', 'SELLER_NOT_FOUND');
        $post($base(['customer' => ['phone' => '0911'.random_int(100000, 999999)]]))
            ->assertStatus(422)->assertJsonPath('error.code', 'CUSTOMER_NOT_FOUND');
        $post($base(['customer' => null, 'customer_paid' => 0]))
            ->assertStatus(422)->assertJsonPath('error.code', 'CUSTOMER_REQUIRED');
        $post($base(['customer_paid' => 600000]))
            ->assertStatus(422)->assertJsonPath('error.code', 'OVERPAYMENT');
        $post($base(['sold_at' => now()->addDay()->toIso8601String()]))
            ->assertStatus(422)->assertJsonPath('error.code', 'SOLD_AT_IN_FUTURE');

        $this->assertSame($invoiceCount, Invoice::count());
        $this->assertSame(1, (int) $product->fresh()->stock_quantity);
        $this->assertSame(10, PublicApiWriteLog::where('inventory_api_token_id', $token->id)->whereNotNull('error_code')->count());
    }

    public function test_seller_resolves_to_linked_employee_and_khach_le_paid_in_full(): void
    {
        [, $plain] = $this->makeToken([InventoryApiToken::ABILITY_INVOICES_CREATE]);
        $employee = Employee::create([
            'code' => 'NVAPI'.Str::random(5), 'name' => 'NV '.$this->seller->name, 'user_id' => $this->seller->id, 'is_active' => true,
        ]);
        $product = $this->makeProduct();

        $response = $this->postJson(self::INVOICES, [
            'items' => [['sku' => $product->sku, 'quantity' => 1, 'price' => 300000]],
            'seller' => ['name' => $this->seller->name],
            'customer_paid' => 300000,
        ], $this->bearer($plain) + ['Idempotency-Key' => 'khach-le-'.Str::random(10)]);

        $response->assertCreated()->assertJsonPath('data.customer_code', null)->assertJsonPath('data.remaining', 0);
        $invoice = Invoice::findOrFail($response->json('data.invoice_id'));
        $this->assertSame($employee->id, (int) $invoice->created_by);
        $this->assertSame($employee->name, $invoice->seller_name);
        $this->assertNull($invoice->customer_id);
    }

    private function makeToken(array $abilities): array
    {
        return app(InventoryApiTokenService::class)->create('Write token '.Str::random(6), 'Laptop Plus', $this->admin->id, $abilities);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'sku' => 'APIW-'.strtoupper(Str::random(10)),
            'name' => 'Public Write Product',
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
