<?php

namespace Tests\Unit\Services\Exports;

use App\Services\Exports\PartnerDebtExportEffectResolver;
use PHPUnit\Framework\TestCase;

class PartnerDebtExcelPaymentEffectTest extends TestCase
{
    public function test_payment_uses_orientation_specific_effect_without_inventing_detail_amount(): void
    {
        $resolver = new PartnerDebtExportEffectResolver;
        $entry = [
            'event_identity' => 'customer|cash_flows|7|customer_payment|receivable',
            'event_kind' => 'customer_payment',
            'customer_display_effect' => -250000,
            'supplier_display_effect' => 250000,
            'amount' => 123456789,
        ];

        $this->assertSame(-250000.0, $resolver->resolve($entry, 'customer'));
        $this->assertSame(250000.0, $resolver->resolve($entry, 'supplier'));
    }
}
