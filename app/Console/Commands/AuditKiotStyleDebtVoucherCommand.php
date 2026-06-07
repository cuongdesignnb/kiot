<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\PartnerDebtLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * STEP 10 / 10B / 10C — Read-only audit of KiotViet-style debt vouchers.
 *
 * STEP 10C adds BULK mode: scan every customer / supplier, classify each
 * timeline row's risk + severity, group by partner, and export JSON/CSV/MD
 * top-risk reports. It NEVER writes data — only reads via
 * PartnerDebtLedgerService and bounded SELECT lookups.
 */
class AuditKiotStyleDebtVoucherCommand extends Command
{
    protected $signature = 'debt:audit-kiot-vouchers
        {--dry-run : Required. This command is read-only.}
        {--customer-code= : Single customer code}
        {--supplier-code= : Single supplier code}
        {--all : Audit all customers and suppliers}
        {--all-customers : Audit all customers}
        {--all-suppliers : Audit all suppliers}
        {--only-risk : Only include partners that have at least one risk}
        {--limit= : Limit number of partners (smoke test)}
        {--chunk=200 : Chunk size for bulk scan}
        {--summary-only : Print only the summary to console}
        {--max-rows=100 : Max rows printed to console}
        {--export-json= : Write JSON report}
        {--export-csv= : Write CSV report}
        {--export-md= : Write Markdown report}';

    protected $description = 'Read-only audit (single or bulk): real vs virtual-fallback debt vouchers, risks and severity.';

    /** @var array<string,string> risk → severity */
    private const SEVERITY = [
        // critical
        'clickable_fallback' => 'critical',
        'receipt_allocation_mismatch' => 'critical',
        'balance_mismatch' => 'critical',
        'duplicate_receipt' => 'critical',
        'orphan_cashflow' => 'critical',
        'audit_exception' => 'critical',
        // warning
        'virtual_fallback' => 'warning',
        'missing_real_voucher' => 'warning',
        'missing_click_target' => 'warning',
        // info
        'reference_only' => 'info',
        'legacy_entry' => 'info',
        // ok
        'ok' => 'ok',
    ];

    public function handle(): int
    {
        if (!$this->option('dry-run')) {
            $this->error('This command is read-only. Please pass --dry-run. No data was modified.');
            return self::FAILURE;
        }

        $single = $this->option('customer-code') || $this->option('supplier-code');
        $bulk = $this->option('all') || $this->option('all-customers') || $this->option('all-suppliers');

        if ($single && $bulk) {
            $this->error('Choose EITHER a single (--customer-code/--supplier-code) OR a bulk (--all/--all-customers/--all-suppliers) mode, not both.');
            return self::FAILURE;
        }
        if (!$single && !$bulk) {
            $this->error('Provide a single (--customer-code/--supplier-code) or bulk (--all/--all-customers/--all-suppliers) mode.');
            return self::FAILURE;
        }

        return $bulk ? $this->runBulk() : $this->runSingle();
    }

