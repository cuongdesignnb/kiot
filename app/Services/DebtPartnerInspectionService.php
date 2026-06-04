<?php

namespace App\Services;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SupplierDebtTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class DebtPartnerInspectionService
{
    public function inspect(Customer $partner, bool $includeRaw = false, bool $includeTimeline = false): array
    {
        $payload = [
            'partner' => $this->partnerPayload($partner),
            'stored_balances' => $this->storedBalances($partner),
            'source_counts' => $this->sourceCounts($partner),
            'raw' => $includeRaw ? $this->rawSources($partner) : $this->emptyRawSources(),
            'timelines' => $this->timelines($partner, $includeTimeline),
        ];

        $payload['computed'] = $this->computed($payload);
        $payload['diagnosis'] = $this->diagnose($payload);
        $payload['recommended_action'] = [
            'summary' => $payload['diagnosis']['recommended_action'],
            'requires_confirmation_before_fix' => true,
            'forbidden_without_confirmation' => [
                'opening_balance',
                'insert_customer_debt',
                'insert_supplier_debt_transaction',
                'insert_debt_offset',
                'update_customer_balances',
                'update_cash_flows',
                'recalculate_debt',
            ],
        ];

        return $payload;
    }

    public function findPartner(?string $id, ?string $code, ?string $phone): ?Customer
    {
        if ($id) {
            return Customer::query()->find($id);
        }

        if ($code) {
            return Customer::query()->where('code', $code)->first();
        }

        if ($phone) {
            return Customer::query()
                ->where('phone', $phone)
                ->orWhere('phone2', $phone)
                ->first();
        }

        return null;
    }

    private function partnerPayload(Customer $partner): array
    {
        return [
            'id' => $partner->id,
            'code' => $partner->code,
            'name' => $partner->name,
            'phone' => $partner->phone,
            'is_customer' => (bool) ($partner->is_customer ?? false),
            'is_supplier' => (bool) ($partner->is_supplier ?? false),
            'status' => $partner->status ?? null,
            'debt_amount' => $this->number($partner->debt_amount ?? 0),
            'supplier_debt_amount' => $this->hasColumn('customers', 'supplier_debt_amount')
                ? $this->number($partner->supplier_debt_amount ?? 0)
                : 0.0,
            'total_sales' => $this->number($partner->total_spent ?? 0),
            'total_purchases' => $this->number($partner->total_bought ?? 0),
            'created_at' => $this->dateValue($partner->created_at),
            'updated_at' => $this->dateValue($partner->updated_at),
        ];
    }

    private function storedBalances(Customer $partner): array
    {
        $customerReceivable = $this->number($partner->debt_amount ?? 0);
        $supplierPayable = $this->hasColumn('customers', 'supplier_debt_amount')
            ? $this->number($partner->supplier_debt_amount ?? 0)
            : 0.0;

        return [
            'customer_receivable' => $customerReceivable,
            'supplier_payable' => $supplierPayable,
            'customer_view' => $customerReceivable - $supplierPayable,
            'supplier_view' => $supplierPayable - $customerReceivable,
        ];
    }

    private function sourceCounts(Customer $partner): array
    {
        $raw = $this->rawSources($partner);

        return [
            'customer_debt_count' => count($raw['customer_debts']),
            'supplier_debt_transaction_count' => count($raw['supplier_debt_transactions']),
            'invoice_count' => count($raw['invoices']),
            'order_return_count' => count($raw['order_returns']),
            'purchase_count' => count($raw['purchases']),
            'purchase_return_count' => count($raw['purchase_returns']),
            'cash_flow_count' => count($raw['cash_flows']),
            'debt_offset_count' => count($raw['debt_offsets']),
            'document_count' => count($raw['invoices'])
                + count($raw['order_returns'])
                + count($raw['purchases'])
                + count($raw['purchase_returns'])
                + count($raw['cash_flows'])
                + count($raw['debt_offsets']),
            'ledger_count' => count($raw['customer_debts']) + count($raw['supplier_debt_transactions']),
        ];
    }

