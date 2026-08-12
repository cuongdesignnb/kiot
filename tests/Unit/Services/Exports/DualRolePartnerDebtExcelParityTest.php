<?php

namespace Tests\Unit\Services\Exports;

use App\Services\Exports\PartnerDebtExportEffectResolver;
use PHPUnit\Framework\TestCase;

class DualRolePartnerDebtExcelParityTest extends TestCase
{
    public function test_customer_and_supplier_canonical_display_effects_are_opposite(): void
    {
        $resolver = new PartnerDebtExportEffectResolver;
        $entry = [
            'event_identity' => 'dual|invoices|41|customer_sale|receivable',
            'reference_type' => 'Invoice',
            'reference_id' => 41,
            'customer_display_effect' => 1250000,
            'supplier_display_effect' => -1250000,
            'amount' => 9999999,
        ];

        $customer = $resolver->resolve($entry, 'customer');
        $supplier = $resolver->resolve($entry, 'supplier');

        $this->assertSame(1250000.0, $customer);
        $this->assertSame(-1250000.0, $supplier);
        $this->assertSame(0.0, $customer + $supplier);
    }

    public function test_canonical_entry_does_not_fall_back_to_generic_amount(): void
    {
        $resolver = new PartnerDebtExportEffectResolver;

        $this->assertSame(0.0, $resolver->resolve([
            'event_identity' => 'dual|debt_offsets|5|debt_offset|receivable',
            'reference_type' => 'DebtOffset',
            'reference_id' => 5,
            'amount' => 700000,
        ], 'supplier'));
    }
}
