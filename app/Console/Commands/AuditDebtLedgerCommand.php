<?php

namespace App\Console\Commands;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SupplierDebtTransaction;
use App\Services\PartnerDebtLedgerService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class AuditDebtLedgerCommand extends Command
{
    protected $signature = 'debt:audit-ledger
        {--dry-run : Required. Audit only, do not write DB}
        {--customer-id= : Audit one customer/partner id}
        {--supplier-id= : Alias for supplier customer id}
        {--dual-role-only : Only audit partners that are both customer and supplier}
        {--with-virtual-opening : Only include rows that use virtual opening}
        {--only-mismatch : Only include rows with mismatch/risk}
        {--export= : Export CSV path}
        {--limit= : Limit number of audited rows}';

    protected $description = 'Read-only audit of customer/supplier debt ledger integrity';

    private const CSV_HEADERS = [
        'id',
        'code',
        'name',
        'phone',
        'is_customer',
        'is_supplier',
        'status',
        'debt_amount',
        'supplier_debt_amount',
        'stored_customer_view',
        'stored_supplier_view',
        'customer_debt_count',
        'customer_debt_sum',
        'supplier_debt_transaction_count',
        'supplier_debt_transaction_sum',
        'invoice_count',
        'invoice_total',
        'invoice_paid_total',
        'invoice_outstanding_total',
        'cashflow_receipt_count',
        'cashflow_receipt_total',
        'order_return_count',
        'order_return_total',
        'order_return_refund_total',
        'purchase_count',
        'purchase_total',
        'purchase_paid_total',
        'purchase_outstanding_total',
        'purchase_return_count',
        'purchase_return_total',
        'purchase_return_refund_total',
        'debt_offset_count',
        'debt_offset_total',
        'customer_display_balance_target',
        'customer_display_balance_final',
        'customer_ledger_mismatch',
        'customer_display_resolved',
        'customer_has_virtual_opening',
        'customer_virtual_opening_balance',
        'customer_reconcile_severity',
        'supplier_display_balance_target',
        'supplier_display_balance_final',
        'supplier_ledger_mismatch',
        'supplier_display_resolved',
        'supplier_has_virtual_opening',
        'supplier_virtual_opening_balance',
        'supplier_reconcile_severity',
        'classification',
        'risk_level',
        'recommended_action',
    ];

    public function handle(PartnerDebtLedgerService $ledgerService): int
    {
        if (!$this->option('dry-run')) {
            $this->error('This command is audit-only. Please pass --dry-run. No data was modified.');
            return self::FAILURE;
        }

        $rows = [];
        $totalScanned = 0;

        $query = $this->partnerQuery();
        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->orderBy('id')->get() as $partner) {
            $totalScanned++;
            $row = $this->auditPartner($partner, $ledgerService);

            if ($this->option('dual-role-only') && (!$row['is_customer'] || !$row['is_supplier'])) {
                continue;
            }

            if ($this->option('with-virtual-opening') && !$this->hasVirtualOpening($row)) {
                continue;
            }

            if ($this->option('only-mismatch') && $row['classification'] === 'OK') {
                continue;
            }

            $rows[] = $row;
        }

        if ($exportPath = $this->option('export')) {
            $this->exportCsv((string) $exportPath, $rows);
        }

        $this->printSummary($rows, $totalScanned, $exportPath ? (string) $exportPath : null);

        return self::SUCCESS;
    }

    private function partnerQuery(): Builder
    {
        $query = Customer::query();

        $id = $this->option('customer-id') ?: $this->option('supplier-id');
        if ($id) {
            return $query->where('id', $id);
        }

        $hasSupplierDebt = Schema::hasColumn('customers', 'supplier_debt_amount');
        $hasIsCustomer = Schema::hasColumn('customers', 'is_customer');
        $hasIsSupplier = Schema::hasColumn('customers', 'is_supplier');

        return $query->where(function (Builder $q) use ($hasSupplierDebt, $hasIsCustomer, $hasIsSupplier) {
            if ($hasIsCustomer) {
                $q->orWhere('is_customer', true);
            }
            if ($hasIsSupplier) {
                $q->orWhere('is_supplier', true);
            }

            $q->orWhere('debt_amount', '!=', 0);

            if ($hasSupplierDebt) {
                $q->orWhere('supplier_debt_amount', '!=', 0);
            }
        });
    }

    private function auditPartner(Customer $partner, PartnerDebtLedgerService $ledgerService): array
    {
        $storedCustomerDebt = (float) ($partner->debt_amount ?? 0);
        $storedSupplierDebt = Schema::hasColumn('customers', 'supplier_debt_amount')
            ? (float) ($partner->supplier_debt_amount ?? 0)
            : 0.0;
        $storedCustomerView = $storedCustomerDebt - $storedSupplierDebt;
        $storedSupplierView = $storedSupplierDebt - $storedCustomerDebt;

        $source = $this->sourceMetrics($partner);
        $customerLedger = $this->customerLedgerSnapshot($partner, $ledgerService);
        $supplierLedger = $this->supplierLedgerSnapshot($partner, $ledgerService);

        $row = array_merge([
            'id' => $partner->id,
            'code' => (string) ($partner->code ?? ''),
            'name' => (string) ($this->normalizeText($partner->name ?? '') ?? ''),
            'phone' => (string) ($partner->phone ?? ''),
            'is_customer' => (bool) ($partner->is_customer ?? false),
            'is_supplier' => (bool) ($partner->is_supplier ?? false),
            'status' => (string) ($partner->status ?? ''),
            'debt_amount' => $storedCustomerDebt,
            'supplier_debt_amount' => $storedSupplierDebt,
            'stored_customer_view' => $storedCustomerView,
            'stored_supplier_view' => $storedSupplierView,
        ], $source, $customerLedger, $supplierLedger);

        $row['classification'] = $this->classifyDebtAudit($row);
        $row['risk_level'] = $this->riskLevel($row, $row['classification']);
        $row['recommended_action'] = $this->recommendedAction($row['classification']);

        return $row;
    }

    private function sourceMetrics(Customer $partner): array
    {
        $customerDebts = CustomerDebt::query()->where('customer_id', $partner->id);
        $supplierDebtTransactions = SupplierDebtTransaction::query()->where('supplier_id', $partner->id);

        $invoices = Invoice::query()
            ->where('customer_id', $partner->id)
            ->whereNotIn('status', $this->cancelledStatuses());

        $cashflows = CashFlow::query()
            ->where('target_id', $partner->id)
            ->where('type', 'receipt')
            ->whereNull('deleted_at')
            ->where(function (Builder $q) {
                $q->whereNull('status')
                    ->orWhereNotIn('status', $this->cancelledStatuses());
            });

        $returns = OrderReturn::query()
            ->where('customer_id', $partner->id)
            ->whereNotIn('status', $this->cancelledStatuses());

        $purchases = Purchase::query()
            ->where('supplier_id', $partner->id)
            ->whereNotIn('status', $this->cancelledStatuses());

        $purchaseReturns = PurchaseReturn::query()
            ->where('supplier_id', $partner->id)
            ->whereNotIn('status', $this->cancelledStatuses());

        $debtOffsets = DebtOffset::query()
            ->where('customer_id', $partner->id)
            ->whereNotIn('status', $this->cancelledStatuses());

        $purchaseTotal = $this->sumColumn($purchases, 'total_amount');
        $purchaseDiscount = $this->sumColumn($purchases, 'discount');
        $purchasePaid = $this->sumColumn($purchases, 'paid_amount');

        return [
            'customer_debt_count' => (int) $customerDebts->count(),
            'customer_debt_sum' => $this->sumColumn($customerDebts, 'amount'),
            'supplier_debt_transaction_count' => (int) $supplierDebtTransactions->count(),
            'supplier_debt_transaction_sum' => $this->sumColumn($supplierDebtTransactions, 'amount'),
            'invoice_count' => (int) $invoices->count(),
            'invoice_total' => $this->sumColumn($invoices, 'total'),
            'invoice_paid_total' => $this->sumColumn($invoices, 'customer_paid'),
            'invoice_outstanding_total' => $this->sumColumn($invoices, 'total') - $this->sumColumn($invoices, 'customer_paid'),
            'cashflow_receipt_count' => (int) $cashflows->count(),
            'cashflow_receipt_total' => $this->sumColumn($cashflows, 'amount'),
            'order_return_count' => (int) $returns->count(),
            'order_return_total' => $this->sumColumn($returns, 'total'),
            'order_return_refund_total' => $this->sumColumn($returns, 'paid_to_customer'),
            'purchase_count' => (int) $purchases->count(),
            'purchase_total' => $purchaseTotal,
            'purchase_paid_total' => $purchasePaid,
            'purchase_outstanding_total' => $purchaseTotal - $purchaseDiscount - $purchasePaid,
            'purchase_return_count' => (int) $purchaseReturns->count(),
            'purchase_return_total' => $this->sumColumn($purchaseReturns, 'total_amount'),
            'purchase_return_refund_total' => $this->sumColumn($purchaseReturns, 'refund_amount'),
            'debt_offset_count' => (int) $debtOffsets->count(),
            'debt_offset_total' => $this->sumColumn($debtOffsets, 'amount'),
        ];
    }

    private function customerLedgerSnapshot(Customer $partner, PartnerDebtLedgerService $ledgerService): array
    {
        try {
            $ledger = $ledgerService->buildCustomerNetLedger($partner);
            $summary = $ledger['summary'] ?? [];
            $reconcile = $ledger['reconcile'] ?? [];

            return [
                'customer_display_balance_target' => (float) ($summary['display_balance_target'] ?? $reconcile['display_balance_target'] ?? 0),
                'customer_display_balance_final' => (float) ($summary['display_balance_final'] ?? $reconcile['display_balance_final'] ?? 0),
                'customer_ledger_mismatch' => (bool) ($reconcile['ledger_mismatch'] ?? false),
                'customer_display_resolved' => (bool) ($reconcile['display_resolved'] ?? true),
                'customer_has_virtual_opening' => (bool) ($summary['has_virtual_opening_balance'] ?? $reconcile['has_virtual_opening_balance'] ?? false),
                'customer_virtual_opening_balance' => (float) ($summary['virtual_opening_balance'] ?? 0),
                'customer_reconcile_severity' => (string) ($reconcile['severity'] ?? 'ok'),
            ];
        } catch (\Throwable $e) {
            return [
                'customer_display_balance_target' => 0.0,
                'customer_display_balance_final' => 0.0,
                'customer_ledger_mismatch' => true,
                'customer_display_resolved' => false,
                'customer_has_virtual_opening' => false,
                'customer_virtual_opening_balance' => 0.0,
                'customer_reconcile_severity' => 'error: ' . $e->getMessage(),
            ];
        }
    }

    private function supplierLedgerSnapshot(Customer $partner, PartnerDebtLedgerService $ledgerService): array
    {
        if (!(bool) ($partner->is_supplier ?? false)) {
            return [
                'supplier_display_balance_target' => 0.0,
                'supplier_display_balance_final' => 0.0,
                'supplier_ledger_mismatch' => false,
                'supplier_display_resolved' => true,
                'supplier_has_virtual_opening' => false,
                'supplier_virtual_opening_balance' => 0.0,
                'supplier_reconcile_severity' => 'ok',
            ];
        }

        try {
            $ledger = ((bool) ($partner->is_customer ?? false))
                ? $ledgerService->buildSupplierDualRolePartnerTimeline($partner)
                : $ledgerService->buildSupplierPayableLedger($partner);
            $summary = $ledger['summary'] ?? [];
            $reconcile = $ledger['reconcile'] ?? [];

            return [
                'supplier_display_balance_target' => (float) ($summary['display_balance_target'] ?? $reconcile['display_balance_target'] ?? 0),
                'supplier_display_balance_final' => (float) ($summary['display_balance_final'] ?? $reconcile['display_balance_final'] ?? 0),
                'supplier_ledger_mismatch' => (bool) ($reconcile['ledger_mismatch'] ?? false),
                'supplier_display_resolved' => (bool) ($reconcile['display_resolved'] ?? true),
                'supplier_has_virtual_opening' => (bool) ($summary['has_virtual_opening_balance'] ?? $reconcile['has_virtual_opening_balance'] ?? false),
                'supplier_virtual_opening_balance' => (float) ($summary['virtual_opening_balance'] ?? 0),
                'supplier_reconcile_severity' => (string) ($reconcile['severity'] ?? 'ok'),
            ];
        } catch (\Throwable $e) {
            return [
                'supplier_display_balance_target' => 0.0,
                'supplier_display_balance_final' => 0.0,
                'supplier_ledger_mismatch' => true,
                'supplier_display_resolved' => false,
                'supplier_has_virtual_opening' => false,
                'supplier_virtual_opening_balance' => 0.0,
                'supplier_reconcile_severity' => 'error: ' . $e->getMessage(),
            ];
        }
    }

    private function classifyDebtAudit(array $row): string
    {
        $hasLedger = ((int) $row['customer_debt_count'] + (int) $row['supplier_debt_transaction_count']) > 0;
        $hasDocuments = $this->documentCount($row) > 0;
        $hasAnySource = $hasLedger || $hasDocuments;
        $hasStoredBalance = abs((float) $row['debt_amount']) >= 0.01
            || abs((float) $row['supplier_debt_amount']) >= 0.01;
        $hasVirtualOpening = $this->hasVirtualOpening($row);
        $displayResolved = (bool) $row['customer_display_resolved'] && (bool) $row['supplier_display_resolved'];
        $ledgerMismatch = (bool) $row['customer_ledger_mismatch'] || (bool) $row['supplier_ledger_mismatch'];

        if (!$ledgerMismatch && $displayResolved && !$hasVirtualOpening) {
            return 'OK';
        }

        if ($hasStoredBalance && !$hasAnySource) {
            return 'STORED_BALANCE_NO_HISTORY';
        }

        if ((bool) $row['is_customer'] && (bool) $row['is_supplier']) {
            $viewsAreOpposite = abs((float) $row['stored_customer_view'] + (float) $row['stored_supplier_view']) < 0.01;
            $supplierFinalMatches = abs((float) $row['supplier_display_balance_final'] - (float) $row['stored_supplier_view']) < 0.01;
            if (!$viewsAreOpposite || !$supplierFinalMatches) {
                return 'DUAL_ROLE_NET_MISMATCH';
            }
        }

        if ($hasDocuments && !$hasLedger && ($ledgerMismatch || !$displayResolved)) {
            return 'HAS_DOCUMENTS_NO_LEDGER';
        }

        if ($hasLedger && !$hasDocuments) {
            return 'HAS_LEDGER_NO_DOCUMENT';
        }

        if ($hasDocuments && $hasLedger && $ledgerMismatch) {
            return 'DOCUMENT_LEDGER_MISMATCH';
        }

        if ($hasVirtualOpening && $displayResolved) {
            return 'VIRTUAL_OPENING_REQUIRED';
        }

        if ((bool) $row['is_customer'] && !(bool) $row['is_supplier'] && ($ledgerMismatch || !$displayResolved)) {
            return 'CUSTOMER_ONLY_MISMATCH';
        }

        if ((bool) $row['is_supplier'] && !(bool) $row['is_customer'] && ($ledgerMismatch || !$displayResolved)) {
            return 'SUPPLIER_ONLY_MISMATCH';
        }

        return 'NEEDS_MANUAL_REVIEW';
    }

    private function riskLevel(array $row, string $classification): string
    {
        $risk = match ($classification) {
            'OK' => 'LOW',
            'VIRTUAL_OPENING_REQUIRED' => 'MEDIUM',
            'DUAL_ROLE_NET_MISMATCH' => 'CRITICAL',
            'STORED_BALANCE_NO_HISTORY',
            'HAS_DOCUMENTS_NO_LEDGER',
            'DOCUMENT_LEDGER_MISMATCH' => 'HIGH',
            default => 'MEDIUM',
        };

        if ($this->maxAbsAmount($row) >= 10_000_000) {
            return $this->elevateRisk($risk);
        }

        return $risk;
    }

    private function recommendedAction(string $classification): string
    {
        return match ($classification) {
            'OK' => 'Khong can xu ly.',
            'STORED_BALANCE_NO_HISTORY' => 'Can nhac tao opening balance that sau khi backup/xac nhan.',
            'HAS_DOCUMENTS_NO_LEDGER' => 'Kiem tra mapping chung tu/ledger truoc, khong backfill voi.',
            'HAS_LEDGER_NO_DOCUMENT' => 'Giu ledger, kiem tra chung tu goc bi thieu/xoa/import.',
            'DOCUMENT_LEDGER_MISMATCH' => 'Manual review truoc khi sua.',
            'VIRTUAL_OPENING_REQUIRED' => 'Co the giu read-only hoac tao opening balance that sau xac nhan.',
            'DUAL_ROLE_NET_MISMATCH' => 'Manual review dual-role.',
            'SUPPLIER_ONLY_MISMATCH' => 'Manual review NCC.',
            'CUSTOMER_ONLY_MISMATCH' => 'Manual review KH.',
            default => 'Manual review.',
        };
    }

    private function exportCsv(string $path, array $rows): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            throw new \RuntimeException("Cannot prepare CSV export directory: {$dir}");
        }

        $handle = fopen($path, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::CSV_HEADERS);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($header) => $this->csvValue($row[$header] ?? ''), self::CSV_HEADERS));
        }

        fclose($handle);
    }

    private function printSummary(array $rows, int $totalScanned, ?string $exportPath): void
    {
        $classificationCounts = [];
        $riskCounts = [];
        $virtualOpeningCount = 0;

        foreach ($rows as $row) {
            $classificationCounts[$row['classification']] = ($classificationCounts[$row['classification']] ?? 0) + 1;
            $riskCounts[$row['risk_level']] = ($riskCounts[$row['risk_level']] ?? 0) + 1;
            if ($this->hasVirtualOpening($row)) {
                $virtualOpeningCount++;
            }
        }

        ksort($classificationCounts);
        ksort($riskCounts);

        $this->info('Total audited: ' . count($rows));
        $this->info('Total scanned: ' . $totalScanned);
        $this->info('Total mismatches: ' . count(array_filter($rows, fn ($row) => $row['classification'] !== 'OK')));
        $this->info('Total virtual opening: ' . $virtualOpeningCount);
        $this->line('Classification counts:');
        foreach ($classificationCounts as $classification => $count) {
            $this->line("- {$classification}: {$count}");
        }
        $this->line('Risk counts:');
        foreach ($riskCounts as $risk => $count) {
            $this->line("- {$risk}: {$count}");
        }
        $this->info('Export path: ' . ($exportPath ?: 'not requested'));
    }

    private function sumColumn(Builder $query, string $column): float
    {
        $model = $query->getModel();
        if (!Schema::hasColumn($model->getTable(), $column)) {
            return 0.0;
        }

        return (float) (clone $query)->sum($column);
    }

    private function documentCount(array $row): int
    {
        return (int) $row['invoice_count']
            + (int) $row['cashflow_receipt_count']
            + (int) $row['order_return_count']
            + (int) $row['purchase_count']
            + (int) $row['purchase_return_count']
            + (int) $row['debt_offset_count'];
    }

    private function hasVirtualOpening(array $row): bool
    {
        return (bool) ($row['customer_has_virtual_opening'] ?? false)
            || (bool) ($row['supplier_has_virtual_opening'] ?? false)
            || abs((float) ($row['customer_virtual_opening_balance'] ?? 0)) >= 0.01
            || abs((float) ($row['supplier_virtual_opening_balance'] ?? 0)) >= 0.01;
    }

    private function maxAbsAmount(array $row): float
    {
        return max(array_map(
            fn ($key) => abs((float) ($row[$key] ?? 0)),
            [
                'debt_amount',
                'supplier_debt_amount',
                'stored_customer_view',
                'stored_supplier_view',
                'customer_virtual_opening_balance',
                'supplier_virtual_opening_balance',
                'customer_display_balance_final',
                'supplier_display_balance_final',
            ]
        ));
    }

    private function elevateRisk(string $risk): string
    {
        return match ($risk) {
            'LOW' => 'MEDIUM',
            'MEDIUM' => 'HIGH',
            'HIGH' => 'CRITICAL',
            default => $risk,
        };
    }

    private function cancelledStatuses(): array
    {
        return ['Đã hủy', 'đã hủy', 'Da huy', 'da huy', 'cancelled', 'canceled', 'void', 'deleted'];
    }

    private function csvValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    private function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;
        if (preg_match('/(?:[ÃÂÆÄâ€\x{0080}-\x{009F}]|á[º»])/u', $text) !== 1) {
            return $text;
        }

        $map = [
            0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84,
            0x2026 => 0x85, 0x2020 => 0x86, 0x2021 => 0x87, 0x02C6 => 0x88,
            0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C,
            0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93,
            0x201D => 0x94, 0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97,
            0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B,
            0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F,
        ];

        $bytes = '';
        $length = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $length; $i++) {
            $codepoint = mb_ord(mb_substr($text, $i, 1, 'UTF-8'), 'UTF-8');
            if ($codepoint <= 0xFF) {
                $bytes .= chr($codepoint);
                continue;
            }
            if (isset($map[$codepoint])) {
                $bytes .= chr($map[$codepoint]);
                continue;
            }

            return $text;
        }

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : $text;
    }
}
