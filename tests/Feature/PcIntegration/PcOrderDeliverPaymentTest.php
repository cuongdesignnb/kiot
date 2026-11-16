<?php

namespace Tests\Feature\PcIntegration;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Services\Debt\CanonicalPartnerDebtService;
use Illuminate\Support\Str;

class PcOrderDeliverPaymentTest extends PcIntegrationTestCase
{
    private function importOrder(array $productAttributes = [], array $overrides = []): array
    {
        $product = $this->makeProduct($productAttributes + ['sku' => 'PC-DLV-'.strtoupper(Str::random(8)), 'stock_quantity' => 3]);
        $payload = $this->orderPayload($product, $overrides);
        $this->postSignedJson('/api/integrations/v1/pc/orders', $payload, 'idem-'.Str::uuid())->assertCreated();

        return [$product, $payload, Order::where('external_order_id', $payload['external_order_id'])->firstOrFail()];
    }

    private function deliverPath(array $payload): string
    {
        return '/api/integrations/v1/pc/orders/'.$payload['external_order_id'].'/deliver';
    }

    private function paymentPath(array $payload): string
    {
        return '/api/integrations/v1/pc/orders/'.$payload['external_order_id'].'/payments';
    }

    private function deliverBody(array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'delivered_at' => now()->toIso8601String(),
            'tracking_code' => 'GHN123456',
            'carrier' => 'GHN',
        ], $overrides);
    }

    private function paymentBody(int $amount, array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'amount' => $amount,
            'paid_at' => now()->toIso8601String(),
            'method' => 'bank_transfer',
            'reference' => 'FT2611160001',
        ], $overrides);
    }

    private function assertDebtInvariant(int $customerId): void
    {
        $customer = Customer::findOrFail($customerId);
        $canonical = app(CanonicalPartnerDebtService::class)->calculate($customer);
        $this->assertEqualsWithDelta((float) $customer->debt_amount, (float) $canonical['customer_receivable'], 1.0);
    }

    public function test_deliver_converts_order_to_invoice_once_and_replays_duplicates(): void
    {
        [$product, $payload, $order] = $this->importOrder();
        $body = $this->deliverBody();
        $key = 'deliver-'.Str::uuid();

        $response = $this->postSignedJson($this->deliverPath($payload), $body, $key);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('data.kiot_order_code', $order->code)
            ->assertJsonPath('data.external_order_id', $payload['external_order_id'])
            ->assertJsonPath('data.status', 'completed');
        $invoice = Invoice::where('order_id', $order->id)->firstOrFail();
        $response->assertJsonPath('data.kiot_invoice_code', $invoice->code);
        $this->assertSame('GHN123456', $invoice->tracking_code);
        $this->assertSame('GHN', $invoice->delivery_partner);
        $this->assertStringContainsString('Web báo đã giao', (string) $invoice->note);
        $this->assertSame(830000.0, (float) $invoice->total);
        $this->assertSame(0.0, (float) $invoice->customer_paid);
        $this->assertSame(2, (int) $product->fresh()->stock_quantity);
        $this->assertSame('consumed', $order->externalInventoryReservations()->first()->status);
        $this->assertDebtInvariant((int) $order->customer_id);

        // Same request again -> replay.
        $this->postSignedJson($this->deliverPath($payload), $body, $key)
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('data.kiot_invoice_code', $invoice->code);
        // A new delivery event for an already converted order -> success, duplicate.
        $this->postSignedJson($this->deliverPath($payload), $this->deliverBody(), 'deliver-'.Str::uuid())
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('data.kiot_invoice_code', $invoice->code);
        // Same key, different body -> conflict.
        $this->postSignedJson($this->deliverPath($payload), $this->deliverBody(), $key)
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');

        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
        $this->assertSame(1, StockMovement::where('product_id', $product->id)->where('type', 'out_invoice')->count());
        $this->assertSame(2, (int) $product->fresh()->stock_quantity);
    }

    public function test_deliver_unknown_or_cancelled_order_is_rejected(): void
    {
        $this->postSignedJson('/api/integrations/v1/pc/orders/EXT-UNKNOWN-'.Str::random(6).'/deliver', $this->deliverBody(), 'deliver-'.Str::uuid())
            ->assertNotFound()->assertJsonPath('error.code', 'ORDER_NOT_FOUND');

        [, $payload, $order] = $this->importOrder();
        $order->update(['status' => Order::STATUS_CANCELLED]);
        $this->postSignedJson($this->deliverPath($payload), $this->deliverBody(), 'deliver-'.Str::uuid())
            ->assertStatus(422)->assertJsonPath('error.code', 'ORDER_NOT_PROCESSABLE');
        $this->assertNull(Invoice::where('order_id', $order->id)->first());
    }

    public function test_deliver_serial_order_without_allocated_serial_is_rejected_and_noted_once(): void
    {
        [$product, $payload, $order] = $this->importOrder([
            'has_serial' => true, 'stock_quantity' => 1, 'inventory_total_cost' => 500000, 'name' => 'Laptop Dell Test',
        ]);
        SerialImei::create([
            'product_id' => $product->id, 'serial_number' => 'PC-DLV-SN-'.Str::random(8),
            'status' => 'in_stock', 'cost_price' => 500000, 'original_cost' => 500000,
        ]);

        foreach ([1, 2] as $attempt) {
            $this->postSignedJson($this->deliverPath($payload), $this->deliverBody(), 'deliver-'.Str::uuid())
                ->assertStatus(422)
                ->assertJsonPath('success', false)
                ->assertJsonPath('error.code', 'SERIAL_NOT_ALLOCATED')
                ->assertJsonFragment(['message' => 'Cần chọn Serial/IMEI trong KIOT cho: '.$product->sku.' Laptop Dell Test.']);
        }

        $order->refresh();
        $this->assertSame(1, substr_count((string) $order->note, 'Web báo đã giao nhưng chưa xuất hóa đơn'));
        $this->assertSame(1, ActivityLog::where('action', ActivityLog::ACTION_EXTERNAL_ORDER_DELIVER_BLOCKED)->where('subject_id', $order->id)->count());
        $this->assertNull(Invoice::where('order_id', $order->id)->first());
        $this->assertSame('confirmed', $order->status);
    }

    public function test_payment_before_conversion_becomes_deposit_and_is_applied_on_delivery(): void
    {
        [, $payload, $order] = $this->importOrder();
        $body = $this->paymentBody(300000);
        $key = 'pay-'.Str::uuid();

        $response = $this->postSignedJson($this->paymentPath($payload), $body, $key);

        $response->assertCreated()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('data.kiot_order_code', $order->code)
            ->assertJsonPath('data.kiot_invoice_code', null)
            ->assertJsonPath('data.amount_paid_total', 300000);
        $cashFlow = CashFlow::where('code', $response->json('data.cash_flow_code'))->firstOrFail();
        $this->assertSame('Order', $cashFlow->reference_type);
        $this->assertSame($order->code, $cashFlow->reference_code);
        $this->assertSame((int) $order->customer_id, (int) $cashFlow->target_id);
        $this->assertSame('transfer', $cashFlow->payment_method);
        $this->assertStringContainsString('Web báo đã thanh toán', $cashFlow->description);
        $this->assertStringContainsString('FT2611160001', $cashFlow->description);
        $this->assertSame(300000.0, (float) $order->fresh()->amount_paid);
        $this->assertDebtInvariant((int) $order->customer_id);

        // Replay
        $this->postSignedJson($this->paymentPath($payload), $body, $key)
            ->assertOk()->assertJsonPath('duplicate', true)
            ->assertJsonPath('data.cash_flow_code', $cashFlow->code)
            ->assertJsonPath('data.amount_paid_total', 300000);
        $this->assertSame(1, CashFlow::where('reference_type', 'Order')->where('reference_code', $order->code)->count());
        // Same key, other amount -> conflict
        $this->postSignedJson($this->paymentPath($payload), $this->paymentBody(1000), $key)
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
        // Over the order total -> rejected
        $this->postSignedJson($this->paymentPath($payload), $this->paymentBody(600000), 'pay-'.Str::uuid())
            ->assertStatus(422)->assertJsonPath('error.code', 'OVERPAYMENT');
        $this->assertSame(300000.0, (float) $order->fresh()->amount_paid);

        // Delivery applies the deposit.
        $this->postSignedJson($this->deliverPath($payload), $this->deliverBody(), 'deliver-'.Str::uuid())->assertOk();
        $invoice = Invoice::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(300000.0, (float) $invoice->customer_paid);
        $this->assertSame(300000.0, (float) $invoice->order_deposit_applied_amount);
        $this->assertDebtInvariant((int) $order->customer_id);
        $this->assertEqualsWithDelta(530000.0, (float) Customer::findOrFail($order->customer_id)->debt_amount, 0.01);
    }

    public function test_payment_after_conversion_is_collected_against_the_invoice(): void
    {
        [, $payload, $order] = $this->importOrder();
        $this->postSignedJson($this->deliverPath($payload), $this->deliverBody(), 'deliver-'.Str::uuid())->assertOk();
        $invoice = Invoice::where('order_id', $order->id)->firstOrFail();

        $this->postSignedJson($this->paymentPath($payload), $this->paymentBody(900000), 'pay-'.Str::uuid())
            ->assertStatus(422)->assertJsonPath('error.code', 'OVERPAYMENT');

        $body = $this->paymentBody(830000, ['method' => 'cod', 'reference' => null]);
        $key = 'pay-'.Str::uuid();
        $response = $this->postSignedJson($this->paymentPath($payload), $body, $key);

        $response->assertCreated()
            ->assertJsonPath('data.kiot_invoice_code', $invoice->code)
            ->assertJsonPath('data.amount_paid_total', 830000);
        $this->assertSame(830000.0, (float) $invoice->fresh()->customer_paid);
        $this->assertEqualsWithDelta(0.0, (float) Customer::findOrFail($order->customer_id)->debt_amount, 0.01);
        $this->assertDebtInvariant((int) $order->customer_id);
        $cashFlowCode = $response->json('data.cash_flow_code');
        $this->assertNotNull($cashFlowCode);

        $this->postSignedJson($this->paymentPath($payload), $body, $key)
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('data.cash_flow_code', $cashFlowCode);
        $this->assertSame(830000.0, (float) $invoice->fresh()->customer_paid);
    }

    public function test_payment_on_cancelled_order_is_rejected(): void
    {
        [, $payload, $order] = $this->importOrder();
        $order->update(['status' => Order::STATUS_CANCELLED]);

        $this->postSignedJson($this->paymentPath($payload), $this->paymentBody(1000), 'pay-'.Str::uuid())
            ->assertStatus(422)->assertJsonPath('error.code', 'ORDER_NOT_PROCESSABLE');
        $this->assertSame(0, CashFlow::where('reference_code', $order->code)->count());
    }

    public function test_invalid_payload_and_missing_idempotency_key_are_rejected(): void
    {
        [, $payload] = $this->importOrder();

        $this->postSignedJson($this->paymentPath($payload), $this->paymentBody(0), 'pay-'.Str::uuid())
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PAYLOAD');
        $this->postSignedJson($this->paymentPath($payload), $this->paymentBody(1000, ['method' => 'bitcoin']), 'pay-'.Str::uuid())
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PAYLOAD');
        $raw = json_encode($this->deliverBody());
        $path = $this->deliverPath($payload);
        $this->call('POST', $path, [], [], [], $this->transformHeadersToServerVars($this->signedHeaders('POST', $path, $raw)), $raw)
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PAYLOAD');
    }
}
