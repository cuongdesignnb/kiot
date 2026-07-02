<?php

namespace Tests\Feature\Orders;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\LegacyOrderAmountPaidAuditService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AuditLegacyOrderAmountPaidCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_order_with_correct_deposit_provenance_is_option_a_consistent(): void
    {
        $order = $this->order(1_000_000, 200_000);
        $this->invoice($order, 500_000, 200_000);

        $item = app(LegacyOrderAmountPaidAuditService::class)->inspectOrder($order);

        $this->assertSame('option_a_consistent', $item['classification']);
        $this->assertSame(500_000.0, $item['computed_order_paid_total']);
        $this->assertSame(500_000.0, $item['computed_remaining_debt']);
        $this->assertSame([], $item['signals']);
    }

    public function test_legacy_cumulative_amount_paid_is_flagged_for_manual_review(): void
    {
        $order = $this->order(1_500_000, 1_200_000);
        $this->invoice($order, 1_200_000, null);

        $item = app(LegacyOrderAmountPaidAuditService::class)->inspectOrder($order);

        $this->assertSame('legacy_order_requires_manual_review', $item['classification']);
        $this->assertContains(
            'amount_paid_positive_and_invoice_paid_but_no_deposit_provenance',
            $item['signals']
        );
        $this->assertSame(2_400_000.0, $item['computed_order_paid_total']);
    }

    public function test_order_without_invoice_is_classified_as_deposit_only_or_no_invoice(): void
    {
        $order = $this->order(1_000_000, 200_000);

        $item = app(LegacyOrderAmountPaidAuditService::class)->inspectOrder($order);

        $this->assertSame('deposit_only_or_no_invoice', $item['classification']);
        $this->assertSame('deposit_only_or_no_invoice', $item['reason']);
        $this->assertSame(200_000.0, $item['computed_order_paid_total']);
    }

    public function test_json_output_is_parseable_and_contains_summary_and_items(): void
    {
        $order = $this->order(1_500_000, 1_200_000);
        $this->invoice($order, 1_200_000, null);

        $exitCode = Artisan::call('orders:audit-legacy-amount-paid', [
            '--json' => true,
            '--limit' => 10,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('items', $payload);
        $this->assertTrue($payload['read_only']);
        $this->assertGreaterThanOrEqual(
            1,
            $payload['summary']['suspected_legacy_cumulative_amount_paid']
        );
        $this->assertSame(
            'legacy_order_requires_manual_review',
            collect($payload['items'])->firstWhere('order_id', $order->id)['classification']
        );
    }

    private function order(float $total, float $amountPaid): Order
    {
        $customer = Customer::create([
            'code' => 'AUDIT-KH-'.uniqid(),
            'name' => 'Legacy order audit customer',
        ]);

        return Order::create([
            'code' => 'AUDIT-DH-'.uniqid(),
            'customer_id' => $customer->id,
            'status' => 'completed',
            'total_price' => $total,
            'total_payment' => $total,
            'amount_paid' => $amountPaid,
        ]);
    }

    private function invoice(
        Order $order,
        float $customerPaid,
        ?float $depositApplied
    ): Invoice {
        return Invoice::create([
            'code' => 'AUDIT-HD-'.uniqid(),
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'subtotal' => $order->total_payment,
            'total' => $order->total_payment,
            'customer_paid' => $customerPaid,
            'order_deposit_applied_amount' => $depositApplied,
            'status' => 'completed',
        ]);
    }
}