    // ════════════════════════════════════════════════════
    // SINGLE mode (backward compatible with STEP 10/10B)
    // ════════════════════════════════════════════════════
    private function runSingle(): int
    {
        $code = $this->option('customer-code') ?: $this->option('supplier-code');
        $partner = Customer::where('code', $code)->first();
        if (!$partner) {
            $this->error("Partner not found: {$code}");
            return self::FAILURE;
        }

        $view = $this->option('supplier-code') ? 'supplier' : 'customer';
        $audit = $this->auditPartner($partner, $view);
        $summary = $audit['summary'];
        $rows = collect($audit['rows']);

        $this->info("Audit ({$view}): {$partner->name} ({$partner->code})");
        if (!$this->option('summary-only')) {
            $this->table(
                ['Code', 'Type', 'Amount', 'Real', 'Fallback', 'Click', 'Ref', 'Risk'],
                $rows->take((int) $this->option('max-rows'))->map(fn ($r) => [
                    $r['code'], $r['type'], number_format($r['amount']),
                    $r['is_real_voucher'] ? 'Y' : '', $r['is_virtual_fallback'] ? 'Y' : '',
                    $r['click_modal'], $r['click_ref'] ?? '', $r['risk'],
                ])->all()
            );
        }
        foreach ($summary as $k => $v) {
            $this->line(str_pad($k, 28) . ': ' . (is_bool($v) ? ($v ? 'true' : 'false') : $v));
        }

        // Backward-compatible single-mode JSON shape (summary/groups/rows).
        $report = [
            'summary' => $summary,
            'invoice_receipt_groups' => $audit['invoice_receipt_groups'],
            'rows' => $audit['rows'],
        ];
        if ($path = $this->option('export-json')) {
            $this->writeFile($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("JSON written: {$path}");
        }
        if ($path = $this->option('export-md')) {
            $md = "# Kiot-style debt voucher audit — {$partner->name} ({$partner->code})\n\n";
            foreach ($summary as $k => $v) $md .= "- **{$k}**: " . (is_bool($v) ? ($v ? 'true' : 'false') : $v) . "\n";
            $this->writeFile($path, $md);
            $this->info("Markdown written: {$path}");
        }

        $this->warn('Read-only audit complete. No data was modified.');
        return self::SUCCESS;
    }

    // ════════════════════════════════════════════════════
    // BULK mode (STEP 10C)
    // ════════════════════════════════════════════════════
    private function runBulk(): int
    {
        $all = (bool) $this->option('all');
        $doCustomers = $all || $this->option('all-customers');
        $doSuppliers = $all || $this->option('all-suppliers');
        $onlyRisk = (bool) $this->option('only-risk');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $chunk = max(1, (int) $this->option('chunk'));

        $partners = collect();   // per-partner audit results (filtered)
        $allRisks = collect();   // flattened risk rows
        $counters = [
            'customers_scanned' => 0,
            'suppliers_scanned' => 0,
            'partners_scanned' => 0,
            'partners_with_risk' => 0,
            'critical_partners' => 0,
            'warning_partners' => 0,
            'info_partners' => 0,
            'ok_partners' => 0,
            'total_rows' => 0,
            'virtual_fallbacks' => 0,
            'clickable_fallback_rows' => 0,
            'receipt_allocation_mismatches' => 0,
            'missing_real_voucher' => 0,
            'missing_click_target' => 0,
            'duplicate_receipt' => 0,
            'orphan_cashflow' => 0,
            'balance_mismatch' => 0,
            'audit_exceptions' => 0,
        ];

        $scanCounter = ['n' => 0]; // honours --limit across both views

        $views = [];
        if ($doCustomers) $views[] = ['view' => 'customer', 'column' => 'is_customer'];
        if ($doSuppliers) $views[] = ['view' => 'supplier', 'column' => 'is_supplier'];

        foreach ($views as $cfg) {
            Customer::query()
                ->where($cfg['column'], true)
                ->orderBy('id')
                ->select(['id', 'code', 'name', 'is_customer', 'is_supplier', 'debt_amount', 'supplier_debt_amount'])
                ->chunkById($chunk, function (Collection $batch) use (
                    $cfg, $limit, $onlyRisk, &$partners, &$allRisks, &$counters, &$scanCounter
                ) {
                    foreach ($batch as $partner) {
                        if ($limit !== null && $scanCounter['n'] >= $limit) {
                            return false; // stop chunking
                        }
                        $scanCounter['n']++;

                        try {
                            $audit = $this->auditPartner($partner, $cfg['view']);
                        } catch (\Throwable $e) {
                            $counters['audit_exceptions']++;
                            $counters['partners_scanned']++;
                            $cfg['view'] === 'supplier' ? $counters['suppliers_scanned']++ : $counters['customers_scanned']++;
                            $allRisks->push([
                                'severity' => 'critical', 'risk' => 'audit_exception',
                                'view' => $cfg['view'], 'partner_id' => $partner->id,
                                'partner_code' => $partner->code, 'partner_name' => $partner->name,
                                'document_code' => null, 'document_type' => null, 'amount' => 0,
                                'reference_code' => null, 'message' => 'Audit threw: ' . $e->getMessage(),
                                'suggested_action' => 'manual_review',
                            ]);
                            continue;
                        }

                        $counters['partners_scanned']++;
                        $cfg['view'] === 'supplier' ? $counters['suppliers_scanned']++ : $counters['customers_scanned']++;
                        $s = $audit['summary'];
                        $counters['total_rows'] += $s['total_rows'];
                        $counters['virtual_fallbacks'] += $s['virtual_fallbacks'];
                        $counters['clickable_fallback_rows'] += $s['clickable_fallback_rows'];
                        $counters['receipt_allocation_mismatches'] += $s['receipt_allocation_mismatches'];
                        $counters['missing_real_voucher'] += $s['missing_real_voucher'];
                        $counters['missing_click_target'] += $s['missing_click_target'];
                        $counters['duplicate_receipt'] += $s['duplicate_receipt'];
                        $counters['orphan_cashflow'] += $s['orphan_cashflow'];
                        $counters['balance_mismatch'] += $s['balance_mismatch'] ? 1 : 0;

                        $maxSev = $s['max_severity'];
                        if ($maxSev === 'critical') $counters['critical_partners']++;
                        elseif ($maxSev === 'warning') $counters['warning_partners']++;
                        elseif ($maxSev === 'info') $counters['info_partners']++;
                        else $counters['ok_partners']++;

                        if ($s['risk_count'] > 0) {
                            $counters['partners_with_risk']++;
                            foreach ($audit['risks'] as $risk) {
                                $allRisks->push($risk);
                            }
                        }

                        if (!$onlyRisk || $s['risk_count'] > 0) {
                            $partners->push([
                                'partner' => $audit['partner'],
                                'summary' => $s,
                                'risks' => $audit['risks'],
                                'invoice_receipt_groups' => $audit['invoice_receipt_groups'],
                            ]);
                        }
                    }

                    $this->line(sprintf(
                        'Scanned %d (cust %d / sup %d) | risks %d | critical %d',
                        $counters['partners_scanned'],
                        $counters['customers_scanned'],
                        $counters['suppliers_scanned'],
                        $counters['partners_with_risk'],
                        $counters['critical_partners']
                    ));

                    return !($limit !== null && $scanCounter['n'] >= $limit);
                });
        }

        $severityRank = ['critical' => 0, 'warning' => 1, 'info' => 2, 'ok' => 3];
        $topRisks = $allRisks
            ->sortBy(fn ($r) => $severityRank[$r['severity']] ?? 9)
            ->take(200)
            ->values();

        $mode = $all ? 'all' : ($doCustomers ? 'all-customers' : 'all-suppliers');
        $report = [
            'generated_at' => now()->toIso8601String(),
            'mode' => $mode,
            'dry_run' => true,
            'source' => [
                'local_db_is_production_import' => app()->environment('local'),
                'environment' => app()->environment(),
            ],
            'summary' => $counters,
            'top_risks' => $topRisks->all(),
            'partners' => $partners->all(),
        ];

        // Console summary
        $this->info("Bulk audit ({$mode}) — read-only");
        foreach ($counters as $k => $v) {
            $this->line(str_pad($k, 30) . ': ' . $v);
        }
        if (!$this->option('summary-only') && $topRisks->isNotEmpty()) {
            $this->info('Top risks:');
            $this->table(
                ['Severity', 'Risk', 'View', 'Partner', 'Doc', 'Amount', 'Action'],
                $topRisks->take((int) $this->option('max-rows'))->map(fn ($r) => [
                    $r['severity'], $r['risk'], $r['view'],
                    $r['partner_code'], $r['document_code'] ?? '',
                    number_format((float) ($r['amount'] ?? 0)), $r['suggested_action'],
                ])->all()
            );
        }

        if ($path = $this->option('export-json')) {
            $this->writeFile($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("JSON written: {$path}");
        }
        if ($path = $this->option('export-csv')) {
            $this->writeFile($path, $this->buildCsv($allRisks));
            $this->info("CSV written: {$path}");
        }
        if ($path = $this->option('export-md')) {
            $this->writeFile($path, $this->buildBulkMd($report, $topRisks));
            $this->info("Markdown written: {$path}");
        }

        $this->warn('Read-only bulk audit complete. No data was modified.');
        return self::SUCCESS;
    }

    // ════════════════════════════════════════════════════
    // Per-partner audit (shared by single + bulk)
    // ════════════════════════════════════════════════════
    private function auditPartner(Customer $partner, string $view): array
    {
        $service = app(PartnerDebtLedgerService::class);
        $ledger = $view === 'supplier'
            ? $service->buildSupplierPayableLedger($partner)
            : $service->buildCustomerNetLedger($partner);

        $rows = collect($ledger['entries'])->map(fn ($e) => $this->classifyRow(is_array($e) ? $e : (array) $e))->values();

        // duplicate_receipt — same cash_flow id/code surfacing twice as a real receipt.
        $dupReceiptCodes = $rows
            ->where('event_kind', 'invoice_payment')
            ->where('is_real_voucher', true)
            ->groupBy(fn ($r) => $r['cash_flow_id'] ?? $r['code'])
            ->filter(fn ($g) => $g->count() > 1)
            ->keys()->all();

        // balance_mismatch — only if the service already computed a reconcile flag.
        $balanceMismatch = (bool) ($ledger['reconcile']['has_mismatch'] ?? false);

        // orphan_cashflow — bounded read: partner cashflows referencing an
        // invoice/purchase code that does NOT belong to this partner.
        $orphanCount = $this->orphanCashflowCount($partner, $view);

        $fallbackRows = $rows->where('is_virtual_fallback', true);
        $invoiceReceiptGroups = $rows
            ->where('event_kind', 'invoice_payment')
            ->where('is_real_voucher', true)
            ->groupBy('reference_code')
            ->map(fn ($g, $code) => [
                'invoice_code' => $code,
                'real_receipt_count' => $g->count(),
                'real_receipt_total' => (float) $g->sum('amount'),
                'receipt_codes' => $g->pluck('code')->all(),
                'is_mismatch' => (bool) $g->contains('receipt_allocation_mismatch', true),
            ])->values();

        // Build risk list (row-level + partner-level).
        $risks = collect();
        foreach ($rows as $r) {
            if ($r['risk'] === 'ok') continue;
            $risks->push($this->riskRow($partner, $view, $r['risk'], $r));
        }
        foreach ($dupReceiptCodes as $dupKey) {
            $risks->push($this->riskRow($partner, $view, 'duplicate_receipt', ['code' => (string) $dupKey, 'type' => 'cash_flow', 'amount' => 0, 'reference_code' => null]));
        }
        if ($balanceMismatch) {
            $risks->push($this->riskRow($partner, $view, 'balance_mismatch', ['code' => null, 'type' => 'ledger', 'amount' => (float) ($ledger['reconcile']['computed_balance'] ?? 0), 'reference_code' => null]));
        }
        if ($orphanCount > 0) {
            $risks->push($this->riskRow($partner, $view, 'orphan_cashflow', ['code' => null, 'type' => 'cash_flow', 'amount' => 0, 'reference_code' => null, 'count' => $orphanCount]));
        }

        $maxSeverity = 'ok';
        foreach ($risks as $rk) {
            if ($rk['severity'] === 'critical') { $maxSeverity = 'critical'; break; }
            if ($rk['severity'] === 'warning') $maxSeverity = 'warning';
            elseif ($rk['severity'] === 'info' && $maxSeverity === 'ok') $maxSeverity = 'info';
        }

        $summary = [
            'partner_code' => $partner->code,
            'partner_name' => $partner->name,
            'view' => $view,
            'total_rows' => $rows->count(),
            'real_vouchers' => $rows->where('is_real_voucher', true)->count(),
            'virtual_fallbacks' => $rows->where('is_virtual_fallback', true)->count(),
            'fallback_rows' => $fallbackRows->count(),
            'non_clickable_fallback_rows' => $fallbackRows->where('clickable', false)->count(),
            'clickable_fallback_rows' => $fallbackRows->where('clickable', true)->count(),
            'receipt_allocation_mismatches' => $rows->where('receipt_allocation_mismatch', true)->count(),
            'missing_real_voucher' => $rows->where('risk', 'missing_real_voucher')->count(),
            'missing_click_target' => $rows->where('risk', 'missing_click_target')->count(),
            'duplicate_receipt' => count($dupReceiptCodes),
            'orphan_cashflow' => $orphanCount,
            'balance_mismatch' => $balanceMismatch,
            'critical_count' => $risks->where('severity', 'critical')->count(),
            'warning_count' => $risks->where('severity', 'warning')->count(),
            'info_count' => $risks->where('severity', 'info')->count(),
            'risk_count' => $risks->count(),
            'max_severity' => $maxSeverity,
        ];

        return [
            'partner' => [
                'id' => $partner->id, 'code' => $partner->code, 'name' => $partner->name,
                'view' => $view, 'is_customer' => (bool) $partner->is_customer,
                'is_supplier' => (bool) $partner->is_supplier,
                'debt_amount' => (float) ($partner->debt_amount ?? 0),
                'supplier_debt_amount' => (float) ($partner->supplier_debt_amount ?? 0),
            ],
            'summary' => $summary,
            'invoice_receipt_groups' => $invoiceReceiptGroups->all(),
            'risks' => $risks->values()->all(),
            'rows' => $rows->all(),
        ];
    }

    private function classifyRow(array $e): array
    {
        $isReal = (bool) ($e['is_real_voucher'] ?? false);
        $isFallback = (bool) ($e['is_virtual_fallback'] ?? false);
        $modal = $e['detail_modal_type'] ?? null;

        // Mirror the frontend click gate (STEP 10B).
        $rawClickable = !empty($e['code'])
            && (($e['detail_available'] ?? null) !== false)
            && ($modal !== 'none');
        $clickable = $rawClickable && !$isFallback;

        $mismatch = (bool) ($e['receipt_allocation_mismatch'] ?? false);

        $risk = 'ok';
        if ($mismatch) {
            $risk = 'receipt_allocation_mismatch';
        } elseif ($isFallback && $rawClickable) {
            $risk = 'clickable_fallback';
        } elseif ($isFallback) {
            $risk = 'virtual_fallback';
        } elseif (($e['event_kind'] ?? '') === 'invoice_payment' && !$isReal) {
            $risk = 'missing_real_voucher';
        } elseif (!$clickable && !($e['is_virtual_opening'] ?? false)) {
            $risk = 'missing_click_target';
        }

        return [
            'code' => $e['code'] ?? '—',
            'type' => $e['display_type'] ?? $e['type'] ?? '—',
            'amount' => (float) ($e['amount'] ?? 0),
            'display_effect' => $e['display_effect'] ?? null,
            'running_balance' => $e['balance'] ?? null,
            'event_kind' => $e['event_kind'] ?? '',
            'reference_code' => $e['reference_code'] ?? null,
            'cash_flow_id' => $e['cash_flow_id'] ?? null,
            'is_real_voucher' => $isReal,
            'is_virtual_fallback' => $isFallback,
            'clickable' => $clickable,
            'receipt_allocation_mismatch' => $mismatch,
            'click_modal' => $modal ?: 'none',
            'click_ref' => $e['detail_reference_code'] ?? null,
            'risk' => $risk,
        ];
    }

    private function riskRow(Customer $partner, string $view, string $risk, array $row): array
    {
        $messages = [
            'clickable_fallback' => 'Dòng tạm tính (fallback) đang mở được modal — KHÔNG được phép.',
            'receipt_allocation_mismatch' => 'Tổng phiếu thu thật không khớp số đã thanh toán trên hóa đơn.',
            'balance_mismatch' => 'Số dư hiển thị lệch với công nợ lưu trữ.',
            'duplicate_receipt' => 'Một phiếu thu xuất hiện nhiều lần trong timeline.',
            'orphan_cashflow' => 'Có cashflow tham chiếu chứng từ không thuộc đối tác này.',
            'virtual_fallback' => 'Thanh toán suy ra từ hóa đơn/phiếu nhập, chưa có chứng từ thật.',
            'missing_real_voucher' => 'Dòng thanh toán không phải chứng từ thật và không đánh dấu fallback.',
            'missing_click_target' => 'Dòng đáng lẽ click được nhưng không mở được chứng từ.',
            'audit_exception' => 'Lỗi khi audit đối tác.',
        ];
        $actions = [
            'clickable_fallback' => 'manual_review',
            'receipt_allocation_mismatch' => 'manual_review',
            'balance_mismatch' => 'manual_review',
            'duplicate_receipt' => 'manual_review',
            'orphan_cashflow' => 'manual_review',
            'virtual_fallback' => 'data_fix_proposal',
            'missing_real_voucher' => 'manual_review',
            'missing_click_target' => 'no_action',
            'audit_exception' => 'manual_review',
        ];

        return [
            'severity' => self::SEVERITY[$risk] ?? 'warning',
            'risk' => $risk,
            'view' => $view,
            'partner_id' => $partner->id,
            'partner_code' => $partner->code,
            'partner_name' => $partner->name,
            'document_code' => $row['code'] ?? null,
            'document_type' => $row['type'] ?? null,
            'amount' => (float) ($row['amount'] ?? 0),
            'display_effect' => $row['display_effect'] ?? null,
            'running_balance' => $row['running_balance'] ?? null,
            'reference_code' => $row['reference_code'] ?? null,
            'message' => $messages[$risk] ?? $risk,
            'suggested_action' => $actions[$risk] ?? 'manual_review',
        ];
    }

    /**
     * Bounded, read-only orphan check: count this partner's cashflows that
     * reference an Invoice/Purchase code which does not belong to them.
     */
    private function orphanCashflowCount(Customer $partner, string $view): int
    {
        if ($view === 'customer') {
            $codes = \App\Models\CashFlow::query()
                ->where('target_type', 'Khách hàng')->where('target_id', $partner->id)
                ->where('type', 'receipt')->where('reference_type', 'Invoice')
                ->whereNotNull('reference_code')->whereNull('deleted_at')
                ->where('status', '!=', 'cancelled')
                ->distinct()->pluck('reference_code')->filter()->all();
            if (empty($codes)) return 0;
            $owned = \App\Models\Invoice::where('customer_id', $partner->id)
                ->whereIn('code', $codes)->pluck('code')->all();
            return count(array_diff($codes, $owned));
        }

        // STRICT: only cashflows that explicitly target THIS supplier
        // (no `OR target_id IS NULL` — that would count every other
        // supplier's untargeted legacy payments as cross-orphans).
        $codes = \App\Models\CashFlow::query()
            ->where('type', 'payment')->where('reference_type', 'Purchase')
            ->whereIn('target_type', ['Nha cung cap', 'Nhà cung cấp'])
            ->where('target_id', $partner->id)
            ->whereNotNull('reference_code')->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')
            ->distinct()->pluck('reference_code')->filter()->all();
        if (empty($codes)) return 0;
        $owned = \App\Models\Purchase::where('supplier_id', $partner->id)
            ->whereIn('code', $codes)->pluck('code')->all();
        // A referenced purchase code that is NOT this supplier's purchase = orphan.
        return count(array_diff($codes, $owned));
    }

    // ════════════════════════════════════════════════════
    // Exports
    // ════════════════════════════════════════════════════
    private function buildCsv(Collection $risks): string
    {
        $header = ['severity', 'risk', 'view', 'partner_id', 'partner_code', 'partner_name', 'document_code', 'document_type', 'amount', 'reference_code', 'message', 'suggested_action'];
        $lines = [implode(',', $header)];
        foreach ($risks as $r) {
            $lines[] = implode(',', array_map(fn ($k) => $this->csvCell($r[$k] ?? ''), $header));
        }
        return implode("\n", $lines) . "\n";
    }

    private function csvCell($v): string
    {
        $v = (string) (is_bool($v) ? ($v ? 'true' : 'false') : $v);
        if (preg_match('/[",\n]/', $v)) {
            $v = '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }

    private function buildBulkMd(array $report, Collection $topRisks): string
    {
        $s = $report['summary'];
        $md = "# STEP 10C — Bulk Kiot-style debt voucher audit\n\n";
        $md .= "- generated_at: {$report['generated_at']}\n- mode: {$report['mode']}\n- dry_run: true\n\n";
        $md .= "## Summary\n";
        foreach ($s as $k => $v) $md .= "- **{$k}**: {$v}\n";

        $crit = $topRisks->where('severity', 'critical')->take(50);
        $warn = $topRisks->where('severity', 'warning')->take(50);

        $md .= "\n## Top critical (" . $crit->count() . ")\n";
        $md .= "| Risk | View | Partner | Doc | Amount | Action |\n|---|---|---|---|--:|---|\n";
        foreach ($crit as $r) {
            $md .= "| {$r['risk']} | {$r['view']} | {$r['partner_code']} | " . ($r['document_code'] ?? '') . ' | ' . number_format((float) ($r['amount'] ?? 0)) . " | {$r['suggested_action']} |\n";
        }

        $md .= "\n## Top warning (" . $warn->count() . ")\n";
        $md .= "| Risk | View | Partner | Doc | Amount | Action |\n|---|---|---|---|--:|---|\n";
        foreach ($warn as $r) {
            $md .= "| {$r['risk']} | {$r['view']} | {$r['partner_code']} | " . ($r['document_code'] ?? '') . ' | ' . number_format((float) ($r['amount'] ?? 0)) . " | {$r['suggested_action']} |\n";
        }

        return $md;
    }

    private function writeFile(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    }
}
