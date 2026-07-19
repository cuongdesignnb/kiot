<?php

namespace Tests\Feature\PcIntegration;

use App\Http\Controllers\OrderController;
use App\Models\CashFlow;
use App\Models\ExternalInventoryReservation;
use App\Models\IntegrationEvent;
use App\Models\Invoice;
use App\Models\InvoiceItemSerial;
use App\Models\Order;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PcExternalOrderTest extends PcIntegrationTestCase
{
    public function test_import_creates_order_customer_items_reservation_and_no_financial_or_stock_side_effect(): void
    {
        $product = $this->makeProduct(['sku' => 'PC-ORDER-SUCCESS', 'stock_quantity' => 5]);
        $payload = $this->orderPayload($product, ['customer' => ['phone' => '+84 987 654 321']]);
        $stockBefore = $product->stock_quantity;

        $response = $this->postSignedJson('/api/integrations/v1/pc/orders', $payload, 'idem-'.Str::uuid());

        $response->assertCreated()->assertJsonPath('success', true)->assertJsonPath('duplicate', false);
        $order = Order::where('external_order_id', $payload['external_order_id'])->firstOrFail();
        $this->assertSame('pc_website', $order->external_source);
        $this->assertSame('confirmed', $order->status);
        $this->assertSame($this->integrationBranch->id, $order->branch_id);
        $this->assertSame(0.0, (float) $order->amount_paid);
        $this->assertSame('0987654321', $order->customer->phone);
        $this->assertTrue((bool) $order->customer->is_customer);
        $this->assertCount(1, $order->items);
        $this->assertNull($order->items->first()->serial_ids);
        $this->assertDatabaseHas('external_inventory_reservations', [
            'order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1, 'status' => 'active',
        ]);
        $this->assertSame((int) $stockBefore, (int) $product->fresh()->stock_quantity);
        $this->assertSame(0, Invoice::where('order_id', $order->id)->count());
        $this->assertSame(0, CashFlow::where('reference_code', $order->code)->count());
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
    }

    public function test_customer_is_reused_by_normalized_phone_without_overwriting_name(): void
    {
        $product = $this->makeProduct(['sku' => 'PC-CUSTOMER-REUSE']);
        $existing = \App\Models\Customer::create([
            'code' => 'KH-REUSE-'.Str::random(8), 'name' => 'Tên đang dùng', 'phone' => '0987654321',
            'email' => null, 'is_customer' => true, 'is_supplier' => true, 'status' => 'active',
        ]);
        $payload = $this->orderPayload($product, [
            'customer' => ['name' => 'Tên website', 'phone' => '+84 987 654 321', 'email' => 'new-email@example.test'],
        ]);

        $this->postSignedJson('/api/integrations/v1/pc/orders', $payload, 'idem-'.Str::uuid())->assertCreated();

        $order = Order::where('external_order_id', $payload['external_order_id'])->firstOrFail();
        $this->assertSame($existing->id, $order->customer_id);
        $this->assertSame('Tên đang dùng', $existing->fresh()->name);
        $this->assertSame('new-email@example.test', $existing->fresh()->email);
        $this->assertTrue((bool) $existing->fresh()->is_supplier);
    }

    public function test_duplicate_conflict_and_idempotency_conflict_contracts(): void
    {
        $product = $this->makeProduct(['sku' => 'PC-IDEMPOTENCY']);
        $payload = $this->orderPayload($product);
        $key = 'idem-'.Str::uuid();

        $this->postSignedJson('/api/integrations/v1/pc/orders', $payload, $key)->assertCreated();
        $this->postSignedJson('/api/integrations/v1/pc/orders', $payload, $key)
            ->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, Order::where('external_order_id', $payload['external_order_id'])->count());
        $this->assertSame(1, ExternalInventoryReservation::whereHas('order', fn ($query) => $query->where('external_order_id', $payload['external_order_id']))->count());

        $changed = $payload;
        $changed['event_id'] = (string) Str::uuid();
        $changed['note'] = 'Payload đã thay đổi';
        $this->postSignedJson('/api/integrations/v1/pc/orders', $changed, 'idem-'.Str::uuid())
            ->assertStatus(409)->assertJsonPath('error.code', 'EXTERNAL_ORDER_CONFLICT');

        $other = $this->orderPayload($product);
        $this->postSignedJson('/api/integrations/v1/pc/orders', $other, $key)
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
    }

    public function test_invalid_product_stock_and_total_are_rejected_without_orphans(): void
    {
        $product = $this->makeProduct(['sku' => 'PC-VALIDATION', 'stock_quantity' => 0]);
        $payload = $this->orderPayload($product);
        $ordersBefore = Order::count();

        $this->postSignedJson('/api/integrations/v1/pc/orders', $payload, 'idem-'.Str::uuid())
            ->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_AVAILABLE_STOCK');
        $this->assertSame($ordersBefore, Order::count());

        $unknown = $this->orderPayload($product, ['items' => [0 => ['sku' => 'UNKNOWN-PC-SKU']]]);
        $this->postSignedJson('/api/integrations/v1/pc/orders', $unknown, 'idem-'.Str::uuid())
            ->assertStatus(422)->assertJsonPath('error.code', 'UNKNOWN_SKU');

        $product->update(['stock_quantity' => 5]);
        $wrongTotal = $this->orderPayload($product, ['totals' => ['total' => 123]]);
        $this->postSignedJson('/api/integrations/v1/pc/orders', $wrongTotal, 'idem-'.Str::uuid())
            ->assertStatus(422)->assertJsonPath('error.code', 'ORDER_TOTAL_MISMATCH');

        $this->assertSame(0, ExternalInventoryReservation::where('product_id', $product->id)->count());
        $this->assertGreaterThanOrEqual(3, IntegrationEvent::where('source', 'pc_website')->count());
    }

    public function test_authenticated_invalid_payload_is_audited_without_sensitive_fields(): void
    {
        $product = $this->makeProduct(['sku' => 'PC-INVALID-AUDIT']);
        $payload = $this->orderPayload($product);
        unset($payload['customer']);
        $payload['secret'] = 'must-not-be-stored';

        $this->postSignedJson('/api/integrations/v1/pc/orders', $payload, 'idem-'.Str::uuid())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PAYLOAD');

        $event = IntegrationEvent::where('event_id', $payload['event_id'])->firstOrFail();
        $this->assertSame(IntegrationEvent::STATUS_FAILED, $event->status);
        $this->assertSame('INVALID_PAYLOAD', $event->last_error_code);
        $this->assertSame('[REDACTED]', $event->payload['secret']);
    }

    public function test_inactive_not_sellable_and_service_products_are_rejected(): void
    {
        foreach ([
            ['attributes' => ['sku' => 'PC-ORDER-INACTIVE', 'is_active' => false], 'code' => 'PRODUCT_INACTIVE'],
            ['attributes' => ['sku' => 'PC-ORDER-NOT-SELLABLE', 'sell_directly' => false], 'code' => 'PRODUCT_NOT_SELLABLE'],
            ['attributes' => ['sku' => 'PC-ORDER-SERVICE', 'type' => 'service'], 'code' => 'PRODUCT_NOT_SELLABLE'],
        ] as $case) {
            $product = $this->makeProduct($case['attributes']);
            $payload = $this->orderPayload($product);
            $this->postSignedJson('/api/integrations/v1/pc/orders', $payload, 'idem-'.Str::uuid())
                ->assertStatus(422)->assertJsonPath('error.code', $case['code']);
        }
    }

    public function test_inactive_merged_and_ambiguous_customers_fail_closed(): void
    {
        $product = $this->makeProduct(['sku' => 'PC-CUSTOMER-GUARDS']);

        \App\Models\Customer::create([
            'code' => 'KH-INACTIVE-'.Str::random(8), 'name' => 'Khách inactive',
            'phone' => '0987000001', 'is_customer' => true, 'status' => 'inactive',
        ]);
        $inactivePayload = $this->orderPayload($product, [
            'customer' => ['name' => 'Khách inactive', 'phone' => '0987000001', 'email' => null],
        ]);
        $this->postSignedJson('/api/integrations/v1/pc/orders', $inactivePayload, 'idem-'.Str::uuid())
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PAYLOAD');

        $mergeTarget = \App\Models\Customer::create([
            'code' => 'KH-MERGE-TARGET-'.Str::random(8), 'name' => 'Khách đích',
            'phone' => '0987000002', 'is_customer' => true, 'status' => 'active',
        ]);
        \App\Models\Customer::create([
            'code' => 'KH-MERGED-'.Str::random(8), 'name' => 'Khách đã merge',
            'phone' => '0987000003', 'is_customer' => true, 'status' => 'active',
            'merged_into_id' => $mergeTarget->id, 'merged_at' => now(),
        ]);
        $mergedPayload = $this->orderPayload($product, [
            'customer' => ['name' => 'Khách đã merge', 'phone' => '0987000003', 'email' => null],
        ]);
        $this->postSignedJson('/api/integrations/v1/pc/orders', $mergedPayload, 'idem-'.Str::uuid())
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PAYLOAD');

        foreach (['0987000004', '+84987000004'] as $index => $phone) {
            \App\Models\Customer::create([
                'code' => 'KH-AMBIGUOUS-'.$index.'-'.Str::random(6), 'name' => 'Khách trùng phone',
                'phone' => $phone, 'is_customer' => true, 'status' => 'active',
            ]);
        }
        $ambiguousPayload = $this->orderPayload($product, [
            'customer' => ['name' => 'Khách trùng phone', 'phone' => '0987000004', 'email' => null],
        ]);
        $this->postSignedJson('/api/integrations/v1/pc/orders', $ambiguousPayload, 'idem-'.Str::uuid())
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PAYLOAD');
    }

    public function test_status_and_cancel_release_reservation_without_increasing_stock(): void
    {
        $product = $this->makeProduct(['sku' => 'PC-CANCEL', 'stock_quantity' => 3]);
        $payload = $this->orderPayload($product);
        $this->postSignedJson('/api/integrations/v1/pc/orders', $payload, 'idem-'.Str::uuid())->assertCreated();
        $stockBefore = (int) $product->fresh()->stock_quantity;

        $statusPath = '/api/integrations/v1/pc/orders/'.$payload['external_order_id'];
        $this->getJson($statusPath, $this->signedHeaders('GET', $statusPath))
            ->assertOk()->assertJsonPath('data.reservation_status', 'active');

        $cancelPayload = ['event_id' => (string) Str::uuid(), 'reason' => 'Khách yêu cầu hủy'];
        $cancelPath = $statusPath.'/cancel';
        $cancelKey = 'cancel-'.Str::uuid();
        $this->postSignedJson($cancelPath, $cancelPayload, $cancelKey)
            ->assertOk()->assertJsonPath('duplicate', false);
        $this->postSignedJson($cancelPath, $cancelPayload, $cancelKey)
            ->assertOk()->assertJsonPath('duplicate', true);
        $this->postSignedJson($cancelPath, [
            'event_id' => (string) Str::uuid(), 'reason' => 'Sự kiện hủy mới',
        ], 'cancel-'.Str::uuid())
            ->assertStatus(409)->assertJsonPath('error.code', 'ORDER_ALREADY_CANCELLED');

        $order = Order::where('external_order_id', $payload['external_order_id'])->firstOrFail();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('released', $order->externalInventoryReservations()->first()->status);
        $this->assertSame($stockBefore, (int) $product->fresh()->stock_quantity);
    }

    public function test_cancel_rejects_internal_completed_and_invoiced_orders(): void
    {
        $internal = Order::create([
            'code' => 'DH-INTERNAL-'.Str::random(8),
            'branch_id' => $this->integrationBranch->id,
            'status' => Order::STATUS_CONFIRMED,
        ]);
        $internalPath = '/api/integrations/v1/pc/orders/INTERNAL-'.$internal->id.'/cancel';
        $this->postSignedJson($internalPath, [
            'event_id' => (string) Str::uuid(), 'reason' => 'Không được hủy đơn nội bộ',
        ], 'cancel-'.Str::uuid())
            ->assertNotFound()->assertJsonPath('error.code', 'EXTERNAL_ORDER_NOT_FOUND');

        $product = $this->makeProduct(['sku' => 'PC-CANCEL-GUARDS', 'stock_quantity' => 3]);
        $completedPayload = $this->orderPayload($product);
        $this->postSignedJson('/api/integrations/v1/pc/orders', $completedPayload, 'idem-'.Str::uuid())->assertCreated();
        $completed = Order::where('external_order_id', $completedPayload['external_order_id'])->firstOrFail();
        $completed->update(['status' => Order::STATUS_COMPLETED]);
        $completedPath = '/api/integrations/v1/pc/orders/'.$completedPayload['external_order_id'].'/cancel';
        $this->postSignedJson($completedPath, [
            'event_id' => (string) Str::uuid(), 'reason' => 'Không được hủy completed',
        ], 'cancel-'.Str::uuid())
            ->assertStatus(409)->assertJsonPath('error.code', 'ORDER_NOT_CANCELLABLE');

        $invoicedPayload = $this->orderPayload($product);
        $this->postSignedJson('/api/integrations/v1/pc/orders', $invoicedPayload, 'idem-'.Str::uuid())->assertCreated();
        $invoiced = Order::where('external_order_id', $invoicedPayload['external_order_id'])->firstOrFail();
        Invoice::create([
            'code' => 'HD-PC-CANCEL-'.Str::random(8),
            'order_id' => $invoiced->id,
            'branch_id' => $invoiced->branch_id,
            'subtotal' => $invoiced->total_price,
            'total' => $invoiced->total_payment,
            'customer_paid' => 0,
            'status' => 'Hoàn thành',
        ]);
        $invoicedPath = '/api/integrations/v1/pc/orders/'.$invoicedPayload['external_order_id'].'/cancel';
        $this->postSignedJson($invoicedPath, [
            'event_id' => (string) Str::uuid(), 'reason' => 'Không được hủy invoiced',
        ], 'cancel-'.Str::uuid())
            ->assertStatus(409)->assertJsonPath('error.code', 'ORDER_ALREADY_INVOICED');
    }

    public function test_external_order_conversion_consumes_reservation_and_deducts_stock_once(): void
    {
        $product = $this->makeProduct(['sku' => 'PC-CONVERT', 'stock_quantity' => 3]);
        $payload = $this->orderPayload($product);
        $this->postSignedJson('/api/integrations/v1/pc/orders', $payload, 'idem-'.Str::uuid())->assertCreated();
        $order = Order::where('external_order_id', $payload['external_order_id'])->firstOrFail();
        $user = User::create([
            'name' => 'PC Conversion User', 'email' => Str::uuid().'@example.test',
            'password' => bcrypt('password'), 'role_id' => null,
        ]);
        $this->actingAs($user);
        $request = Request::create('/orders/'.$order->id.'/process', 'POST', [
            'amount_paid' => 0, 'payment_method' => 'cash',
        ], [], [], ['HTTP_ACCEPT' => 'application/json']);

        $response = app(OrderController::class)->processOrder($request, $order);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $invoice = Invoice::where('order_id', $order->id)->firstOrFail();
        $this->assertSame($this->integrationBranch->id, $invoice->branch_id);
        $this->assertSame(2, (int) $product->fresh()->stock_quantity);
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->where('type', 'out_invoice')->count());
        $this->assertSame('consumed', $order->externalInventoryReservations()->first()->status);
        $this->assertSame(0, CashFlow::where('reference_code', $invoice->code)->count());
    }

    public function test_conversion_failure_after_reservation_consume_rolls_back_every_effect(): void
    {
        $product = $this->makeProduct(['sku' => 'PC-CONVERT-ROLLBACK', 'stock_quantity' => 3]);
        $payload = $this->orderPayload($product);
        $this->postSignedJson('/api/integrations/v1/pc/orders', $payload, 'idem-'.Str::uuid())->assertCreated();
        $order = Order::where('external_order_id', $payload['external_order_id'])->firstOrFail();
        $user = User::create([
            'name' => 'PC Rollback User', 'email' => Str::uuid().'@example.test',
            'password' => bcrypt('password'), 'role_id' => null,
        ]);
        $this->actingAs($user);
        config()->set('debt.mutation.failure_after', 'projection');
        $request = Request::create('/orders/'.$order->id.'/process', 'POST', [
            'amount_paid' => 0, 'payment_method' => 'cash',
        ], [], [], ['HTTP_ACCEPT' => 'application/json']);

        try {
            $response = app(OrderController::class)->processOrder($request, $order);
        } finally {
            config()->set('debt.mutation.failure_after');
        }

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNull(Invoice::where('order_id', $order->id)->first());
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame('active', $order->externalInventoryReservations()->first()->status);
        $this->assertSame(3, (int) $product->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('product_id', $product->id)->count());
    }

    public function test_serial_order_imports_pending_then_requires_explicit_serial_at_conversion(): void
    {
        $product = $this->makeProduct([
            'sku' => 'PC-SERIAL-ORDER', 'has_serial' => true, 'stock_quantity' => 1,
            'cost_price' => 500000, 'inventory_total_cost' => 500000,
        ]);
        $serial = SerialImei::create([
            'product_id' => $product->id, 'serial_number' => 'PC-SN-'.Str::random(10),
            'status' => 'in_stock', 'cost_price' => 500000, 'original_cost' => 500000,
        ]);
        $payload = $this->orderPayload($product);
        $this->postSignedJson('/api/integrations/v1/pc/orders', $payload, 'idem-'.Str::uuid())->assertCreated();
        $order = Order::where('external_order_id', $payload['external_order_id'])->firstOrFail();
        $this->assertNull($order->items()->first()->serial_ids);

        $user = User::create([
            'name' => 'PC Serial User', 'email' => Str::uuid().'@example.test',
            'password' => bcrypt('password'), 'role_id' => null,
        ]);
        $this->actingAs($user);
        $missing = Request::create('/orders/'.$order->id.'/process', 'POST', [
            'amount_paid' => 0, 'payment_method' => 'cash',
        ], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $missingResponse = app(OrderController::class)->processOrder($missing, $order);
        $this->assertSame(422, $missingResponse->getStatusCode());
        $this->assertNull(Invoice::where('order_id', $order->id)->first());
        $this->assertSame('active', $order->externalInventoryReservations()->first()->status);
        $this->assertSame('in_stock', $serial->fresh()->status);

        $withSerial = Request::create('/orders/'.$order->id.'/process', 'POST', [
            'amount_paid' => 0,
            'payment_method' => 'cash',
            'from_pos' => true,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'serial_ids' => [$serial->id],
            ]],
        ], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $success = app(OrderController::class)->processOrder($withSerial, $order->fresh());
        $this->assertSame(200, $success->getStatusCode(), $success->getContent());
        $invoice = Invoice::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('sold', $serial->fresh()->status);
        $this->assertSame(1, InvoiceItemSerial::whereIn('invoice_item_id', $invoice->items()->pluck('id'))->count());
        $this->assertSame('consumed', $order->externalInventoryReservations()->first()->status);

        $statusPath = '/api/integrations/v1/pc/orders/'.$payload['external_order_id'];
        $this->getJson($statusPath, $this->signedHeaders('GET', $statusPath))
            ->assertOk()
            ->assertJsonPath('data.serial_allocation_status', 'allocated')
            ->assertJsonPath('data.invoice_code', $invoice->code);
    }
}
