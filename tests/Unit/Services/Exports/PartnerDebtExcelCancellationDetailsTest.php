<?php

namespace Tests\Unit\Services\Exports;

use App\Services\Exports\PartnerDebtExportDocumentResolver;
use PHPUnit\Framework\TestCase;

class PartnerDebtExcelCancellationDetailsTest extends TestCase
{
    public function test_cancellation_preserves_original_document_identity(): void
    {
        $identity = (new PartnerDebtExportDocumentResolver)->resolve([
            'event_identity' => 'supplier|purchases|99|purchase_cancel_reversal|payable',
            'event_kind' => 'purchase_cancel_reversal',
            'reversal_of' => 'supplier|purchases|99|purchase|payable',
            'supplier_display_effect' => -900000,
        ]);

        $this->assertSame('Purchase', $identity['document_type']);
        $this->assertSame(99, $identity['document_id']);
        $this->assertSame('Purchase', $identity['original_document_type']);
        $this->assertSame(99, $identity['original_document_id']);
        $this->assertTrue($identity['is_cancellation']);
    }

    public function test_invoice_cancellation_uses_reversal_identity_for_original_detail(): void
    {
        $identity = (new PartnerDebtExportDocumentResolver)->resolve([
            'event_identity' => 'customer|invoices|12|invoice_cancel_reversal|receivable',
            'event_kind' => 'invoice_cancel_reversal',
            'reversal_of' => 'customer|invoices|12|invoice|receivable',
            'customer_display_effect' => -250000,
        ]);

        $this->assertSame('Invoice', $identity['document_type']);
        $this->assertSame(12, $identity['document_id']);
        $this->assertSame('Invoice', $identity['original_document_type']);
        $this->assertSame(12, $identity['original_document_id']);
    }
}
