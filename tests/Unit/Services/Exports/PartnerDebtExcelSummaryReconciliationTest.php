<?php

namespace Tests\Unit\Services\Exports;

use App\Services\Exports\PartnerDebtExportEffectResolver;
use PHPUnit\Framework\TestCase;

class PartnerDebtExcelSummaryReconciliationTest extends TestCase
{
    public function test_summary_formula_reconciles_from_orientation_effects(): void
    {
        $resolver = new PartnerDebtExportEffectResolver;
        $entries = [
            ['customer_display_effect' => 100],
            ['customer_display_effect' => -25],
            ['customer_display_effect' => 50],
        ];
        $debit = 0.0;
        $credit = 0.0;
        foreach ($entries as $entry) {
            $effect = $resolver->resolve($entry, 'customer');
            if ($effect > 0) {
                $debit += $effect;
            } else {
                $credit += abs($effect);
            }
        }

        $this->assertSame(125.0, 0.0 + $debit - $credit);
    }
}
