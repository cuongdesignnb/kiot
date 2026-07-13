<?php

namespace Tests\Unit\Services;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
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
        $this->assertNull($this->pattern($detail, 'CANCELLED_INVOICE_REVERSAL_GAP'));
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