    private function rawSources(Customer $partner): array
    {
        $invoiceCodes = Invoice::query()
            ->where('customer_id', $partner->id)
            ->pluck('code')
            ->filter()
            ->values()
            ->all();
        $purchaseCodes = Purchase::query()
            ->where('supplier_id', $partner->id)
            ->pluck('code')
            ->filter()
            ->values()
            ->all();

        return [
            'customer_debts' => CustomerDebt::query()
                ->where('customer_id', $partner->id)
                ->orderBy('recorded_at')
                ->orderBy('id')
                ->get()
                ->map(fn (CustomerDebt $row) => $this->customerDebtRow($row))
                ->values()
                ->all(),
            'supplier_debt_transactions' => SupplierDebtTransaction::query()
                ->where('supplier_id', $partner->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn (SupplierDebtTransaction $row) => $this->supplierDebtTransactionRow($row))
                ->values()
                ->all(),
            'invoices' => Invoice::query()
                ->where('customer_id', $partner->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn (Invoice $row) => $this->invoiceRow($row))
                ->values()
                ->all(),
            'order_returns' => OrderReturn::query()
                ->where('customer_id', $partner->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn (OrderReturn $row) => $this->orderReturnRow($row))
                ->values()
                ->all(),
            'purchases' => Purchase::query()
                ->where('supplier_id', $partner->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn (Purchase $row) => $this->purchaseRow($row))
                ->values()
                ->all(),
            'purchase_returns' => PurchaseReturn::query()
                ->where('supplier_id', $partner->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn (PurchaseReturn $row) => $this->purchaseReturnRow($row))
                ->values()
                ->all(),
            'cash_flows' => $this->cashFlowQuery($partner, $invoiceCodes, $purchaseCodes)
                ->orderBy('created_at')
                ->get()
                ->map(fn (CashFlow $row) => $this->cashFlowRow($row))
                ->values()
                ->all(),
            'debt_offsets' => DebtOffset::query()
                ->where('customer_id', $partner->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn (DebtOffset $row) => $this->debtOffsetRow($row))
                ->values()
                ->all(),
        ];
    }

    private function emptyRawSources(): array
    {
        return [
            'customer_debts' => [],
            'supplier_debt_transactions' => [],
            'invoices' => [],
            'order_returns' => [],
            'purchases' => [],
            'purchase_returns' => [],
            'cash_flows' => [],
            'debt_offsets' => [],
        ];
    }

    private function timelines(Customer $partner, bool $includeTimeline): array
    {
        $ledgerService = app(PartnerDebtLedgerService::class);

        return [
            'customer_net' => $this->timelinePayload(
                fn () => $ledgerService->buildCustomerNetLedger($partner),
                $includeTimeline
            ),
            'supplier_payable' => (bool) ($partner->is_supplier ?? false)
                ? $this->timelinePayload(fn () => $ledgerService->buildSupplierPayableLedger($partner), $includeTimeline)
                : null,
            'supplier_partner' => ((bool) ($partner->is_customer ?? false) && (bool) ($partner->is_supplier ?? false))
                ? $this->timelinePayload(fn () => $ledgerService->buildSupplierDualRolePartnerTimeline($partner), $includeTimeline)
                : null,
        ];
    }

