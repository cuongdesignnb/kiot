<?php

namespace App\Services\Debt;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class PartnerDebtParityAuditService
{
    public const TOLERANCE = 1.0;

    public const CLASSIFICATIONS = [
        'OK',
        'CUSTOMER_STORED_VS_DOCUMENT',
        'CUSTOMER_STORED_VS_LEDGER',
        'CUSTOMER_DOCUMENT_VS_LEDGER',
        'SUPPLIER_STORED_VS_DOCUMENT',
        'SUPPLIER_STORED_VS_LEDGER',
        'SUPPLIER_DOCUMENT_VS_LEDGER',
        'DUAL_ROLE_NET_MISMATCH',
        'DUAL_ROLE_SCREEN_ASYMMETRY',
        'VIRTUAL_OPENING_REQUIRED',
        'VIRTUAL_DISPLAY_ALIGNMENT_ONLY',
        'STORED_BALANCE_NO_HISTORY',
        'HAS_DOCUMENTS_NO_LEDGER',
        'HAS_LEDGER_NO_DOCUMENTS',
        'DUPLICATE_REAL_AND_FALLBACK',
        'DUPLICATE_CUSTOMER_RECEIPT',
        'DUPLICATE_SUPPLIER_PAYMENT',
        'INVOICE_RECEIPT_ALLOCATION_MISMATCH',
        'PURCHASE_PAYMENT_ALLOCATION_MISMATCH',
        'RETURN_REFUND_DUPLICATE',
        'PURCHASE_RETURN_REFUND_MISMATCH',
        'CANCEL_REVERSAL_MISSING',
        'TARGET_TYPE_ALIAS_SUSPECT',
        'TECHNICAL_LEDGER_EXCLUDED',
        'MULTIPLE_MISMATCHES',
        'AUDIT_ERROR',
    ];

