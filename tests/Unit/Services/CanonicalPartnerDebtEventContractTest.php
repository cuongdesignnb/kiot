<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Services\Debt\CanonicalPartnerDebtEventService;
use App\Services\Debt\PartnerDebtRoleResolver;
use App\Services\Debt\PartnerDebtTimelineOrientationService;
use App\Services\Debt\Source\CustomerDebtDomainEventSource;
use App\Services\Debt\Source\SupplierDebtDomainEventSource;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;

class CanonicalPartnerDebtEventContractTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_build_normalizes_required_contract_and_stable_allocation_identity(): void
    {
        $customerSource = Mockery::mock(CustomerDebtDomainEventSource::class);
        $supplierSource = Mockery::mock(SupplierDebtDomainEventSource::class);
        $customerSource->shouldReceive('events')->once()->andReturn(collect([[
            'id' => 'cash-flow-allocation-45-HD001',
            'code' => 'PT001',
            'domain' => 'customer',
            'source_table' => 'cash_flows',
            'source_id' => '45',
            'event_kind' => 'invoice_payment',
            'effect_side' => 'receivable',
            'display_effect' => -125_000,
            'display_time' => '2026-07-01 10:00:00',
            'created_at' => '2026-07-01 10:00:01',
            'reference_type' => 'Invoice',
            'reference_id' => 91,
            'reference_code' => 'HD001',
            'detail_modal_type' => 'cash_flow',
            'detail_reference_id' => 45,
            'is_real_voucher' => true,
        ]]));
        $supplierSource->shouldReceive('events')->once()->andReturn(collect());
        $partner = new Customer;
        $partner->exists = true;
        $partner->forceFill(['id' => 7, 'is_customer' => true, 'is_supplier' => false]);

        $event = (new CanonicalPartnerDebtEventService($customerSource, $supplierSource))
            ->build($partner)
            ->sole();

        $this->assertSame('customer|cash_flows|cash-flow-allocation-45-HD001|invoice_payment|receivable', $event['event_identity']);
        $this->assertSame(7, $event['economic_partner_id']);
        $this->assertSame(7, $event['customer_row_id']);
        $this->assertNull($event['supplier_row_id']);
        $this->assertSame(-125_000.0, $event['customer_delta']);
        $this->assertSame(0.0, $event['supplier_delta']);
        $this->assertSame(40, $event['event_order']);
        $this->assertTrue($event['affects_balance']);
        $this->assertFalse($event['reference_only']);
        $this->assertSame('cash_flow', $event['detail_type']);
    }

    public function test_debt_offset_is_one_event_that_reduces_both_sides_once(): void
    {
        $service = $this->service();
        $events = $service->canonicalize([
            $this->event('customer|debt_offsets|10|debt_offset|receivable', 'customer', 'debt_offsets', '10', 'debt_offset', -500_000, 0, ['document_amount' => 500_000]),
            $this->event('supplier|debt_offsets|10|debt_offset|payable', 'supplier', 'debt_offsets', '10', 'debt_offset', 0, -500_000, ['document_amount' => 500_000]),
            $this->event('customer|debt_offsets|10|debt_offset_cancel|receivable', 'customer', 'debt_offsets', '10', 'debt_offset_cancel', 500_000, 0, ['document_amount' => 500_000]),
            $this->event('supplier|debt_offsets|10|debt_offset_cancel|payable', 'supplier', 'debt_offsets', '10', 'debt_offset_cancel', 0, 500_000, ['document_amount' => 500_000]),
        ]);

        $this->assertCount(2, $events);
        $offset = $events->firstWhere('event_kind', 'debt_offset');
        $reversal = $events->firstWhere('event_kind', 'debt_offset_cancel');
        $this->assertSame('partner|debt_offsets|10|debt_offset|both', $offset['event_identity']);
        $this->assertSame(-500_000.0, $offset['customer_delta']);
        $this->assertSame(-500_000.0, $offset['supplier_delta']);
        $this->assertSame(500_000.0, $reversal['customer_delta']);
        $this->assertSame(500_000.0, $reversal['supplier_delta']);
        $this->assertSame($offset['event_identity'], $reversal['reversal_of_event_identity']);
    }

    public function test_persisted_debt_offset_reversal_can_use_its_own_source_row(): void
    {
        $offsetIdentity = 'partner|debt_offsets|10|debt_offset|both';
        $customerOffset = $this->event('customer|debt_offsets|10|debt_offset|receivable', 'customer', 'debt_offsets', '10', 'debt_offset', -500_000, 0, ['document_amount' => 500_000]);
        $supplierOffset = $this->event('supplier|debt_offsets|10|debt_offset|payable', 'supplier', 'debt_offsets', '10', 'debt_offset', 0, -500_000, ['document_amount' => 500_000]);
        $customerReversal = $this->event('customer|debt_offsets|11|debt_offset_reversal|receivable', 'customer', 'debt_offsets', '11', 'debt_offset_reversal', 500_000, 0, ['document_amount' => 500_000]);
        $supplierReversal = $this->event('supplier|debt_offsets|11|debt_offset_reversal|payable', 'supplier', 'debt_offsets', '11', 'debt_offset_reversal', 0, 500_000, ['document_amount' => 500_000]);
        $customerReversal['reversal_of_event_identity'] = $offsetIdentity;
        $supplierReversal['reversal_of_event_identity'] = $offsetIdentity;

        $events = $this->service()->canonicalize([
            $customerOffset,
            $supplierOffset,
            $customerReversal,
            $supplierReversal,
        ]);

        $this->assertCount(2, $events);
        $reversal = $events->firstWhere('event_kind', 'debt_offset_reversal');
        $this->assertSame('partner|debt_offsets|11|debt_offset_reversal|both', $reversal['event_identity']);
        $this->assertSame($offsetIdentity, $reversal['reversal_of_event_identity']);
        $this->assertSame(0.0, (float) $events->sum('customer_delta'));
        $this->assertSame(0.0, (float) $events->sum('supplier_delta'));
    }

    public function test_dedup_uses_identity_and_keeps_code_collisions_across_tables(): void
    {
        $service = $this->service();
        $invoice = $this->event('customer|invoices|1|customer_sale|receivable', 'customer', 'invoices', '1', 'customer_sale', 100, 0);
        $cashFlow = $this->event('customer|cash_flows|2|customer_payment|receivable', 'customer', 'cash_flows', '2', 'customer_payment', -100, 0);
        $invoice['source_code'] = 'SAME-CODE';
        $cashFlow['source_code'] = 'SAME-CODE';
        $duplicateReference = array_merge($invoice, ['reference_only' => true, 'affects_balance' => false]);

        $events = $service->canonicalize([$duplicateReference, $invoice, $cashFlow]);

        $this->assertCount(2, $events);
        $this->assertSame([
            'customer|invoices|1|customer_sale|receivable',
            'customer|cash_flows|2|customer_payment|receivable',
        ], $events->pluck('event_identity')->all());
        $this->assertTrue($events->first()['affects_balance']);
    }

    public function test_reversal_link_resolves_to_persisted_fallback_identity_by_document_key_and_opposite_delta(): void
    {
        $original = $this->event(
            'customer|invoices|88|invoice_payment_fallback|receivable',
            'customer',
            'invoices',
            '88',
            'invoice_payment_fallback',
            -75_000,
            0,
            ['reference_code' => 'HD88'],
            '2026-01-01 10:00:00.000000',
        );
        $reversal = $this->event(
            'customer|invoices|88|invoice_payment_cancel_reversal|receivable',
            'customer',
            'invoices',
            '88',
            'invoice_payment_cancel_reversal',
            75_000,
            0,
            ['reference_code' => 'HD88'],
            '2026-01-02 10:00:00.000000',
        );
        $reversal['reversal_of_event_identity'] = 'customer|invoices|88|invoice_payment|receivable';

        $events = $this->service()->canonicalize([$original, $reversal]);

        $this->assertSame(
            $original['event_identity'],
            $events->firstWhere('event_kind', 'invoice_payment_cancel_reversal')['reversal_of_event_identity'],
        );
    }

    public function test_cancelled_purchase_return_and_refund_are_exact_linked_opposites(): void
    {
        $return = $this->event(
            'supplier|purchase_returns|21|purchase_return|payable',
            'supplier',
            'purchase_returns',
            '21',
            'purchase_return',
            0,
            -900_000,
        );
        $refund = $this->event(
            'supplier|cash_flows|31|supplier_refund|payable',
            'supplier',
            'cash_flows',
            '31',
            'supplier_refund',
            0,
            250_000,
        );
        $returnReversal = $this->event(
            'supplier|purchase_returns|21|purchase_return_cancel_reversal|payable',
            'supplier',
            'purchase_returns',
            '21',
            'purchase_return_cancel_reversal',
            0,
            900_000,
            [],
            '2026-01-02 00:00:00.000000',
        );
        $returnReversal['reversal_of_event_identity'] = $return['event_identity'];
        $refundReversal = $this->event(
            'supplier|purchase_returns|21:refund|supplier_refund_cancel_reversal|payable',
            'supplier',
            'purchase_returns',
            '21:refund',
            'supplier_refund_cancel_reversal',
            0,
            -250_000,
            [],
            '2026-01-02 00:00:00.000000',
        );
        $refundReversal['reversal_of_event_identity'] = $refund['event_identity'];

        $events = $this->service()->canonicalize([$return, $refund, $returnReversal, $refundReversal]);

        $this->assertCount(4, $events);
        $this->assertSame(0.0, (float) $events->sum('supplier_delta'));
        foreach ($events->filter(fn (array $event): bool => str_contains($event['event_kind'], 'cancel')) as $reversal) {
            $original = $events->firstWhere('event_identity', $reversal['reversal_of_event_identity']);
            $this->assertNotNull($original);
            $this->assertSame(0.0, (float) $original['supplier_delta'] + (float) $reversal['supplier_delta']);
        }
    }

    public function test_cancelled_standalone_cash_flow_keeps_original_and_exact_reversal(): void
    {
        $original = $this->event(
            'supplier|cash_flows|77|supplier_refund|payable',
            'supplier',
            'cash_flows',
            '77',
            'supplier_refund',
            0,
            125_000,
        );
        $reversal = $this->event(
            'supplier|cash_flows|77:cancel|supplier_refund_cancel_reversal|payable',
            'supplier',
            'cash_flows',
            '77:cancel',
            'supplier_refund_cancel_reversal',
            0,
            -125_000,
            [],
            '2026-01-02 00:00:00.000000',
        );
        $reversal['reversal_of_event_identity'] = $original['event_identity'];

        $events = $this->service()->canonicalize([$original, $reversal]);

        $this->assertCount(2, $events);
        $this->assertSame(0.0, (float) $events->sum('supplier_delta'));
        $this->assertSame(
            $original['event_identity'],
            $events->last()['reversal_of_event_identity'],
        );
    }

    public function test_golden_232_event_fixture_has_identical_pages_and_opposite_signs_and_running_balances(): void
    {
        $events = collect();
        $fixtureKinds = [
            ['customer', 'customer_sale', 100.0, 0.0],
            ['customer', 'customer_payment', -30.0, 0.0],
            ['supplier', 'purchase', 0.0, 40.0],
            ['supplier', 'supplier_payment', 0.0, -10.0],
            ['supplier', 'purchase_return', 0.0, -15.0],
            ['supplier', 'debt_adjustment', 0.0, -20.0],
        ];
        for ($index = 1; $index <= 232; $index++) {
            [$domain, $kind, $customerDelta, $supplierDelta] = $fixtureKinds[($index - 1) % count($fixtureKinds)];
            $events->push($this->event(
                $domain."|fixtures|{$index}|{$kind}|".($domain === 'customer' ? 'receivable' : 'payable'),
                $domain,
                'fixtures',
                (string) $index,
                $kind,
                $customerDelta,
                $supplierDelta,
                [],
                sprintf('2026-01-01 00:%02d:%02d.000000', intdiv($index - 1, 60), ($index - 1) % 60),
            ));
        }
        $canonical = Mockery::mock(CanonicalPartnerDebtEventService::class);
        $canonical->shouldReceive('build')->twice()->andReturn($events);
        $canonical->shouldReceive('identityHash')->twice()->andReturnUsing(
            fn (Collection $stream): string => hash('sha256', $stream->pluck('event_identity')->implode("\n")),
        );
        $orientation = new PartnerDebtTimelineOrientationService($canonical);
        $partner = new Customer;
        $partner->forceFill([
            'id' => 999,
            'is_customer' => true,
            'is_supplier' => true,
            'debt_amount' => (float) $events->sum('customer_delta'),
            'supplier_debt_amount' => (float) $events->sum('supplier_delta'),
        ]);

        $customer = $orientation->customer($partner);
        $supplier = $orientation->supplier($partner);

        $this->assertSame(232, $customer['entry_count']);
        $this->assertSame(232, $supplier['entry_count']);
        $this->assertSame($customer['source_identity_hash'], $supplier['source_identity_hash']);
        $this->assertSame(
            (float) $events->sum('customer_delta') - (float) $events->sum('supplier_delta'),
            $customer['raw_final_balance'],
        );
        $this->assertSame(-$customer['raw_final_balance'], $supplier['raw_final_balance']);
        $this->assertFalse($customer['has_mismatch']);
        $this->assertFalse($supplier['has_mismatch']);

        $customerPage13 = collect($customer['entries'])->slice(120, 10)->values();
        $supplierPage13 = collect($supplier['entries'])->slice(120, 10)->values();
        $this->assertSame($customerPage13->pluck('event_identity')->all(), $supplierPage13->pluck('event_identity')->all());
        foreach ($customer['entries'] as $index => $entry) {
            $opposite = $supplier['entries'][$index];
            $this->assertSame($entry['event_identity'], $opposite['event_identity']);
            $this->assertEqualsWithDelta(0, $entry['display_delta'] + $opposite['display_delta'], 0.0001);
            $this->assertEqualsWithDelta(0, $entry['running_balance'] + $opposite['running_balance'], 0.0001);
        }
    }

    public function test_same_timestamp_order_is_deterministic_by_event_identity(): void
    {
        $events = $this->service()->canonicalize([
            $this->event('supplier|purchases|2|purchase|payable', 'supplier', 'purchases', '2', 'purchase', 0, 40),
            $this->event('customer|invoices|1|customer_sale|receivable', 'customer', 'invoices', '1', 'customer_sale', 100, 0),
            $this->event('customer|invoices|3|customer_sale|receivable', 'customer', 'invoices', '3', 'customer_sale', 50, 0),
        ]);

        $this->assertSame([
            'customer|invoices|1|customer_sale|receivable',
            'customer|invoices|3|customer_sale|receivable',
            'supplier|purchases|2|purchase|payable',
        ], $events->pluck('event_identity')->all());
    }

    public function test_runtime_role_uses_persisted_flags_without_promoting_supplier_only_code(): void
    {
        $partner = new Customer;
        $partner->forceFill([
            'code' => 'NCC177621742868',
            'is_customer' => false,
            'is_supplier' => true,
        ]);

        $this->assertSame([false, true], PartnerDebtRoleResolver::sides($partner));
        $this->assertSame('supplier_only', PartnerDebtRoleResolver::role($partner));
        $integrity = PartnerDebtRoleResolver::integrity($partner);
        $this->assertNull($integrity['owner_confirmed_role']);
        $this->assertSame('OK', $integrity['role_integrity_status']);
    }

    private function service(): CanonicalPartnerDebtEventService
    {
        return new CanonicalPartnerDebtEventService(
            Mockery::mock(CustomerDebtDomainEventSource::class),
            Mockery::mock(SupplierDebtDomainEventSource::class),
        );
    }

    /** @return array<string, mixed> */
    private function event(
        string $identity,
        string $domain,
        string $sourceType,
        string $sourceId,
        string $kind,
        float $customerDelta,
        float $supplierDelta,
        array $metadata = [],
        string $businessTime = '2026-01-01 00:00:00.000000',
    ): array {
        return [
            'event_identity' => $identity,
            'economic_partner_id' => 999,
            'customer_row_id' => 999,
            'supplier_row_id' => 999,
            'domain' => $domain,
            'source_type' => $sourceType,
            'source_table' => $sourceType,
            'source_id' => $sourceId,
            'source_code' => 'CODE-'.$sourceId,
            'event_kind' => $kind,
            'business_time' => $businessTime,
            'created_at' => $businessTime,
            'event_order' => match (true) {
                str_contains($kind, 'return') => 30,
                str_contains($kind, 'payment'), str_contains($kind, 'refund') => 40,
                str_contains($kind, 'adjustment'), str_contains($kind, 'discount') => 50,
                default => 20,
            },
            'customer_delta' => $customerDelta,
            'supplier_delta' => $supplierDelta,
            'affects_balance' => true,
            'reference_only' => false,
            'mirror_of_event_identity' => null,
            'reversal_of_event_identity' => null,
            'source_status' => 'completed',
            'detail_type' => 'none',
            'detail_id' => null,
            'detail_code' => null,
            'display_type' => $kind,
            'badge_label' => null,
            'badge_title' => null,
            'is_real_voucher' => true,
            'is_fallback' => false,
            'metadata' => $metadata,
        ];
    }
}
