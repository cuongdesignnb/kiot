<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Invoice;
use App\Models\SupplierDebtTransaction;
use App\Services\CustomerDebtDocumentTimelineService;
use App\Services\Debt\PartnerDebtParityAuditService;
use App\Services\SupplierDebtDocumentTimelineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PartnerDebtParityAuditServiceTest extends TestCase
{
    use DatabaseTransactions;

    private PartnerDebtParityAuditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PartnerDebtParityAuditService::class);
    }

    public function test_customer_only_clean_is_ok(): void
    {
        $row = $this->baseline();

        $this->assertSame(['OK'], $this->service->classify($row));
        $this->assertSame('OK', $this->service->riskLevel($row, ['OK']));
    }

    public function test_customer_stored_document_mismatch_9070000_is_high(): void
    {
        $row = $this->baseline([
            'customer_stored_vs_document_raw' => 9_070_000,
            'customer_document_vs_ledger' => -9_070_000,
        ]);
        $flags = $this->service->classify($row);

        $this->assertContains('CUSTOMER_STORED_VS_DOCUMENT', $flags);
        $this->assertSame('HIGH', $this->service->riskLevel($row, $flags));
    }

    public function test_missing_opening_is_classified_without_proposing_a_write(): void
    {
        $row = $this->baseline([
            'stored_customer_screen' => 4_300_000,
            'customer_stored_vs_document_raw' => 4_300_000,
            'customer_stored_vs_ledger' => 4_300_000,
            'customer_document_entry_count' => 0,
            'customer_debt_count' => 0,
            'has_virtual_opening' => true,
        ]);
        $flags = $this->service->classify($row);

        $this->assertContains('VIRTUAL_OPENING_REQUIRED', $flags);
        $this->assertContains('STORED_BALANCE_NO_HISTORY', $flags);
    }

    public function test_stored_matches_ledger_but_document_differs(): void
    {
        $flags = $this->service->classify($this->baseline([
            'customer_stored_vs_document_raw' => 500_000,
            'customer_document_vs_ledger' => -500_000,
        ]));

        $this->assertContains('CUSTOMER_STORED_VS_DOCUMENT', $flags);
        $this->assertContains('CUSTOMER_DOCUMENT_VS_LEDGER', $flags);
        $this->assertNotContains('CUSTOMER_STORED_VS_LEDGER', $flags);
    }

    public function test_stored_matches_document_but_ledger_differs(): void
    {
        $flags = $this->service->classify($this->baseline([
            'customer_stored_vs_ledger' => 500_000,
            'customer_document_vs_ledger' => 500_000,
        ]));

        $this->assertContains('CUSTOMER_STORED_VS_LEDGER', $flags);
        $this->assertContains('CUSTOMER_DOCUMENT_VS_LEDGER', $flags);
        $this->assertNotContains('CUSTOMER_STORED_VS_DOCUMENT', $flags);
    }

    public function test_supplier_only_clean_and_mismatch_contracts(): void
    {
        $clean = $this->baseline(['role' => 'supplier_only']);
        $this->assertSame(['OK'], $this->service->classify($clean));

        $mismatch = $this->baseline([
            'role' => 'supplier_only',
            'supplier_stored_vs_document_raw' => 2_900_000,
        ]);
        $this->assertContains('SUPPLIER_STORED_VS_DOCUMENT', $this->service->classify($mismatch));
    }

    public function test_dual_role_clean_is_symmetric(): void
    {
        $row = $this->baseline([
            'role' => 'dual_role',
            'stored_customer_screen' => -2_700_000,
            'stored_supplier_screen' => 2_700_000,
        ]);

        $this->assertSame(['OK'], $this->service->classify($row));
    }

    public function test_dual_role_asymmetry_is_critical(): void
    {
        $row = $this->baseline([
            'role' => 'dual_role',
            'dual_role_screen_symmetry_difference' => 100,
        ]);
        $flags = $this->service->classify($row);

        $this->assertContains('DUAL_ROLE_SCREEN_ASYMMETRY', $flags);
        $this->assertSame('CRITICAL', $this->service->riskLevel($row, $flags));
    }

    public function test_dual_role_stored_mismatch_is_flagged(): void
    {
        $flags = $this->service->classify($this->baseline([
            'role' => 'dual_role',
            'customer_stored_vs_document_raw' => 2_000_000,
        ]));

        $this->assertContains('DUAL_ROLE_NET_MISMATCH', $flags);
    }

    public function test_duplicate_real_fallback_and_return_refund_are_critical(): void
    {
        $row = $this->baseline([
            'has_duplicate_real_and_fallback' => true,
            'has_return_refund_duplicate' => true,
        ]);
        $flags = $this->service->classify($row);

        $this->assertContains('DUPLICATE_REAL_AND_FALLBACK', $flags);
        $this->assertContains('RETURN_REFUND_DUPLICATE', $flags);
        $this->assertSame('CRITICAL', $this->service->riskLevel($row, $flags));
    }

    public function test_cancel_reversal_missing_and_target_alias_are_visible(): void
    {
        $flags = $this->service->classify($this->baseline([
            'has_cancel_reversal_missing' => true,
            'has_target_type_alias' => true,
        ]));

        $this->assertContains('CANCEL_REVERSAL_MISSING', $flags);
        $this->assertContains('TARGET_TYPE_ALIAS_SUSPECT', $flags);
    }

    public function test_allocation_warnings_have_explicit_classifications(): void
    {
        $flags = $this->service->classify($this->baseline([
            'has_invoice_receipt_allocation_mismatch' => true,
            'has_purchase_payment_allocation_mismatch' => true,
        ]));

        $this->assertContains('INVOICE_RECEIPT_ALLOCATION_MISMATCH', $flags);
        $this->assertContains('PURCHASE_PAYMENT_ALLOCATION_MISMATCH', $flags);
    }

    public function test_customer_parity_matches_ui_while_technical_ledger_is_evidence_only(): void
    {
        $partner = $this->partner();
        CustomerDebt::query()->create([
            'customer_id' => $partner->id,
            'ref_code' => 'MERGE-CUSTOMER-AUDIT-1',
            'amount' => 2_000_000,
            'debt_total' => 2_000_000,
            'type' => 'adjustment',
            'recorded_at' => now(),
        ]);

        $ui = app(CustomerDebtDocumentTimelineService::class)->build($partner, []);
        $audit = $this->service->audit($partner);

        $this->assertSame(
            (float) $ui['summary']['raw_document_final_balance'],
            (float) $audit['customer_document_raw_final'],
        );
        $this->assertContains('MERGE-CUSTOMER-AUDIT-1', $audit['customer_technical_codes']);
        $this->assertContains('MERGE-CUSTOMER-AUDIT-1', $audit['excluded_technical_codes']);
        $this->assertTrue($audit['has_technical_ledger_exclusion']);
        $this->assertContains('TECHNICAL_LEDGER_EXCLUDED', $audit['classification_flags']);
        $this->assertSame(2_000_000.0, $audit['technical_customer_total']);
    }

    public function test_supplier_only_parity_matches_ui_while_technical_ledger_is_evidence_only(): void
    {
        $partner = $this->partner(['is_customer' => false, 'is_supplier' => true]);
        SupplierDebtTransaction::query()->create([
            'supplier_id' => $partner->id,
            'code' => 'OPENING-BALANCE-SUPPLIER-AUDIT-1',
            'type' => 'adjustment',
            'amount' => 3_000_000,
            'debt_remain' => 3_000_000,
            'created_at' => now(),
        ]);

        $ui = app(SupplierDebtDocumentTimelineService::class)->build($partner, []);
        $audit = $this->service->audit($partner);

        $this->assertSame(
            (float) $ui['summary']['raw_document_final_balance'],
            (float) $audit['supplier_document_raw_final'],
        );
        $this->assertContains('OPENING-BALANCE-SUPPLIER-AUDIT-1', $audit['supplier_technical_codes']);
        $this->assertContains('OPENING-BALANCE-SUPPLIER-AUDIT-1', $audit['excluded_technical_codes']);
        $this->assertTrue($audit['has_technical_ledger_exclusion']);
        $this->assertSame(3_000_000.0, $audit['technical_supplier_total']);
    }

    public function test_dual_role_supplier_parity_uses_partner_view(): void
    {
        $partner = $this->partner([
            'is_supplier' => true,
            'debt_amount' => 500_000,
            'supplier_debt_amount' => 0,
        ]);
        Invoice::query()->create([
            'code' => 'HD-DUAL-PARITY-' . uniqid(),
            'customer_id' => $partner->id,
            'status' => 'Hoàn thành',
            'total' => 500_000,
            'customer_paid' => 0,
            'transaction_date' => now(),
        ]);

        $ui = app(SupplierDebtDocumentTimelineService::class)->build($partner, ['view' => 'partner']);
        $audit = $this->service->audit($partner);

        $this->assertSame('supplier_partner_timeline', $ui['summary']['display_mode']);
        $this->assertSame(
            (float) $ui['summary']['raw_document_final_balance'],
            (float) $audit['supplier_document_raw_final'],
        );
    }

    private function partner(array $overrides = []): Customer
    {
        return Customer::query()->create(array_merge([
            'code' => 'PARITY-SERVICE-' . uniqid(),
            'name' => 'Generic Parity Service Partner',
            'phone' => '0900000000',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ], $overrides));
    }

    private function baseline(array $overrides = []): array
    {
        return array_merge([
            'role' => 'customer_only',
            'stored_customer_screen' => 0.0,
            'stored_supplier_screen' => 0.0,
            'customer_stored_vs_document_raw' => 0.0,
            'customer_stored_vs_document_display' => 0.0,
            'customer_stored_vs_ledger' => 0.0,
            'customer_document_vs_ledger' => 0.0,
            'supplier_stored_vs_document_raw' => 0.0,
            'supplier_stored_vs_document_display' => 0.0,
            'supplier_stored_vs_ledger' => 0.0,
            'supplier_document_vs_ledger' => 0.0,
            'dual_role_screen_symmetry_difference' => 0.0,
            'customer_document_entry_count' => 1,
            'supplier_document_entry_count' => 0,
            'customer_debt_count' => 1,
            'supplier_debt_transaction_count' => 0,
            'has_virtual_opening' => false,
            'customer_document_display_aligned' => false,
            'supplier_document_display_aligned' => false,
            'has_duplicate_real_and_fallback' => false,
            'has_duplicate_customer_receipt' => false,
            'has_duplicate_supplier_payment' => false,
            'has_invoice_receipt_allocation_mismatch' => false,
            'has_purchase_payment_allocation_mismatch' => false,
            'has_return_refund_duplicate' => false,
            'has_purchase_return_refund_mismatch' => false,
            'has_cancel_reversal_missing' => false,
            'has_target_type_alias' => false,
            'has_technical_ledger_exclusion' => false,
            'audit_error' => null,
        ], $overrides);
    }
}
