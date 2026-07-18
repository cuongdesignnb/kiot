<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Services\Debt\CanonicalPartnerDebtService;
use Carbon\Carbon;
use Tests\TestCase;

class CanonicalPartnerDebtServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_unpersisted_partner_snapshot_keeps_sides_separate_without_claiming_canonical_evidence(): void
    {
        Carbon::setTestNow('2026-07-15 09:30:00');
        $partner = new Customer;
        $partner->forceFill([
            'id' => 210,
            'debt_amount' => 12_300_000,
            'supplier_debt_amount' => 2_700_000,
            'is_customer' => true,
            'is_supplier' => true,
            'updated_at' => Carbon::parse('2026-07-14 16:00:00'),
        ]);

        $result = app(CanonicalPartnerDebtService::class)->calculate($partner);

        $this->assertSame(12_300_000.0, $result['customer_balance']);
        $this->assertSame(2_700_000.0, $result['supplier_balance']);
        $this->assertSame(9_600_000.0, $result['net_display_balance']);
        $this->assertSame('UNPERSISTED_PARTNER_SNAPSHOT', $result['source_kind']);
        $this->assertFalse($result['is_canonical']);
        $this->assertSame('ALIGNED', $result['staleness_status']);
        $this->assertSame('net_balance', $result['display_contract']);
        $this->assertSame(9_600_000.0, $result['raw_timeline_final']);
        $this->assertFalse($result['has_mismatch']);
        $this->assertSame('2026-07-15T09:30:00+07:00', $result['calculated_at']);
        $this->assertStringStartsWith('canonical-document-reducer-v2:', $result['source_version']);
    }

    public function test_snapshot_fingerprint_changes_when_a_balance_changes(): void
    {
        $partner = new Customer;
        $partner->forceFill([
            'id' => 10,
            'debt_amount' => 100_000,
            'supplier_debt_amount' => 0,
        ]);
        $service = app(CanonicalPartnerDebtService::class);
        $before = $service->calculate($partner)['source_version'];

        $partner->debt_amount = 200_000;
        $after = $service->calculate($partner)['source_version'];

        $this->assertNotSame($before, $after);
    }

    public function test_snapshot_fingerprint_is_independent_of_optional_updated_at_hydration(): void
    {
        $withTimestamp = new Customer;
        $withTimestamp->forceFill([
            'id' => 10,
            'debt_amount' => 100_000,
            'supplier_debt_amount' => 25_000,
            'updated_at' => Carbon::parse('2026-07-15 10:00:00'),
        ]);
        $withoutTimestamp = new Customer;
        $withoutTimestamp->forceFill([
            'id' => 10,
            'debt_amount' => 100_000,
            'supplier_debt_amount' => 25_000,
        ]);
        $service = app(CanonicalPartnerDebtService::class);

        $this->assertSame(
            $service->calculate($withTimestamp)['source_version'],
            $service->calculate($withoutTimestamp)['source_version'],
        );
    }
}
