<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\CustomerDebtDocumentTimelineService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class AuditDocumentDebtTimelineCommand extends Command
{
    protected $signature = 'debt:audit-document-timeline
        {--dry-run : Required. This command is read-only}
        {--all : Audit all customers and suppliers}
        {--all-customers : Audit all customers}
        {--all-suppliers : Audit all suppliers}
        {--customer-code= : Audit one customer by code}
        {--supplier-code= : Audit one supplier by code}
        {--only-mismatch : Only export/show partners with mismatch or risk}
        {--limit= : Limit partners for local smoke test}
        {--chunk=200 : Chunk size for bulk scan}
        {--threshold=1 : Amount difference threshold}
        {--export-json= : Export full JSON report}
        {--export-csv= : Export CSV risk rows}
        {--export-md= : Export Markdown summary}
        {--summary-only : Only print summary to console}
        {--max-rows=100 : Max risk rows printed to console}';

    protected $description = 'Read-only bulk audit of partners using document-first timeline service';

    private const SEVERITY_RANKS = [
        'critical' => 0,
        'warning' => 1,
        'info' => 2,
        'ok' => 3,
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
        $risks = collect($audit['risks']);

        $this->info("Audit ({$view}): {$partner->name} ({$partner->code})");
        if (!$this->option('summary-only')) {
            if ($risks->isNotEmpty()) {
                $this->info("Risks found:");
                $this->table(
                    ['Severity', 'Risk', 'Doc Code', 'Doc Type', 'Amount', 'Message'],
                    $risks->take((int) $this->option('max-rows'))->map(fn ($r) => [
                        $r['severity'],
                        $r['risk'],
                        $r['document_code'] ?? '—',
                        $r['document_type'] ?? '—',
                        number_format($r['amount']),
                        $r['message'],
                    ])->all()
                );
            } else {
                $this->info("No risks detected (OK).");
            }
        }

        foreach ($summary as $k => $v) {
            $this->line(str_pad($k, 32) . ': ' . (is_bool($v) ? ($v ? 'true' : 'false') : $v));
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'mode' => 'single',
            'dry_run' => true,
            'source' => [
                'environment' => app()->environment(),
                'local_db_is_production_import' => app()->environment('local'),
                'command' => 'debt:audit-document-timeline',
            ],
            'summary' => $summary,
            'risks' => $audit['risks'],
            'partner' => $audit['partner'],
        ];

        if ($path = $this->option('export-json')) {
            $this->writeFile($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("JSON written: {$path}");
        }
        if ($path = $this->option('export-csv')) {
            $this->writeFile($path, $this->buildCsv(collect($audit['risks'])));
            $this->info("CSV written: {$path}");
        }
        if ($path = $this->option('export-md')) {
            $this->writeFile($path, $this->buildSingleMd($report));
            $this->info("Markdown written: {$path}");
        }

        return self::SUCCESS;
    }

    private function runBulk(): int
    {
        $all = (bool) $this->option('all');
        $doCustomers = $all || $this->option('all-customers');
        $doSuppliers = $all || $this->option('all-suppliers');
        $onlyMismatch = (bool) $this->option('only-mismatch');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $chunk = max(1, (int) $this->option('chunk'));

        $partnersList = collect();
        $allRisks = collect();

        $counters = [
            'partners_scanned' => 0,
            'customers_scanned' => 0,
            'suppliers_scanned' => 0,
            'ok_partners' => 0,
            'mismatch_partners' => 0,
            'critical_partners' => 0,
            'warning_partners' => 0,
            'info_partners' => 0,
            'total_difference_abs' => 0.0,
            'merge_rows' => 0,
            'opening_rows' => 0,
            'fallback_rows' => 0,
            'missing_running_balance_rows' => 0,
            'dual_role_partners' => 0,
            'audit_exceptions' => 0,
        ];

        $riskGroups = [
            'document_balance_mismatch' => 0,
            'document_balance_mismatch_critical' => 0,
            'merge_affects_balance' => 0,
            'opening_affects_balance' => 0,
            'fallback_payment' => 0,
            'missing_running_balance' => 0,
            'invoice_display_not_total' => 0,
            'return_not_negative' => 0,
            'dual_role_net_requires_review' => 0,
            'supplier_document_timeline_not_implemented' => 0,
        ];

        $scanCount = 0;
        $views = [];
        if ($doCustomers) $views[] = ['view' => 'customer', 'column' => 'is_customer'];
        if ($doSuppliers) $views[] = ['view' => 'supplier', 'column' => 'is_supplier'];

        foreach ($views as $cfg) {
            $query = Customer::query()
                ->where($cfg['column'], true)
                ->orderBy('id')
                ->select(['id', 'code', 'name', 'is_customer', 'is_supplier', 'debt_amount', 'supplier_debt_amount']);

            $query->chunkById($chunk, function (Collection $batch) use (
                $cfg, $limit, $onlyMismatch, &$partnersList, &$allRisks, &$counters, &$riskGroups, &$scanCount
            ) {
                foreach ($batch as $partner) {
                    if ($limit !== null && $scanCount >= $limit) {
                        return false; // stop chunking
                    }
                    $scanCount++;

                    try {
                        $audit = $this->auditPartner($partner, $cfg['view']);
                    } catch (\Throwable $e) {
                        $counters['audit_exceptions']++;
                        $counters['partners_scanned']++;
                        $cfg['view'] === 'supplier' ? $counters['suppliers_scanned']++ : $counters['customers_scanned']++;
                        
                        $allRisks->push([
                            'severity' => 'critical',
                            'risk' => 'audit_exception',
                            'view' => $cfg['view'],
                            'partner_id' => $partner->id,
                            'partner_code' => $partner->code,
                            'partner_name' => $partner->name,
                            'document_code' => null,
                            'document_type' => null,
                            'amount' => 0.0,
                            'running_balance' => null,
                            'stored_balance' => null,
                            'document_final_balance' => null,
                            'difference' => null,
                            'message' => 'Audit threw exception: ' . $e->getMessage(),
                            'suggested_action' => 'manual_review',
                        ]);
                        continue;
                    }

                    $counters['partners_scanned']++;
                    $cfg['view'] === 'supplier' ? $counters['suppliers_scanned']++ : $counters['customers_scanned']++;

                    $s = $audit['summary'];
                    $counters['total_difference_abs'] += abs($s['difference']);
                    $counters['merge_rows'] += $s['merge_rows'];
                    $counters['opening_rows'] += $s['opening_rows'];
                    $counters['fallback_rows'] += $s['fallback_rows'];
                    $counters['missing_running_balance_rows'] += $s['missing_running_balance_rows'];
                    if ($partner->is_customer && $partner->is_supplier) {
                        $counters['dual_role_partners']++;
                    }

                    if ($s['is_mismatch']) {
                        $counters['mismatch_partners']++;
                    }

                    $maxSev = $s['max_severity'];
                    if ($maxSev === 'critical') $counters['critical_partners']++;
                    elseif ($maxSev === 'warning') $counters['warning_partners']++;
                    elseif ($maxSev === 'info') $counters['info_partners']++;
                    else $counters['ok_partners']++;

                    // Count risk groups
                    foreach ($audit['risks'] as $risk) {
                        $riskType = $risk['risk'];
                        if (array_key_exists($riskType, $riskGroups)) {
                            $riskGroups[$riskType]++;
                        }
                        $allRisks->push($risk);
                    }

                    if (!$onlyMismatch || $s['is_mismatch'] || $s['risk_count'] > 0) {
                        $partnersList->push([
                            'partner' => $audit['partner'],
                            'summary' => $s,
                            'risks' => $audit['risks'],
                        ]);
                    }
                }

                $this->line(sprintf(
                    'Scanned partners: %d / mismatch: %d / critical: %d',
                    $counters['partners_scanned'],
                    $counters['mismatch_partners'],
                    $counters['critical_partners']
                ));

                return !($limit !== null && $scanCount >= $limit);
            });
        }

        // Sort top mismatches by absolute difference descending
        $topMismatches = $partnersList
            ->filter(fn ($p) => abs($p['summary']['difference']) > 0.01)
            ->sortByDesc(fn ($p) => abs($p['summary']['difference']))
            ->take(50)
            ->values();

        // Sort top risks by severity rank
        $topRisks = $allRisks
            ->sortBy(fn ($r) => self::SEVERITY_RANKS[$r['severity']] ?? 9)
            ->take(200)
            ->values();

        $report = [
            'generated_at' => now()->toIso8601String(),
            'mode' => $all ? 'all' : ($doCustomers ? 'all-customers' : 'all-suppliers'),
            'dry_run' => true,
            'source' => [
                'environment' => app()->environment(),
                'local_db_is_production_import' => app()->environment('local'),
                'git_head' => trim(shell_exec('git rev-parse HEAD') ?: 'unknown'),
                'command' => 'debt:audit-document-timeline',
            ],
            'summary' => $counters,
            'risk_groups' => $riskGroups,
            'top_mismatches' => $topMismatches->all(),
            'top_risks' => $topRisks->all(),
            'partners' => $partnersList->all(),
        ];

        // Print bulk summary to console
        $this->info("Bulk audit summary (" . $report['mode'] . "):");
        foreach ($counters as $k => $v) {
            $this->line(str_pad($k, 32) . ': ' . $v);
        }

        if (!$this->option('summary-only') && $topRisks->isNotEmpty()) {
            $this->info("\nTop risk rows:");
            $this->table(
                ['Severity', 'Risk', 'View', 'Partner Code', 'Doc Code', 'Amount', 'Action'],
                $topRisks->take((int) $this->option('max-rows'))->map(fn ($r) => [
                    $r['severity'],
                    $r['risk'],
                    $r['view'],
                    $r['partner_code'],
                    $r['document_code'] ?? '—',
                    number_format($r['amount']),
                    $r['suggested_action'],
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
            $this->writeFile($path, $this->buildBulkMd($report, $topRisks, $topMismatches));
            $this->info("Markdown written: {$path}");
        }

        $this->warn('Read-only bulk audit complete. No data was modified.');
        return self::SUCCESS;
    }

    private function auditPartner(Customer $partner, string $view): array
    {
        if ($view === 'supplier') {
            $service = app(\App\Services\SupplierDebtDocumentTimelineService::class);
        } else {
            $service = app(\App\Services\CustomerDebtDocumentTimelineService::class);
        }
        $timeline = $service->build($partner);

        $entries = collect($timeline['entries'] ?? []);
        $threshold = (float) $this->option('threshold');

        $documentFinal = (float) ($timeline['reconcile']['document_balance'] ?? $timeline['summary']['document_final_balance'] ?? 0.0);
        $storedNet = (float) ($timeline['reconcile']['stored_balance'] ?? 0.0);
        $difference = (float) ($timeline['reconcile']['difference'] ?? ($documentFinal - $storedNet));
        $isMismatch = (bool) ($timeline['reconcile']['has_mismatch'] ?? (abs($difference) > $threshold));

        $risks = [];
        $mergeRows = 0;
        $openingRows = 0;
        $fallbackRows = 0;
        $missingRunningBalanceRows = 0;

        // Partner-level checks
        if ($isMismatch) {
            $sev = abs($difference) >= 1000000.0 ? 'critical' : 'warning';
            $riskCode = abs($difference) >= 1000000.0 ? 'document_balance_mismatch_critical' : 'document_balance_mismatch';
            $risks[] = [
                'severity' => $sev,
                'risk' => $riskCode,
                'view' => $view,
                'partner_id' => $partner->id,
                'partner_code' => $partner->code,
                'partner_name' => $partner->name,
                'document_code' => null,
                'document_type' => 'ledger',
                'amount' => abs($difference),
                'running_balance' => $documentFinal,
                'stored_balance' => $storedNet,
                'document_final_balance' => $documentFinal,
                'difference' => $difference,
                'message' => "Số dư hiển thị lệch với công nợ lưu trữ: Lệch " . number_format(abs($difference)) . "đ (Final timeline: " . number_format($documentFinal) . "đ vs DB net: " . number_format($storedNet) . "đ)",
                'suggested_action' => 'manual_review',
            ];
        }

        if ($partner->is_customer && $partner->is_supplier) {
            $risks[] = [
                'severity' => 'warning',
                'risk' => 'dual_role_net_requires_review',
                'view' => $view,
                'partner_id' => $partner->id,
                'partner_code' => $partner->code,
                'partner_name' => $partner->name,
                'document_code' => null,
                'document_type' => 'dual_role',
                'amount' => 0.0,
                'running_balance' => null,
                'stored_balance' => null,
                'document_final_balance' => $documentFinal,
                'difference' => $difference,
                'message' => "Đối tác có vai trò kép (KH & NCC) cần đối soát bù trừ công nợ.",
                'suggested_action' => 'manual_review',
            ];
        }



        if ($entries->isEmpty()) {
            $risks[] = [
                'severity' => 'info',
                'risk' => 'no_entries',
                'view' => $view,
                'partner_id' => $partner->id,
                'partner_code' => $partner->code,
                'partner_name' => $partner->name,
                'document_code' => null,
                'document_type' => 'timeline',
                'amount' => 0.0,
                'running_balance' => null,
                'stored_balance' => null,
                'document_final_balance' => $documentFinal,
                'difference' => $difference,
                'message' => "Không tìm thấy chứng từ/giao dịch công nợ nào.",
                'suggested_action' => 'no_action',
            ];
        }

        // Process excluded ledger entries
        $excludedEntries = $timeline['reconcile']['excluded_ledger_entries'] ?? [];
        foreach ($excludedEntries as $ex) {
            $code = $ex['code'] ?? '';
            $amount = (float) ($ex['amount'] ?? 0.0);
            if (str_starts_with($code, 'MERGE-CUSTOMER-') || str_starts_with($code, 'MERGE-SUPPLIER-')) {
                $mergeRows++;
                if (abs($amount) > 0.01) {
                    $risks[] = [
                        'severity' => 'warning',
                        'risk' => 'merge_affects_balance',
                        'view' => $view,
                        'partner_id' => $partner->id,
                        'partner_code' => $partner->code,
                        'partner_name' => $partner->name,
                        'document_code' => $code,
                        'document_type' => 'merge',
                        'amount' => $amount,
                        'running_balance' => null,
                        'stored_balance' => null,
                        'document_final_balance' => $documentFinal,
                        'difference' => $difference,
                        'message' => "Dòng MERGE kỹ thuật {$code} đã bị loại bỏ khỏi timeline chính nhưng giá trị ảnh hưởng là " . number_format($amount) . "đ.",
                        'suggested_action' => 'manual_review',
                    ];
                }
            } elseif (str_starts_with($code, 'OPENING-BALANCE-') || str_starts_with($code, 'OPENING-BALANCE-SUPPLIER-')) {
                $openingRows++;
                if (abs($amount) > 0.01) {
                    $risks[] = [
                        'severity' => 'warning',
                        'risk' => 'opening_affects_balance',
                        'view' => $view,
                        'partner_id' => $partner->id,
                        'partner_code' => $partner->code,
                        'partner_name' => $partner->name,
                        'document_code' => $code,
                        'document_type' => 'opening',
                        'amount' => $amount,
                        'running_balance' => null,
                        'stored_balance' => null,
                        'document_final_balance' => $documentFinal,
                        'difference' => $difference,
                        'message' => "Dòng OPENING kỹ thuật {$code} đã bị loại bỏ khỏi timeline chính nhưng giá trị ảnh hưởng là " . number_format($amount) . "đ.",
                        'suggested_action' => 'manual_review',
                    ];
                }
            }
        }

        // Row-level checks
        foreach ($entries as $entry) {
            $code = (string) ($entry['code'] ?? '');
            $type = (string) ($entry['display_type'] ?? $entry['type'] ?? '');
            $eventKind = (string) ($entry['event_kind'] ?? '');
            $effect = (float) ($entry['customer_display_effect'] ?? $entry['display_effect'] ?? 0.0);
            $docAmount = (float) ($entry['document_amount'] ?? 0.0);
            $runningVal = $entry['customer_display_running_balance'] ?? $entry['running_balance'] ?? null;
            $isFallback = (bool) ($entry['is_virtual_fallback'] ?? false);
            $source = (string) ($entry['source'] ?? '');

            // 1. Missing running balance check
            if ($runningVal === null) {
                $missingRunningBalanceRows++;
                $risks[] = [
                    'severity' => 'critical',
                    'risk' => 'missing_running_balance',
                    'view' => $view,
                    'partner_id' => $partner->id,
                    'partner_code' => $partner->code,
                    'partner_name' => $partner->name,
                    'document_code' => $code ?: 'id:' . ($entry['id'] ?? ''),
                    'document_type' => $type,
                    'amount' => $effect,
                    'running_balance' => null,
                    'stored_balance' => null,
                    'document_final_balance' => $documentFinal,
                    'difference' => $difference,
                    'message' => "Chứng từ {$code} thiếu running balance.",
                    'suggested_action' => 'manual_review',
                ];
            }

            // 2. MERGE rows check
            if (str_starts_with($code, 'MERGE-CUSTOMER-') || $eventKind === 'merge') {
                $mergeRows++;
                if (abs($effect) > 0.01) {
                    $risks[] = [
                        'severity' => 'warning',
                        'risk' => 'merge_affects_balance',
                        'view' => $view,
                        'partner_id' => $partner->id,
                        'partner_code' => $partner->code,
                        'partner_name' => $partner->name,
                        'document_code' => $code,
                        'document_type' => 'merge',
                        'amount' => $effect,
                        'running_balance' => $runningVal,
                        'stored_balance' => null,
                        'document_final_balance' => $documentFinal,
                        'difference' => $difference,
                        'message' => "Dòng MERGE đang ảnh hưởng document balance. Cần xác minh đây là gộp nợ thật hay chỉ là điều chỉnh hiển thị đã phản ánh vào stored debt.",
                        'suggested_action' => 'manual_review',
                    ];
                } else {
                    $risks[] = [
                        'severity' => 'info',
                        'risk' => 'merge_present',
                        'view' => $view,
                        'partner_id' => $partner->id,
                        'partner_code' => $partner->code,
                        'partner_name' => $partner->name,
                        'document_code' => $code,
                        'document_type' => 'merge',
                        'amount' => 0.0,
                        'running_balance' => $runningVal,
                        'stored_balance' => null,
                        'document_final_balance' => $documentFinal,
                        'difference' => $difference,
                        'message' => "Dòng gộp nợ (merge) có mặt trong lịch sử.",
                        'suggested_action' => 'no_action',
                    ];
                }
            }

            // 3. OPENING-BALANCE check
            if (str_starts_with($code, 'OPENING-BALANCE') || $eventKind === 'opening_balance') {
                $openingRows++;
                if (abs($effect) > 0.01) {
                    $risks[] = [
                        'severity' => 'warning',
                        'risk' => 'opening_affects_balance',
                        'view' => $view,
                        'partner_id' => $partner->id,
                        'partner_code' => $partner->code,
                        'partner_name' => $partner->name,
                        'document_code' => $code,
                        'document_type' => 'opening',
                        'amount' => $effect,
                        'running_balance' => $runningVal,
                        'stored_balance' => null,
                        'document_final_balance' => $documentFinal,
                        'difference' => $difference,
                        'message' => "Dòng OPENING-BALANCE đang ảnh hưởng document balance.",
                        'suggested_action' => 'manual_review',
                    ];
                } else {
                    $risks[] = [
                        'severity' => 'info',
                        'risk' => 'opening_balance_present',
                        'view' => $view,
                        'partner_id' => $partner->id,
                        'partner_code' => $partner->code,
                        'partner_name' => $partner->name,
                        'document_code' => $code,
                        'document_type' => 'opening',
                        'amount' => 0.0,
                        'running_balance' => $runningVal,
                        'stored_balance' => null,
                        'document_final_balance' => $documentFinal,
                        'difference' => $difference,
                        'message' => "Số dư đầu kỳ (opening balance) có mặt trong lịch sử.",
                        'suggested_action' => 'no_action',
                    ];
                }
            }

            // 4. Invoice invariants
            if ($eventKind === 'customer_sale' || $type === 'Bán hàng' || str_starts_with($code, 'HD')) {
                // If display_effect is <= 0
                if ($effect <= 0.01) {
                    $risks[] = [
                        'severity' => 'critical',
                        'risk' => 'invalid_document_amount',
                        'view' => $view,
                        'partner_id' => $partner->id,
                        'partner_code' => $partner->code,
                        'partner_name' => $partner->name,
                        'document_code' => $code,
                        'document_type' => 'invoice',
                        'amount' => $effect,
                        'running_balance' => $runningVal,
                        'stored_balance' => null,
                        'document_final_balance' => $documentFinal,
                        'difference' => $difference,
                        'message' => "Hóa đơn {$code} có giá trị phát sinh không hợp lệ: " . number_format($effect) . "đ",
                        'suggested_action' => 'manual_review',
                    ];
                }

                // Check invoice_display_not_total: lookup actual invoice in DB
                $inv = Invoice::where('code', $code)->first();
                if ($inv && abs($effect - (float) $inv->total) > 0.01) {
                    $risks[] = [
                        'severity' => 'critical',
                        'risk' => 'invoice_display_not_total',
                        'view' => $view,
                        'partner_id' => $partner->id,
                        'partner_code' => $partner->code,
                        'partner_name' => $partner->name,
                        'document_code' => $code,
                        'document_type' => 'invoice',
                        'amount' => $effect,
                        'running_balance' => $runningVal,
                        'stored_balance' => null,
                        'document_final_balance' => $documentFinal,
                        'difference' => $difference,
                        'message' => "Hóa đơn {$code} hiển thị sai giá trị tổng: Timeline phát sinh " . number_format($effect) . "đ vs DB total " . number_format((float) $inv->total) . "đ",
                        'suggested_action' => 'manual_review',
                    ];
                }
            }

            // 5. Receipt invariants
            if (in_array($eventKind, ['invoice_payment', 'customer_payment'], true) || (str_starts_with($code, 'PT') && !str_starts_with($code, 'PTN')) || str_starts_with($code, 'TTHD')) {
                if ($effect >= -0.01) {
                    $risks[] = [
                        'severity' => 'critical',
                        'risk' => 'receipt_not_negative',
                        'view' => $view,
                        'partner_id' => $partner->id,
                        'partner_code' => $partner->code,
                        'partner_name' => $partner->name,
                        'document_code' => $code,
                        'document_type' => 'receipt',
                        'amount' => $effect,
                        'running_balance' => $runningVal,
                        'stored_balance' => null,
                        'document_final_balance' => $documentFinal,
                        'difference' => $difference,
                        'message' => "Phiếu thu {$code} phát sinh giá trị dương hoặc bằng 0: " . number_format($effect) . "đ",
                        'suggested_action' => 'manual_review',
                    ];
                }

                if ($isFallback) {
                    $fallbackRows++;
                    $risks[] = [
                        'severity' => 'warning',
                        'risk' => 'fallback_payment',
                        'view' => $view,
                        'partner_id' => $partner->id,
                        'partner_code' => $partner->code,
                        'partner_name' => $partner->name,
                        'document_code' => $code,
                        'document_type' => 'receipt',
                        'amount' => $effect,
                        'running_balance' => $runningVal,
                        'stored_balance' => null,
                        'document_final_balance' => $documentFinal,
                        'difference' => $difference,
                        'message' => "Thanh toán tạm tính từ hóa đơn, chưa có phiếu thu thật.",
                        'suggested_action' => 'manual_review',
                    ];

                    // Clickable fallback check
                    $modal = $entry['detail_modal_type'] ?? null;
                    $rawClickable = !empty($code)
                        && (($entry['detail_available'] ?? null) !== false)
                        && ($modal !== 'none');
                    if ($rawClickable) {
                        $risks[] = [
                            'severity' => 'critical',
                            'risk' => 'clickable_virtual_fallback',
                            'view' => $view,
                            'partner_id' => $partner->id,
                            'partner_code' => $partner->code,
                            'partner_name' => $partner->name,
                            'document_code' => $code,
                            'document_type' => 'receipt',
                            'amount' => $effect,
                            'running_balance' => $runningVal,
                            'stored_balance' => null,
                            'document_final_balance' => $documentFinal,
                            'difference' => $difference,
                            'message' => "Dòng tạm tính (fallback) {$code} đang mở được modal detail — không cho phép.",
                            'suggested_action' => 'manual_review',
                        ];
                    }
                }
            }

            // 6. Return invariants
            if ($eventKind === 'sales_return' || str_starts_with($code, 'TH')) {
                if ($effect >= -0.01) {
                    $risks[] = [
                        'severity' => 'critical',
                        'risk' => 'return_not_negative',
                        'view' => $view,
                        'partner_id' => $partner->id,
                        'partner_code' => $partner->code,
                        'partner_name' => $partner->name,
                        'document_code' => $code,
                        'document_type' => 'return',
                        'amount' => $effect,
                        'running_balance' => $runningVal,
                        'stored_balance' => null,
                        'document_final_balance' => $documentFinal,
                        'difference' => $difference,
                        'message' => "Phiếu trả hàng {$code} phát sinh giá trị dương hoặc bằng 0: " . number_format($effect) . "đ",
                        'suggested_action' => 'manual_review',
                    ];
                }
            }

            // 7. Legacy adjustments check
            if ($source === 'ledger') {
                $risks[] = [
                    'severity' => 'info',
                    'risk' => 'legacy_adjustment_present',
                    'view' => $view,
                    'partner_id' => $partner->id,
                    'partner_code' => $partner->code,
                    'partner_name' => $partner->name,
                    'document_code' => $code,
                    'document_type' => 'adjustment',
                    'amount' => $effect,
                    'running_balance' => $runningVal,
                    'stored_balance' => null,
                    'document_final_balance' => $documentFinal,
                    'difference' => $difference,
                    'message' => "Chứng từ điều chỉnh công nợ lưu trữ (ledger adjustment) {$code} có mặt trong timeline.",
                    'suggested_action' => 'no_action',
                ];
            }
        }

        // Calculate max severity
        $maxSeverity = 'ok';
        foreach ($risks as $r) {
            if ($r['severity'] === 'critical') {
                $maxSeverity = 'critical';
                break;
            }
            if ($r['severity'] === 'warning') {
                $maxSeverity = 'warning';
            } elseif ($r['severity'] === 'info' && $maxSeverity === 'ok') {
                $maxSeverity = 'info';
            }
        }

        $summary = [
            'partner_code' => $partner->code,
            'partner_name' => $partner->name,
            'view' => $view,
            'total_rows' => $entries->count(),
            'is_mismatch' => $isMismatch,
            'difference' => $difference,
            'merge_rows' => $mergeRows,
            'opening_rows' => $openingRows,
            'fallback_rows' => $fallbackRows,
            'missing_running_balance_rows' => $missingRunningBalanceRows,
            'risk_count' => count($risks),
            'max_severity' => $maxSeverity,
        ];

        return [
            'partner' => [
                'id' => $partner->id,
                'code' => $partner->code,
                'name' => $partner->name,
                'view' => $view,
                'is_customer' => (bool) $partner->is_customer,
                'is_supplier' => (bool) $partner->is_supplier,
                'debt_amount' => (float) ($partner->debt_amount ?? 0),
                'supplier_debt_amount' => (float) ($partner->supplier_debt_amount ?? 0),
            ],
            'summary' => $summary,
            'risks' => $risks,
        ];
    }

    private function buildCsv(Collection $risks): string
    {
        $headers = ['severity', 'risk', 'view', 'partner_id', 'partner_code', 'partner_name', 'document_code', 'document_type', 'amount', 'running_balance', 'stored_balance', 'document_final_balance', 'difference', 'message', 'suggested_action'];
        $lines = [implode(',', $headers)];
        foreach ($risks as $r) {
            $rowValues = array_map(fn ($k) => $this->csvCell($r[$k] ?? ''), $headers);
            $lines[] = implode(',', $rowValues);
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

    private function buildSingleMd(array $report): string
    {
        $p = $report['partner'];
        $s = $report['summary'];
        $md = "# STEP 10E — Document-first timeline audit: {$p['name']} ({$p['code']})\n\n";
        $md .= "- Generated at: {$report['generated_at']}\n";
        $md .= "- Mode: Single\n";
        $md .= "- Dry Run: true\n\n";

        $md .= "## Partner summary\n";
        $md .= "- Stored Customer Debt: " . number_format($p['debt_amount']) . "đ\n";
        $md .= "- Stored Supplier Debt: " . number_format($p['supplier_debt_amount']) . "đ\n";
        $md .= "- Timeline rows: {$s['total_rows']}\n";
        $md .= "- Mismatch: " . ($s['is_mismatch'] ? 'YES' : 'NO') . "\n";
        $md .= "- Difference: " . number_format($s['difference']) . "đ\n";
        $md .= "- Max Severity: {$s['max_severity']}\n\n";

        $md .= "## Risks list ({$s['risk_count']})\n";
        if (empty($report['risks'])) {
            $md .= "No risks detected (OK).\n";
        } else {
            $md .= "| Severity | Risk | Code | Type | Amount | Message |\n|---|---|---|---|--:|---|\n";
            foreach ($report['risks'] as $r) {
                $md .= "| {$r['severity']} | {$r['risk']} | " . ($r['document_code'] ?? '—') . " | {$r['document_type']} | " . number_format($r['amount']) . " | {$r['message']} |\n";
            }
        }

        return $md;
    }

    private function buildBulkMd(array $report, Collection $topRisks, Collection $topMismatches): string
    {
        $s = $report['summary'];
        $rg = $report['risk_groups'];
        $md = "# STEP 10E — Bulk audit Document-first debt timeline\n\n";
        $md .= "- Generated at: {$report['generated_at']}\n";
        $md .= "- Mode: {$report['mode']}\n";
        $md .= "- Dry Run: true\n\n";

        $md .= "## Summary\n";
        foreach ($s as $k => $v) {
            $md .= "- **{$k}**: " . (is_numeric($v) && $k === 'total_difference_abs' ? number_format($v) . 'đ' : $v) . "\n";
        }

        $md .= "\n## Risk groups\n";
        foreach ($rg as $k => $v) {
            $md .= "- **{$k}**: {$v}\n";
        }

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

        $md .= "\n## Top 50 mismatches\n";
        $md .= "| Partner Code | Partner Name | View | Stored Net | Document Final | Difference |\n|---|---|---|--:|--:|--:|\n";
        foreach ($topMismatches as $p) {
            $summary = $p['summary'];
            $partner = $p['partner'];
            $storedNet = (float) ($partner['debt_amount'] - $partner['supplier_debt_amount']);
            $md .= "| {$partner['code']} | {$partner['name']} | {$summary['view']} | " . number_format($storedNet) . "đ | " . number_format($summary['difference'] + $storedNet) . "đ | " . number_format($summary['difference']) . "đ |\n";
        }

        $md .= "\n## Suggested next steps\n";
        $md .= "- No DB changes should be made.\n";
        $md .= "- Review groups of manual_review actions.\n";
        $md .= "- Plan any data fix proposals with explicit approval.\n";

        return $md;
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($path, $contents);
    }
}
