<?php

namespace App\Services\Debt;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\CustomerPaymentAllocation;
use App\Models\DebtOffset;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SupplierDebtTransaction;
use App\Services\CustomerDebtDocumentTimelineService;
use App\Services\PartnerDebtLedgerService;
use App\Services\SupplierDebtDocumentTimelineService;
use App\Support\Debt\PartnerDebtDisplayBalance;
use App\Support\Status\BusinessStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class MaterialDebtRootCauseDrilldownService
{
    public const SOURCE_OF_TRUTH_STATUS = 'UNRESOLVED';

    public const PATTERN_TAXONOMY = [
        'LEGACY_OPENING_BALANCE_GAP',
        'STORED_BALANCE_WITHOUT_COMPLETE_DOCUMENT_HISTORY',
        'DOCUMENT_HISTORY_WITHOUT_LEDGER',
        'LEDGER_HISTORY_WITHOUT_DOCUMENT',
        'CANCELLED_INVOICE_REVERSAL_GAP',
        'CUSTOMER_RECEIPT_ALLOCATION_GAP',
        'GENERIC_SUPPLIER_PAYMENT_UNALLOCATED',
        'SUPPLIER_PAYMENT_ALLOCATION_INFERENCE',
        'RETURN_REFUND_MAPPING_GAP',
        'PURCHASE_RETURN_REFUND_MAPPING_GAP',
        'DUAL_ROLE_NETTING_INCONSISTENCY',
        'TECHNICAL_LEDGER_EXCLUDED',
        'TARGET_TYPE_ALIAS_PRESENT',
        'MULTI_SOURCE_DIVERGENCE',
        'UNRESOLVED',
    ];

    public function __construct(
        private readonly CustomerDebtDocumentTimelineService $customerDocuments,
        private readonly SupplierDebtDocumentTimelineService $supplierDocuments,
        private readonly PartnerDebtLedgerService $ledgers,
    ) {}

    public function drilldown(Customer $partner, array $auditRow): array
    {
        $isCustomer = (bool) ($partner->is_customer ?? false);
        $isSupplier = (bool) ($partner->is_supplier ?? false);
        $isDualRole = PartnerDebtDisplayBalance::isDualRole($partner);

        $customerTimeline = $isCustomer ? $this->customerDocuments->build($partner, []) : null;
        $supplierTimeline = $isSupplier
            ? $this->supplierDocuments->build($partner, $isDualRole ? ['view' => 'partner'] : [])
            : null;
        $customerLedger = $isCustomer ? $this->ledgers->buildCustomerNetLedger($partner) : null;
        $supplierLedger = $isSupplier
            ? ($isDualRole
                ? $this->ledgers->buildSupplierDualRolePartnerTimeline($partner)
                : $this->ledgers->buildSupplierPayableLedger($partner))
            : null;

        $invoices = Invoice::query()->where('customer_id', $partner->id)
            ->orderByRaw('COALESCE(transaction_date, created_at)')->orderBy('id')->get();
        $customerCashFlows = CashFlow::withTrashed()->where('target_id', $partner->id)
            ->whereIn('target_type', ['Khách hàng', 'Khach hang'])
            ->orderByRaw('COALESCE(time, created_at)')->orderBy('id')->get();
        $salesReturns = OrderReturn::query()->where('customer_id', $partner->id)
            ->orderBy('created_at')->orderBy('id')->get();
        $customerDebts = CustomerDebt::query()->where('customer_id', $partner->id)
            ->orderByRaw('COALESCE(recorded_at, created_at)')->orderBy('id')->get();

        $purchases = Purchase::query()->where('supplier_id', $partner->id)
            ->orderByRaw('COALESCE(purchase_date, created_at)')->orderBy('id')->get();
        $supplierPayments = CashFlow::withTrashed()->where('target_id', $partner->id)
            ->where('type', 'payment')
            ->where(function ($query): void {
                $query->whereIn('target_type', ['Nhà cung cấp', 'Nha cung cap'])
                    ->orWhere('reference_type', 'SupplierPayment');
            })
            ->orderByRaw('COALESCE(time, created_at)')->orderBy('id')->get();
        $purchaseReturns = PurchaseReturn::query()->where('supplier_id', $partner->id)
            ->orderByRaw('COALESCE(return_date, created_at)')->orderBy('id')->get();
        $supplierTransactions = SupplierDebtTransaction::query()->where('supplier_id', $partner->id)
            ->orderBy('created_at')->orderBy('id')->get();
        $offsets = DebtOffset::query()->where('customer_id', $partner->id)
            ->orderBy('created_at')->orderBy('id')->get();

        $customerDocumentSection = $this->documentSection($customerTimeline);
        $supplierDocumentSection = $this->documentSection($supplierTimeline);
        $customerLedgerSection = $this->ledgerSection($customerLedger);
        $supplierLedgerSection = $this->ledgerSection($supplierLedger);
        $cancelledInvoices = $this->cancellationMatrix($invoices, $customerDebts, $customerCashFlows);
        $allocationEvidence = $this->allocationEvidence(
            $customerCashFlows,
            $supplierPayments,
            $invoices,
            $purchases,
            $supplierTimeline,
        );
        $technicalExclusions = $this->technicalExclusions($customerTimeline, $supplierTimeline);
        $stored = $this->storedBalance($partner);
        $coverage = $this->timelineCoverage(
            $stored,
            $auditRow,
            $invoices,
            $purchases,
            $customerCashFlows->concat($supplierPayments)->unique('id')->values(),
            collect($customerLedgerSection['ledger_entries'])->concat($supplierLedgerSection['ledger_entries']),
            $customerDocumentSection,
            $supplierDocumentSection,
            $customerLedgerSection,
            $supplierLedgerSection,
        );

        $evidence = [
            'invoices' => $invoices,
            'customer_receipts' => $customerCashFlows->where('type', 'receipt')->values(),
            'sales_returns' => $salesReturns,
            'customer_debts' => $customerDebts,
            'purchases' => $purchases,
            'supplier_payments' => $supplierPayments,
            'purchase_returns' => $purchaseReturns,
            'supplier_debt_transactions' => $supplierTransactions,
        ];
        $patterns = $this->observedPatterns(
            $partner,
            $auditRow,
            $stored,
            $evidence,
            $cancelledInvoices,
            $allocationEvidence,
            $technicalExclusions,
            $coverage,
        );
        $missingEvidence = $this->missingEvidence($patterns);

        return [
            'drilldown_status' => 'OK',
            'partner' => [
                'partner_id' => (int) $partner->id,
                'partner_code' => (string) ($partner->code ?? ''),
                'role' => self::roleFor($partner),
                'is_customer' => $isCustomer,
                'is_supplier' => $isSupplier,
                'status' => (string) ($partner->status ?? ''),
            ],
            'stored_balance' => $stored,
            'customer_document' => $customerDocumentSection,
            'customer_ledger' => $customerLedgerSection,
            'supplier_document' => $supplierDocumentSection,
            'supplier_ledger' => $supplierLedgerSection,
            'invoices' => $this->modelRows($invoices, [
                'id', 'code', 'status', 'total', 'customer_paid', 'transaction_date', 'created_at', 'updated_at',
            ]),
            'cancelled_invoices' => $cancelledInvoices,
            'customer_receipts' => $this->cashFlowRows($customerCashFlows->where('type', 'receipt'), [
                'id', 'code', 'type', 'amount', 'target_type', 'target_id', 'reference_type',
                'reference_id', 'reference_code', 'status', 'time', 'deleted_at',
            ]),
            'sales_returns' => $this->modelRows($salesReturns, [
                'id', 'code', 'status', 'total', 'paid_to_customer', 'invoice_id', 'created_at',
            ]),
            'customer_debts' => $this->modelRows($customerDebts, [
                'id', 'ref_code', 'type', 'amount', 'debt_total', 'order_id', 'order_return_id', 'recorded_at', 'created_at',
            ]),
            'purchases' => $this->modelRows($purchases, [
                'id', 'code', 'status', 'total_amount', 'paid_amount', 'debt_amount', 'purchase_date', 'created_at',
            ]),
            'supplier_payments' => $this->cashFlowRows($supplierPayments, [
                'id', 'code', 'type', 'amount', 'target_type', 'target_id', 'reference_type',
                'reference_id', 'reference_code', 'status', 'time', 'deleted_at',
            ]),
            'purchase_returns' => $this->modelRows($purchaseReturns, [
                'id', 'code', 'status', 'total_amount', 'refund_amount', 'purchase_id', 'return_date', 'created_at',
            ]),
            'supplier_debt_transactions' => $this->modelRows($supplierTransactions, [
                'id', 'code', 'type', 'amount', 'debt_remain', 'purchase_id', 'reference_type', 'reference_id', 'created_at',
            ]),
            'debt_offsets' => $this->modelRows($offsets, [
                'id', 'code', 'amount', 'receivable_before', 'payable_before', 'receivable_after',
                'payable_after', 'status', 'cancelled_at', 'created_at',
            ]),
            'technical_exclusions' => $technicalExclusions,
            'allocation_evidence' => $allocationEvidence,
            'timeline_coverage' => $coverage,
            'observed_patterns' => $patterns,
            'missing_evidence' => $missingEvidence,
            'source_of_truth_status' => self::SOURCE_OF_TRUTH_STATUS,
            'recommended_next_review' => $this->recommendedNextReview($patterns),
        ];
    }

    private function storedBalance(Customer $partner): array
    {
        $customer = PartnerDebtDisplayBalance::customerReceivable($partner);
        $supplier = PartnerDebtDisplayBalance::supplierPayable($partner);
        $customerScreen = PartnerDebtDisplayBalance::customerScreen($partner);
        $supplierScreen = PartnerDebtDisplayBalance::supplierScreen($partner);

        return [
            'raw_customer_debt' => $customer,
            'raw_supplier_debt' => $supplier,
            'customer_receivable' => $customer,
            'supplier_payable' => $supplier,
            'stored_customer_screen' => $customerScreen,
            'stored_supplier_screen' => $supplierScreen,
            'customer_screen' => $customerScreen,
            'supplier_screen' => $supplierScreen,
            'expected_symmetry' => 0.0,
            'actual_symmetry' => PartnerDebtDisplayBalance::isDualRole($partner)
                ? $customerScreen + $supplierScreen
                : 0.0,
            'dual_role_screen_symmetry_difference' => PartnerDebtDisplayBalance::isDualRole($partner)
                ? $customerScreen + $supplierScreen
                : 0.0,
        ];
    }

    private function documentSection(?array $timeline): array
    {
        if ($timeline === null) {
            return $this->emptyDocumentSection();
        }

        $summary = (array) ($timeline['summary'] ?? []);
        $reconcile = (array) ($timeline['reconcile'] ?? []);

        return [
            'raw_document_final_balance' => (float) ($summary['raw_document_final_balance'] ?? $summary['document_final_balance'] ?? 0),
            'display_balance_final' => (float) ($summary['display_balance_final'] ?? 0),
            'display_alignment_amount' => (float) ($summary['display_alignment_amount'] ?? 0),
            'has_virtual_opening_balance' => (bool) ($summary['has_virtual_opening_balance'] ?? false),
            'virtual_opening_balance' => (float) ($summary['virtual_opening_balance'] ?? 0),
            'entry_count' => count((array) ($timeline['entries'] ?? [])),
            'excluded_technical_entries' => $this->technicalRows($reconcile['excluded_ledger_entries'] ?? []),
            'reconcile' => $this->reconcilePayload($reconcile),
            'entries' => collect($timeline['entries'] ?? [])->map(fn ($entry): array => $this->timelineEntry((array) $entry))->values()->all(),
        ];
    }

    private function emptyDocumentSection(): array
    {
        return [
            'raw_document_final_balance' => 0.0,
            'display_balance_final' => 0.0,
            'display_alignment_amount' => 0.0,
            'has_virtual_opening_balance' => false,
            'virtual_opening_balance' => 0.0,
            'entry_count' => 0,
            'excluded_technical_entries' => [],
            'reconcile' => [],
            'entries' => [],
        ];
    }

    private function ledgerSection(?array $ledger): array
    {
        if ($ledger === null) {
            return ['ledger_final' => 0.0, 'ledger_entry_count' => 0, 'ledger_entries' => [], 'ledger_warnings' => []];
        }

        $summary = (array) ($ledger['summary'] ?? []);
        $reconcile = (array) ($ledger['reconcile'] ?? []);
        $entries = collect($ledger['entries'] ?? [])->map(fn ($entry): array => $this->timelineEntry((array) $entry))->values();
        $warnings = collect([
            $reconcile['user_warning'] ?? false ? ($reconcile['message'] ?? 'ledger_reconcile_warning') : null,
            $reconcile['resolved_by_virtual_opening'] ?? false ? 'ledger_resolved_by_virtual_opening' : null,
        ])->filter()->values()->all();

        return [
            'ledger_final' => (float) ($reconcile['ledger_balance'] ?? $ledger['closing_balance'] ?? $summary['display_balance_final'] ?? 0),
            'ledger_entry_count' => $entries->count(),
            'ledger_entries' => $entries->all(),
            'ledger_warnings' => $warnings,
            'has_virtual_opening_balance' => (bool) ($summary['has_virtual_opening_balance'] ?? false),
            'virtual_opening_balance' => (float) ($summary['virtual_opening_balance'] ?? 0),
            'reconcile' => $this->reconcilePayload($reconcile),
        ];
    }

    private function timelineEntry(array $entry): array
    {
        $effect = $this->firstNumeric($entry, [
            'customer_display_effect', 'supplier_display_effect', 'display_effect', 'partner_effect', 'amount',
        ]);

        return [
            'source' => (string) ($entry['source_ledger'] ?? $entry['source'] ?? ''),
            'source_id' => $entry['source_id'] ?? $entry['reference_id'] ?? $entry['detail_reference_id'] ?? null,
            'code' => (string) ($entry['code'] ?? $entry['ref_code'] ?? ''),
            'ref_code' => (string) ($entry['reference_code'] ?? $entry['ref_code'] ?? ''),
            'event_kind' => (string) ($entry['event_kind'] ?? $entry['type'] ?? ''),
            'type' => (string) ($entry['reference_type'] ?? $entry['type'] ?? ''),
            'amount' => (float) ($entry['document_amount'] ?? $entry['amount'] ?? abs($effect)),
            'direction' => $effect > 0 ? 'increase' : ($effect < 0 ? 'decrease' : 'neutral'),
            'effect' => $effect,
            'running_balance' => $this->firstNumeric($entry, [
                'customer_display_running_balance', 'supplier_display_running_balance',
                'supplier_partner_running_balance', 'partner_running_balance',
                'customer_ledger_running_balance', 'supplier_ledger_running_balance', 'debt_remain', 'balance',
            ], null),
            'date' => $this->dateValue($entry['time'] ?? $entry['display_time'] ?? $entry['created_at'] ?? null),
            'is_real_voucher' => (bool) ($entry['is_real_voucher'] ?? false),
            'is_virtual_fallback' => (bool) ($entry['is_virtual_fallback'] ?? false),
            'is_virtual_opening' => (bool) ($entry['is_virtual_opening'] ?? false),
            'allocation_confidence' => (string) ($entry['payment_allocation_confidence'] ?? $entry['allocation_confidence'] ?? ''),
            'allocation_warning' => (bool) ($entry['receipt_allocation_mismatch'] ?? $entry['payment_allocation_mismatch'] ?? $entry['needs_manual_review'] ?? false),
        ];
    }

    private function cancellationMatrix(Collection $invoices, Collection $debts, Collection $cashFlows): array
    {
        return $invoices->filter(fn (Invoice $invoice): bool => BusinessStatus::isCancelled($invoice->status))
            ->map(function (Invoice $invoice) use ($invoices, $debts, $cashFlows): array {
                $expectedSigned = -((float) $invoice->total - (float) $invoice->customer_paid);
                $expected = abs($expectedSigned);
                $warnings = collect();

                $originalReceipts = $cashFlows->filter(function (CashFlow $flow) use ($invoice, $invoices, $warnings): bool {
                    if ((string) $flow->type !== 'receipt' || (string) $flow->reference_type !== 'Invoice') {
                        return false;
                    }
                    $reference = $this->resolveDocumentReference($flow, $invoices, 'Invoice', 'invoice');
                    if ($reference['warning']) {
                        $warnings->push($reference['warning']);
                    }

                    return (int) ($reference['document']?->id ?? 0) === (int) $invoice->id;
                })->values();

                $debtReversals = $debts->filter(fn (CustomerDebt $debt): bool => $this->isCustomerDebtInvoiceReversal($debt, $invoice, $expectedSigned)
                )->values();

                $activeCashReversals = collect();
                $historicalCashReversals = collect();
                $matchMethods = collect();
                $referenceConflicts = collect();
                foreach ($cashFlows as $flow) {
                    if (!$this->isExplicitInvoiceCashFlowReversal($flow)) {
                        continue;
                    }
                    $reference = $this->resolveDocumentReference($flow, $invoices, 'InvoiceCancellation', 'invoice');
                    if ($reference['warning'] && (
                        (int) ($reference['document']?->id ?? 0) === (int) $invoice->id
                        || (string) ($flow->reference_code ?? '') === (string) $invoice->code
                    )) {
                        $warnings->push($reference['warning']);
                    }
                    if ((int) ($reference['document']?->id ?? 0) !== (int) $invoice->id) {
                        continue;
                    }
                    $matchMethods->push($reference['method']);
                    if ($reference['conflict']) {
                        $referenceConflicts->push([
                            'cashflow_id' => (int) $flow->id,
                            'cashflow_code' => (string) $flow->code,
                            'match_method' => (string) $reference['method'],
                            'warning' => (string) $reference['warning'],
                            'reference_id' => $flow->getAttribute('reference_id'),
                            'reference_code' => (string) ($flow->reference_code ?? ''),
                        ]);
                    }
                    $scope = $this->cashFlowEvidenceScope($flow);
                    if ($scope['is_active_for_balance']) {
                        $activeCashReversals->push($flow);
                    } else {
                        $historicalCashReversals->push($flow);
                    }
                }

                $debtAmount = (float) $debtReversals->sum(fn (CustomerDebt $debt): float => abs((float) $debt->amount));
                $activeCashAmount = (float) $activeCashReversals->sum(fn (CashFlow $flow): float => abs((float) $flow->amount));
                $historicalAmount = (float) $historicalCashReversals->sum(fn (CashFlow $flow): float => abs((float) $flow->amount));
                $activeAmount = $debtAmount + $activeCashAmount;
                $difference = $expected - $activeAmount;
                $requiresReversal = $expected > PartnerDebtParityAuditService::TOLERANCE;
                $hasActive = $debtReversals->isNotEmpty() || $activeCashReversals->isNotEmpty();
                $exact = $requiresReversal && $hasActive
                    && abs($difference) <= PartnerDebtParityAuditService::TOLERANCE;
                $partial = $requiresReversal && $hasActive
                    && $difference > PartnerDebtParityAuditService::TOLERANCE;
                $reversalState = ! $requiresReversal
                    ? 'not_required'
                    : (! $hasActive
                        ? 'missing'
                        : ($exact
                            ? 'exact'
                            : ($difference > 0 ? 'under_reversed' : 'over_reversed')));

                if ($debtReversals->isNotEmpty()) {
                    $debtReversals->each(function (CustomerDebt $debt) use ($invoice, $matchMethods): void {
                        $matchMethods->push(
                        (string) ($debt->ref_code ?? '') === (string) $invoice->code
                                ? 'code'
                                : 'relationship',
                        );
                    });
                }
                $orderedMatchMethods = collect(['id', 'relationship', 'code'])
                    ->filter(fn (string $method): bool => $matchMethods->contains($method))
                    ->values()->all();

                return [
                    'invoice_id' => (int) $invoice->id,
                    'invoice_code' => (string) $invoice->code,
                    'invoice_status' => (string) $invoice->status,
                    'invoice_total' => (float) $invoice->total,
                    'customer_paid' => (float) $invoice->customer_paid,
                    'expected_reversal_amount' => $expected,
                    'matching_original_receipt_codes' => $originalReceipts->pluck('code')->filter()->unique()->values()->all(),
                    'matching_active_reversal_codes' => $activeCashReversals->pluck('code')->filter()->unique()->values()->all(),
                    'matching_historical_reversal_codes' => $historicalCashReversals->pluck('code')->filter()->unique()->values()->all(),
                    'matching_customer_debt_reversal_codes' => $debtReversals->pluck('ref_code')->filter()->unique()->values()->all(),
                    'active_reversal_amount' => $activeAmount,
                    'historical_reversal_amount' => $historicalAmount,
                    'has_exact_reversal' => $exact,
                    'has_partial_reversal' => $partial,
                    'missing_reversal' => $requiresReversal && !$hasActive,
                    'reversal_state' => $reversalState,
                    'candidate_amount_difference' => $difference,
                    'match_method' => $this->strongestMatchMethod($matchMethods),
                    'match_methods' => $orderedMatchMethods,
                    'reference_conflict' => $referenceConflicts->isNotEmpty(),
                    'reference_conflicts' => $referenceConflicts->values()->all(),
                    'warnings' => $warnings->filter()->unique()->sort()->values()->all(),
                ];
            })->values()->all();
    }

    private function allocationEvidence(
        Collection $customerFlows,
        Collection $supplierPayments,
        Collection $invoices,
        Collection $purchases,
        ?array $supplierTimeline,
    ): array {
        $customer = $customerFlows->where('type', 'receipt')->map(function (CashFlow $flow) use ($invoices): array {
            $allocations = Schema::hasTable('customer_payment_allocations')
                ? CustomerPaymentAllocation::query()->where('cash_flow_id', $flow->id)->orderBy('invoice_id')->get()
                : collect();
            $reference = $this->resolveDocumentReference($flow, $invoices, 'Invoice', 'invoice');
            $linkedInvoice = $reference['document'];
            $scope = $this->cashFlowEvidenceScope($flow);
            $allocationWarning = null;
            $candidates = $allocations->map(function (CustomerPaymentAllocation $allocation) use ($flow, $invoices, &$allocationWarning): array {
                $invoice = $invoices->firstWhere('id', $allocation->invoice_id);
                $invoiceAvailable = $invoice !== null;
                $ownershipValid = (int) ($flow->target_id ?? 0) > 0
                    && (int) $allocation->customer_id === (int) $flow->target_id
                    && $invoiceAvailable
                    && (int) $invoice->customer_id === (int) $flow->target_id;
                if (! $invoiceAvailable) {
                    $allocationWarning ??= 'customer_allocation_invoice_unavailable';
                } elseif (! $ownershipValid) {
                    $allocationWarning ??= 'customer_allocation_ownership_mismatch';
                }

                return [
                    'document_id' => (int) $allocation->invoice_id,
                    'document_code' => (string) ($invoice?->code ?? ''),
                    'amount' => (float) $allocation->amount,
                    'evidence' => 'customer_payment_allocations',
                    'valid_ownership' => $ownershipValid,
                    'invalid_ownership' => ! $ownershipValid,
                ];
            })->values();
            $allAllocationsValid = $allocations->isNotEmpty()
                && $candidates->every(fn (array $candidate): bool => $candidate['valid_ownership']);
            $allocated = $allAllocationsValid ? (float) $allocations->sum('amount') : 0.0;
            if ($allAllocationsValid
                && $allocated - (float) $flow->amount > PartnerDebtParityAuditService::TOLERANCE) {
                $allocationWarning = 'customer_allocation_exceeds_cashflow_amount';
                $allAllocationsValid = false;
                $allocated = 0.0;
            }
            if ($allocations->isEmpty() && $linkedInvoice) {
                $allocated = min((float) $flow->amount, (float) $linkedInvoice->customer_paid);
            }
            if ($candidates->isEmpty() && $linkedInvoice) {
                $candidates->push([
                    'document_id' => (int) $linkedInvoice->id,
                    'document_code' => (string) $linkedInvoice->code,
                    'amount' => $allocated,
                    'evidence' => 'cash_flow_reference',
                ]);
            }

            $method = $allocations->isNotEmpty() ? 'allocation_table' : $reference['method'];
            $activeAllocated = $scope['is_active_for_balance'] ? $allocated : 0.0;

            return $this->paymentEvidenceRow(
                $flow,
                $scope['is_active_for_balance'] && ($allAllocationsValid || ($allocations->isEmpty() && (bool) $linkedInvoice)),
                false,
                $scope['is_active_for_balance']
                    ? ($allAllocationsValid ? 'actual' : ($allocations->isEmpty() && $linkedInvoice ? 'actual_reference' : 'unknown'))
                    : 'historical',
                $candidates->all(),
                $activeAllocated,
                $scope['is_active_for_balance'] ? max(0.0, (float) $flow->amount - $activeAllocated) : 0.0,
                $scope['is_active_for_balance']
                    ? ($allocationWarning ?: ($reference['warning'] ?: (
                        $allocated + PartnerDebtParityAuditService::TOLERANCE < (float) $flow->amount
                        ? 'customer_receipt_not_fully_allocated'
                            : null
                    )))
                    : 'historical_cashflow_excluded_from_active_allocation',
                $method,
                $reference['conflict'],
            );
        })->values()->all();

        $diagnostics = (array) (($supplierTimeline['reconcile']['generic_payment_allocation'] ?? []));
        $inferred = collect($diagnostics['inferred_allocations'] ?? [])->groupBy('payment_code');
        $unallocated = collect($diagnostics['unallocated_generic_payments'] ?? [])->keyBy('payment_code');
        $supplier = $supplierPayments->map(function (CashFlow $flow) use ($purchases, $inferred, $unallocated): array {
            $reference = $this->resolveDocumentReference($flow, $purchases, 'Purchase', 'purchase');
            $direct = $reference['document'];
            $scope = $this->cashFlowEvidenceScope($flow);
            $inferredRows = collect($inferred->get($flow->code, []));
            $unallocatedRow = (array) ($unallocated->get($flow->code, []));
            $candidates = $inferredRows->map(function (array $row) use ($purchases): array {
                $purchase = $purchases->firstWhere('code', $row['purchase_code'] ?? null);

                return [
                    'document_id' => $purchase?->id,
                    'document_code' => (string) ($row['purchase_code'] ?? ''),
                    'amount' => (float) ($row['amount'] ?? 0),
                    'evidence' => 'fifo_projection_only',
                ];
            })->values();
            if ($direct) {
                $candidates->prepend([
                    'document_id' => (int) $direct->id,
                    'document_code' => (string) $direct->code,
                    'amount' => (float) $flow->amount,
                    'evidence' => 'cash_flow_reference',
                ]);
            }
            $isGeneric = (string) $flow->reference_type === 'SupplierPayment';
            $unallocatedAmount = isset($unallocatedRow['amount'])
                ? (float) $unallocatedRow['amount']
                : ($isGeneric && $inferredRows->isEmpty() ? (float) $flow->amount : 0.0);

            $useInference = $scope['is_active_for_balance'] && !$direct && $inferredRows->isNotEmpty();
            $method = $direct ? $reference['method'] : ($useInference ? 'fifo_projection' : 'none');

            return $this->paymentEvidenceRow(
                $flow,
                $scope['is_active_for_balance'] && (bool) $direct,
                $useInference,
                !$scope['is_active_for_balance']
                    ? 'historical'
                    : ($direct ? 'actual_reference' : ($useInference ? 'inferred' : ($isGeneric ? 'unknown' : 'global_payment_only'))),
                $candidates->all(),
                $scope['is_active_for_balance'] && $direct ? (float) $flow->amount : 0.0,
                $scope['is_active_for_balance'] ? $unallocatedAmount : 0.0,
                !$scope['is_active_for_balance']
                    ? 'historical_cashflow_excluded_from_active_allocation'
                    : ($reference['warning'] ?: ($direct ? null : ($useInference
                        ? 'supplier_allocation_is_inferred_not_actual'
                        : 'supplier_payment_has_no_persisted_purchase_allocation'))),
                $method,
                $reference['conflict'],
            );
        })->values()->all();

        return ['customer_receipts' => $customer, 'supplier_payments' => $supplier];
    }

    private function paymentEvidenceRow(
        CashFlow $flow,
        bool $explicit,
        bool $inferred,
        string $confidence,
        array $candidates,
        float $allocated,
        float $unallocated,
        ?string $warning,
        string $referenceMatchMethod,
        bool $referenceConflict,
    ): array {
        $scope = $this->cashFlowEvidenceScope($flow);

        return [
            'cashflow_id' => (int) $flow->id,
            'cashflow_code' => (string) $flow->code,
            'amount' => (float) $flow->amount,
            'target_type' => (string) ($flow->target_type ?? ''),
            'reference_type' => (string) ($flow->reference_type ?? ''),
            'reference_id' => $flow->getAttribute('reference_id'),
            'reference_match_method' => $referenceMatchMethod,
            'reference_conflict' => $referenceConflict,
            'is_deleted' => $scope['is_deleted'],
            'is_cancelled' => $scope['is_cancelled'],
            'is_active_for_balance' => $scope['is_active_for_balance'],
            'evidence_scope' => $scope['evidence_scope'],
            'explicitly_allocated' => $explicit,
            'inferred_allocation' => $inferred,
            'allocation_confidence' => $confidence,
            'candidate_documents' => $candidates,
            'allocated_amount' => $allocated,
            'unallocated_amount' => $unallocated,
            'warning' => $warning,
        ];
    }

    /**
     * Resolve a direct document reference without allowing a legacy code to override an ID.
     */
    private function resolveDocumentReference(
        CashFlow $flow,
        Collection $documents,
        string $expectedReferenceType,
        string $warningPrefix,
    ): array {
        if ((string) $flow->reference_type !== $expectedReferenceType) {
            return ['document' => null, 'method' => 'none', 'conflict' => false, 'warning' => null];
        }

        $attributes = $flow->getAttributes();
        $hasReferenceId = array_key_exists('reference_id', $attributes);
        $referenceId = $flow->getAttribute('reference_id');
        $referenceCode = trim((string) ($flow->reference_code ?? ''));
        if ($hasReferenceId && $referenceId !== null) {
            if (! (is_int($referenceId) || (is_string($referenceId) && preg_match('/^[1-9][0-9]*$/', $referenceId) === 1))
                || (int) $referenceId <= 0) {
                return [
                    'document' => null,
                    'method' => 'none',
                    'conflict' => false,
                    'warning' => "{$warningPrefix}_reference_id_invalid",
                ];
            }
            $byId = $documents->firstWhere('id', (int) $referenceId);
            $byCode = $referenceCode !== '' ? $documents->firstWhere('code', $referenceCode) : null;
            $conflict = $byId && (
                ($referenceCode !== '' && (string) $byId->code !== $referenceCode)
                || ($byCode && (int) $byCode->id !== (int) $byId->id)
            );
            $warning = $conflict
                ? "{$warningPrefix}_reference_id_code_conflict"
                : (!$byId ? "{$warningPrefix}_reference_id_not_found" : null);

            return ['document' => $byId, 'method' => 'id', 'conflict' => $conflict, 'warning' => $warning];
        }

        if ($referenceCode !== '') {
            return [
                'document' => $documents->firstWhere('code', $referenceCode),
                'method' => 'code',
                'conflict' => false,
                'warning' => null,
            ];
        }

        return ['document' => null, 'method' => 'none', 'conflict' => false, 'warning' => null];
    }

    private function cashFlowEvidenceScope(CashFlow $flow): array
    {
        $isDeleted = $flow->trashed() || $flow->getAttribute('deleted_at') !== null;
        $isCancelled = BusinessStatus::isCancelled($flow->status);
        $isActive = !$isDeleted && !$isCancelled && BusinessStatus::isValidCashFlow($flow->status);

        return [
            'is_deleted' => $isDeleted,
            'is_cancelled' => $isCancelled,
            'is_active_for_balance' => $isActive,
            'evidence_scope' => $isActive ? 'active' : 'historical',
        ];
    }

    private function isCustomerDebtInvoiceReversal(
        CustomerDebt $debt,
        Invoice $invoice,
        float $expectedSignedAmount,
    ): bool {
        $type = (string) $debt->type;
        $isSpecificType = $type === 'sale_reversal';
        $note = Str::ascii(mb_strtolower((string) ($debt->note ?? '')));
        $isCancellationAdjustment = $type === 'adjustment'
            && (str_contains($note, 'huy hoa don') || str_contains($note, 'dao cong no'));
        if (!$isSpecificType && !$isCancellationAdjustment) {
            return false;
        }

        $codeMatch = (string) ($debt->ref_code ?? '') === (string) ($invoice->code ?? '');
        $orderMatch = $invoice->order_id && $debt->order_id
            && (int) $debt->order_id === (int) $invoice->order_id;
        if (!$codeMatch && !$orderMatch) {
            return false;
        }

        $amount = (float) $debt->amount;
        if (abs($expectedSignedAmount) <= PartnerDebtParityAuditService::TOLERANCE) {
            return false;
        }

        return $amount * $expectedSignedAmount > 0;
    }

    private function isExplicitInvoiceCashFlowReversal(CashFlow $flow): bool
    {
        return (string) $flow->type === 'payment'
            && (string) $flow->reference_type === 'InvoiceCancellation'
            && (float) $flow->amount > PartnerDebtParityAuditService::TOLERANCE;
    }

    private function strongestMatchMethod(Collection $methods): string
    {
        foreach (['id', 'relationship', 'code'] as $method) {
            if ($methods->contains($method)) {
                return $method;
            }
        }

        return 'none';
    }

    private function timelineCoverage(
        array $stored,
        array $auditRow,
        Collection $invoices,
        Collection $purchases,
        Collection $cashFlows,
        Collection $ledgerEntries,
        array $customerDocument,
        array $supplierDocument,
        array $customerLedger,
        array $supplierLedger,
    ): array {
        $invoiceDates = $invoices->map(fn (Invoice $invoice) => $invoice->transaction_date ?: $invoice->created_at);
        $purchaseDates = $purchases->map(fn (Purchase $purchase) => $purchase->purchase_date ?: $purchase->created_at);
        $cashDates = $cashFlows->map(fn (CashFlow $flow) => $flow->time ?: $flow->created_at);
        $ledgerDates = $ledgerEntries->pluck('date');
        $documentDates = $invoiceDates->concat($purchaseDates)->concat($cashDates)->filter();
        $storedNonZero = $this->different($stored['stored_customer_screen']) || $this->different($stored['stored_supplier_screen']);
        $documentVirtual = $customerDocument['has_virtual_opening_balance'] || $supplierDocument['has_virtual_opening_balance'];
        $ledgerVirtual = ($customerLedger['has_virtual_opening_balance'] ?? false) || ($supplierLedger['has_virtual_opening_balance'] ?? false);
        $documentGap = $documentVirtual
            || $this->different($auditRow['customer_stored_vs_document_raw'] ?? 0)
            || $this->different($auditRow['supplier_stored_vs_document_raw'] ?? 0);
        $ledgerGap = $ledgerVirtual
            || $this->different($auditRow['customer_stored_vs_ledger'] ?? 0)
            || $this->different($auditRow['supplier_stored_vs_ledger'] ?? 0);

        return [
            'earliest_invoice_date' => $this->dateExtreme($invoiceDates, false),
            'latest_invoice_date' => $this->dateExtreme($invoiceDates, true),
            'earliest_purchase_date' => $this->dateExtreme($purchaseDates, false),
            'latest_purchase_date' => $this->dateExtreme($purchaseDates, true),
            'earliest_cashflow_date' => $this->dateExtreme($cashDates, false),
            'latest_cashflow_date' => $this->dateExtreme($cashDates, true),
            'earliest_ledger_date' => $this->dateExtreme($ledgerDates, false),
            'latest_ledger_date' => $this->dateExtreme($ledgerDates, true),
            'has_stored_balance_before_first_document' => $storedNonZero
                && ($documentDates->isEmpty() || $documentVirtual || ($documentGap && $ledgerGap)),
            'has_document_history_gap' => $documentGap,
            'has_ledger_history_gap' => $ledgerGap,
            'has_only_recent_ledger' => $ledgerDates->filter()->isNotEmpty()
                && $documentDates->isNotEmpty()
                && (string) $this->dateExtreme($ledgerDates, false) > (string) $this->dateExtreme($documentDates, false),
            'has_possible_legacy_opening_balance' => $storedNonZero
                && ($documentDates->isEmpty() || $documentVirtual || $ledgerVirtual || ($documentGap && $ledgerGap)),
        ];
    }

    private function observedPatterns(
        Customer $partner,
        array $auditRow,
        array $stored,
        array $evidence,
        array $cancelledInvoices,
        array $allocationEvidence,
        array $technicalExclusions,
        array $coverage,
    ): array {
        $patterns = [];
        $add = function (string $pattern, string $confidence, array $codes, array $ids, string $reason) use (&$patterns): void {
            $patterns[$pattern] = [
                'pattern' => $pattern,
                'confidence' => $confidence,
                'evidence_codes' => array_values(array_unique(array_filter(array_map('strval', $codes)))),
                'evidence_ids' => array_values(array_unique(array_filter(array_map('intval', $ids)))),
                'reason' => $reason,
            ];
        };

        if ($coverage['has_possible_legacy_opening_balance']) {
            $add('LEGACY_OPENING_BALANCE_GAP', 'low', [], [], 'Stored balance or virtual opening predates complete observable document history.');
        }
        if ($coverage['has_document_history_gap']) {
            $add('STORED_BALANCE_WITHOUT_COMPLETE_DOCUMENT_HISTORY', 'medium', [], [], 'Stored and document evidence diverge or require a virtual opening.');
        }
        $customerDocuments = $evidence['invoices']->count() + $evidence['customer_receipts']->count() + $evidence['sales_returns']->count();
        $supplierDocuments = $evidence['purchases']->count() + $evidence['supplier_payments']->count() + $evidence['purchase_returns']->count();
        if (($customerDocuments > 0 && $evidence['customer_debts']->isEmpty())
            || ($supplierDocuments > 0 && $evidence['supplier_debt_transactions']->isEmpty())) {
            $add('DOCUMENT_HISTORY_WITHOUT_LEDGER', 'medium', [], [], 'Business documents exist without corresponding persisted debt-ledger history.');
        }
        if (($evidence['customer_debts']->isNotEmpty() && $customerDocuments === 0)
            || ($evidence['supplier_debt_transactions']->isNotEmpty() && $supplierDocuments === 0)) {
            $add('LEDGER_HISTORY_WITHOUT_DOCUMENT', 'medium', [], [], 'Debt-ledger rows exist without matching observable business documents.');
        }
        $cancelGaps = collect($cancelledInvoices)->filter(fn (array $row): bool => in_array((string) ($row['reversal_state'] ?? ''), ['missing', 'under_reversed', 'over_reversed'], true)
        );
        if ($cancelGaps->isNotEmpty()) {
            $add(
                'CANCELLED_INVOICE_REVERSAL_GAP',
                'high',
                $cancelGaps->pluck('invoice_code')->all(),
                $cancelGaps->pluck('invoice_id')->all(),
                'Cancelled invoice reversal evidence is missing or differs from the required signed amount.',
            );
        }
        $customerAllocationGaps = collect($allocationEvidence['customer_receipts'])->filter(fn (array $row): bool => $row['evidence_scope'] === 'active'
            && $row['unallocated_amount'] > PartnerDebtParityAuditService::TOLERANCE
        );
        if ($customerAllocationGaps->isNotEmpty()) {
            $add('CUSTOMER_RECEIPT_ALLOCATION_GAP', 'medium', $customerAllocationGaps->pluck('cashflow_code')->all(), $customerAllocationGaps->pluck('cashflow_id')->all(), 'Receipt is not fully covered by persisted allocation or direct invoice reference.');
        }
        $supplierUnallocated = collect($allocationEvidence['supplier_payments'])->filter(fn (array $row): bool => $row['evidence_scope'] === 'active'
            && $row['reference_type'] === 'SupplierPayment'
            && $row['unallocated_amount'] > PartnerDebtParityAuditService::TOLERANCE
        );
        if ($supplierUnallocated->isNotEmpty()) {
            $add('GENERIC_SUPPLIER_PAYMENT_UNALLOCATED', 'medium', $supplierUnallocated->pluck('cashflow_code')->all(), $supplierUnallocated->pluck('cashflow_id')->all(), 'Generic supplier payment has no persisted purchase-level allocation evidence.');
        }
        $supplierInferred = collect($allocationEvidence['supplier_payments'])->filter(fn (array $row): bool => $row['evidence_scope'] === 'active' && $row['inferred_allocation'] === true
        );
        if ($supplierInferred->isNotEmpty()) {
            $add('SUPPLIER_PAYMENT_ALLOCATION_INFERENCE', 'medium', $supplierInferred->pluck('cashflow_code')->all(), $supplierInferred->pluck('cashflow_id')->all(), 'Purchase coverage is FIFO presentation inference, not actual allocation evidence.');
        }
        if (in_array('RETURN_REFUND_DUPLICATE', (array) ($auditRow['classification_flags'] ?? []), true)) {
            $add('RETURN_REFUND_MAPPING_GAP', 'high', (array) ($auditRow['suspect_return_codes'] ?? []), [], 'Sales-return refund mapping requires manual voucher review.');
        }
        if (in_array('PURCHASE_RETURN_REFUND_MISMATCH', (array) ($auditRow['classification_flags'] ?? []), true)) {
            $add('PURCHASE_RETURN_REFUND_MAPPING_GAP', 'high', (array) ($auditRow['suspect_purchase_return_codes'] ?? []), [], 'Purchase-return refund mapping requires manual voucher review.');
        }
        if (PartnerDebtDisplayBalance::isDualRole($partner)
            && ($this->different($stored['dual_role_screen_symmetry_difference'])
                || in_array('DUAL_ROLE_NET_MISMATCH', (array) ($auditRow['classification_flags'] ?? []), true))) {
            $add('DUAL_ROLE_NETTING_INCONSISTENCY', 'high', [], [(int) $partner->id], 'Dual-role customer and supplier evidence does not reconcile symmetrically.');
        }
        if ($technicalExclusions !== []) {
            $add('TECHNICAL_LEDGER_EXCLUDED', 'high', collect($technicalExclusions)->pluck('code')->all(), [], 'Technical ledger rows are excluded from UI document balances and retained only as evidence.');
        }
        $aliases = CashFlow::withTrashed()->where('target_id', $partner->id)
            ->whereIn('target_type', ['Khach hang', 'Nha cung cap'])->get(['id', 'code']);
        if ($aliases->isNotEmpty()) {
            $add('TARGET_TYPE_ALIAS_PRESENT', 'high', $aliases->pluck('code')->all(), $aliases->pluck('id')->all(), 'Unaccented target type alias is present; no normalization is performed.');
        }
        if ($this->maxDifference($auditRow) > PartnerDebtParityAuditService::TOLERANCE) {
            $add('MULTI_SOURCE_DIVERGENCE', 'high', $this->auditEvidenceCodes($auditRow), [], 'Stored, document and/or ledger balances diverge beyond tolerance.');
        }
        if ($patterns === []) {
            $add('UNRESOLVED', 'low', [], [], 'No deterministic root cause is proven by available read-only evidence.');
        }

        return collect(self::PATTERN_TAXONOMY)->filter(fn (string $pattern): bool => isset($patterns[$pattern]))
            ->map(fn (string $pattern): array => $patterns[$pattern])->values()->all();
    }

    private function missingEvidence(array $patterns): array
    {
        $names = collect($patterns)->pluck('pattern');
        $missing = collect();
        if ($names->contains('LEGACY_OPENING_BALANCE_GAP') || $names->contains('STORED_BALANCE_WITHOUT_COMPLETE_DOCUMENT_HISTORY')) {
            $missing->push('Opening balance confirmation document', 'Historical import cutoff date', 'Original legacy system balance');
        }
        if ($names->contains('GENERIC_SUPPLIER_PAYMENT_UNALLOCATED') || $names->contains('SUPPLIER_PAYMENT_ALLOCATION_INFERENCE')) {
            $missing->push('Supplier payment allocation record');
        }
        if ($names->contains('CUSTOMER_RECEIPT_ALLOCATION_GAP')) {
            $missing->push('Customer receipt allocation record');
        }
        if ($names->contains('CANCELLED_INVOICE_REVERSAL_GAP')) {
            $missing->push('Cancelled invoice reversal voucher');
        }
        if ($names->contains('DUAL_ROLE_NETTING_INCONSISTENCY') || $names->contains('MULTI_SOURCE_DIVERGENCE')) {
            $missing->push('Manual debt confirmation');
        }
        if ($missing->isEmpty()) {
            $missing->push('Manual source-of-truth confirmation');
        }

        return $missing->unique()->sort()->values()->all();
    }

    private function recommendedNextReview(array $patterns): string
    {
        $names = collect($patterns)->pluck('pattern');
        if ($names->contains('CANCELLED_INVOICE_REVERSAL_GAP')) {
            return 'Review cancelled invoice and original reversal voucher references.';
        }
        if ($names->contains('GENERIC_SUPPLIER_PAYMENT_UNALLOCATED') || $names->contains('SUPPLIER_PAYMENT_ALLOCATION_INFERENCE')) {
            return 'Review original supplier payment allocation outside FIFO presentation inference.';
        }
        if ($names->contains('LEGACY_OPENING_BALANCE_GAP')) {
            return 'Confirm historical import cutoff and signed opening balance evidence.';
        }

        return 'Manually reconcile stored, document and ledger evidence; do not generate a delta.';
    }

    private function technicalExclusions(?array $customerTimeline, ?array $supplierTimeline): array
    {
        return collect($customerTimeline['reconcile']['excluded_ledger_entries'] ?? [])
            ->concat($supplierTimeline['reconcile']['excluded_ledger_entries'] ?? [])
            ->map(fn ($row): array => $this->technicalRow((array) $row))
            ->sortBy(fn (array $row): string => implode('|', [$row['source'], $row['code'], $row['amount']]))
            ->values()->all();
    }

    private function technicalRows(array $rows): array
    {
        return collect($rows)->map(fn ($row): array => $this->technicalRow((array) $row))
            ->sortBy(fn (array $row): string => implode('|', [$row['source'], $row['code']]))
            ->values()->all();
    }

    private function technicalRow(array $row): array
    {
        return [
            'code' => (string) ($row['code'] ?? $row['ref_code'] ?? ''),
            'amount' => (float) ($row['amount'] ?? 0),
            'reason' => (string) ($row['reason'] ?? ''),
            'source' => (string) ($row['source'] ?? ''),
        ];
    }

    private function modelRows(Collection $models, array $fields): array
    {
        return $models->map(function (Model $model) use ($fields): array {
            $row = [];
            foreach ($fields as $field) {
                $value = $model->getAttribute($field);
                $row[$field] = $this->scalarValue($value);
            }

            return $row;
        })->values()->all();
    }

    private function cashFlowRows(Collection $flows, array $fields): array
    {
        return $flows->map(function (CashFlow $flow) use ($fields): array {
            $row = [];
            foreach ($fields as $field) {
                $row[$field] = $this->scalarValue($flow->getAttribute($field));
            }

            return array_merge($row, $this->cashFlowEvidenceScope($flow));
        })->values()->all();
    }

    private function reconcilePayload(array $reconcile): array
    {
        $keys = [
            'severity', 'user_warning', 'has_mismatch', 'raw_has_mismatch', 'ledger_mismatch',
            'display_mismatch', 'display_resolved', 'stored_balance', 'document_balance',
            'raw_document_balance', 'ledger_balance', 'difference', 'display_balance_target',
            'display_balance_final', 'display_alignment_amount', 'display_aligned',
            'has_virtual_opening_balance', 'resolved_by_virtual_opening',
            'allocation_confidence', 'has_inferred_generic_allocations', 'has_unallocated_generic_payments',
        ];

        return collect($keys)->filter(fn (string $key): bool => array_key_exists($key, $reconcile))
            ->mapWithKeys(fn (string $key): array => [$key => $this->scalarValue($reconcile[$key])])->all();
    }

    public static function roleFor(Customer $partner): string
    {
        return PartnerDebtDisplayBalance::isDualRole($partner)
            ? 'dual_role'
            : ((bool) ($partner->is_supplier ?? false) ? 'supplier_only' : 'customer_only');
    }

    private function maxDifference(array $auditRow): float
    {
        return max(array_map(fn (string $key): float => abs((float) ($auditRow[$key] ?? 0)), [
            'customer_stored_vs_document_raw', 'customer_stored_vs_ledger', 'customer_document_vs_ledger',
            'supplier_stored_vs_document_raw', 'supplier_stored_vs_ledger', 'supplier_document_vs_ledger',
            'dual_role_screen_symmetry_difference',
        ]));
    }

    private function auditEvidenceCodes(array $auditRow): array
    {
        return collect([
            'suspect_invoice_codes', 'suspect_receipt_codes', 'suspect_return_codes', 'suspect_refund_codes',
            'suspect_purchase_codes', 'suspect_supplier_payment_codes', 'suspect_purchase_return_codes',
            'suspect_adjustment_codes', 'suspect_fallback_codes', 'excluded_technical_codes',
        ])->flatMap(fn (string $key): array => (array) ($auditRow[$key] ?? []))->filter()->unique()->sort()->values()->all();
    }

    private function dateExtreme(Collection $values, bool $latest): ?string
    {
        $dates = $values->map(fn ($value): ?string => $this->dateValue($value))->filter()->sort()->values();

        return $dates->isEmpty() ? null : ($latest ? $dates->last() : $dates->first());
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private function scalarValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_numeric($value) && !is_string($value)) {
            return $value;
        }

        return $value;
    }

    private function firstNumeric(array $entry, array $keys, ?float $default = 0.0): ?float
    {
        foreach ($keys as $key) {
            if (isset($entry[$key]) && is_numeric($entry[$key])) {
                return (float) $entry[$key];
            }
        }

        return $default;
    }

    private function different(float|int|string $value): bool
    {
        return abs((float) $value) > PartnerDebtParityAuditService::TOLERANCE;
    }
}
