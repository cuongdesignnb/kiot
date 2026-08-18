<?php

namespace Tests\Feature\CustomerDebt;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\CustomerPaymentAllocation;
use App\Models\Invoice;
use App\Services\CustomerDebtDocumentTimelineService;
use App\Services\CustomerPaymentService;
use App\Services\Debt\PartnerDebtPublicTimelineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerPaymentBusinessTimeContractTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_backdated_payment_uses_business_time_keeps_recorded_time_and_skips_future_invoice(): void
    {
        Carbon::setTestNow('2026-08-18 16:00:37');
        $customer = $this->customer(200_000);
        $eligible = $this->invoice($customer, 'HD-ELIGIBLE', 100_000, '2026-07-30 14:08:59');
        $future = $this->invoice($customer, 'HD-FUTURE', 100_000, '2026-07-30 14:09:01');

        $result = app(CustomerPaymentService::class)->collect(
            $customer,
            150_000,
            'auto',
            [],
            'Ghi chú vẫn được lưu trong chứng từ gốc',
            '30/07/2026 14:09',
            'customer-payment-business-time-'.uniqid(),
        );

        $cashFlow = CashFlow::findOrFail($result['cash_flow_id']);
        $this->assertSame(100_000.0, $result['allocated_amount']);
        $this->assertSame(50_000.0, $result['unallocated_amount']);
        $this->assertSame(100_000.0, (float) $eligible->fresh()->customer_paid);
        $this->assertSame(0.0, (float) $future->fresh()->customer_paid);
        $this->assertSame(50_000.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame('2026-07-30 14:09:00', Carbon::parse($cashFlow->time)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-18 16:00:37', Carbon::parse($cashFlow->created_at)->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('Ghi chú vẫn được lưu', (string) $cashFlow->description);

        $timeline = app(PartnerDebtPublicTimelineService::class)->project(
            app(CustomerDebtDocumentTimelineService::class)->build($customer->fresh()),
            'customer',
        );
        $entries = collect($timeline['entries']);
        $paymentEntry = $entries->firstWhere('code', $cashFlow->code);
        $this->assertNotNull($paymentEntry);
        $this->assertSame('2026-07-30 14:09:00', Carbon::parse($paymentEntry['business_time'])->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-18 16:00:37', Carbon::parse($paymentEntry['created_at'])->format('Y-m-d H:i:s'));

        $futureIndex = $entries->search(fn (array $entry): bool => ($entry['code'] ?? null) === $future->code);
        $paymentIndex = $entries->search(fn (array $entry): bool => ($entry['code'] ?? null) === $cashFlow->code);
        $eligibleIndex = $entries->search(fn (array $entry): bool => ($entry['code'] ?? null) === $eligible->code);
        $this->assertIsInt($futureIndex);
        $this->assertIsInt($paymentIndex);
        $this->assertIsInt($eligibleIndex);
        $this->assertTrue($futureIndex < $paymentIndex && $paymentIndex < $eligibleIndex);
    }

    public function test_manual_backdated_payment_rejects_future_invoice_without_mutation(): void
    {
        Carbon::setTestNow('2026-08-18 16:00:00');
        $customer = $this->customer(100_000);
        $future = $this->invoice($customer, 'HD-MANUAL-FUTURE', 100_000, '2026-07-30 14:09:01');
        $before = $this->snapshot($customer, $future);

        try {
            app(CustomerPaymentService::class)->collect(
                $customer,
                100_000,
                'manual',
                [['invoice_id' => $future->id, 'amount' => 100_000]],
                null,
                '30/07/2026 14:09',
                'customer-payment-manual-future-'.uniqid(),
            );
            $this->fail('A backdated payment must not allocate to a future invoice.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('phát sinh sau ngày thanh toán', $exception->errors()['allocations'][0]);
        }

        $this->assertSame($before, $this->snapshot($customer, $future));
    }

    public function test_malformed_customer_payment_date_returns_vietnamese_validation_without_mutation(): void
    {
        $customer = $this->customer(100_000);
        $invoice = $this->invoice($customer, 'HD-DATE-INVALID', 100_000, '2026-07-30 14:08:59');
        $before = $this->snapshot($customer, $invoice);

        try {
            app(CustomerPaymentService::class)->collect(
                $customer,
                100_000,
                'auto',
                [],
                null,
                '30/99/2026 14:09',
            );
            $this->fail('An invalid Vietnamese payment date must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Ngày thanh toán không hợp lệ. Vui lòng nhập dd/MM/yyyy HH:mm.',
                $exception->errors()['date'][0],
            );
        }

        $this->assertSame($before, $this->snapshot($customer, $invoice));
    }

    public function test_default_payment_time_does_not_break_idempotent_replay_when_clock_advances(): void
    {
        Carbon::setTestNow('2026-08-18 16:00:37');
        $customer = $this->customer(100_000);
        $invoice = $this->invoice($customer, 'HD-IDEMPOTENT', 100_000, '2026-08-18 15:00:00');
        $key = 'customer-payment-default-time-'.uniqid();

        $first = app(CustomerPaymentService::class)->collect(
            $customer,
            100_000,
            'auto',
            [],
            null,
            null,
            $key,
        );

        Carbon::setTestNow('2026-08-18 16:01:42');
        $second = app(CustomerPaymentService::class)->collect(
            $customer->fresh(),
            100_000,
            'auto',
            [],
            null,
            null,
            $key,
        );

        $this->assertSame($first['cash_flow_id'], $second['cash_flow_id']);
        $this->assertSame($first['cash_flow_code'], $second['cash_flow_code']);
        $this->assertSame((float) $first['payment_amount'], (float) $second['payment_amount']);
        $this->assertSame((float) $first['allocated_amount'], (float) $second['allocated_amount']);
        $this->assertSame((float) $first['debt_after'], (float) $second['debt_after']);
        $this->assertSame(1, CashFlow::whereKey($first['cash_flow_id'])->count());
        $this->assertSame(1, CustomerPaymentAllocation::where('customer_id', $customer->id)->count());
        $this->assertSame(100_000.0, (float) $invoice->fresh()->customer_paid);
        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
    }

    private function customer(float $debt): Customer
    {
        return Customer::create([
            'code' => 'KH-PAYMENT-TIME-'.uniqid(),
            'name' => 'Khách hàng kiểm thử thời gian',
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
            'debt_amount' => $debt,
            'supplier_debt_amount' => 0,
        ]);
    }

    private function invoice(Customer $customer, string $prefix, float $total, string $businessTime): Invoice
    {
        return Invoice::create([
            'code' => $prefix.'-'.uniqid(),
            'customer_id' => $customer->id,
            'subtotal' => $total,
            'total' => $total,
            'customer_paid' => 0,
            'status' => 'completed',
            'transaction_date' => Carbon::parse($businessTime),
        ]);
    }

    private function snapshot(Customer $customer, Invoice $invoice): array
    {
        return [
            'customer_debt' => (float) $customer->fresh()->debt_amount,
            'invoice_paid' => (float) $invoice->fresh()->customer_paid,
            'cash_flows' => CashFlow::where('target_id', $customer->id)->count(),
            'allocations' => CustomerPaymentAllocation::where('customer_id', $customer->id)->count(),
            'customer_debts' => CustomerDebt::where('customer_id', $customer->id)->count(),
        ];
    }
}
