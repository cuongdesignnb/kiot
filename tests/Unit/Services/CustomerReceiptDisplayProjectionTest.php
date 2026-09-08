<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Services\Debt\CanonicalPartnerDebtEventService;
use App\Services\Debt\PartnerDebtTimelineOrientationService;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;

class CustomerReceiptDisplayProjectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_one_receipt_allocated_to_multiple_invoices_renders_once_at_the_exact_voucher_amount(): void
    {
        $events = collect([
            $this->allocationEvent(701, 'allocation:a', -1_250_000, 101, 'HD-SYNTH-A', 4_500_000),
            $this->allocationEvent(701, 'allocation:b', -2_750_000, 102, 'HD-SYNTH-B', 4_500_000),
            $this->unallocatedEvent(701, -500_000, 4_500_000),
        ]);
        $partner = $this->customer(-4_500_000);

        $timeline = $this->orientation($events)->customer($partner);
        $row = collect($timeline['entries'])->sole();

        $this->assertSame(3, $timeline['canonical_entry_count']);
        $this->assertSame(1, $timeline['entry_count']);
        $this->assertSame(-4_500_000.0, (float) $timeline['raw_final_balance']);
        $this->assertSame(-4_500_000.0, (float) $row['display_delta']);
        $this->assertSame(4_500_000.0, (float) $row['payment_amount']);
        $this->assertSame(4_500_000.0, (float) $row['receipt_voucher_amount']);
        $this->assertSame(2, (int) $row['allocation_count']);
        $this->assertSame(4_000_000.0, (float) $row['allocation_total']);
        $this->assertSame(500_000.0, (float) $row['unallocated_amount']);
        $this->assertSame('PT-SYNTH-001', $row['code']);
        $this->assertSame('Khách thanh toán', $row['display_type']);
        $this->assertSame('customer_payment', $row['event_kind']);
        $this->assertSame('DebtPayment', $row['reference_type']);
        $this->assertSame(701, (int) $row['reference_id']);
        $this->assertSame('PT-SYNTH-001', $row['reference_code']);
        $this->assertSame('customer_receipt', $row['document_group_type']);
        $this->assertNull($row['payment_for_code']);
        $this->assertSame([101, 102], $row['allocation_invoice_ids']);
        $this->assertSame(['HD-SYNTH-A', 'HD-SYNTH-B'], $row['allocation_invoice_codes']);
        $this->assertCount(3, $row['canonical_event_identities']);
        $this->assertSame(
            hash('sha256', $events->pluck('event_identity')->implode("\n")),
            $timeline['source_identity_hash'],
        );
    }

    public function test_distinct_cash_flow_ids_are_never_merged_even_when_code_time_and_amount_match(): void
    {
        $events = collect([
            $this->allocationEvent(701, 'allocation:a', -600_000, 101, 'HD-SYNTH-A', 1_000_000, 'PT-SAME'),
            $this->allocationEvent(701, 'allocation:b', -400_000, 102, 'HD-SYNTH-B', 1_000_000, 'PT-SAME'),
            $this->allocationEvent(702, 'allocation:c', -600_000, 103, 'HD-SYNTH-C', 1_000_000, 'PT-SAME'),
            $this->allocationEvent(702, 'allocation:d', -400_000, 104, 'HD-SYNTH-D', 1_000_000, 'PT-SAME'),
        ]);

        $timeline = $this->orientation($events)->customer($this->customer(-2_000_000));

        $this->assertSame(2, $timeline['entry_count']);
        $this->assertSame([701, 702], collect($timeline['entries'])
            ->pluck('payment_cash_flow_id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all());
        $this->assertSame(-2_000_000.0, (float) $timeline['raw_final_balance']);
    }

    public function test_invoice_owned_receipt_keeps_its_invoice_context(): void
    {
        $event = $this->allocationEvent(
            703,
            'invoice-owned',
            -900_000,
            105,
            'HD-SOURCE-DOCUMENT',
            900_000,
            'PT-SOURCE-DOCUMENT',
            'source_document',
        );
        $event['metadata']['document_group_key'] = 'HD-SOURCE-DOCUMENT';
        $event['metadata']['document_group_type'] = 'invoice';
        $event['metadata']['document_group_parent_code'] = 'HD-SOURCE-DOCUMENT';
        $event['metadata']['payment_for_code'] = 'HD-SOURCE-DOCUMENT';

        $row = collect($this->orientation(collect([$event]))
            ->customer($this->customer(-900_000))['entries'])
            ->sole();

        $this->assertSame('invoice_payment', $row['event_kind']);
        $this->assertSame('Invoice', $row['reference_type']);
        $this->assertSame('HD-SOURCE-DOCUMENT', $row['reference_code']);
        $this->assertSame('HD-SOURCE-DOCUMENT', $row['payment_for_code']);
        $this->assertSame('invoice', $row['document_group_type']);
        $this->assertArrayNotHasKey('display_projection', $row);
    }

    private function orientation(Collection $events): PartnerDebtTimelineOrientationService
    {
        $canonical = Mockery::mock(CanonicalPartnerDebtEventService::class);
        $canonical->shouldReceive('build')->atLeast()->once()->andReturn($events);
        $canonical->shouldReceive('identityHash')->atLeast()->once()->andReturnUsing(
            fn (Collection $stream): string => hash('sha256', $stream->pluck('event_identity')->implode("\n")),
        );

        return new PartnerDebtTimelineOrientationService($canonical);
    }

    private function customer(float $debt): Customer
    {
        $partner = new Customer;
        $partner->forceFill([
            'id' => 88,
            'is_customer' => true,
            'is_supplier' => false,
            'debt_amount' => $debt,
            'supplier_debt_amount' => 0,
        ]);

        return $partner;
    }

    /** @return array<string, mixed> */
    private function allocationEvent(
        int $cashFlowId,
        string $suffix,
        float $delta,
        int $invoiceId,
        string $invoiceCode,
        float $voucherAmount,
        string $receiptCode = 'PT-SYNTH-001',
        string $origin = 'standalone',
    ): array {
        $sourceId = $cashFlowId.':'.$suffix;

        return $this->event(
            $cashFlowId,
            $sourceId,
            $receiptCode,
            'invoice_payment',
            $delta,
            $voucherAmount,
            [
                'reference_type' => 'Invoice',
                'reference_id' => $invoiceId,
                'reference_code' => $invoiceCode,
                'payment_origin' => $origin,
                'allocation_is_actual' => true,
                'original_allocated_amount' => abs($delta),
                'allocated_amount' => abs($delta),
                'document_group_key' => $invoiceCode,
                'document_group_type' => 'invoice',
                'document_group_parent_code' => $invoiceCode,
                'payment_for_code' => $invoiceCode,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function unallocatedEvent(int $cashFlowId, float $delta, float $voucherAmount): array
    {
        return $this->event(
            $cashFlowId,
            (string) $cashFlowId,
            'PT-SYNTH-001',
            'customer_payment',
            $delta,
            $voucherAmount,
            [
                'reference_type' => 'DebtPayment',
                'reference_id' => $cashFlowId,
                'reference_code' => null,
                'payment_origin' => 'standalone',
                'allocated_amount' => $voucherAmount - abs($delta),
                'unallocated_amount' => abs($delta),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function event(
        int $cashFlowId,
        string $sourceId,
        string $receiptCode,
        string $kind,
        float $delta,
        float $voucherAmount,
        array $metadata,
    ): array {
        $metadata = array_merge([
            'detail_reference_id' => $cashFlowId,
            'detail_reference_code' => $receiptCode,
            'receipt_voucher_amount' => $voucherAmount,
            'receipt_allocation_mismatch' => false,
            'needs_manual_review' => false,
        ], $metadata);

        return [
            'event_identity' => "customer|cash_flows|{$sourceId}|{$kind}|receivable",
            'domain' => 'customer',
            'source_type' => 'cash_flows',
            'source_table' => 'cash_flows',
            'source_id' => $sourceId,
            'source_code' => $receiptCode,
            'event_kind' => $kind,
            'business_time' => '2026-01-15 10:00:00',
            'created_at' => '2026-01-15 10:00:00',
            'event_order' => 40,
            'customer_delta' => $delta,
            'supplier_delta' => 0.0,
            'affects_balance' => true,
            'reference_only' => false,
            'detail_type' => 'cash_flow',
            'detail_id' => $cashFlowId,
            'detail_code' => $receiptCode,
            'display_type' => $kind === 'invoice_payment' ? 'Thanh toán hóa đơn' : 'Khách thanh toán',
            'is_real_voucher' => true,
            'is_fallback' => false,
            'metadata' => $metadata,
        ];
    }
}
