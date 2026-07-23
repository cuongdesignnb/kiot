<?php

namespace Tests\Feature\PcIntegration;

use App\Models\ExternalInventoryReservation;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class PcReservationExpiryTest extends PcIntegrationTestCase
{
    public function test_expiry_command_is_idempotent_and_does_not_change_stock_or_order_status(): void
    {
        $product = $this->makeProduct(['sku' => 'PC-EXPIRY', 'stock_quantity' => 2]);
        $payload = $this->orderPayload($product);
        $this->postSignedJson('/api/integrations/v1/pc/orders', $payload, 'idem-'.Str::uuid())->assertCreated();
        $order = Order::where('external_order_id', $payload['external_order_id'])->firstOrFail();
        $reservation = $order->externalInventoryReservations()->firstOrFail();
        $reservation->update(['expires_at' => now()->subMinute()]);
        $stockBefore = (int) $product->fresh()->stock_quantity;

        $this->assertSame(0, Artisan::call('integrations:expire-pc-reservations'));
        $this->assertSame('expired', $reservation->fresh()->status);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame($stockBefore, (int) $product->fresh()->stock_quantity);

        $this->assertSame(0, Artisan::call('integrations:expire-pc-reservations'));
        $this->assertSame(1, ExternalInventoryReservation::whereKey($reservation->id)->where('status', 'expired')->count());
    }

    public function test_expiry_skips_completed_and_invoiced_orders(): void
    {
        $product = $this->makeProduct(['sku' => 'PC-EXPIRY-TERMINAL', 'stock_quantity' => 2]);
        $reservations = collect();

        foreach (['completed', 'invoiced'] as $case) {
            $payload = $this->orderPayload($product);
            $this->postSignedJson('/api/integrations/v1/pc/orders', $payload, 'idem-'.Str::uuid())->assertCreated();
            $order = Order::where('external_order_id', $payload['external_order_id'])->firstOrFail();
            $reservation = $order->externalInventoryReservations()->firstOrFail();
            $reservation->update(['expires_at' => now()->subMinute()]);
            $reservations->push($reservation);

            if ($case === 'completed') {
                $order->update(['status' => Order::STATUS_COMPLETED]);
            } else {
                Invoice::create([
                    'code' => 'HD-PC-EXPIRY-'.Str::random(8),
                    'order_id' => $order->id,
                    'branch_id' => $order->branch_id,
                    'subtotal' => $order->total_price,
                    'total' => $order->total_payment,
                    'customer_paid' => 0,
                    'status' => 'Hoàn thành',
                ]);
            }
        }

        $this->assertSame(0, Artisan::call('integrations:expire-pc-reservations'));
        $reservations->each(fn (ExternalInventoryReservation $reservation) => $this->assertSame('active', $reservation->fresh()->status));
        $this->assertSame(2, (int) $product->fresh()->stock_quantity);
    }
}
