<?php

namespace Tests\Feature\CustomerDebt;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PartnerMerge;
use App\Services\CustomerDebtService;
use App\Services\CustomerPaymentService;
use App\Services\InvoiceSaleService;
use App\Services\OrderPaymentSummaryService;
use App\Services\PartnerMergeService;
use App\Services\PartnerTransactionGuard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class SapoDebtParityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_record_sale_rejects_negative_and_signed_effect_keeps_credit(): void
    {
        $customer = $this->customer();
        $service = app(CustomerDebtService::class);

        try {
            $service->recordSale($customer->id, -300_000);
            $this->fail('recordSale must reject a negative amount.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('signed invoice balance', $exception->getMessage());
        }

        $service->recordInvoiceBalanceEffect($customer->id, -300_000);
        $this->assertSame(-300_000.0, (float) $customer->fresh()->debt_amount);
    }

    public function test_invoice_overpayment_creates_credit_without_increasing_revenue(): void
    {
        $customer = $this->customer();

        $invoice = app(InvoiceSaleService::class)->createSale([
            'customer_id' => $customer->id,
            'subtotal' => 1_500_000,
            'total' => 1_500_000,
            'customer_paid' => 1_800_000,
            'items' => [],
        ], [
            'validate_before_purchase_date' => false,
            'validate_stock_setting' => false,
            'allow_oversell' => true,
        ]);

        $this->assertSame(1_500_000.0, (float) $invoice->total);
        $this->assertSame(1_800_000.0, (float) $invoice->customer_paid);
        $this->assertSame(-300_000.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(1_500_000.0, (float) $customer->fresh()->total_spent);
        $this->assertSame(
            1_800_000.0,
            (float) CashFlow::where('reference_code', $invoice->code)->value('amount')
        );
    }

    public function test_collecting_more_than_receivable_keeps_full_cash_and_unallocated_credit(): void
    {
        $customer = $this->customer();
        $invoice = $this->receivableInvoice($customer, 1_300_000);

        $result = app(CustomerPaymentService::class)->collect($customer, 1_500_000);

        $this->assertSame(1_500_000.0, $result['payment_amount']);
        $this->assertSame(1_300_000.0, $result['allocated_amount']);
        $this->assertSame(200_000.0, $result['unallocated_amount']);
        $this->assertSame(-200_000.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(1_300_000.0, (float) $invoice->fresh()->customer_paid);
        $this->assertSame(
            1_500_000.0,
            (float) CashFlow::findOrFail($result['cash_flow_id'])->amount
        );
    }

    public function test_existing_credit_offsets_the_next_invoice_without_becoming_deposit(): void
    {
        $customer = $this->customer();
        $this->receivableInvoice($customer, 1_300_000);
        app(CustomerPaymentService::class)->collect($customer, 1_500_000);

        $next = Invoice::create([
            'code' => 'HD-NEXT-' . uniqid(),
            'customer_id' => $customer->id,
            'subtotal' => 1_500_000,
            'total' => 1_500_000,
            'customer_paid' => 0,
            'order_deposit_applied_amount' => 0,
            'status' => 'completed',
        ]);
        app(CustomerDebtService::class)->recordInvoiceBalanceEffect(
            $customer->id,
            1_500_000,
            $next
        );

        $this->assertSame(1_300_000.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(0.0, (float) $next->fresh()->order_deposit_applied_amount);
    }

    public function test_order_summary_counts_payments_after_zero_deposit(): void
    {
        $order = $this->order(1_500_000, 0);
        $this->orderInvoice($order, 1_200_000, 0);

        $summary = app(OrderPaymentSummaryService::class)->summary($order);

        $this->assertSame(1_200_000.0, $summary['order_paid_total']);
        $this->assertSame(300_000.0, $summary['order_remaining_debt']);
        $this->assertSame('partial', $summary['payment_status']);
    }

    public function test_order_summary_uses_original_deposit_only_once(): void
    {
        $order = $this->order(10_000_000, 2_000_000);
        $this->orderInvoice($order, 5_000_000, 2_000_000);

        $summary = app(OrderPaymentSummaryService::class)->summary($order);

        $this->assertSame(2_000_000.0, $summary['original_deposit']);
        $this->assertSame(3_000_000.0, $summary['paid_after_deposit']);
        $this->assertSame(5_000_000.0, $summary['order_paid_total']);
    }

    public function test_merge_marker_is_zero_and_does_not_double_customer_debt(): void
    {
        $source = $this->customer(300_000, 0, true, false);
        $target = $this->customer(0, 0, false, true);

        $result = app(PartnerMergeService::class)->merge($source, $target);
        $marker = CustomerDebt::where('ref_code', $result['marker']['ref_code'])->firstOrFail();

        $this->assertSame(300_000.0, $result['after']['customer_net_position']);
        $this->assertSame(-300_000.0, $result['after']['supplier_net_position']);
        $this->assertSame(0.0, (float) $marker->amount);
        $this->assertSame(300_000.0, (float) $target->fresh()->debt_amount);
        $this->assertSame(1, PartnerMerge::where('ref_code', $marker->ref_code)->count());
    }

    public function test_dual_role_net_zero_remains_zero_with_reference_only_marker(): void
    {
        $source = $this->customer(200_000, 200_000, true, true);
        $target = $this->customer(0, 0, true, true);

        $result = app(PartnerMergeService::class)->merge($source, $target);

        $this->assertSame(0.0, $result['after']['customer_net_position']);
        $this->assertSame(0.0, $result['after']['supplier_net_position']);
        $this->assertSame(0.0, $result['marker']['amount']);
        $this->assertFalse($result['marker']['affects_debt_balance']);
    }

    public function test_manual_allocation_rejects_another_customers_invoice(): void
    {
        $payer = $this->customer();
        $owner = $this->customer();
        $invoice = $this->receivableInvoice($owner, 300_000);

        $this->expectException(ValidationException::class);
        app(CustomerPaymentService::class)->collect($payer, 100_000, 'manual', [
            ['invoice_id' => $invoice->id, 'amount' => 100_000],
        ]);
    }

    public function test_manual_allocation_rejects_cancelled_invoice(): void
    {
        $customer = $this->customer();
        $invoice = $this->receivableInvoice($customer, 300_000);
        $invoice->update(['status' => 'cancelled']);

        $this->expectException(ValidationException::class);
        app(CustomerPaymentService::class)->collect($customer, 100_000, 'manual', [
            ['invoice_id' => $invoice->id, 'amount' => 100_000],
        ]);
    }

    public function test_cash_flow_cancellation_is_idempotent(): void
    {
        $customer = $this->customer();
        $invoice = $this->receivableInvoice($customer, 300_000);
        $result = app(CustomerPaymentService::class)->collect($customer, 300_000);
        $cashFlow = CashFlow::findOrFail($result['cash_flow_id']);

        $this->assertSame(
            CustomerPaymentService::CANCELLED,
            app(CustomerPaymentService::class)->cancel($cashFlow)
        );
        $this->assertSame(300_000.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(0.0, (float) $invoice->fresh()->customer_paid);

        $this->assertSame(
            CustomerPaymentService::ALREADY_CANCELLED,
            app(CustomerPaymentService::class)->cancel($cashFlow)
        );
        $this->assertSame(300_000.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(0.0, (float) $invoice->fresh()->customer_paid);
    }

    public function test_merged_source_is_rejected_by_transaction_guard_and_invoice_flow(): void
    {
        $target = $this->customer();
        $source = $this->customer();
        $source->update([
            'status' => 'inactive',
            'merged_into_id' => $target->id,
            'merged_at' => now(),
        ]);

        try {
            app(PartnerTransactionGuard::class)->assertCanTransact($source->id, 'customer_id');
            $this->fail('Merged source must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($target->code, $exception->errors()['customer_id'][0]);
        }

        $this->expectException(ValidationException::class);
        app(InvoiceSaleService::class)->createSale([
            'customer_id' => $source->id,
            'subtotal' => 100_000,
            'total' => 100_000,
            'customer_paid' => 0,
            'items' => [],
        ]);
    }

    private function customer(
        float $debt = 0,
        float $supplierDebt = 0,
        bool $isCustomer = true,
        bool $isSupplier = false
    ): Customer {
        return Customer::create([
            'code' => 'PARTNER-' . uniqid(),
            'name' => 'Partner ' . uniqid(),
            'debt_amount' => $debt,
            'supplier_debt_amount' => $supplierDebt,
            'total_spent' => 0,
            'total_returns' => 0,
            'total_bought' => 0,
            'is_customer' => $isCustomer,
            'is_supplier' => $isSupplier,
            'status' => 'active',
        ]);
    }

    private function receivableInvoice(Customer $customer, float $total): Invoice
    {
        $invoice = Invoice::create([
            'code' => 'HD-RECEIVABLE-' . uniqid(),
            'customer_id' => $customer->id,
            'subtotal' => $total,
            'total' => $total,
            'customer_paid' => 0,
            'order_deposit_applied_amount' => 0,
            'status' => 'completed',
        ]);
        app(CustomerDebtService::class)->recordInvoiceBalanceEffect($customer->id, $total, $invoice);

        return $invoice;
    }

    private function order(float $total, float $deposit): Order
    {
        return Order::create([
            'code' => 'ORDER-' . uniqid(),
            'status' => 'confirmed',
            'total_price' => $total,
            'total_payment' => $total,
            'amount_paid' => $deposit,
        ]);
    }

    private function orderInvoice(Order $order, float $customerPaid, float $depositApplied): Invoice
    {
        return Invoice::create([
            'code' => 'HD-ORDER-' . uniqid(),
            'order_id' => $order->id,
            'subtotal' => $order->total_payment,
            'total' => $order->total_payment,
            'customer_paid' => $customerPaid,
            'order_deposit_applied_amount' => $depositApplied,
            'status' => 'completed',
        ]);
    }
}