    private function timelinePayload(callable $builder, bool $includeTimeline): array
    {
        try {
            $timeline = $builder();
            $entries = collect($timeline['entries'] ?? []);

            $payload = [
                'summary' => $timeline['summary'] ?? [],
                'reconcile' => $timeline['reconcile'] ?? [],
                'entries_count' => $entries->count(),
            ];

            if ($includeTimeline) {
                $payload['entries'] = $entries
                    ->map(fn ($entry) => $this->timelineEntry((array) $entry))
                    ->values()
                    ->all();
            }

            return $payload;
        } catch (\Throwable $e) {
            return [
                'summary' => [],
                'reconcile' => [
                    'ledger_mismatch' => true,
                    'display_resolved' => false,
                    'severity' => 'error',
                    'message' => $e->getMessage(),
                ],
                'entries_count' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function computed(array $payload): array
    {
        $customerTimeline = $payload['timelines']['customer_net'] ?? [];
        $supplierTimeline = $payload['timelines']['supplier_partner']
            ?? $payload['timelines']['supplier_payable']
            ?? null;
        $customerReconcile = $customerTimeline['reconcile'] ?? [];
        $supplierReconcile = is_array($supplierTimeline) ? ($supplierTimeline['reconcile'] ?? []) : [];

        return [
            'customer_ledger_mismatch' => (bool) ($customerReconcile['ledger_mismatch'] ?? false),
            'customer_display_resolved' => (bool) ($customerReconcile['display_resolved'] ?? true),
            'customer_has_virtual_opening' => (bool) (($customerTimeline['summary']['has_virtual_opening_balance'] ?? false)
                || ($customerReconcile['has_virtual_opening_balance'] ?? false)),
            'customer_virtual_opening_balance' => $this->number($customerTimeline['summary']['virtual_opening_balance'] ?? 0),
            'supplier_ledger_mismatch' => (bool) ($supplierReconcile['ledger_mismatch'] ?? false),
            'supplier_display_resolved' => (bool) ($supplierReconcile['display_resolved'] ?? true),
            'supplier_has_virtual_opening' => (bool) ((is_array($supplierTimeline) ? ($supplierTimeline['summary']['has_virtual_opening_balance'] ?? false) : false)
                || ($supplierReconcile['has_virtual_opening_balance'] ?? false)),
            'supplier_virtual_opening_balance' => $this->number(is_array($supplierTimeline) ? ($supplierTimeline['summary']['virtual_opening_balance'] ?? 0) : 0),
            'supplier_final_balance' => $this->number(is_array($supplierTimeline) ? ($supplierTimeline['summary']['display_balance_final'] ?? 0) : 0),
        ];
    }

    public function diagnose(array $payload): array
    {
        $partner = $payload['partner'];
        $counts = $payload['source_counts'];
        $stored = $payload['stored_balances'];
        $computed = $payload['computed'];
        $hasStoredBalance = abs((float) $stored['customer_receivable']) >= 0.01
            || abs((float) $stored['supplier_payable']) >= 0.01;
        $hasVirtualOpening = (bool) $computed['customer_has_virtual_opening']
            || (bool) $computed['supplier_has_virtual_opening'];
        $displayResolved = (bool) $computed['customer_display_resolved']
            && (bool) $computed['supplier_display_resolved'];
        $ledgerMismatch = (bool) $computed['customer_ledger_mismatch']
            || (bool) $computed['supplier_ledger_mismatch'];

        if ($hasStoredBalance
            && (int) $counts['ledger_count'] === 0
            && (int) $counts['document_count'] === 0) {
            return $this->diagnosis(
                'stored_balance_without_source_history',
                [
                    'stored balance exists',
                    'ledger_count=0',
                    'document_count=0',
                ],
                'high',
                'Co the la so du import/dau ky. Neu muon ledger that, can tao opening balance sau backup/xac nhan.'
            );
        }

        if ((int) $counts['document_count'] > 0 && (int) $counts['ledger_count'] === 0) {
            return $this->diagnosis(
                'documents_exist_but_no_ledger',
                [
                    'document_count=' . $counts['document_count'],
                    'ledger_count=0',
                ],
                'high',
                'Kiem tra mapping chung tu sang ledger truoc; chua backfill.'
            );
        }

        if ((bool) $partner['is_customer'] && (bool) $partner['is_supplier']) {
            $viewsOpposite = abs((float) $stored['customer_view'] + (float) $stored['supplier_view']) < 0.01;
            $supplierMatches = abs((float) $computed['supplier_final_balance'] - (float) $stored['supplier_view']) < 0.01;
            if (!$viewsOpposite || !$supplierMatches) {
                return $this->diagnosis(
                    'dual_role_orientation_risk',
                    [
                        'stored_customer_view=' . $stored['customer_view'],
                        'stored_supplier_view=' . $stored['supplier_view'],
                        'supplier_final_balance=' . $computed['supplier_final_balance'],
                    ],
                    'medium',
                    'Manual review dual-role, khong tu offset.'
                );
            }
        }

        if ((int) $counts['document_count'] > 0 && (int) $counts['ledger_count'] > 0 && $ledgerMismatch) {
            return $this->diagnosis(
                'ledger_and_documents_mismatch',
                [
                    'document_count=' . $counts['document_count'],
                    'ledger_count=' . $counts['ledger_count'],
                    'ledger_mismatch=true',
                ],
                'high',
                'Manual review tung chung tu/ledger, xac dinh trung hoac thieu truoc khi sua.'
            );
        }

        if ($hasVirtualOpening && $displayResolved) {
            return $this->diagnosis(
                'virtual_opening_display_resolved',
                [
                    'has_virtual_opening=true',
                    'display_resolved=true',
                ],
                'medium',
                'Co the giu read-only; neu muon chung tu that thi can xac nhan tao opening balance.'
            );
        }

        return $this->diagnosis(
            'needs_manual_review',
            [
                'fallback diagnosis',
                'ledger_mismatch=' . ($ledgerMismatch ? 'true' : 'false'),
            ],
            'low',
            'Manual review truoc khi fix du lieu that.'
        );
    }

    private function diagnosis(string $cause, array $evidence, string $confidence, string $action): array
    {
        return [
            'primary_cause' => $cause,
            'evidence' => $evidence,
            'confidence' => $confidence,
            'recommended_action' => $action,
            'requires_confirmation_before_fix' => true,
        ];
    }

    private function customerDebtRow(CustomerDebt $row): array
    {
        return [
            'id' => $row->id,
            'code' => $row->ref_code,
            'type' => $row->type,
            'amount' => $this->number($row->amount),
            'balance' => $this->number($row->debt_total),
            'recorded_at' => $this->dateValue($row->recorded_at),
            'created_at' => $this->dateValue($row->created_at),
            'reference_type' => 'CustomerDebt',
            'reference_id' => $row->id,
            'reference_code' => $row->ref_code,
            'invoice_id' => $this->safeAttr($row, 'invoice_id'),
            'cash_flow_id' => $this->safeAttr($row, 'cash_flow_id'),
            'note' => $row->note,
            'status' => $this->safeAttr($row, 'status'),
        ];
    }

    private function supplierDebtTransactionRow(SupplierDebtTransaction $row): array
    {
        return [
            'id' => $row->id,
            'code' => $row->code,
            'type' => $row->type,
            'amount' => $this->number($row->amount),
            'balance' => $this->number($row->debt_remain),
            'recorded_at' => $this->dateValue($this->safeAttr($row, 'recorded_at')),
            'created_at' => $this->dateValue($row->created_at),
            'reference_type' => 'SupplierDebtTransaction',
            'reference_id' => $row->id,
            'reference_code' => $row->code,
            'purchase_id' => $row->purchase_id,
            'cash_flow_id' => $this->safeAttr($row, 'cash_flow_id'),
            'note' => $row->note,
            'status' => $this->safeAttr($row, 'status'),
        ];
    }

    private function invoiceRow(Invoice $row): array
    {
        $cashflows = CashFlow::query()
            ->where('reference_type', 'Invoice')
            ->where('reference_code', $row->code);
        $outstanding = $this->number($row->total) - $this->number($row->customer_paid);

        return [
            'id' => $row->id,
            'code' => $row->code,
            'status' => $row->status,
            'transaction_date' => $this->dateValue($row->transaction_date),
            'created_at' => $this->dateValue($row->created_at),
            'total' => $this->number($row->total),
            'customer_paid' => $this->number($row->customer_paid),
            'debt_amount' => $outstanding,
            'outstanding' => $outstanding,
            'payment_status' => $outstanding <= 0 ? 'paid' : ($this->number($row->customer_paid) > 0 ? 'partial' : 'unpaid'),
            'cashflow_count' => (clone $cashflows)->count(),
            'cashflow_total' => $this->number((clone $cashflows)->sum('amount')),
        ];
    }

    private function orderReturnRow(OrderReturn $row): array
    {
        return [
            'id' => $row->id,
            'code' => $row->code,
            'status' => $row->status,
            'amount' => $this->number($row->total),
            'total' => $this->number($row->total),
            'paid' => $this->number($row->paid_to_customer),
            'refund' => $this->number($row->paid_to_customer),
            'created_at' => $this->dateValue($row->created_at),
            'business_date' => $this->dateValue($this->safeAttr($row, 'return_date') ?: $row->created_at),
            'reference_code' => $row->code,
            'note' => $row->note,
        ];
    }

    private function purchaseRow(Purchase $row): array
    {
        $cashflows = CashFlow::query()
            ->where('reference_type', 'Purchase')
            ->where('reference_code', $row->code);
        $outstanding = $this->number($row->total_amount) - $this->number($row->discount) - $this->number($row->paid_amount);

        return [
            'id' => $row->id,
            'code' => $row->code,
            'supplier_id' => $row->supplier_id,
            'status' => $row->status,
            'purchase_date' => $this->dateValue($row->purchase_date),
            'created_at' => $this->dateValue($row->created_at),
            'total_amount' => $this->number($row->total_amount),
            'discount' => $this->number($row->discount),
            'paid_amount' => $this->number($row->paid_amount),
            'outstanding' => $outstanding,
            'cashflow_count' => (clone $cashflows)->count(),
            'cashflow_total' => $this->number((clone $cashflows)->sum('amount')),
        ];
    }

    private function purchaseReturnRow(PurchaseReturn $row): array
    {
        return [
            'id' => $row->id,
            'code' => $row->code,
            'status' => $row->status,
            'amount' => $this->number($row->total_amount),
            'total' => $this->number($row->total_amount),
            'paid' => $this->number($row->refund_amount),
            'refund' => $this->number($row->refund_amount),
            'created_at' => $this->dateValue($row->created_at),
            'business_date' => $this->dateValue($row->return_date ?: $row->created_at),
            'reference_code' => $row->code,
            'note' => $row->note,
        ];
    }

    private function cashFlowRow(CashFlow $row): array
    {
        return [
            'id' => $row->id,
            'code' => $row->code,
            'type' => $row->type,
            'target_type' => $row->target_type,
            'target_id' => $row->target_id,
            'amount' => $this->number($row->amount),
            'time' => $this->dateValue($row->time),
            'created_at' => $this->dateValue($row->created_at),
            'reference_type' => $row->reference_type,
            'reference_id' => $this->safeAttr($row, 'reference_id'),
            'reference_code' => $row->reference_code,
            'status' => $row->status,
            'deleted_at' => $this->dateValue($row->deleted_at),
            'payment_method' => $row->payment_method,
            'note' => $row->description,
        ];
    }

    private function debtOffsetRow(DebtOffset $row): array
    {
        return [
            'id' => $row->id,
            'code' => $row->code,
            'status' => $row->status,
            'amount' => $this->number($row->amount),
            'total' => $this->number($row->amount),
            'paid' => 0.0,
            'refund' => 0.0,
            'created_at' => $this->dateValue($row->created_at),
            'business_date' => $this->dateValue($row->created_at),
            'reference_code' => $row->code,
            'note' => $row->note,
        ];
    }

    private function timelineEntry(array $entry): array
    {
        return [
            'code' => $entry['code'] ?? null,
            'time' => $this->dateValue($entry['time'] ?? $entry['display_time'] ?? null),
            'display_time' => $this->dateValue($entry['display_time'] ?? $entry['time'] ?? null),
            'display_type' => $entry['display_type'] ?? $entry['type_label'] ?? null,
            'event_kind' => $entry['event_kind'] ?? $entry['type'] ?? null,
            'display_effect' => $this->number($entry['display_effect'] ?? 0),
            'balance_effect' => $this->number($entry['balance_effect'] ?? 0),
            'customer_display_running_balance' => $this->nullableNumber($entry['customer_display_running_balance'] ?? null),
            'supplier_display_running_balance' => $this->nullableNumber($entry['supplier_display_running_balance'] ?? null),
            'is_virtual_opening' => (bool) ($entry['is_virtual_opening'] ?? false),
            'source' => $entry['source'] ?? null,
            'reference_type' => $entry['reference_type'] ?? null,
            'reference_code' => $entry['reference_code'] ?? $entry['code'] ?? null,
        ];
    }

    private function cashFlowQuery(Customer $partner, array $invoiceCodes, array $purchaseCodes): Builder
    {
        return CashFlow::query()
            ->where(function (Builder $q) use ($partner, $invoiceCodes, $purchaseCodes) {
                $q->where('target_id', $partner->id)
                    ->orWhere(function (Builder $sub) use ($invoiceCodes) {
                        $sub->whereIn('reference_code', $invoiceCodes);
                    })
                    ->orWhere(function (Builder $sub) use ($purchaseCodes) {
                        $sub->whereIn('reference_code', $purchaseCodes);
                    });
            });
    }

    private function safeAttr(Model $model, string $key): mixed
    {
        if (!$this->hasColumn($model->getTable(), $key)) {
            return null;
        }

        return $model->getAttribute($key);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }

    private function number(mixed $value): float
    {
        return (float) ($value ?? 0);
    }

    private function nullableNumber(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }
}
