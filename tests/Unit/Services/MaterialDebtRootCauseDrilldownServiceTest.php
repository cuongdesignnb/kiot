<?php

namespace Tests\Unit\Services;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\CustomerPaymentAllocation;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Services\CustomerDebtDocumentTimelineService;
use App\Services\Debt\DebtReconciliationPlanService;
use App\Services\Debt\MaterialDebtRootCauseDrilldownService;
use App\Services\PartnerDebtLedgerService;
use App\Services\SupplierDebtDocumentTimelineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

class MaterialDebtRootCauseDrilldownServiceTest extends TestCase
{
    use DatabaseTransactions;

    private MaterialDebtRootCauseDrilldownService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 13, 10));
        $this->service = app(MaterialDebtRootCauseDrilldownService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_customer_stored_document_and_ledger_divergence_stays_unresolved(): void
    {
        $partner = $this->partner(['debt_amount' => 3_000_000]);
        $customerDocuments = Mockery::mock(CustomerDebtDocumentTimelineService::class);
        $customerDocuments->shouldReceive('build')->once()->with($partner, [])->andReturn($this->timeline(1_000_000));
        $supplierDocuments = Mockery::mock(SupplierDebtDocumentTimelineService::class);
        $ledgers = Mockery::mock(PartnerDebtLedgerService::class);
        $ledgers->shouldReceive('buildCustomerNetLedger')->once()->with($partner)->andReturn($this->ledger(2_000_000));
        $service = new MaterialDebtRootCauseDrilldownService($customerDocuments, $supplierDocuments, $ledgers);

        $detail = $service->drilldown($partner, $this->auditRow($partner, [
            'customer_stored_vs_document_raw' => 2_000_000,
            'customer_stored_vs_ledger' => 1_000_000,
            'customer_document_vs_ledger' => -1_000_000,
        ]));

        $this->assertSame(3_000_000.0, $detail['stored_balance']['stored_customer_screen']);
        $this->assertSame(1_000_000.0, $detail['customer_document']['raw_document_final_balance']);
        $this->assertSame(2_000_000.0, $detail['customer_ledger']['ledger_final']);
        $this->assertSame('UNRESOLVED', $detail['source_of_truth_status']);
        $this->assertSame('MULTI_SOURCE_DIVERGENCE', $this->pattern($detail, 'MULTI_SOURCE_DIVERGENCE')['pattern']);
        $this->assertArrayNotHasKey('customer_delta', $detail);
        $this->assertArrayNotHasKey('proposed_voucher', $detail);
    }

    public function test_generic_supplier_payment_without_persisted_allocation_is_not_actual(): void
    {
        $partner = $this->partner(['is_customer' => false, 'is_supplier' => true]);
        $this->supplierPayment($partner, 'PCPN-UNALLOCATED', 1_000_000);

        $detail = $this->service->drilldown($partner, $this->auditRow($partner, [
            'role' => 'supplier_only',
            'supplier_stored_vs_document_raw' => 1_000_000,
        ]));
        $evidence = collect($detail['allocation_evidence']['supplier_payments'])->firstWhere('cashflow_code', 'PCPN-UNALLOCATED');
        $pattern = $this->pattern($detail, 'GENERIC_SUPPLIER_PAYMENT_UNALLOCATED');

        $this->assertNotNull($evidence);
        $this->assertFalse($evidence['explicitly_allocated']);
        $this->assertContains($evidence['allocation_confidence'], ['unknown', 'inferred']);
        $this->assertNotSame('high', $pattern['confidence']);
        $this->assertSame('UNRESOLVED', $detail['source_of_truth_status']);
    }

    public function test_customer_receipt_prefers_reference_id_and_detects_code_conflict(): void
    {
        $partner = $this->partner();
        $first = $this->invoice($partner, 'HD-ALLOC-ID-A', 900_000);
        $first->customer_paid = 900_000;
        $second = $this->invoice($partner, 'HD-ALLOC-ID-B', 900_000);
        $flow = $this->syntheticCashFlow([
            'id' => 800001,
            'code' => 'PT-ALLOC-ID',
            'type' => 'receipt',
            'amount' => 900_000,
            'reference_type' => 'Invoice',
            'reference_id' => $first->id,
            'reference_code' => $second->code,
            'status' => 'active',
        ]);

        $evidence = $this->invokeService('allocationEvidence', [
            collect([$flow]), collect(), collect([$first, $second]), collect(), null,
        ])['customer_receipts'][0];

        $this->assertSame('id', $evidence['reference_match_method']);
        $this->assertTrue($evidence['reference_conflict']);
        $this->assertTrue($evidence['explicitly_allocated']);
        $this->assertSame('actual_reference', $evidence['allocation_confidence']);
        $this->assertSame(0.0, $evidence['unallocated_amount']);
        $this->assertSame($first->id, $evidence['candidate_documents'][0]['document_id']);
        $this->assertSame('invoice_reference_id_code_conflict', $evidence['warning']);
    }

    public function test_customer_receipt_reference_id_only_is_actual_reference(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-ALLOC-ID-ONLY', 500_000);
        $invoice->customer_paid = 500_000;
        $flow = $this->syntheticCashFlow([
            'id' => 800002,
            'code' => 'PT-ALLOC-ID-ONLY',
            'type' => 'receipt',
            'amount' => 500_000,
            'reference_type' => 'Invoice',
            'reference_id' => $invoice->id,
            'reference_code' => null,
            'status' => 'active',
        ]);

        $evidence = $this->invokeService('allocationEvidence', [
            collect([$flow]), collect(), collect([$invoice]), collect(), null,
        ])['customer_receipts'][0];

        $this->assertSame('id', $evidence['reference_match_method']);
        $this->assertFalse($evidence['reference_conflict']);
        $this->assertTrue($evidence['explicitly_allocated']);
        $this->assertSame(0.0, $evidence['unallocated_amount']);
    }

    public function test_invalid_non_null_reference_id_does_not_fallback_to_code(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-INVALID-ID-FALLBACK', 500_000);
        $invoice->customer_paid = 500_000;
        foreach (['abc', 0, -1, ''] as $referenceId) {
            $flow = $this->syntheticCashFlow([
                'id' => 810000 + crc32((string) $referenceId),
                'code' => 'PT-INVALID-ID-'.md5((string) $referenceId),
                'type' => 'receipt',
                'amount' => 500_000,
                'reference_type' => 'Invoice',
                'reference_id' => $referenceId,
                'reference_code' => $invoice->code,
                'status' => 'active',
            ]);

            $evidence = $this->invokeService('allocationEvidence', [
                collect([$flow]), collect(), collect([$invoice]), collect(), null,
            ])['customer_receipts'][0];

            $this->assertFalse($evidence['explicitly_allocated'], 'Invalid present ID must not fall back to code.');
            $this->assertSame('none', $evidence['reference_match_method']);
            $this->assertSame('invoice_reference_id_invalid', $evidence['warning']);
        }
    }

    public function test_positive_missing_reference_id_does_not_fallback_to_code(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-MISSING-ID-FALLBACK', 500_000);
        $flow = $this->syntheticCashFlow([
            'id' => 810010,
            'code' => 'PT-MISSING-ID',
            'type' => 'receipt',
            'amount' => 500_000,
            'reference_type' => 'Invoice',
            'reference_id' => 999999999,
            'reference_code' => $invoice->code,
            'status' => 'active',
        ]);

        $evidence = $this->invokeService('allocationEvidence', [
            collect([$flow]), collect(), collect([$invoice]), collect(), null,
        ])['customer_receipts'][0];

        $this->assertFalse($evidence['explicitly_allocated']);
        $this->assertSame('id', $evidence['reference_match_method']);
        $this->assertSame('invoice_reference_id_not_found', $evidence['warning']);
    }

    public function test_null_reference_id_uses_code_fallback(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-NULL-ID-CODE', 500_000);
        $invoice->customer_paid = 500_000;
        $flow = $this->syntheticCashFlow([
            'id' => 810011,
            'code' => 'PT-NULL-ID-CODE',
            'type' => 'receipt',
            'amount' => 500_000,
            'reference_type' => 'Invoice',
            'reference_id' => null,
            'reference_code' => $invoice->code,
            'status' => 'active',
        ]);

        $evidence = $this->invokeService('allocationEvidence', [
            collect([$flow]), collect(), collect([$invoice]), collect(), null,
        ])['customer_receipts'][0];

        $this->assertTrue($evidence['explicitly_allocated']);
        $this->assertSame('code', $evidence['reference_match_method']);
    }

    public function test_supplier_payment_prefers_purchase_reference_id_and_detects_code_conflict(): void
    {
        $partner = $this->partner(['is_customer' => false, 'is_supplier' => true]);
        $first = $this->purchase($partner, 'PN-ALLOC-ID-A', 1_000_000);
        $second = $this->purchase($partner, 'PN-ALLOC-ID-B', 1_000_000);
        $flow = $this->syntheticCashFlow([
            'id' => 800003,
            'code' => 'PCPN-ALLOC-ID',
            'type' => 'payment',
            'amount' => 1_000_000,
            'reference_type' => 'Purchase',
            'reference_id' => $first->id,
            'reference_code' => $second->code,
            'status' => 'active',
        ]);

        $evidence = $this->invokeService('allocationEvidence', [
            collect(), collect([$flow]), collect(), collect([$first, $second]), null,
        ])['supplier_payments'][0];

        $this->assertSame('id', $evidence['reference_match_method']);
        $this->assertTrue($evidence['reference_conflict']);
        $this->assertTrue($evidence['explicitly_allocated']);
        $this->assertSame($first->id, $evidence['candidate_documents'][0]['document_id']);
        $this->assertSame('purchase_reference_id_code_conflict', $evidence['warning']);
    }

    public function test_supplier_purchase_reference_id_only_is_explicit_actual_reference(): void
    {
        $partner = $this->partner(['is_customer' => false, 'is_supplier' => true]);
        $purchase = $this->purchase($partner, 'PN-ALLOC-ID-ONLY', 1_000_000);
        $flow = $this->syntheticCashFlow([
            'id' => 800004,
            'code' => 'PCPN-ALLOC-ID-ONLY',
            'type' => 'payment',
            'amount' => 1_000_000,
            'reference_type' => 'Purchase',
            'reference_id' => $purchase->id,
            'reference_code' => null,
            'status' => 'active',
        ]);

        $evidence = $this->invokeService('allocationEvidence', [
            collect(), collect([$flow]), collect(), collect([$purchase]), null,
        ])['supplier_payments'][0];

        $this->assertSame('id', $evidence['reference_match_method']);
        $this->assertTrue($evidence['explicitly_allocated']);
        $this->assertFalse($evidence['inferred_allocation']);
        $this->assertSame('actual_reference', $evidence['allocation_confidence']);
    }

    public function test_historical_receipt_and_cancelled_supplier_payment_are_excluded_from_active_allocation(): void
    {
        $partner = $this->partner(['is_supplier' => true]);
        $invoice = $this->invoice($partner, 'HD-HISTORICAL-ALLOC', 500_000);
        $invoice->customer_paid = 500_000;
        $purchase = $this->purchase($partner, 'PN-HISTORICAL-ALLOC', 500_000);
        $receipt = $this->syntheticCashFlow([
            'id' => 800005,
            'code' => 'PT-HISTORICAL-ALLOC',
            'type' => 'receipt',
            'amount' => 500_000,
            'reference_type' => 'Invoice',
            'reference_id' => $invoice->id,
            'status' => 'active',
            'deleted_at' => now(),
        ]);
        $payment = $this->syntheticCashFlow([
            'id' => 800006,
            'code' => 'PCPN-HISTORICAL-ALLOC',
            'type' => 'payment',
            'amount' => 500_000,
            'reference_type' => 'Purchase',
            'reference_id' => $purchase->id,
            'status' => 'cancelled',
        ]);

        $evidence = $this->invokeService('allocationEvidence', [
            collect([$receipt]), collect([$payment]), collect([$invoice]), collect([$purchase]), null,
        ]);

        foreach ([$evidence['customer_receipts'][0], $evidence['supplier_payments'][0]] as $row) {
            $this->assertSame('historical', $row['evidence_scope']);
            $this->assertFalse($row['is_active_for_balance']);
            $this->assertFalse($row['explicitly_allocated']);
            $this->assertSame(0.0, $row['unallocated_amount']);
        }
    }

    public function test_supplier_fifo_projection_remains_inferred_not_actual(): void
    {
        $partner = $this->partner(['is_customer' => false, 'is_supplier' => true]);
        $purchase = $this->purchase($partner, 'PN-FIFO-PROJECTION', 1_000_000);
        $flow = $this->syntheticCashFlow([
            'id' => 800007,
            'code' => 'PCPN-FIFO-PROJECTION',
            'type' => 'payment',
            'amount' => 1_000_000,
            'reference_type' => 'SupplierPayment',
            'reference_code' => 'PCPN-FIFO-PROJECTION',
            'status' => 'active',
        ]);
        $timeline = ['reconcile' => ['generic_payment_allocation' => ['inferred_allocations' => [[
            'payment_code' => $flow->code,
            'purchase_code' => $purchase->code,
            'amount' => 1_000_000,
        ]]]]];

        $evidence = $this->invokeService('allocationEvidence', [
            collect(), collect([$flow]), collect(), collect([$purchase]), $timeline,
        ])['supplier_payments'][0];

        $this->assertSame('fifo_projection', $evidence['reference_match_method']);
        $this->assertTrue($evidence['inferred_allocation']);
        $this->assertFalse($evidence['explicitly_allocated']);
        $this->assertSame('inferred', $evidence['allocation_confidence']);
    }

    public function test_persisted_customer_allocation_is_strongest_valid_evidence(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-PERSISTED-ALLOCATION', 500_000);
        $flow = $this->customerReceipt($partner, 'PT-PERSISTED-ALLOCATION', 500_000);
        CustomerPaymentAllocation::query()->create([
            'cash_flow_id' => $flow->id,
            'customer_id' => $partner->id,
            'invoice_id' => $invoice->id,
            'amount' => 500_000,
        ]);

        $evidence = $this->invokeService('allocationEvidence', [
            collect([$flow]), collect(), collect([$invoice]), collect(), null,
        ])['customer_receipts'][0];

        $this->assertTrue($evidence['explicitly_allocated']);
        $this->assertSame('allocation_table', $evidence['reference_match_method']);
        $this->assertSame(500_000.0, $evidence['allocated_amount']);
        $this->assertSame(0.0, $evidence['unallocated_amount']);
    }

    public function test_cross_partner_customer_allocation_is_rejected_as_actual_evidence(): void
    {
        $owner = $this->partner();
        $foreign = $this->partner();
        $foreignInvoice = $this->invoice($foreign, 'HD-FOREIGN-ALLOCATION', 500_000);
        $flow = $this->customerReceipt($owner, 'PT-CROSS-PARTNER-ALLOCATION', 500_000);
        CustomerPaymentAllocation::query()->create([
            'cash_flow_id' => $flow->id,
            'customer_id' => $foreign->id,
            'invoice_id' => $foreignInvoice->id,
            'amount' => 500_000,
        ]);

        $evidence = $this->invokeService('allocationEvidence', [
            collect([$flow]), collect(), collect([$foreignInvoice]), collect(), null,
        ])['customer_receipts'][0];

        $this->assertFalse($evidence['explicitly_allocated']);
        $this->assertSame(0.0, $evidence['allocated_amount']);
        $this->assertSame(500_000.0, $evidence['unallocated_amount']);
        $this->assertSame('customer_allocation_ownership_mismatch', $evidence['warning']);
        $this->assertTrue($evidence['candidate_documents'][0]['invalid_ownership']);
    }

    public function test_allocation_to_foreign_or_stale_invoice_is_warning_not_actual(): void
    {
        $owner = $this->partner();
        $foreign = $this->partner();
        $foreignInvoice = $this->invoice($foreign, 'HD-FOREIGN-INVOICE', 300_000);
        $flow = $this->customerReceipt($owner, 'PT-FOREIGN-INVOICE', 300_000);
        CustomerPaymentAllocation::query()->create([
            'cash_flow_id' => $flow->id,
            'customer_id' => $owner->id,
            'invoice_id' => $foreignInvoice->id,
            'amount' => 300_000,
        ]);

        $evidence = $this->invokeService('allocationEvidence', [
            collect([$flow]), collect(), collect(), collect(), null,
        ])['customer_receipts'][0];

        $this->assertFalse($evidence['explicitly_allocated']);
        $this->assertSame('customer_allocation_invoice_unavailable', $evidence['warning']);
        $this->assertSame(300_000.0, $evidence['unallocated_amount']);
    }

    public function test_allocated_sum_exceeding_cashflow_amount_is_flagged_not_actual(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-OVER-ALLOCATED', 700_000);
        $flow = $this->customerReceipt($partner, 'PT-OVER-ALLOCATED', 500_000);
        CustomerPaymentAllocation::query()->create([
            'cash_flow_id' => $flow->id,
            'customer_id' => $partner->id,
            'invoice_id' => $invoice->id,
            'amount' => 700_000,
        ]);

        $evidence = $this->invokeService('allocationEvidence', [
            collect([$flow]), collect(), collect([$invoice]), collect(), null,
        ])['customer_receipts'][0];

        $this->assertFalse($evidence['explicitly_allocated']);
        $this->assertSame(0.0, $evidence['allocated_amount']);
        $this->assertSame(500_000.0, $evidence['unallocated_amount']);
        $this->assertSame('customer_allocation_exceeds_cashflow_amount', $evidence['warning']);
    }

    public function test_cancelled_invoice_without_reversal_is_evidence_only_and_does_not_write(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-CANCEL-MISSING', 900_000, 'cancelled');
        $before = [CustomerDebt::query()->count(), CashFlow::withTrashed()->count()];

        $detail = $this->service->drilldown($partner, $this->auditRow($partner, [
            'classification_flags' => ['CANCEL_REVERSAL_MISSING'],
        ]));
        $cancelled = collect($detail['cancelled_invoices'])->firstWhere('invoice_id', $invoice->id);

        $this->assertTrue($cancelled['missing_reversal']);
        $this->assertFalse($cancelled['has_exact_reversal']);
        $this->assertFalse($cancelled['has_partial_reversal']);
        $this->assertSame('CANCELLED_INVOICE_REVERSAL_GAP', $this->pattern($detail, 'CANCELLED_INVOICE_REVERSAL_GAP')['pattern']);
        $this->assertContains('Cancelled invoice reversal voucher', $detail['missing_evidence']);
        $this->assertSame($before, [CustomerDebt::query()->count(), CashFlow::withTrashed()->count()]);
    }

    public function test_cancelled_invoice_with_exact_code_reversal_is_not_flagged_missing(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-CANCEL-EXACT', 900_000, 'cancelled');
        CustomerDebt::query()->create([
            'customer_id' => $partner->id,
            'ref_code' => $invoice->code,
            'type' => 'sale_reversal',
            'amount' => -900_000,
            'debt_total' => 0,
            'recorded_at' => now(),
        ]);

        $detail = $this->service->drilldown($partner, $this->auditRow($partner));
        $cancelled = collect($detail['cancelled_invoices'])->firstWhere('invoice_id', $invoice->id);

        $this->assertTrue($cancelled['has_exact_reversal']);
        $this->assertFalse($cancelled['missing_reversal']);
        $this->assertSame(900_000.0, $cancelled['expected_reversal_amount']);
        $this->assertSame(900_000.0, $cancelled['active_reversal_amount']);
        $this->assertNull($this->pattern($detail, 'CANCELLED_INVOICE_REVERSAL_GAP'));
    }

    public function test_current_invoice_cancellation_adjustment_semantics_count_as_exact_reversal(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-CANCEL-CURRENT-SEMANTICS', 900_000, 'cancelled');
        CustomerDebt::query()->create([
            'customer_id' => $partner->id,
            'ref_code' => $invoice->code,
            'type' => 'adjustment',
            'amount' => -900_000,
            'debt_total' => 0,
            'note' => 'Đảo công nợ do hủy hóa đơn ' . $invoice->code,
            'recorded_at' => now(),
        ]);

        $detail = $this->service->drilldown($partner, $this->auditRow($partner));
        $row = collect($detail['cancelled_invoices'])->firstWhere('invoice_id', $invoice->id);

        $this->assertTrue($row['has_exact_reversal']);
        $this->assertSame([$invoice->code], $row['matching_customer_debt_reversal_codes']);
        $this->assertSame('code', $row['match_method']);
    }

    public function test_original_active_invoice_receipt_never_counts_as_reversal(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-CANCEL-RECEIPT', 900_000, 'cancelled');
        CashFlow::query()->create([
            'code' => 'PT-ORIGINAL-ACTIVE',
            'type' => 'receipt',
            'amount' => 900_000,
            'time' => now(),
            'target_type' => 'Khach hang',
            'target_id' => $partner->id,
            'target_name' => 'Generic Partner',
            'reference_type' => 'Invoice',
            'reference_code' => $invoice->code,
            'status' => 'active',
        ]);

        $detail = $this->service->drilldown($partner, $this->auditRow($partner));
        $row = collect($detail['cancelled_invoices'])->firstWhere('invoice_id', $invoice->id);

        $this->assertSame(['PT-ORIGINAL-ACTIVE'], $row['matching_original_receipt_codes']);
        $this->assertSame([], $row['matching_active_reversal_codes']);
        $this->assertTrue($row['missing_reversal']);
        $this->assertSame(0.0, $row['active_reversal_amount']);
    }

    public function test_deleted_original_receipt_is_historical_and_does_not_satisfy_reversal(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-CANCEL-DELETED-RECEIPT', 500_000, 'cancelled');
        $receipt = CashFlow::query()->create([
            'code' => 'PT-ORIGINAL-DELETED',
            'type' => 'receipt',
            'amount' => 500_000,
            'time' => now(),
            'target_type' => 'Khach hang',
            'target_id' => $partner->id,
            'target_name' => 'Generic Partner',
            'reference_type' => 'Invoice',
            'reference_code' => $invoice->code,
            'status' => 'cancelled',
        ]);
        $receipt->delete();

        $detail = $this->service->drilldown($partner, $this->auditRow($partner));
        $row = collect($detail['cancelled_invoices'])->firstWhere('invoice_id', $invoice->id);
        $evidence = collect($detail['allocation_evidence']['customer_receipts'])->firstWhere('cashflow_code', $receipt->code);

        $this->assertSame('historical', $evidence['evidence_scope']);
        $this->assertFalse($evidence['explicitly_allocated']);
        $this->assertTrue($row['missing_reversal']);
    }

    public function test_partial_customer_debt_reversal_is_not_exact(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-CANCEL-PARTIAL', 900_000, 'cancelled');
        CustomerDebt::query()->create([
            'customer_id' => $partner->id,
            'ref_code' => $invoice->code,
            'type' => 'sale_reversal',
            'amount' => -400_000,
            'debt_total' => 0,
            'recorded_at' => now(),
        ]);

        $detail = $this->service->drilldown($partner, $this->auditRow($partner));
        $row = collect($detail['cancelled_invoices'])->firstWhere('invoice_id', $invoice->id);

        $this->assertFalse($row['has_exact_reversal']);
        $this->assertTrue($row['has_partial_reversal']);
        $this->assertFalse($row['missing_reversal']);
        $this->assertSame(500_000.0, $row['candidate_amount_difference']);
        $this->assertNotNull($this->pattern($detail, 'CANCELLED_INVOICE_REVERSAL_GAP'));
        $this->assertSame('under_reversed', $row['reversal_state']);
    }

    public function test_over_reversal_emits_cancellation_amount_gap_pattern(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-CANCEL-OVER', 900_000, 'cancelled');
        CustomerDebt::query()->create([
            'customer_id' => $partner->id,
            'ref_code' => $invoice->code,
            'type' => 'sale_reversal',
            'amount' => -1_200_000,
            'debt_total' => 0,
            'recorded_at' => now(),
        ]);

        $detail = $this->service->drilldown($partner, $this->auditRow($partner));
        $row = collect($detail['cancelled_invoices'])->firstWhere('invoice_id', $invoice->id);

        $this->assertSame('over_reversed', $row['reversal_state']);
        $this->assertFalse($row['has_exact_reversal']);
        $this->assertNotNull($this->pattern($detail, 'CANCELLED_INVOICE_REVERSAL_GAP'));
    }

    public function test_duplicate_reversal_rows_do_not_silently_suppress_gap(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-CANCEL-DUPLICATE-REVERSAL', 900_000, 'cancelled');
        foreach ([1, 2] as $index) {
            CustomerDebt::query()->create([
                'customer_id' => $partner->id,
                'ref_code' => $invoice->code,
                'type' => 'sale_reversal',
                'amount' => -900_000,
                'debt_total' => 0,
                'note' => 'Duplicate evidence '.$index,
                'recorded_at' => now(),
            ]);
        }

        $detail = $this->service->drilldown($partner, $this->auditRow($partner));
        $row = collect($detail['cancelled_invoices'])->firstWhere('invoice_id', $invoice->id);

        $this->assertSame('over_reversed', $row['reversal_state']);
        $this->assertNotNull($this->pattern($detail, 'CANCELLED_INVOICE_REVERSAL_GAP'));
    }

    public function test_zero_expected_reversal_is_not_flagged(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-CANCEL-ZERO-EXPECTED', 900_000, 'cancelled');
        $invoice->customer_paid = 900_000;
        $invoice->save();

        $detail = $this->service->drilldown($partner, $this->auditRow($partner));
        $row = collect($detail['cancelled_invoices'])->firstWhere('invoice_id', $invoice->id);

        $this->assertSame('not_required', $row['reversal_state']);
        $this->assertFalse($row['missing_reversal']);
        $this->assertNull($this->pattern($detail, 'CANCELLED_INVOICE_REVERSAL_GAP'));
    }

    public function test_historical_explicit_cashflow_reversal_does_not_satisfy_active_contract(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-CANCEL-HISTORICAL', 900_000, 'cancelled');
        $flow = $this->syntheticCashFlow([
            'id' => 700001,
            'code' => 'PC-INVOICE-CANCEL-HISTORICAL',
            'type' => 'payment',
            'amount' => 900_000,
            'reference_type' => 'InvoiceCancellation',
            'reference_id' => $invoice->id,
            'reference_code' => $invoice->code,
            'status' => 'cancelled',
            'deleted_at' => now(),
        ]);

        $row = $this->invokeService('cancellationMatrix', [collect([$invoice]), collect(), collect([$flow])])[0];

        $this->assertSame(['PC-INVOICE-CANCEL-HISTORICAL'], $row['matching_historical_reversal_codes']);
        $this->assertSame([], $row['matching_active_reversal_codes']);
        $this->assertSame(900_000.0, $row['historical_reversal_amount']);
        $this->assertTrue($row['missing_reversal']);
    }

    public function test_generic_adjustment_with_matching_code_is_not_a_reversal(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-CANCEL-GENERIC-ADJUSTMENT', 900_000, 'cancelled');
        CustomerDebt::query()->create([
            'customer_id' => $partner->id,
            'ref_code' => $invoice->code,
            'type' => 'adjustment',
            'amount' => -900_000,
            'debt_total' => 0,
            'note' => 'Generic adjustment only',
            'recorded_at' => now(),
        ]);

        $detail = $this->service->drilldown($partner, $this->auditRow($partner));
        $row = collect($detail['cancelled_invoices'])->firstWhere('invoice_id', $invoice->id);

        $this->assertSame([], $row['matching_customer_debt_reversal_codes']);
        $this->assertFalse($row['has_exact_reversal']);
        $this->assertTrue($row['missing_reversal']);
    }

    public function test_explicit_cashflow_reversal_uses_reference_id_without_code(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-CANCEL-ID-ONLY', 900_000, 'cancelled');
        $flow = $this->syntheticCashFlow([
            'id' => 700002,
            'code' => 'PC-INVOICE-CANCEL-ID',
            'type' => 'payment',
            'amount' => 900_000,
            'reference_type' => 'InvoiceCancellation',
            'reference_id' => $invoice->id,
            'reference_code' => null,
            'status' => 'active',
        ]);

        $row = $this->invokeService('cancellationMatrix', [collect([$invoice]), collect(), collect([$flow])])[0];

        $this->assertSame('id', $row['match_method']);
        $this->assertTrue($row['has_exact_reversal']);
        $this->assertFalse($row['reference_conflict']);
    }

    public function test_invalid_reversal_reference_id_does_not_fallback_and_keeps_warning(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-CANCEL-INVALID-ID', 900_000, 'cancelled');
        $flow = $this->syntheticCashFlow([
            'id' => 700020,
            'code' => 'PC-INVOICE-CANCEL-INVALID-ID',
            'type' => 'payment',
            'amount' => 900_000,
            'reference_type' => 'InvoiceCancellation',
            'reference_id' => 'invalid',
            'reference_code' => $invoice->code,
            'status' => 'active',
        ]);

        $row = $this->invokeService('cancellationMatrix', [collect([$invoice]), collect(), collect([$flow])])[0];

        $this->assertTrue($row['missing_reversal']);
        $this->assertSame([], $row['matching_active_reversal_codes']);
        $this->assertContains('invoice_reference_id_invalid', $row['warnings']);
    }

    public function test_explicit_reversal_id_is_authoritative_when_code_conflicts(): void
    {
        $partner = $this->partner();
        $first = $this->invoice($partner, 'HD-CANCEL-ID-A', 900_000, 'cancelled');
        $second = $this->invoice($partner, 'HD-CANCEL-ID-B', 900_000, 'cancelled');
        $flow = $this->syntheticCashFlow([
            'id' => 700003,
            'code' => 'PC-INVOICE-CANCEL-CONFLICT',
            'type' => 'payment',
            'amount' => 900_000,
            'reference_type' => 'InvoiceCancellation',
            'reference_id' => $first->id,
            'reference_code' => $second->code,
            'status' => 'active',
        ]);

        $rows = collect($this->invokeService('cancellationMatrix', [collect([$first, $second]), collect(), collect([$flow])]))
            ->keyBy('invoice_id');

        $this->assertTrue($rows[$first->id]['has_exact_reversal']);
        $this->assertTrue($rows[$first->id]['reference_conflict']);
        $this->assertContains('invoice_reference_id_code_conflict', $rows[$first->id]['warnings']);
        $this->assertTrue($rows[$second->id]['missing_reversal']);
    }

    public function test_multiple_reversal_match_methods_are_preserved(): void
    {
        $partner = $this->partner();
        $invoice = $this->invoice($partner, 'HD-CANCEL-MULTI-METHOD', 900_000, 'cancelled');
        $invoice->order_id = 4242;
        $codeDebt = new CustomerDebt([
            'ref_code' => $invoice->code,
            'type' => 'sale_reversal',
            'amount' => -450_000,
        ]);
        $relationshipDebt = new CustomerDebt([
            'ref_code' => 'OTHER-REF',
            'order_id' => 4242,
            'type' => 'sale_reversal',
            'amount' => -450_000,
        ]);
        $idFlow = $this->syntheticCashFlow([
            'id' => 700010,
            'code' => 'PC-CANCEL-ID-METHOD',
            'type' => 'payment',
            'amount' => 10,
            'reference_type' => 'InvoiceCancellation',
            'reference_id' => $invoice->id,
            'status' => 'active',
        ]);

        $row = $this->invokeService('cancellationMatrix', [
            collect([$invoice]), collect([$codeDebt, $relationshipDebt]), collect([$idFlow]),
        ])[0];

        $this->assertSame(['id', 'relationship', 'code'], $row['match_methods']);
        $this->assertSame('id', $row['match_method']);
    }

    public function test_multiple_reference_conflicts_are_preserved_with_typed_details(): void
    {
        $partner = $this->partner();
        $first = $this->invoice($partner, 'HD-CANCEL-CONFLICT-A', 900_000, 'cancelled');
        $second = $this->invoice($partner, 'HD-CANCEL-CONFLICT-B', 900_000, 'cancelled');
        $flows = collect([11, 12])->map(fn (int $suffix): CashFlow => $this->syntheticCashFlow([
            'id' => 700000 + $suffix,
            'code' => 'PC-CANCEL-CONFLICT-'.$suffix,
            'type' => 'payment',
            'amount' => 450_000,
            'reference_type' => 'InvoiceCancellation',
            'reference_id' => $first->id,
            'reference_code' => $second->code,
            'status' => 'active',
        ]));

        $row = collect($this->invokeService('cancellationMatrix', [
            collect([$first, $second]), collect(), $flows,
        ]))->firstWhere('invoice_id', $first->id);

        $this->assertCount(2, $row['reference_conflicts']);
        $this->assertSame(
            ['invoice_reference_id_code_conflict'],
            collect($row['reference_conflicts'])->pluck('warning')->unique()->values()->all(),
        );
        $this->assertSame([700011, 700012], collect($row['reference_conflicts'])->pluck('cashflow_id')->all());
    }

    public function test_document_without_ledger_and_ledger_without_document_are_distinguished(): void
    {
        $withDocument = $this->partner();
        $this->invoice($withDocument, 'HD-DOCUMENT-ONLY', 700_000);
        $documentDetail = $this->service->drilldown($withDocument, $this->auditRow($withDocument));
        $this->assertNotNull($this->pattern($documentDetail, 'DOCUMENT_HISTORY_WITHOUT_LEDGER'));

        $withLedger = $this->partner();
        CustomerDebt::query()->create([
            'customer_id' => $withLedger->id,
            'ref_code' => 'DCCN-LEDGER-ONLY',
            'type' => 'adjustment',
            'amount' => 400_000,
            'debt_total' => 400_000,
            'recorded_at' => now(),
        ]);
        $ledgerDetail = $this->service->drilldown($withLedger, $this->auditRow($withLedger));
        $this->assertNotNull($this->pattern($ledgerDetail, 'LEDGER_HISTORY_WITHOUT_DOCUMENT'));
    }

    public function test_legacy_opening_is_low_confidence_heuristic_only(): void
    {
        $partner = $this->partner(['debt_amount' => 4_300_000]);

        $detail = $this->service->drilldown($partner, $this->auditRow($partner, [
            'customer_stored_vs_document_raw' => 4_300_000,
            'customer_stored_vs_ledger' => 4_300_000,
        ]));
        $pattern = $this->pattern($detail, 'LEGACY_OPENING_BALANCE_GAP');

        $this->assertSame('low', $pattern['confidence']);
        $this->assertTrue($detail['timeline_coverage']['has_possible_legacy_opening_balance']);
        $this->assertSame('UNRESOLVED', $detail['source_of_truth_status']);
    }

    public function test_dual_role_inconsistency_is_flagged_without_netting_mutation(): void
    {
        $partner = $this->partner([
            'is_supplier' => true,
            'debt_amount' => 1_500_000,
            'supplier_debt_amount' => 600_000,
        ]);
        $before = [(float) $partner->debt_amount, (float) $partner->supplier_debt_amount];

        $detail = $this->service->drilldown($partner, $this->auditRow($partner, [
            'role' => 'dual_role',
            'classification_flags' => ['DUAL_ROLE_NET_MISMATCH'],
        ]));

        $this->assertNotNull($this->pattern($detail, 'DUAL_ROLE_NETTING_INCONSISTENCY'));
        $this->assertSame(900_000.0, $detail['stored_balance']['customer_screen']);
        $this->assertSame(-900_000.0, $detail['stored_balance']['supplier_screen']);
        $this->assertSame(0.0, $detail['stored_balance']['expected_symmetry']);
        $this->assertSame(0.0, $detail['stored_balance']['actual_symmetry']);
        $fresh = $partner->fresh();
        $this->assertSame($before, [(float) $fresh->debt_amount, (float) $fresh->supplier_debt_amount]);
    }

    public function test_technical_ledger_is_exported_but_not_added_to_document_raw_balance(): void
    {
        $partner = $this->partner(['debt_amount' => 2_000_000]);
        CustomerDebt::query()->create([
            'customer_id' => $partner->id,
            'ref_code' => 'MERGE-CUSTOMER-GENERIC',
            'type' => 'adjustment',
            'amount' => 2_000_000,
            'debt_total' => 2_000_000,
            'recorded_at' => now(),
        ]);
        $ui = app(CustomerDebtDocumentTimelineService::class)->build($partner, []);

        $detail = $this->service->drilldown($partner, $this->auditRow($partner, [
            'has_technical_ledger_exclusion' => true,
        ]));

        $this->assertSame(
            (float) $ui['summary']['raw_document_final_balance'],
            $detail['customer_document']['raw_document_final_balance'],
        );
        $this->assertContains('MERGE-CUSTOMER-GENERIC', collect($detail['technical_exclusions'])->pluck('code')->all());
        $this->assertNotNull($this->pattern($detail, 'TECHNICAL_LEDGER_EXCLUDED'));
    }

    public function test_unaccented_target_alias_is_preserved_as_evidence_and_not_normalized(): void
    {
        $partner = $this->partner();
        CashFlow::query()->create([
            'code' => 'PT-ALIAS-GENERIC',
            'type' => 'receipt',
            'amount' => 100_000,
            'time' => now(),
            'target_type' => 'Khach hang',
            'target_id' => $partner->id,
            'target_name' => 'Generic Partner',
            'reference_type' => 'DebtPayment',
            'status' => 'active',
        ]);

        $detail = $this->service->drilldown($partner, $this->auditRow($partner));

        $this->assertNotNull($this->pattern($detail, 'TARGET_TYPE_ALIAS_PRESENT'));
        $this->assertSame('Khach hang', CashFlow::query()->where('code', 'PT-ALIAS-GENERIC')->value('target_type'));
        $this->assertSame('UNRESOLVED', $detail['source_of_truth_status']);
        $this->assertArrayNotHasKey('confirmed_data_error', $detail);
    }

    public function test_detail_json_whitelist_excludes_partner_pii_and_secrets(): void
    {
        $partner = $this->partner([
            'name' => 'PII NAME SHOULD NOT EXPORT',
            'phone' => '0911222333',
            'email' => 'private@example.test',
            'address' => 'PRIVATE ADDRESS',
        ]);

        $json = json_encode($this->service->drilldown($partner, $this->auditRow($partner)), JSON_THROW_ON_ERROR);

        foreach (['PII NAME SHOULD NOT EXPORT', '0911222333', 'private@example.test', 'PRIVATE ADDRESS'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        foreach (['"phone"', '"email"', '"address"', '"password"', '"token"'] as $field) {
            $this->assertStringNotContainsString($field, $json);
        }
    }

    public function test_same_fixture_has_deterministic_pattern_and_evidence_order(): void
    {
        $partner = $this->partner(['debt_amount' => 1_200_000]);
        $this->invoice($partner, 'HD-DETERMINISTIC-B', 700_000);
        $this->invoice($partner, 'HD-DETERMINISTIC-A', 500_000);
        $row = $this->auditRow($partner, ['customer_stored_vs_ledger' => 1_200_000]);

        $first = $this->service->drilldown($partner, $row);
        $second = $this->service->drilldown($partner, $row);

        $this->assertSame($first['observed_patterns'], $second['observed_patterns']);
        $this->assertSame($first['invoices'], $second['invoices']);
        $this->assertSame($first, $second);
    }

    public function test_existing_reconciliation_plan_contract_remains_blocked_and_zero_delta(): void
    {
        $row = $this->auditRow($this->partner(), [
            'primary_classification' => 'CUSTOMER_STORED_VS_DOCUMENT',
            'classification_flags' => ['CUSTOMER_STORED_VS_DOCUMENT'],
            'risk_level' => 'HIGH',
        ]);

        $plan = app(DebtReconciliationPlanService::class)->planRow($row);

        $this->assertSame('BLOCKED_UNCERTAIN_SOURCE_OF_TRUTH', $plan['proposed_action_type']);
        $this->assertSame(0.0, $plan['customer_delta']);
        $this->assertSame(0.0, $plan['supplier_delta']);
        $this->assertNull($plan['proposed_voucher']);
        $this->assertSame('PROPOSED', $plan['status']);
    }

    private function partner(array $overrides = []): Customer
    {
        return Customer::query()->forceCreate(array_merge([
            'code' => 'DRILLDOWN-' . uniqid(),
            'name' => 'Generic Drilldown Partner',
            'phone' => '09' . random_int(10_000_000, 99_999_999),
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ], $overrides));
    }

    private function invoice(Customer $partner, string $code, float $total, string $status = 'completed'): Invoice
    {
        return Invoice::query()->create([
            'code' => $code,
            'customer_id' => $partner->id,
            'status' => $status,
            'total' => $total,
            'customer_paid' => 0,
            'transaction_date' => now(),
        ]);
    }

    private function purchase(Customer $partner, string $code, float $total): Purchase
    {
        return Purchase::query()->create([
            'code' => $code,
            'supplier_id' => $partner->id,
            'total_amount' => $total,
            'paid_amount' => 0,
            'debt_amount' => $total,
            'status' => 'completed',
            'purchase_date' => now(),
        ]);
    }

    private function syntheticCashFlow(array $attributes): CashFlow
    {
        $flow = new CashFlow;
        $flow->setRawAttributes(array_merge([
            'target_type' => '',
            'target_id' => 0,
            'reference_code' => null,
            'deleted_at' => null,
            'time' => now(),
        ], $attributes), true);

        return $flow;
    }

    private function invokeService(string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod($this->service, $method);

        return $reflection->invokeArgs($this->service, $arguments);
    }

    private function supplierPayment(Customer $partner, string $code, float $amount): CashFlow
    {
        return CashFlow::query()->create([
            'code' => $code,
            'type' => 'payment',
            'amount' => $amount,
            'time' => now(),
            'target_type' => 'Nha cung cap',
            'target_id' => $partner->id,
            'target_name' => 'Generic Supplier',
            'reference_type' => 'SupplierPayment',
            'reference_code' => $code,
            'status' => 'active',
        ]);
    }

    private function customerReceipt(Customer $partner, string $code, float $amount): CashFlow
    {
        return CashFlow::query()->create([
            'code' => $code,
            'type' => 'receipt',
            'amount' => $amount,
            'time' => now(),
            'target_type' => 'Khach hang',
            'target_id' => $partner->id,
            'target_name' => 'Generic Customer',
            'reference_type' => 'DebtPayment',
            'reference_code' => $code,
            'status' => 'active',
        ]);
    }

    private function auditRow(Customer $partner, array $overrides = []): array
    {
        return array_merge([
            'partner_id' => $partner->id,
            'partner_code' => $partner->code,
            'role' => $partner->is_supplier ? ($partner->is_customer ? 'dual_role' : 'supplier_only') : 'customer_only',
            'risk_level' => 'HIGH',
            'primary_classification' => 'CUSTOMER_STORED_VS_DOCUMENT',
            'classification_flags' => ['CUSTOMER_STORED_VS_DOCUMENT'],
            'customer_stored_vs_document_raw' => 0.0,
            'customer_stored_vs_ledger' => 0.0,
            'customer_document_vs_ledger' => 0.0,
            'supplier_stored_vs_document_raw' => 0.0,
            'supplier_stored_vs_ledger' => 0.0,
            'supplier_document_vs_ledger' => 0.0,
            'dual_role_screen_symmetry_difference' => 0.0,
        ], $overrides);
    }

    private function pattern(array $detail, string $name): ?array
    {
        return collect($detail['observed_patterns'])->firstWhere('pattern', $name);
    }

    private function timeline(float $balance): array
    {
        return [
            'summary' => [
                'raw_document_final_balance' => $balance,
                'display_balance_final' => $balance,
            ],
            'reconcile' => [],
            'entries' => [],
        ];
    }

    private function ledger(float $balance): array
    {
        return [
            'closing_balance' => $balance,
            'reconcile' => ['ledger_balance' => $balance],
            'entries' => [],
        ];
    }
}
