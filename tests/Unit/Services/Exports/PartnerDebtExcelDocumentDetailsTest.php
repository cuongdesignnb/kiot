<?php

namespace Tests\Unit\Services\Exports;

use App\Services\Exports\PartnerDebtExportDocumentResolver;
use PHPUnit\Framework\TestCase;

class PartnerDebtExcelDocumentDetailsTest extends TestCase
{
    public function test_canonical_reference_identity_is_preferred_over_event_id(): void
    {
        $identity = (new PartnerDebtExportDocumentResolver)->resolve([
            'id' => 'supplier|purchases|99|purchase|payable',
            'event_identity' => 'supplier|purchases|99|purchase|payable',
            'reference_type' => 'Purchase',
            'reference_id' => 99,
            'reference_code' => 'PN-99',
            'event_kind' => 'purchase',
        ]);

        $this->assertSame('Purchase', $identity['document_type']);
        $this->assertSame(99, $identity['document_id']);
        $this->assertSame('reference', $identity['identity_source']);
        $this->assertTrue($identity['is_product_document']);
    }

    public function test_payment_reference_is_context_only_and_has_no_product_document(): void
    {
        $identity = (new PartnerDebtExportDocumentResolver)->resolve([
            'event_identity' => 'supplier|purchases|99|supplier_payment|payable',
            'reference_type' => 'Purchase',
            'reference_id' => 99,
            'event_kind' => 'supplier_payment',
            'supplier_display_effect' => -100000,
        ]);

        $this->assertTrue($identity['is_payment']);
        $this->assertFalse($identity['is_product_document']);
        $this->assertSame([], (new PartnerDebtExportDocumentResolver)->loadDetailLines([
            'event_identity' => 'supplier|purchases|99|supplier_payment|payable',
            'reference_type' => 'Purchase',
            'reference_id' => 99,
            'event_kind' => 'supplier_payment',
        ]));
    }

    public function test_legacy_canonical_event_id_is_used_when_reference_fields_are_absent(): void
    {
        $identity = (new PartnerDebtExportDocumentResolver)->resolve([
            'id' => 'supplier|purchase_returns|41|purchase_return|payable',
            'event_kind' => 'purchase_return',
        ]);

        $this->assertSame('PurchaseReturn', $identity['document_type']);
        $this->assertSame(41, $identity['document_id']);
        $this->assertSame('legacy_id', $identity['identity_source']);
    }

    public function test_adjustment_and_offset_references_are_context_only(): void
    {
        $resolver = new PartnerDebtExportDocumentResolver;
        foreach (['adjustment', 'debt_offset'] as $eventKind) {
            $identity = $resolver->resolve([
                'event_identity' => 'supplier|purchases|99|'.$eventKind.'|payable',
                'reference_type' => 'Purchase',
                'reference_id' => 99,
                'event_kind' => $eventKind,
            ]);

            $this->assertTrue($identity['is_adjustment']);
            $this->assertFalse($identity['is_product_document']);
            $this->assertSame([], $resolver->loadDetailLines([
                'event_identity' => 'supplier|purchases|99|'.$eventKind.'|payable',
                'reference_type' => 'Purchase',
                'reference_id' => 99,
                'event_kind' => $eventKind,
            ]));
        }
    }
}
