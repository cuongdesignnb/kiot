<?php

namespace Tests\Unit\Services;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\DebtOffset;
use App\Models\SupplierDebtTransaction;
use App\Services\Debt\CanonicalPartnerDebtEventService;
use App\Services\Debt\PartnerDebtTimelineOrientationService;
use App\Services\Debt\Source\SupplierDebtDomainEventSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SupplierPaymentDisplayProjectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_twelve_persisted_allocations_render_as_one_voucher_without_changing_canonical_totals(): void
    {
        $events = collect();
        for ($purchaseId = 1; $purchaseId <= 12; $purchaseId++) {
            $events->push($this->paymentEvent(
                "922:purchase:{$purchaseId}",
                -1_500_000,
                $purchaseId,
                true,
                1_500_000,
            ));
        }
        $events->push($this->paymentEvent('922', -400_000, null, false, 0, [
            'payment_allocation_mismatch' => true,
            'needs_manual_review' => true,
        ]));

        $supplier = $this->orientation($events);
        $partner = new Customer;
        $partner->forceFill([
            'id' => 16,
            'code' => 'NCC177425584137',
            'is_customer' => false,
            'is_supplier' => true,
            'supplier_debt_amount' => -18_400_000,
        ]);

        $timeline = $supplier->supplier($partner);
        $row = collect($timeline['entries'])->sole();

        $this->assertSame(13, $timeline['canonical_entry_count']);
        $this->assertSame(1, $timeline['entry_count']);
        $this->assertSame(-18_400_000.0, (float) $timeline['raw_final_balance']);
        $this->assertSame(-18_400_000.0, (float) $row['display_delta']);
        $this->assertSame(12, $row['allocation_count']);
        $this->assertSame(18_000_000.0, (float) $row['allocation_total']);
        $this->assertSame(400_000.0, (float) $row['unallocated_amount']);
        $this->assertTrue($row['payment_allocation_mismatch']);
        $this->assertTrue($row['needs_manual_review']);
        $this->assertSame(922, (int) $row['payment_cash_flow_id']);
        $this->assertSame('SupplierPayment', $row['reference_type']);
        $this->assertSame(922, (int) $row['reference_id']);
        $this->assertSame('PCPN260807154515978', $row['reference_code']);
        $this->assertSame('PCPN260807154515978', $row['document_group_parent_code']);
        $this->assertSame('supplier_payment', $row['document_group_type']);
        $this->assertNotSame('PN1', $row['parent_document_code']);
        $this->assertCount(12, $row['purchase_ids']);
        $this->assertCount(13, $row['canonical_event_identities']);
        $this->assertSame(
            hash('sha256', $events->pluck('event_identity')->implode("\n")),
            $timeline['source_identity_hash'],
        );
    }

    public function test_distinct_cash_flows_are_never_merged_by_timestamp_code_or_amount(): void
    {
        $events = collect([
            $this->paymentEvent('922:purchase:1', -9_200_000, 1, true, 9_200_000, [
                'detail_reference_code' => 'SAME-CODE',
            ]),
            $this->paymentEvent('923:purchase:2', -9_200_000, 2, true, 9_200_000, [
                'detail_reference_code' => 'SAME-CODE',
            ]),
        ]);
        $supplier = $this->orientation($events);
        $partner = new Customer;
        $partner->forceFill([
            'id' => 17,
            'is_customer' => false,
            'is_supplier' => true,
            'supplier_debt_amount' => -18_400_000,
        ]);

        $timeline = $supplier->supplier($partner);

        $this->assertCount(2, $timeline['entries']);
        $this->assertSame([922, 923], collect($timeline['entries'])
            ->pluck('payment_cash_flow_id')->sort()->values()->all());
    }

    public function test_fully_allocated_voucher_keeps_the_exact_payment_amount(): void
    {
        $events = collect();
        for ($purchaseId = 1; $purchaseId <= 11; $purchaseId++) {
            $events->push($this->paymentEvent(
                "922:purchase:{$purchaseId}",
                -1_500_000,
                $purchaseId,
                true,
                1_500_000,
            ));
        }
        $events->push($this->paymentEvent('922:purchase:12', -1_900_000, 12, true, 1_900_000));

        $partner = new Customer;
        $partner->forceFill(['id' => 19, 'is_supplier' => true, 'supplier_debt_amount' => -18_400_000]);
        $timeline = $this->orientation($events)->supplier($partner);
        $row = collect($timeline['entries'])->sole();

        $this->assertSame(-18_400_000.0, (float) $row['display_delta']);
        $this->assertSame(18_400_000.0, (float) $row['allocation_total']);
        $this->assertSame(12, (int) $row['allocation_count']);
        $this->assertSame(0.0, (float) $row['unallocated_amount']);
        $this->assertFalse((bool) $row['payment_allocation_mismatch']);
    }

    public function test_dual_role_views_share_the_document_row_and_keep_opposite_signs(): void
    {
        $events = collect([
            $this->paymentEvent('922:purchase:1', -10_000, 1, true, 10_000),
            $this->paymentEvent('922:purchase:2', -5_000, 2, true, 5_000),
        ]);
        $supplier = $this->orientation($events);
        $partner = new Customer;
        $partner->forceFill([
            'id' => 18,
            'is_customer' => true,
            'is_supplier' => true,
            'debt_amount' => 15_000,
            'supplier_debt_amount' => 0,
        ]);

        $customer = $supplier->customer($partner);
        $supplierView = $supplier->supplier($partner);

        $this->assertSame(1, $customer['entry_count']);
        $this->assertSame(1, $supplierView['entry_count']);
        $this->assertSame(
            $customer['entries'][0]['event_identity'],
            $supplierView['entries'][0]['event_identity'],
        );
        $this->assertSame(15_000.0, (float) $customer['entries'][0]['display_delta']);
        $this->assertSame(-15_000.0, (float) $supplierView['entries'][0]['display_delta']);
    }

    public function test_payment_and_debt_offset_ledger_mirrors_do_not_create_checkpoints_but_genuine_history_does(): void
    {
        $source = new SupplierDebtDomainEventSource;
        $method = new ReflectionMethod($source, 'addPersistedLedgerCheckpoints');
        $method->setAccessible(true);
        $time = Carbon::parse('2026-08-07 10:00:00');
        $entries = collect([$this->sourceEntry(-100.0)]);

        $paymentMirror = new SupplierDebtTransaction;
        $paymentMirror->setRawAttributes([
            'id' => 1,
            'type' => 'payment',
            'code' => 'PCPN-MIRROR',
            'purchase_id' => null,
            'debt_remain' => 0,
            'created_at' => $time,
        ]);
        $paymentMirror2 = new SupplierDebtTransaction;
        $paymentMirror2->setRawAttributes([
            'id' => 4,
            'type' => 'payment',
            'code' => 'PCPN-MIRROR-2',
            'purchase_id' => null,
            'debt_remain' => 0,
            'created_at' => $time,
        ]);
        $cashFlow = new CashFlow;
        $cashFlow->setRawAttributes(['id' => 922, 'code' => 'PCPN-MIRROR', 'reference_code' => null]);
        $cashFlow2 = new CashFlow;
        $cashFlow2->setRawAttributes(['id' => 923, 'code' => 'PCPN-MIRROR-2', 'reference_code' => null]);

        $offsetMirror = new SupplierDebtTransaction;
        $offsetMirror->setRawAttributes([
            'id' => 2,
            'type' => 'adjustment',
            'code' => 'CB-MIRROR',
            'purchase_id' => null,
            'debt_remain' => 0,
            'created_at' => $time->copy()->addMinute(),
        ]);
        $offset = new DebtOffset;
        $offset->setRawAttributes(['id' => 55, 'code' => 'CB-MIRROR']);

        $genuine = new SupplierDebtTransaction;
        $genuine->setRawAttributes([
            'id' => 3,
            'type' => 'adjustment',
            'code' => 'HISTORY-1',
            'purchase_id' => null,
            'debt_remain' => 50,
            'created_at' => $time->copy()->addMinutes(2),
        ]);

        $result = $method->invoke(
            $source,
            $entries,
            collect([$paymentMirror, $paymentMirror2, $offsetMirror, $genuine]),
            collect(),
            collect([$cashFlow, $cashFlow2]),
            collect([$offset]),
            [],
        );

        $this->assertFalse($result->contains('reference_id', 1));
        $this->assertFalse($result->contains('reference_id', 4));
        $this->assertFalse($result->contains('reference_id', 2));
        $this->assertTrue($result->contains('reference_id', 3));
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

    /** @return array<string, mixed> */
    private function paymentEvent(
        string $sourceId,
        float $supplierDelta,
        ?int $purchaseId,
        bool $actualAllocation,
        float $allocatedAmount,
        array $overrides = [],
    ): array {
        $cashFlowId = (int) explode(':', $sourceId, 2)[0];
        $identity = "supplier|cash_flows|{$sourceId}|supplier_payment|payable";
        $metadata = array_merge([
            'detail_reference_id' => $cashFlowId,
            'detail_reference_code' => 'PCPN260807154515978',
            'reference_id' => $purchaseId,
            'reference_code' => $purchaseId === null ? null : 'PN'.$purchaseId,
            'allocation_is_actual' => $actualAllocation,
            'original_allocated_amount' => $allocatedAmount,
            'allocated_amount' => $allocatedAmount,
            'payment_allocation_mismatch' => false,
            'needs_manual_review' => false,
        ], $overrides);

        return [
            'event_identity' => $identity,
            'domain' => 'supplier',
            'source_type' => 'cash_flows',
            'source_table' => 'cash_flows',
            'source_id' => $sourceId,
            'source_code' => 'PCPN260807154515978',
            'event_kind' => 'supplier_payment',
            'business_time' => '2026-08-07 10:00:00',
            'created_at' => '2026-08-07 10:00:00',
            'event_order' => 30,
            'customer_delta' => 0.0,
            'supplier_delta' => $supplierDelta,
            'affects_balance' => true,
            'reference_only' => false,
            'detail_type' => 'cash_flow',
            'detail_id' => $cashFlowId,
            'detail_code' => 'PCPN260807154515978',
            'display_type' => 'Thanh toán NCC',
            'is_real_voucher' => true,
            'is_fallback' => false,
            'metadata' => $metadata,
        ];
    }

    /** @return array<string, mixed> */
    private function sourceEntry(float $effect): array
    {
        return [
            'event_identity' => 'supplier|purchases|1|purchase|payable',
            'domain' => 'supplier',
            'affects_document_balance' => true,
            'supplier_display_effect' => $effect,
            'created_at' => Carbon::parse('2026-08-07 09:00:00'),
            'time' => Carbon::parse('2026-08-07 09:00:00'),
        ];
    }
}
