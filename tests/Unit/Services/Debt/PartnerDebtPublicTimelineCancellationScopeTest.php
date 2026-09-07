<?php

namespace Tests\Unit\Services\Debt;

use App\Services\Debt\PartnerDebtPublicTimelineService;
use PHPUnit\Framework\TestCase;

class PartnerDebtPublicTimelineCancellationScopeTest extends TestCase
{
    public function test_active_scope_hides_cancelled_invoice_bundle_but_keeps_standalone_receipt(): void
    {
        $service = new PartnerDebtPublicTimelineService;
        $timeline = $this->cancelledInvoiceTimeline();

        $active = $service->project($timeline, 'customer');

        self::assertSame(['HD-ACTIVE', 'PT-STANDALONE'], array_column($active['entries'], 'code'));
        self::assertSame('Khách thanh toán', $active['entries'][1]['display_type']);
        self::assertTrue($active['entries'][1]['released_from_cancelled_document']);
        self::assertSame(-30.0, (float) $active['entries'][1]['customer_display_running_balance']);
        self::assertSame(170.0, (float) $active['entries'][0]['customer_display_running_balance']);
        self::assertSame('active', $active['summary']['cancellation_scope']);
        self::assertSame(1, $active['summary']['cancelled_document_count']);
        self::assertSame(4, $active['summary']['hidden_cancelled_entry_count']);
    }

    public function test_cancelled_and_all_scopes_preserve_audit_evidence(): void
    {
        $service = new PartnerDebtPublicTimelineService;
        $timeline = $this->cancelledInvoiceTimeline();

        $cancelled = $service->project($timeline, 'customer', 'cancelled');
        $all = $service->project($timeline, 'customer', 'all');

        self::assertCount(5, $cancelled['entries']);
        self::assertContains('HD-CANCELLED', array_column($cancelled['entries'], 'code'));
        self::assertContains('HUY-HD-CANCELLED', array_column($cancelled['entries'], 'code'));
        self::assertContains('PT-STANDALONE', array_column($cancelled['entries'], 'code'));
        self::assertCount(6, $all['entries']);
        self::assertSame('all', $all['summary']['cancellation_scope']);
    }

    public function test_active_scope_hides_a_standalone_voucher_only_when_that_voucher_is_cancelled(): void
    {
        $identity = 'customer|cash_flows|77|customer_payment|receivable';
        $timeline = [
            'entries' => [
                [
                    'code' => 'PT-CANCELLED',
                    'event_identity' => $identity,
                    'event_kind' => 'customer_payment',
                    'source_table' => 'cash_flows',
                    'source_id' => '77',
                    'reference_type' => 'DebtPayment',
                    'customer_display_effect' => -50,
                    'business_time' => '2026-09-01 09:00:00',
                    'payment_origin' => 'standalone',
                ],
                [
                    'code' => 'PT-CANCELLED',
                    'event_identity' => 'customer|cash_flows|77:cancel|customer_payment_cancel_reversal|receivable',
                    'event_kind' => 'customer_payment_cancel_reversal',
                    'source_table' => 'cash_flows',
                    'source_id' => '77:cancel',
                    'reference_type' => 'DebtPayment',
                    'reversal_of' => $identity,
                    'customer_display_effect' => 50,
                    'business_time' => '2026-09-02 09:00:00',
                    'payment_origin' => 'standalone',
                ],
            ],
        ];

        $active = (new PartnerDebtPublicTimelineService)->project($timeline, 'customer');
        $cancelled = (new PartnerDebtPublicTimelineService)->project($timeline, 'customer', 'cancelled');

        self::assertSame([], $active['entries']);
        self::assertCount(2, $cancelled['entries']);
    }

