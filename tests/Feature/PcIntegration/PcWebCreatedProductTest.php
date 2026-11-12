<?php

namespace Tests\Feature\PcIntegration;

use App\Http\Controllers\OrderController;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ExternalInventoryReservation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PcWebCreatedProductTest extends PcIntegrationTestCase
{
    private const PATH = '/api/integrations/v1/pc/orders';

    public function test_unknown_sku_is_auto_created_with_flag_category_log_and_order(): void
    {
        $sku = 'WEB-NEW-'.strtoupper(Str::random(8));
        $payload = $this->webOrderPayload($sku, 'Laptop Test 14 inch (bảo hành 24 tháng)', 900000);

        $this->postSignedJson(self::PATH, $payload, 'idem-'.Str::uuid())
            ->assertCreated()
            ->assertJsonPath('duplicate', false);

        $product = Product::query()->where('sku', $sku)->firstOrFail();
        $this->assertSame('Laptop Test 14 inch', $product->name);
        $this->assertSame(900000.0, (float) $product->retail_price);
        $this->assertSame(0.0, (float) $product->cost_price);
        $this->assertSame(0, (int) $product->stock_quantity);
        $this->assertSame('standard', $product->type);
        $this->assertTrue($product->is_active);
        $this->assertTrue($product->sell_directly);
        $this->assertTrue($product->cost_pending);
        $this->assertNotNull($product->created_from_web_at);
        $this->assertSame(Product::WEB_PENDING_CATEGORY_NAME, $product->category?->name);
        $this->assertFalse((bool) $product->category->show_on_pc_website);

        $this->assertTrue(ActivityLog::query()
            ->where('action', ActivityLog::ACTION_EXTERNAL_PRODUCT_CREATED)
            ->where('subject_type', Product::class)
            ->where('subject_id', $product->id)
            ->where('description', "Web tạo hàng {$sku} từ đơn {$payload['external_order_code']}")
            ->exists());

        $order = Order::query()->where('external_order_id', $payload['external_order_id'])->firstOrFail();
        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
        $this->assertSame(1, $order->items()->where('product_id', $product->id)->count());
        $this->assertSame(
            ExternalInventoryReservation::STATUS_ACTIVE,
            $order->externalInventoryReservations()->firstOrFail()->status,
        );
    }

    public function test_catalog_fields_win_and_existing_category_is_reused(): void
    {
        $existing = Category::query()->firstOrCreate(['name' => Product::WEB_PENDING_CATEGORY_NAME, 'parent_id' => null]);
        $sku = 'WEB-NEW-'.strtoupper(Str::random(8));
        $payload = $this->webOrderPayload($sku, 'Camera X (bảo hành 12 tháng)', 1200000, [
            'catalog_name' => 'Camera X chính hãng',
            'catalog_price' => 1000000,
        ]);

        $this->postSignedJson(self::PATH, $payload, 'idem-'.Str::uuid())->assertCreated();

        $product = Product::query()->where('sku', $sku)->firstOrFail();
        $this->assertSame('Camera X chính hãng', $product->name);
        $this->assertSame(1000000.0, (float) $product->retail_price);
        $this->assertSame($existing->id, $product->category_id);
        $this->assertSame(1, Category::query()->where('name', Product::WEB_PENDING_CATEGORY_NAME)->count());
    }

    public function test_repeated_order_is_idempotent_and_second_order_reuses_pending_product(): void
    {
        $sku = 'WEB-NEW-'.strtoupper(Str::random(8));
        $payload = $this->webOrderPayload($sku, 'Thẻ nhớ 128GB', 250000);
        $key = 'idem-'.Str::uuid();

        $this->postSignedJson(self::PATH, $payload, $key)->assertCreated();
        $this->postSignedJson(self::PATH, $payload, $key)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, Product::withTrashed()->where('sku', $sku)->count());
        $this->assertSame(1, Order::query()->where('external_order_id', $payload['external_order_id'])->count());
        $this->assertSame(1, ActivityLog::query()
            ->where('action', ActivityLog::ACTION_EXTERNAL_PRODUCT_CREATED)
            ->where('properties->sku', $sku)
            ->count());

        // A different order for the same still-pending product is accepted
        // even though its stock is 0, and does not create another product.
        $second = $this->webOrderPayload($sku, 'Thẻ nhớ 128GB', 250000);
        $this->postSignedJson(self::PATH, $second, 'idem-'.Str::uuid())->assertCreated();
        $this->assertSame(1, Product::withTrashed()->where('sku', $sku)->count());
        $this->assertSame(2, ExternalInventoryReservation::query()
            ->where('product_id', Product::query()->where('sku', $sku)->value('id'))
            ->where('status', ExternalInventoryReservation::STATUS_ACTIVE)
            ->count());
    }

    public function test_mixed_order_still_enforces_stock_for_existing_products(): void
    {
        $existing = $this->makeProduct(['sku' => 'PC-MIXED-'.strtoupper(Str::random(6)), 'stock_quantity' => 0]);
        $sku = 'WEB-NEW-'.strtoupper(Str::random(8));
        $payload = $this->orderPayload($existing, [
            'items' => [
                0 => ['sku' => $existing->sku],
                1 => [
                    'sku' => $sku, 'product_name' => 'Phần mềm', 'quantity' => 1,
                    'unit_price' => 0, 'discount' => 0, 'line_total' => 0, 'bundle_ref' => null,
                ],
            ],
        ]);

        $this->postSignedJson(self::PATH, $payload, 'idem-'.Str::uuid())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INSUFFICIENT_AVAILABLE_STOCK');

        // The whole import rolled back: no half-created product or order.
        $this->assertSame(0, Product::withTrashed()->where('sku', $sku)->count());
        $this->assertSame(0, Order::query()->where('external_order_id', $payload['external_order_id'])->count());
    }

    public function test_inactive_and_service_skus_are_not_auto_created(): void
    {
        $inactive = $this->makeProduct(['sku' => 'PC-WEB-INACTIVE', 'is_active' => false]);
        $this->postSignedJson(self::PATH, $this->orderPayload($inactive), 'idem-'.Str::uuid())
            ->assertStatus(422)->assertJsonPath('error.code', 'PRODUCT_INACTIVE');

        $service = $this->makeProduct(['sku' => 'PC-WEB-SERVICE', 'type' => 'service']);
        $this->postSignedJson(self::PATH, $this->orderPayload($service), 'idem-'.Str::uuid())
            ->assertStatus(422)->assertJsonPath('error.code', 'PRODUCT_NOT_SELLABLE');

        $this->assertSame(1, Product::withTrashed()->where('sku', 'PC-WEB-INACTIVE')->count());
        $this->assertSame(1, Product::withTrashed()->where('sku', 'PC-WEB-SERVICE')->count());
    }

    public function test_invoicing_is_blocked_while_cost_pending_and_allowed_after_purchase_receipt(): void
    {
        $sku = 'WEB-NEW-'.strtoupper(Str::random(8));
        $payload = $this->webOrderPayload($sku, 'Laptop chờ nhập', 900000);
        $this->postSignedJson(self::PATH, $payload, 'idem-'.Str::uuid())->assertCreated();
        $order = Order::query()->where('external_order_id', $payload['external_order_id'])->firstOrFail();
        $product = Product::query()->where('sku', $sku)->firstOrFail();
        $admin = $this->admin();

        $blocked = $this->convert($order, $admin);
        $this->assertSame(422, $blocked->getStatusCode(), $blocked->getContent());
        $this->assertStringContainsString(
            "Hàng {$sku} do web tạo chưa có phiếu nhập/giá vốn, hãy nhập hàng trước khi xuất hoá đơn.",
            (string) json_decode($blocked->getContent(), true)['message'],
        );
        $this->assertSame(0, Invoice::query()->where('order_id', $order->id)->count());
        $this->assertSame(Order::STATUS_CONFIRMED, $order->fresh()->status);
        $this->assertSame(ExternalInventoryReservation::STATUS_ACTIVE, $order->externalInventoryReservations()->first()->status);

        // Phiếu nhập hoàn thành → cờ "Chưa có giá vốn" tự xoá.
        $this->actingAs($admin)->post('/purchases', [
            'code' => 'PN-WEB-'.Str::random(8),
            'supplier_id' => $this->supplier()->id,
            'status' => 'completed',
            'purchase_date' => now()->toDateTimeString(),
            'discount' => 0,
            'paid_amount' => 0,
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'price' => 700000, 'discount' => 0]],
        ])->assertRedirect();

        $product->refresh();
        $this->assertFalse($product->cost_pending);
        $this->assertSame(700000.0, (float) $product->cost_price);
        $this->assertSame(2, (int) $product->stock_quantity);

        $allowed = $this->convert($order->fresh(), $admin);
        $this->assertSame(200, $allowed->getStatusCode(), $allowed->getContent());
        $invoice = Invoice::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(700000.0, (float) $invoice->items()->firstOrFail()->cost_price);
    }

    public function test_direct_pos_or_invoice_sale_is_blocked_while_cost_pending(): void
    {
        $sku = 'WEB-NEW-'.strtoupper(Str::random(8));
        $this->postSignedJson(self::PATH, $this->webOrderPayload($sku, 'Laptop bán thẳng', 500000), 'idem-'.Str::uuid())
            ->assertCreated();
        $product = Product::query()->where('sku', $sku)->firstOrFail();
        $invoicesBefore = Invoice::query()->count();

        try {
            app(\App\Services\InvoiceSaleService::class)->createSale([
                'customer_id' => null,
                'subtotal' => 500000,
                'total' => 500000,
                'customer_paid' => 500000,
                'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 500000, 'serial_ids' => []]],
            ], [
                'validate_before_purchase_date' => false,
                'validate_stock_setting' => false,
                'allow_oversell' => true,
            ]);
            $this->fail('Selling a cost-pending web product must be blocked.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString("Hàng {$sku} do web tạo chưa có phiếu nhập/giá vốn", $exception->getMessage());
        }
        $this->assertSame($invoicesBefore, Invoice::query()->count());
    }

    public function test_setting_a_cost_price_clears_cost_pending(): void
    {
        $sku = 'WEB-NEW-'.strtoupper(Str::random(8));
        $this->postSignedJson(self::PATH, $this->webOrderPayload($sku, 'Phần mềm diệt virus', 300000), 'idem-'.Str::uuid())
            ->assertCreated();
        $product = Product::query()->where('sku', $sku)->firstOrFail();
        $this->assertTrue($product->isCostPending());

        $product->update(['retail_price' => 310000]);
        $this->assertTrue($product->fresh()->cost_pending, 'A non-cost edit must keep the flag.');

        $product->update(['cost_price' => 150000]);
        $this->assertFalse($product->fresh()->cost_pending);
        $this->assertFalse($product->fresh()->isCostPending());
    }

    public function test_profit_reports_mark_web_created_lines_sold_without_cost(): void
    {
        $sku = 'WEB-NEW-'.strtoupper(Str::random(8));
        $payload = $this->webOrderPayload($sku, 'Thẻ nhớ quà tặng', 200000);
        $this->postSignedJson(self::PATH, $payload, 'idem-'.Str::uuid())->assertCreated();
        $order = Order::query()->where('external_order_id', $payload['external_order_id'])->firstOrFail();
        $product = Product::query()->where('sku', $sku)->firstOrFail();
        $admin = $this->admin();

        // A completed purchase receipt at price 0 (hàng tặng) clears the flag
        // and allows invoicing, but the line is still sold at cost 0.
        $this->actingAs($admin)->post('/purchases', [
            'code' => 'PN-WEB0-'.Str::random(8),
            'supplier_id' => $this->supplier()->id,
            'status' => 'completed',
            'purchase_date' => now()->toDateTimeString(),
            'discount' => 0,
            'paid_amount' => 0,
            'payment_method' => 'cash',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 0, 'discount' => 0]],
        ])->assertRedirect();
        $this->assertFalse($product->fresh()->cost_pending);
        $converted = $this->convert($order, $admin);
        $this->assertSame(200, $converted->getStatusCode(), $converted->getContent());

        $this->actingAs($admin)
            ->get('/reports/products?concern=profit&period=custom&date_from='.now()->subDay()->toDateString().'&date_to='.now()->addDay()->toDateString())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/ProductReport')
                ->where('chartData.costPendingProducts.0.sku', $sku)
                ->where('chartData.costPendingProducts.0.label', 'Chưa có giá vốn')
                ->where('chartData.charts.0.labels', fn ($labels) => collect($labels)->contains('Thẻ nhớ quà tặng (Chưa có giá vốn)')));

        $this->actingAs($admin)
            ->get('/reports/cost-profit?date_from='.now()->subDay()->toDateString().'&date_to='.now()->addDay()->toDateString())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/CostProfit')
                ->where('costPendingSales', fn ($rows) => collect($rows)->contains(fn ($row) => $row['sku'] === $sku && $row['label'] === 'Chưa có giá vốn')));
    }

    private function webOrderPayload(string $sku, string $productName, float $unitPrice, array $itemExtra = []): array
    {
        $item = array_merge([
            'sku' => $sku,
            'product_name' => $productName,
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'discount' => 0,
            'line_total' => $unitPrice,
            'bundle_ref' => null,
        ], $itemExtra);

        $payload = $this->orderPayload($this->makeProduct(), [
            'totals' => [
                'subtotal' => $unitPrice,
                'discount' => 0,
                'shipping_fee' => 30000,
                'total' => $unitPrice + 30000,
            ],
        ]);
        $payload['items'] = [$item];

        return $payload;
    }

    private function convert(Order $order, User $user)
    {
        $this->actingAs($user);
        $request = Request::create('/orders/'.$order->id.'/process', 'POST', [
            'amount_paid' => 0, 'payment_method' => 'cash',
        ], [], [], ['HTTP_ACCEPT' => 'application/json']);

        return app(OrderController::class)->processOrder($request, $order);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'PC Web Product Admin',
            'email' => 'pc-web-'.Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function supplier(): Customer
    {
        return Customer::create([
            'code' => 'NCC-WEB-'.uniqid(),
            'name' => 'Supplier Web Product',
            'phone' => '09'.random_int(10000000, 99999999),
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'total_bought' => 0,
        ]);
    }
}