    public const RISK_LEVELS = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'OK'];

    public const CSV_COLUMNS = [
        'partner_id', 'partner_code', 'role', 'status',
        'raw_customer_debt', 'raw_supplier_debt', 'stored_customer_screen', 'stored_supplier_screen',
        'customer_document_raw_final', 'customer_document_display_final', 'customer_document_difference',
        'customer_document_has_mismatch', 'customer_document_raw_has_mismatch', 'customer_document_display_aligned',
        'customer_document_alignment_amount', 'customer_document_has_virtual_opening',
        'customer_document_virtual_opening_amount', 'customer_document_entry_count',
        'customer_ledger_target', 'customer_ledger_final', 'customer_ledger_difference',
        'customer_ledger_mismatch', 'customer_ledger_display_resolved', 'customer_ledger_has_virtual_opening',
        'customer_ledger_virtual_opening_amount',
        'supplier_document_raw_final', 'supplier_document_display_final', 'supplier_document_difference',
        'supplier_document_has_mismatch', 'supplier_document_raw_has_mismatch', 'supplier_document_display_aligned',
        'supplier_document_alignment_amount', 'supplier_document_has_virtual_opening',
        'supplier_document_virtual_opening_amount', 'supplier_document_entry_count',
        'supplier_ledger_target', 'supplier_ledger_final', 'supplier_ledger_difference',
        'supplier_ledger_mismatch', 'supplier_ledger_display_resolved', 'supplier_ledger_has_virtual_opening',
        'supplier_ledger_virtual_opening_amount',
        'customer_stored_vs_document_raw', 'customer_stored_vs_document_display',
        'customer_stored_vs_ledger', 'customer_document_vs_ledger',
        'supplier_stored_vs_document_raw', 'supplier_stored_vs_document_display',
        'supplier_stored_vs_ledger', 'supplier_document_vs_ledger', 'dual_role_screen_symmetry_difference',
        'invoice_count', 'invoice_total', 'invoice_paid_total', 'invoice_outstanding_total',
        'customer_receipt_count', 'customer_receipt_total', 'customer_payment_count', 'customer_payment_total',
        'order_return_count', 'order_return_total', 'order_return_refund_total',
        'customer_debt_count', 'customer_debt_sum',
        'purchase_count', 'purchase_total', 'purchase_paid_total', 'purchase_outstanding_total',
        'supplier_payment_count', 'supplier_payment_total',
        'purchase_return_count', 'purchase_return_total', 'purchase_return_refund_total',
        'supplier_debt_transaction_count', 'supplier_debt_transaction_sum',
        'debt_offset_count', 'debt_offset_total',
        'has_real_receipt', 'has_virtual_receipt_fallback', 'has_real_supplier_payment',
        'has_virtual_supplier_payment_fallback', 'has_real_return_refund', 'has_virtual_return_refund',
        'has_cancelled_invoice', 'has_cancel_reversal', 'has_customer_adjustment', 'has_supplier_adjustment',
        'has_opening_balance', 'has_virtual_opening', 'has_target_type_alias',
        'has_technical_ledger_exclusion', 'has_allocation_warning',
        'primary_classification', 'classification_flags', 'risk_level', 'recommended_action', 'audit_error',
    ];

    public function __construct(
        private readonly CustomerDebtDocumentTimelineService $customerDocuments,
        private readonly SupplierDebtDocumentTimelineService $supplierDocuments,
        private readonly PartnerDebtLedgerService $ledgers,
    ) {
    }

    public function audit(Customer $partner): array
    {
        try {
            $stored = $this->storedSnapshot($partner);
            $customerDocument = $this->customerDocumentSnapshot($partner, $stored['stored_customer_screen']);
            $customerLedger = $this->customerLedgerSnapshot($partner, $stored['stored_customer_screen']);
            $supplierDocument = $this->supplierDocumentSnapshot($partner, $stored['stored_supplier_screen']);
            $supplierLedger = $this->supplierLedgerSnapshot($partner, $stored['stored_supplier_screen']);
            $metrics = $this->sourceMetrics($partner);
            $evidence = $this->evidence(
                $partner,
                $customerDocument['_entries'],
                $supplierDocument['_entries'],
                $customerDocument,
                $supplierDocument,
            );

            unset($customerDocument['_entries'], $supplierDocument['_entries']);

            $row = array_merge([
                'partner_id' => (int) $partner->id,
                'partner_code' => (string) ($partner->code ?? ''),
                'role' => $this->role($partner),
                'status' => (string) ($partner->status ?? ''),
            ], $stored, $customerDocument, $customerLedger, $supplierDocument, $supplierLedger, $metrics, $evidence);

            $row = array_merge($row, $this->parityDifferences($row));
            $flags = $this->classify($row);
            $row['primary_classification'] = $this->primaryClassification($flags);
            $row['classification_flags'] = $flags;
            $row['risk_level'] = $this->riskLevel($row, $flags);
            $row['recommended_action'] = $this->recommendedAction($row['primary_classification']);
            $row['audit_error'] = null;

            return $row;
        } catch (Throwable $e) {
            return $this->errorRow($partner, $e);
        }
    }

    public function classify(array $row): array
    {
        if (!empty($row['audit_error'])) {
            return ['AUDIT_ERROR'];
        }

        $flags = [];
        $customerApplicable = in_array($row['role'] ?? '', ['customer_only', 'dual_role'], true);
        $supplierApplicable = in_array($row['role'] ?? '', ['supplier_only', 'dual_role'], true);

        if ($customerApplicable && $this->different($row['customer_stored_vs_document_raw'] ?? 0)) {
            $flags[] = 'CUSTOMER_STORED_VS_DOCUMENT';
        }
        if ($customerApplicable && $this->different($row['customer_stored_vs_ledger'] ?? 0)) {
            $flags[] = 'CUSTOMER_STORED_VS_LEDGER';
        }
        if ($customerApplicable && $this->different($row['customer_document_vs_ledger'] ?? 0)) {
            $flags[] = 'CUSTOMER_DOCUMENT_VS_LEDGER';
        }
        if ($supplierApplicable && $this->different($row['supplier_stored_vs_document_raw'] ?? 0)) {
            $flags[] = 'SUPPLIER_STORED_VS_DOCUMENT';
        }
        if ($supplierApplicable && $this->different($row['supplier_stored_vs_ledger'] ?? 0)) {
            $flags[] = 'SUPPLIER_STORED_VS_LEDGER';
        }
        if ($supplierApplicable && $this->different($row['supplier_document_vs_ledger'] ?? 0)) {
            $flags[] = 'SUPPLIER_DOCUMENT_VS_LEDGER';
        }
        if (($row['role'] ?? '') === 'dual_role' && $this->different($row['dual_role_screen_symmetry_difference'] ?? 0)) {
            $flags[] = 'DUAL_ROLE_SCREEN_ASYMMETRY';
        }
        if (($row['role'] ?? '') === 'dual_role'
            && ($this->different($row['customer_stored_vs_document_raw'] ?? 0)
                || $this->different($row['supplier_stored_vs_document_raw'] ?? 0))) {
            $flags[] = 'DUAL_ROLE_NET_MISMATCH';
        }

        $documentCount = (int) ($row['customer_document_entry_count'] ?? 0)
            + (int) ($row['supplier_document_entry_count'] ?? 0);
        $ledgerCount = (int) ($row['customer_debt_count'] ?? 0)
            + (int) ($row['supplier_debt_transaction_count'] ?? 0);
        $hasStored = $this->different($row['stored_customer_screen'] ?? 0)
            || $this->different($row['stored_supplier_screen'] ?? 0);

        if ($hasStored && $documentCount === 0 && $ledgerCount === 0) {
            $flags[] = 'STORED_BALANCE_NO_HISTORY';
        }
        if ($documentCount > 0 && $ledgerCount === 0 && $flags !== []) {
            $flags[] = 'HAS_DOCUMENTS_NO_LEDGER';
        }
        if ($ledgerCount > 0 && $documentCount === 0) {
            $flags[] = 'HAS_LEDGER_NO_DOCUMENTS';
        }
        if (!empty($row['has_virtual_opening'])) {
            $flags[] = 'VIRTUAL_OPENING_REQUIRED';
        } elseif (!empty($row['customer_document_display_aligned']) || !empty($row['supplier_document_display_aligned'])) {
            $flags[] = 'VIRTUAL_DISPLAY_ALIGNMENT_ONLY';
        }

        $flagMap = [
            'has_duplicate_real_and_fallback' => 'DUPLICATE_REAL_AND_FALLBACK',
            'has_duplicate_customer_receipt' => 'DUPLICATE_CUSTOMER_RECEIPT',
            'has_duplicate_supplier_payment' => 'DUPLICATE_SUPPLIER_PAYMENT',
            'has_invoice_receipt_allocation_mismatch' => 'INVOICE_RECEIPT_ALLOCATION_MISMATCH',
            'has_purchase_payment_allocation_mismatch' => 'PURCHASE_PAYMENT_ALLOCATION_MISMATCH',
            'has_return_refund_duplicate' => 'RETURN_REFUND_DUPLICATE',
            'has_purchase_return_refund_mismatch' => 'PURCHASE_RETURN_REFUND_MISMATCH',
            'has_cancel_reversal_missing' => 'CANCEL_REVERSAL_MISSING',
            'has_target_type_alias' => 'TARGET_TYPE_ALIAS_SUSPECT',
            'has_technical_ledger_exclusion' => 'TECHNICAL_LEDGER_EXCLUDED',
        ];
        foreach ($flagMap as $key => $classification) {
            if (!empty($row[$key])) {
                $flags[] = $classification;
            }
        }

        $flags = array_values(array_unique($flags));
        if ($flags === []) {
            return ['OK'];
        }
        if (count(array_diff($flags, ['VIRTUAL_DISPLAY_ALIGNMENT_ONLY', 'TARGET_TYPE_ALIAS_SUSPECT', 'TECHNICAL_LEDGER_EXCLUDED'])) > 1) {
            $flags[] = 'MULTIPLE_MISMATCHES';
        }

        return array_values(array_unique($flags));
    }

    public function riskLevel(array $row, array $flags): string
    {
        if ($flags === ['OK']) {
            return 'OK';
        }

        $critical = [
            'DUAL_ROLE_SCREEN_ASYMMETRY', 'RETURN_REFUND_DUPLICATE',
            'CANCEL_REVERSAL_MISSING', 'DUPLICATE_REAL_AND_FALLBACK',
        ];
        if (array_intersect($critical, $flags) !== []) {
            return 'CRITICAL';
        }

        $maxDifference = max(array_map(
            fn (string $key): float => abs((float) ($row[$key] ?? 0)),
            [
                'customer_stored_vs_document_raw', 'customer_stored_vs_ledger',
                'customer_document_vs_ledger', 'supplier_stored_vs_document_raw',
                'supplier_stored_vs_ledger', 'supplier_document_vs_ledger',
            ]
        ));
        if ($maxDifference >= 10_000_000) {
            return 'CRITICAL';
        }

        $high = [
            'CUSTOMER_STORED_VS_DOCUMENT', 'CUSTOMER_STORED_VS_LEDGER',
            'SUPPLIER_STORED_VS_DOCUMENT', 'SUPPLIER_STORED_VS_LEDGER',
            'DUAL_ROLE_NET_MISMATCH', 'VIRTUAL_OPENING_REQUIRED', 'STORED_BALANCE_NO_HISTORY',
        ];
        if ($maxDifference >= 1_000_000 || array_intersect($high, $flags) !== []) {
            return 'HIGH';
        }

        $medium = [
            'CUSTOMER_DOCUMENT_VS_LEDGER', 'SUPPLIER_DOCUMENT_VS_LEDGER',
            'TARGET_TYPE_ALIAS_SUSPECT', 'TECHNICAL_LEDGER_EXCLUDED',
            'INVOICE_RECEIPT_ALLOCATION_MISMATCH', 'PURCHASE_PAYMENT_ALLOCATION_MISMATCH',
        ];

        return array_intersect($medium, $flags) !== [] ? 'MEDIUM' : 'LOW';
    }

    private function storedSnapshot(Customer $partner): array
    {
        return [
            'raw_customer_debt' => PartnerDebtDisplayBalance::customerReceivable($partner),
            'raw_supplier_debt' => PartnerDebtDisplayBalance::supplierPayable($partner),
            'stored_customer_screen' => PartnerDebtDisplayBalance::customerScreen($partner),
            'stored_supplier_screen' => PartnerDebtDisplayBalance::supplierScreen($partner),
        ];
    }

    private function customerDocumentSnapshot(Customer $partner, float $stored): array
    {
        if (!(bool) ($partner->is_customer ?? false)) {
            return $this->emptyDocumentSnapshot('customer');
        }

        return $this->documentSnapshot(
            $this->customerDocuments->build($partner, ['audit' => true, 'include_technical' => true]),
            'customer',
            $stored,
        );
    }

    private function supplierDocumentSnapshot(Customer $partner, float $stored): array
    {
        if (!(bool) ($partner->is_supplier ?? false)) {
            return $this->emptyDocumentSnapshot('supplier');
        }

        $options = ['audit' => true, 'include_technical' => true];
        if (PartnerDebtDisplayBalance::isDualRole($partner)) {
            $options['view'] = 'partner';
        }

        return $this->documentSnapshot($this->supplierDocuments->build($partner, $options), 'supplier', $stored);
    }

    private function documentSnapshot(array $timeline, string $prefix, float $stored): array
    {
        $summary = $timeline['summary'] ?? [];
        $reconcile = $timeline['reconcile'] ?? [];
        $entries = collect($timeline['entries'] ?? [])->map(fn ($entry): array => (array) $entry)->values();
        $raw = (float) ($summary['raw_document_final_balance'] ?? $summary['document_final_balance'] ?? 0);
        $display = (float) ($summary['display_balance_final'] ?? $raw);
        $excluded = collect($reconcile['excluded_ledger_entries'] ?? [])
            ->map(fn ($entry): string => (string) (is_array($entry) ? ($entry['code'] ?? $entry['ref_code'] ?? '') : $entry))
            ->filter()->unique()->take(20)->values()->all();

        return [
            "{$prefix}_document_raw_final" => $raw,
            "{$prefix}_document_display_final" => $display,
            "{$prefix}_document_difference" => $stored - $raw,
            "{$prefix}_document_has_mismatch" => (bool) ($reconcile['has_mismatch'] ?? $this->different($stored - $display)),
            "{$prefix}_document_raw_has_mismatch" => (bool) ($reconcile['raw_has_mismatch'] ?? $this->different($stored - $raw)),
            "{$prefix}_document_display_aligned" => (bool) ($summary['display_aligned'] ?? false),
            "{$prefix}_document_alignment_amount" => (float) ($summary['display_alignment_amount'] ?? 0),
            "{$prefix}_document_has_virtual_opening" => (bool) ($summary['has_virtual_opening_balance'] ?? false),
            "{$prefix}_document_virtual_opening_amount" => (float) ($summary['virtual_opening_balance'] ?? 0),
            "{$prefix}_document_entry_count" => $entries->count(),
            "{$prefix}_document_excluded_technical_codes" => $excluded,
            "_entries" => $entries,
        ];
    }

    private function emptyDocumentSnapshot(string $prefix): array
    {
        return [
            "{$prefix}_document_raw_final" => 0.0,
            "{$prefix}_document_display_final" => 0.0,
            "{$prefix}_document_difference" => 0.0,
            "{$prefix}_document_has_mismatch" => false,
            "{$prefix}_document_raw_has_mismatch" => false,
            "{$prefix}_document_display_aligned" => false,
            "{$prefix}_document_alignment_amount" => 0.0,
            "{$prefix}_document_has_virtual_opening" => false,
            "{$prefix}_document_virtual_opening_amount" => 0.0,
            "{$prefix}_document_entry_count" => 0,
            "{$prefix}_document_excluded_technical_codes" => [],
            '_entries' => collect(),
        ];
    }

    private function customerLedgerSnapshot(Customer $partner, float $stored): array
    {
        if (!(bool) ($partner->is_customer ?? false)) {
            return $this->emptyLedgerSnapshot('customer');
        }

        return $this->ledgerSnapshot($this->ledgers->buildCustomerNetLedger($partner), 'customer', $stored);
    }

    private function supplierLedgerSnapshot(Customer $partner, float $stored): array
    {
        if (!(bool) ($partner->is_supplier ?? false)) {
            return $this->emptyLedgerSnapshot('supplier');
        }

        $ledger = PartnerDebtDisplayBalance::isDualRole($partner)
            ? $this->ledgers->buildSupplierDualRolePartnerTimeline($partner)
            : $this->ledgers->buildSupplierPayableLedger($partner);

        return $this->ledgerSnapshot($ledger, 'supplier', $stored);
    }

    private function ledgerSnapshot(array $ledger, string $prefix, float $stored): array
    {
        $summary = $ledger['summary'] ?? [];
        $reconcile = $ledger['reconcile'] ?? [];
        $target = (float) ($summary['display_balance_target'] ?? $reconcile['display_balance_target'] ?? $stored);
        $final = (float) ($reconcile['ledger_balance'] ?? $summary['display_balance_final'] ?? 0);

        return [
            "{$prefix}_ledger_target" => $target,
            "{$prefix}_ledger_final" => $final,
            "{$prefix}_ledger_difference" => $stored - $final,
            "{$prefix}_ledger_mismatch" => (bool) ($reconcile['ledger_mismatch'] ?? $this->different($stored - $final)),
            "{$prefix}_ledger_display_resolved" => (bool) ($reconcile['display_resolved'] ?? true),
            "{$prefix}_ledger_has_virtual_opening" => (bool) ($summary['has_virtual_opening_balance'] ?? false),
            "{$prefix}_ledger_virtual_opening_amount" => (float) ($summary['virtual_opening_balance'] ?? 0),
        ];
    }

    private function emptyLedgerSnapshot(string $prefix): array
    {
        return [
            "{$prefix}_ledger_target" => 0.0,
            "{$prefix}_ledger_final" => 0.0,
            "{$prefix}_ledger_difference" => 0.0,
            "{$prefix}_ledger_mismatch" => false,
            "{$prefix}_ledger_display_resolved" => true,
            "{$prefix}_ledger_has_virtual_opening" => false,
            "{$prefix}_ledger_virtual_opening_amount" => 0.0,
        ];
    }

    private function sourceMetrics(Customer $partner): array
    {
        $invoices = $this->active(Invoice::query()->where('customer_id', $partner->id));
        $returns = $this->active(OrderReturn::query()->where('customer_id', $partner->id));
        $purchases = $this->active(Purchase::query()->where('supplier_id', $partner->id));
        $purchaseReturns = $this->active(PurchaseReturn::query()->where('supplier_id', $partner->id));
        $receipts = $this->active(CashFlow::query()->whereNull('deleted_at'))
            ->where('target_id', $partner->id)->where('type', 'receipt');
        $customerPayments = (clone $receipts)->whereIn('target_type', ['Khách hàng', 'Khach hang']);
        $supplierPayments = $this->active(CashFlow::query()->whereNull('deleted_at'))
            ->where('target_id', $partner->id)->where('type', 'payment')
            ->where(function (Builder $query): void {
                $query->whereIn('target_type', ['Nhà cung cấp', 'Nha cung cap'])
                    ->orWhere('reference_type', 'SupplierPayment');
            });
        $customerDebts = CustomerDebt::query()->where('customer_id', $partner->id);
        $supplierTransactions = SupplierDebtTransaction::query()->where('supplier_id', $partner->id);
        $offsets = $this->active(DebtOffset::query()->where('customer_id', $partner->id));

        return [
            'invoice_count' => (clone $invoices)->count(),
            'invoice_total' => (float) (clone $invoices)->sum('total'),
            'invoice_paid_total' => (float) (clone $invoices)->sum('customer_paid'),
            'invoice_outstanding_total' => (float) (clone $invoices)->sum('total') - (float) (clone $invoices)->sum('customer_paid'),
            'customer_receipt_count' => (clone $receipts)->count(),
            'customer_receipt_total' => (float) (clone $receipts)->sum('amount'),
            'customer_payment_count' => (clone $customerPayments)->count(),
            'customer_payment_total' => (float) (clone $customerPayments)->sum('amount'),
            'order_return_count' => (clone $returns)->count(),
            'order_return_total' => (float) (clone $returns)->sum('total'),
            'order_return_refund_total' => (float) (clone $returns)->sum('paid_to_customer'),
            'customer_debt_count' => (clone $customerDebts)->count(),
            'customer_debt_sum' => (float) (clone $customerDebts)->sum('amount'),
            'purchase_count' => (clone $purchases)->count(),
            'purchase_total' => (float) (clone $purchases)->sum('total_amount'),
            'purchase_paid_total' => (float) (clone $purchases)->sum('paid_amount'),
            'purchase_outstanding_total' => (float) (clone $purchases)->sum('debt_amount'),
            'supplier_payment_count' => (clone $supplierPayments)->count(),
            'supplier_payment_total' => (float) (clone $supplierPayments)->sum('amount'),
            'purchase_return_count' => (clone $purchaseReturns)->count(),
            'purchase_return_total' => (float) (clone $purchaseReturns)->sum('total_amount'),
            'purchase_return_refund_total' => (float) (clone $purchaseReturns)->sum('refund_amount'),
            'supplier_debt_transaction_count' => (clone $supplierTransactions)->count(),
            'supplier_debt_transaction_sum' => (float) (clone $supplierTransactions)->sum('amount'),
            'debt_offset_count' => (clone $offsets)->count(),
            'debt_offset_total' => (float) (clone $offsets)->sum('amount'),
        ];
    }

    private function evidence(
        Customer $partner,
        Collection $customerEntries,
        Collection $supplierEntries,
        array $customerDocument,
        array $supplierDocument,
    ): array {
        $entries = $customerEntries->concat($supplierEntries)->values();
        $eventKinds = $entries->pluck('event_kind')->map(fn ($value): string => (string) $value);
        $realEntries = $entries->filter(fn (array $entry): bool => (bool) ($entry['is_real_voucher'] ?? false));
        $fallbackEntries = $entries->filter(fn (array $entry): bool => (bool) ($entry['is_virtual_fallback'] ?? false));
        $targetAliases = CashFlow::withTrashed()->where('target_id', $partner->id)
            ->whereIn('target_type', ['Khach hang', 'Nha cung cap'])->exists();
        $cancelledInvoices = Invoice::query()->where('customer_id', $partner->id)->get()
            ->filter(fn (Invoice $invoice): bool => BusinessStatus::isCancelled($invoice->status));
        $cancelReversalCodes = CustomerDebt::query()->where('customer_id', $partner->id)
            ->whereIn('type', ['sale_reversal', 'payment_cancel', 'adjustment'])
            ->pluck('ref_code')->filter()->all();
        $missingCancelReversal = $cancelledInvoices->contains(
            fn (Invoice $invoice): bool => !in_array((string) $invoice->code, $cancelReversalCodes, true)
        );
        $duplicateRealFallback = $this->hasRealFallbackCollision($realEntries, $fallbackEntries);

        return [
            'has_real_receipt' => $realEntries->contains(fn (array $e): bool => in_array($e['event_kind'] ?? '', ['invoice_payment', 'customer_payment'], true)),
            'has_virtual_receipt_fallback' => $fallbackEntries->contains(fn (array $e): bool => str_contains((string) ($e['event_kind'] ?? ''), 'payment')),
            'has_real_supplier_payment' => $realEntries->contains(fn (array $e): bool => ($e['event_kind'] ?? '') === 'supplier_payment'),
            'has_virtual_supplier_payment_fallback' => $fallbackEntries->contains(fn (array $e): bool => ($e['event_kind'] ?? '') === 'supplier_payment_fallback'),
            'has_real_return_refund' => $realEntries->contains(fn (array $e): bool => in_array($e['event_kind'] ?? '', ['refund', 'return_refund'], true)),
            'has_virtual_return_refund' => $fallbackEntries->contains(fn (array $e): bool => in_array($e['event_kind'] ?? '', ['refund', 'return_refund'], true)),
            'has_cancelled_invoice' => $cancelledInvoices->isNotEmpty(),
            'has_cancel_reversal' => $cancelReversalCodes !== [],
            'has_customer_adjustment' => $eventKinds->contains(fn (string $kind): bool => str_contains($kind, 'adjustment') || $kind === 'debt_adjustment'),
            'has_supplier_adjustment' => SupplierDebtTransaction::query()->where('supplier_id', $partner->id)->where('type', 'adjustment')->exists(),
            'has_opening_balance' => $entries->contains(fn (array $e): bool => str_contains((string) ($e['event_kind'] ?? ''), 'opening') && !(bool) ($e['is_virtual_opening'] ?? false)),
            'has_virtual_opening' => (bool) ($customerDocument['customer_document_has_virtual_opening'] ?? false)
                || (bool) ($supplierDocument['supplier_document_has_virtual_opening'] ?? false),
            'has_target_type_alias' => $targetAliases,
            'has_technical_ledger_exclusion' => ($customerDocument['customer_document_excluded_technical_codes'] ?? []) !== []
                || ($supplierDocument['supplier_document_excluded_technical_codes'] ?? []) !== [],
            'has_allocation_warning' => $entries->contains(fn (array $e): bool => $this->entryHasAllocationWarning($e))
                || (bool) ($supplierDocument['supplier_document_has_mismatch'] ?? false),
            'has_duplicate_real_and_fallback' => $duplicateRealFallback,
            'has_duplicate_customer_receipt' => $this->hasDuplicateCashFlow($partner, 'receipt'),
            'has_duplicate_supplier_payment' => $this->hasDuplicateCashFlow($partner, 'payment', true),
            'has_invoice_receipt_allocation_mismatch' => $entries->contains(fn (array $e): bool => (bool) ($e['receipt_allocation_mismatch'] ?? false)),
            'has_purchase_payment_allocation_mismatch' => $entries->contains(fn (array $e): bool => (bool) ($e['payment_allocation_mismatch'] ?? false) || ($e['payment_allocation_confidence'] ?? '') === 'unknown'),
            'has_return_refund_duplicate' => $this->hasReturnRefundCollision($entries),
            'has_purchase_return_refund_mismatch' => $entries->contains(fn (array $e): bool => (bool) ($e['purchase_return_refund_mismatch'] ?? false)),
            'has_cancel_reversal_missing' => $missingCancelReversal,
            'suspect_invoice_codes' => $this->codes($entries, ['customer_sale', 'invoice_cancel']),
            'suspect_receipt_codes' => $this->codes($entries, ['invoice_payment', 'customer_payment']),
            'suspect_return_codes' => $this->codes($entries, ['sales_return']),
            'suspect_refund_codes' => $this->codes($entries, ['refund', 'return_refund']),
            'suspect_purchase_codes' => $this->codes($entries, ['purchase']),
            'suspect_supplier_payment_codes' => $this->codes($entries, ['supplier_payment', 'supplier_payment_fallback']),
            'suspect_purchase_return_codes' => $this->codes($entries, ['purchase_return']),
            'suspect_adjustment_codes' => $entries->filter(fn (array $e): bool => str_contains((string) ($e['event_kind'] ?? ''), 'adjustment'))->pluck('code')->filter()->unique()->take(20)->values()->all(),
            'suspect_fallback_codes' => $fallbackEntries->pluck('code')->filter()->unique()->take(20)->values()->all(),
            'excluded_technical_codes' => array_values(array_unique(array_merge(
                $customerDocument['customer_document_excluded_technical_codes'] ?? [],
                $supplierDocument['supplier_document_excluded_technical_codes'] ?? [],
            ))),
        ];
    }

    private function parityDifferences(array $row): array
    {
        return [
            'customer_stored_vs_document_raw' => (float) $row['stored_customer_screen'] - (float) $row['customer_document_raw_final'],
            'customer_stored_vs_document_display' => (float) $row['stored_customer_screen'] - (float) $row['customer_document_display_final'],
            'customer_stored_vs_ledger' => (float) $row['stored_customer_screen'] - (float) $row['customer_ledger_final'],
            'customer_document_vs_ledger' => (float) $row['customer_document_raw_final'] - (float) $row['customer_ledger_final'],
            'supplier_stored_vs_document_raw' => (float) $row['stored_supplier_screen'] - (float) $row['supplier_document_raw_final'],
            'supplier_stored_vs_document_display' => (float) $row['stored_supplier_screen'] - (float) $row['supplier_document_display_final'],
            'supplier_stored_vs_ledger' => (float) $row['stored_supplier_screen'] - (float) $row['supplier_ledger_final'],
            'supplier_document_vs_ledger' => (float) $row['supplier_document_raw_final'] - (float) $row['supplier_ledger_final'],
            'dual_role_screen_symmetry_difference' => ($row['role'] ?? '') === 'dual_role'
                ? (float) $row['stored_customer_screen'] + (float) $row['stored_supplier_screen']
                : 0.0,
        ];
    }

    private function active(Builder $query): Builder
    {
        return BusinessStatus::scopeNotCancelled($query);
    }

    private function hasRealFallbackCollision(Collection $real, Collection $fallback): bool
    {
        $realKeys = $real->map(fn (array $e): string => $this->collisionKey($e))->filter()->unique();

        return $fallback->contains(fn (array $e): bool => $realKeys->contains($this->collisionKey($e)));
    }

    private function hasReturnRefundCollision(Collection $entries): bool
    {
        $refunds = $entries->filter(fn (array $e): bool => in_array($e['event_kind'] ?? '', ['refund', 'return_refund'], true));

        return $this->hasRealFallbackCollision(
            $refunds->filter(fn (array $e): bool => !(bool) ($e['is_virtual_fallback'] ?? false)),
            $refunds->filter(fn (array $e): bool => (bool) ($e['is_virtual_fallback'] ?? false)),
        );
    }

    private function documentKey(array $entry): string
    {
        return (string) ($entry['document_group_parent_code']
            ?? $entry['parent_document_code']
            ?? $entry['reference_code']
            ?? '');
    }

    private function collisionKey(array $entry): string
    {
        $kind = (string) ($entry['event_kind'] ?? '');
        $family = match (true) {
            str_contains($kind, 'payment') => 'payment',
            str_contains($kind, 'refund') => 'refund',
            default => '',
        };
        $document = $this->documentKey($entry);
        if ($family === '' || $document === '') {
            return '';
        }

        return implode('|', [$family, (string) ($entry['domain'] ?? ''), $document]);
    }

    private function hasDuplicateCashFlow(Customer $partner, string $type, bool $supplier = false): bool
    {
        $query = $this->active(CashFlow::query()->whereNull('deleted_at'))
            ->where('target_id', $partner->id)->where('type', $type);
        if ($supplier) {
            $query->where(function (Builder $builder): void {
                $builder->whereIn('target_type', ['Nhà cung cấp', 'Nha cung cap'])
                    ->orWhere('reference_type', 'SupplierPayment');
            });
        } else {
            $query->whereIn('target_type', ['Khách hàng', 'Khach hang']);
        }

        $query->whereNotNull('reference_code')->where('reference_code', '!=', '');

        return $query->selectRaw('COALESCE(reference_type, \'\') AS ref_type, COALESCE(reference_code, \'\') AS ref_code, amount, COUNT(*) AS aggregate_count')
            ->addSelect('time')
            ->groupBy('ref_type', 'ref_code', 'amount', 'time')
            ->having('aggregate_count', '>', 1)
            ->exists();
    }

    private function entryHasAllocationWarning(array $entry): bool
    {
        return (bool) ($entry['receipt_allocation_mismatch'] ?? false)
            || (bool) ($entry['payment_allocation_mismatch'] ?? false)
            || in_array($entry['payment_allocation_confidence'] ?? '', ['inferred', 'unknown', 'global_payment_only'], true)
            || in_array($entry['allocation_confidence'] ?? '', ['inferred', 'unknown'], true);
    }

    private function codes(Collection $entries, array $kinds): array
    {
        return $entries->filter(fn (array $entry): bool => in_array($entry['event_kind'] ?? '', $kinds, true))
            ->pluck('code')->filter()->unique()->take(20)->values()->all();
    }

    private function primaryClassification(array $flags): string
    {
        $priority = [
            'AUDIT_ERROR', 'DUAL_ROLE_SCREEN_ASYMMETRY', 'RETURN_REFUND_DUPLICATE',
            'CANCEL_REVERSAL_MISSING', 'DUPLICATE_REAL_AND_FALLBACK', 'DUAL_ROLE_NET_MISMATCH',
            'CUSTOMER_STORED_VS_DOCUMENT', 'SUPPLIER_STORED_VS_DOCUMENT',
            'CUSTOMER_STORED_VS_LEDGER', 'SUPPLIER_STORED_VS_LEDGER',
            'VIRTUAL_OPENING_REQUIRED', 'STORED_BALANCE_NO_HISTORY',
            'CUSTOMER_DOCUMENT_VS_LEDGER', 'SUPPLIER_DOCUMENT_VS_LEDGER',
            'PURCHASE_PAYMENT_ALLOCATION_MISMATCH', 'INVOICE_RECEIPT_ALLOCATION_MISMATCH',
            'TARGET_TYPE_ALIAS_SUSPECT', 'TECHNICAL_LEDGER_EXCLUDED',
            'VIRTUAL_DISPLAY_ALIGNMENT_ONLY', 'OK',
        ];
        foreach ($priority as $classification) {
            if (in_array($classification, $flags, true)) {
                return $classification;
            }
        }

        return $flags[0] ?? 'OK';
    }

    private function recommendedAction(string $classification): string
    {
        return match ($classification) {
            'OK' => 'Không xử lý.',
            'VIRTUAL_OPENING_REQUIRED', 'STORED_BALANCE_NO_HISTORY' => 'Review số dư đầu kỳ; chưa tạo opening thật.',
            'CUSTOMER_STORED_VS_DOCUMENT', 'CUSTOMER_STORED_VS_LEDGER', 'CUSTOMER_DOCUMENT_VS_LEDGER' => 'Drilldown hóa đơn, phiếu thu, trả hàng và adjustment; chưa sửa dữ liệu.',
            'SUPPLIER_STORED_VS_DOCUMENT', 'SUPPLIER_STORED_VS_LEDGER', 'SUPPLIER_DOCUMENT_VS_LEDGER' => 'Drilldown phiếu nhập, phiếu chi, trả nhập và adjustment; chưa sửa dữ liệu.',
            'DUPLICATE_REAL_AND_FALLBACK', 'DUPLICATE_CUSTOMER_RECEIPT', 'DUPLICATE_SUPPLIER_PAYMENT' => 'Review và sửa dedup code trước; không xóa dữ liệu.',
            'RETURN_REFUND_DUPLICATE', 'PURCHASE_RETURN_REFUND_MISMATCH' => 'Review matching chứng từ hoàn tiền thật/fallback; chưa sửa dữ liệu.',
            'CANCEL_REVERSAL_MISSING' => 'Review luồng hủy và reversal; chưa tự tạo reversal.',
            'DUAL_ROLE_NET_MISMATCH', 'DUAL_ROLE_SCREEN_ASYMMETRY' => 'Review raw customer/supplier fields và write-path dual-role.',
            'TARGET_TYPE_ALIAS_SUSPECT' => 'Chuẩn hóa query alias trong code; không sửa data ở bước audit.',
            default => 'Manual review bằng chứng trước khi đề xuất điều chỉnh dữ liệu.',
        };
    }

    private function role(Customer $partner): string
    {
        $customer = (bool) ($partner->is_customer ?? false);
        $supplier = (bool) ($partner->is_supplier ?? false);

        return $customer && $supplier ? 'dual_role' : ($supplier ? 'supplier_only' : 'customer_only');
    }

    private function different(float|int|string $value): bool
    {
        return abs((float) $value) > self::TOLERANCE;
    }

    private function errorRow(Customer $partner, Throwable $e): array
    {
        $row = array_fill_keys(self::CSV_COLUMNS, null);
        $row['partner_id'] = (int) $partner->id;
        $row['partner_code'] = (string) ($partner->code ?? '');
        $row['role'] = $this->role($partner);
        $row['status'] = (string) ($partner->status ?? '');
        $row['primary_classification'] = 'AUDIT_ERROR';
        $row['classification_flags'] = ['AUDIT_ERROR'];
        $row['risk_level'] = 'CRITICAL';
        $row['recommended_action'] = 'Dừng và phân tích lỗi audit; không lập kế hoạch điều chỉnh.';
        $row['audit_error'] = $e->getMessage();

        return $row;
    }
}