    public function test_active_scope_hides_cancelled_purchase_and_keeps_separate_supplier_payment_as_credit(): void
    {
        $timeline = [
            'entries' => [
                [
                    'code' => 'PN-CANCELLED',
                    'event_identity' => 'supplier|purchases|1|purchase|payable',
                    'event_kind' => 'purchase',
                    'reference_type' => 'Purchase',
                    'reference_code' => 'PN-CANCELLED',
                    'document_group_key' => 'PN-CANCELLED',
                    'document_group_type' => 'purchase',
                    'supplier_display_effect' => 100,
                    'business_time' => '2026-09-01 08:00:00',
                ],
                [
                    'code' => 'PC-STANDALONE',
                    'event_identity' => 'supplier|cash_flows|8:purchase:1|supplier_payment|payable',
                    'event_kind' => 'supplier_payment',
                    'reference_type' => 'Purchase',
                    'reference_code' => 'PN-CANCELLED',
                    'document_group_key' => 'PN-CANCELLED',
                    'document_group_type' => 'purchase',
                    'supplier_display_effect' => -30,
                    'business_time' => '2026-09-01 09:00:00',
                    'payment_origin' => 'standalone',
                ],
                [
                    'code' => 'HUY-PN-CANCELLED',
                    'event_identity' => 'supplier|purchases|1|purchase_cancel_reversal|payable',
                    'event_kind' => 'purchase_cancel_reversal',
                    'reference_type' => 'Purchase',
                    'reference_code' => 'PN-CANCELLED',
                    'supplier_display_effect' => -100,
                    'business_time' => '2026-09-02 08:00:00',
                ],
                [
                    'code' => 'HUY-TT-PN-CANCELLED',
                    'event_identity' => 'supplier|purchases|1:payment|supplier_payment_cancel_reversal|payable',
                    'event_kind' => 'supplier_payment_cancel_reversal',
                    'reference_type' => 'Purchase',
                    'reference_code' => 'PN-CANCELLED',
                    'supplier_display_effect' => 30,
                    'business_time' => '2026-09-02 08:00:01',
                ],
            ],
        ];

        $active = (new PartnerDebtPublicTimelineService)->project($timeline, 'supplier');

        self::assertSame(['PC-STANDALONE'], array_column($active['entries'], 'code'));
        self::assertSame(-30.0, (float) $active['entries'][0]['supplier_display_running_balance']);
        self::assertSame('Thanh toán NCC', $active['entries'][0]['display_type']);
    }

    private function cancelledInvoiceTimeline(): array
    {
        return [
            'entries' => [
                [
                    'code' => 'HD-CANCELLED',
                    'event_identity' => 'customer|invoices|1|customer_sale|receivable',
                    'event_kind' => 'customer_sale',
                    'reference_type' => 'Invoice',
                    'reference_code' => 'HD-CANCELLED',
                    'document_group_key' => 'HD-CANCELLED',
                    'document_group_type' => 'invoice',
                    'customer_display_effect' => 100,
                    'business_time' => '2026-09-01 08:00:00',
                ],
                [
                    'code' => 'PT-INVOICE',
                    'event_identity' => 'customer|cash_flows|10:HD-CANCELLED|invoice_payment|receivable',
                    'event_kind' => 'invoice_payment',
                    'reference_type' => 'Invoice',
                    'reference_code' => 'HD-CANCELLED',
                    'document_group_key' => 'HD-CANCELLED',
                    'document_group_type' => 'invoice',
                    'customer_display_effect' => -20,
                    'business_time' => '2026-09-01 08:00:01',
                    'payment_origin' => 'source_document',
                ],
                [
                    'code' => 'PT-STANDALONE',
                    'event_identity' => 'customer|cash_flows|11:HD-CANCELLED|invoice_payment|receivable',
                    'event_kind' => 'invoice_payment',
                    'reference_type' => 'Invoice',
                    'reference_code' => 'HD-CANCELLED',
                    'document_group_key' => 'HD-CANCELLED',
                    'document_group_type' => 'invoice',
                    'customer_display_effect' => -30,
                    'business_time' => '2026-09-01 09:00:00',
                    'payment_origin' => 'standalone',
                ],
                [
                    'code' => 'HUY-HD-CANCELLED',
                    'event_identity' => 'customer|invoices|1|invoice_cancel_reversal|receivable',
                    'event_kind' => 'invoice_cancel_reversal',
                    'reference_type' => 'Invoice',
                    'reference_code' => 'HD-CANCELLED',
                    'document_group_key' => 'HD-CANCELLED',
                    'document_group_type' => 'invoice',
                    'customer_display_effect' => -100,
                    'business_time' => '2026-09-02 08:00:00',
                ],
                [
                    'code' => 'HUY-TT-HD-CANCELLED',
                    'event_identity' => 'customer|invoices|1|invoice_payment_cancel_reversal|receivable',
                    'event_kind' => 'invoice_payment_cancel_reversal',
                    'reference_type' => 'Invoice',
                    'reference_code' => 'HD-CANCELLED',
                    'document_group_key' => 'HD-CANCELLED',
                    'document_group_type' => 'invoice',
                    'customer_display_effect' => 50,
                    'business_time' => '2026-09-02 08:00:01',
                ],
                [
                    'code' => 'HD-ACTIVE',
                    'event_identity' => 'customer|invoices|2|customer_sale|receivable',
                    'event_kind' => 'customer_sale',
                    'reference_type' => 'Invoice',
                    'reference_code' => 'HD-ACTIVE',
                    'document_group_key' => 'HD-ACTIVE',
                    'document_group_type' => 'invoice',
                    'customer_display_effect' => 200,
                    'business_time' => '2026-09-03 08:00:00',
                ],
            ],
        ];
    }
}
