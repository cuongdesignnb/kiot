<?php

namespace App\Console\Commands;

use App\Models\CashFlow;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\SupplierDebtTransaction;
use App\Services\DebtPartnerInspectionService;
use Illuminate\Console\Command;

class DiffDebtPartnerCommand extends Command
{
    protected $signature = 'debt:diff-partner
        {--dry-run : Required. Read-only diff only, do not write DB}
        {--code= : Partner code}
        {--customer-id= : Partner id}
        {--plan-json=storage/app/audits/debt-fix-plan.json : Plan JSON}
        {--inspect-json= : Optional inspect JSON path}
        {--export-json= : Export evidence JSON}
        {--export-md= : Export evidence markdown}
        {--include-raw : Include raw source rows}
        {--include-timeline : Include timeline entries}';

    protected $description = 'Read-only manual diff between debt documents, ledger, cashflow, timeline, and fix plan';

    private const REQUIRED_MATRIX_FIELDS = [
        'source_type',
        'source_code',
        'source_date',
        'source_status',
        'source_amount',
        'expected_effect',
        'matched_ledger_id',
        'matched_ledger_code',
        'matched_ledger_amount',
        'matched_cashflow_id',
        'matched_cashflow_code',
        'matched_cashflow_amount',
        'match_status',
        'issue',
    ];

    public function handle(DebtPartnerInspectionService $service): int
    {
        if (!$this->option('dry-run')) {
            $this->error('This command is read-only. Please pass --dry-run. No data was modified.');
            return self::FAILURE;
        }

        $partner = $service->findPartner(
            $this->option('customer-id') ? (string) $this->option('customer-id') : null,
            $this->option('code') ? (string) $this->option('code') : null,
            null
        );

        if (!$partner) {
            $this->error('Partner not found. Pass --customer-id or --code.');
            return self::FAILURE;
        }

        $inspection = $this->loadInspection($service, $partner);
        $inspection = $this->normalizePayload($inspection);
        $planPayload = $this->loadPlanPayload((string) $this->option('plan-json'));
        $plan = $this->findPlan($planPayload, $inspection['partner'] ?? []);
        $raw = $inspection['raw'] ?? $this->emptyRawSources();
        $matrix = $this->matchingMatrix($raw);
        $issues = $this->detectedIssues($matrix, $inspection, $plan);
        $resolution = $this->proposedResolution($plan, $matrix, $issues, $inspection);

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'dry_run' => true,
            'partner' => $inspection['partner'] ?? [],
            'plan' => $plan,
            'stored_balances' => $inspection['stored_balances'] ?? [],
            'document_rows' => $this->documentRows($raw),
            'ledger_rows' => $this->ledgerRows($raw),
            'cashflow_rows' => array_values($raw['cash_flows'] ?? []),
            'timeline_rows' => $this->timelineRows($inspection),
            'matching_matrix' => $matrix,
            'detected_issues' => $issues,
            'proposed_resolution' => $resolution,
            'data_safety' => [
                'migration' => false,
                'backfill' => false,
                'update_old_data' => false,
                'delete' => false,
                'recalculate' => false,
                'write_db' => false,
            ],
        ];

        if ($export = $this->option('export-json')) {
            $this->writeJsonFile((string) $export, $payload);
            $this->info('Diff evidence JSON exported: ' . $export);
        }

        if ($export = $this->option('export-md')) {
            $this->writeTextFile((string) $export, $this->markdown($payload));
            $this->info('Diff evidence Markdown exported: ' . $export);
        }

        if (!$this->option('export-json') && !$this->option('export-md')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        return self::SUCCESS;
    }

    private function loadInspection(DebtPartnerInspectionService $service, mixed $partner): array
    {
        $path = $this->option('inspect-json');
        if ($path) {
            if (!file_exists((string) $path)) {
                throw new \RuntimeException('Inspect JSON not found: ' . $path);
            }

            $payload = json_decode((string) file_get_contents((string) $path), true);
            if (!is_array($payload)) {
                throw new \RuntimeException('Inspect JSON is invalid: ' . $path);
            }

            return $payload;
        }

        return $service->inspect($partner, true, (bool) $this->option('include-timeline'));
    }

    private function loadPlanPayload(string $path): array
    {
        if (!file_exists($path)) {
            return [
                'path' => $path,
                'plans' => [],
                'missing' => true,
            ];
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (!is_array($payload)) {
            return [
                'path' => $path,
                'plans' => [],
                'invalid' => true,
            ];
        }

        $payload['path'] = $path;

        return $payload;
    }

    private function findPlan(array $planPayload, array $partner): array
    {
        $plans = $planPayload['plans'] ?? [];
        foreach ($plans as $plan) {
            if ((string) ($plan['code'] ?? '') === (string) ($partner['code'] ?? '')
                || (string) ($plan['id'] ?? '') === (string) ($partner['id'] ?? '')) {
                $plan['plan_json'] = $planPayload['path'] ?? null;
                return $plan;
            }
        }

        return [
            'plan_json' => $planPayload['path'] ?? null,
            'fix_group' => 'Z_NEEDS_MANUAL_REVIEW',
            'diagnosis' => 'plan_row_not_found',
            'authority_candidate' => 'manual_review',
            'proposed_write_operations' => [],
            'blocked_reason' => 'Plan row not found for partner.',
        ];
    }

    private function matchingMatrix(array $raw): array
    {
        $ledger = $this->ledgerRows($raw);
        $cashflows = array_values($raw['cash_flows'] ?? []);
        $documents = $this->documentRows($raw);
        $matrix = [];
        $matchedLedgerIds = [];
        $matchedCashflowIds = [];

        foreach ($documents as $document) {
            $ledgerMatches = $this->matchingRows($ledger, $document);
            $cashflowMatches = $this->matchingRows($cashflows, $document);
            $ledgerRow = $ledgerMatches[0] ?? null;
            $cashflowRow = $cashflowMatches[0] ?? null;
            $status = $this->matchStatus($document, $ledgerMatches, $cashflowMatches);

            if ($ledgerRow && isset($ledgerRow['id'])) {
                $matchedLedgerIds[(string) $ledgerRow['id']] = true;
            }
            if ($cashflowRow && isset($cashflowRow['id'])) {
                $matchedCashflowIds[(string) $cashflowRow['id']] = true;
            }

            $matrix[] = $this->matrixRow($document, $ledgerRow, $cashflowRow, $status);
        }

        foreach ($ledger as $row) {
            if (!isset($row['id']) || isset($matchedLedgerIds[(string) $row['id']])) {
                continue;
            }
            $matrix[] = $this->matrixRow(
                $this->sourceFromLedger($row),
                $row,
                null,
                'MISSING_DOCUMENT',
                'Ledger row has no matching document by code/reference.'
            );
        }

        foreach ($cashflows as $row) {
            if (!isset($row['id']) || isset($matchedCashflowIds[(string) $row['id']])) {
                continue;
            }
            $matrix[] = $this->matrixRow(
                $this->sourceFromCashflow($row),
                null,
                $row,
                'UNMATCHED',
                'Cashflow row has no matching document by code/reference.'
            );
        }

        return $matrix;
    }

    private function matchStatus(array $document, array $ledgerMatches, array $cashflowMatches): array
    {
        if (count($ledgerMatches) > 1) {
            return ['status' => 'DUPLICATE_LEDGER', 'issue' => 'More than one ledger row matches this document.'];
        }
        if (count($cashflowMatches) > 1) {
            return ['status' => 'DUPLICATE_CASHFLOW', 'issue' => 'More than one cashflow row matches this document.'];
        }
        if (!$ledgerMatches) {
            return ['status' => 'MISSING_LEDGER', 'issue' => 'No ledger row matched document code/reference.'];
        }

        $ledger = $ledgerMatches[0];
        if ($this->amountMismatch((float) ($document['expected_effect'] ?? 0), (float) ($ledger['amount'] ?? 0))) {
            return ['status' => 'AMOUNT_MISMATCH', 'issue' => 'Document expected effect and ledger amount differ.'];
        }

        if ($this->signMismatch((float) ($document['expected_effect'] ?? 0), (float) ($ledger['amount'] ?? 0))) {
            return ['status' => 'SIGN_MISMATCH', 'issue' => 'Document expected effect and ledger amount signs differ.'];
        }

        return ['status' => 'MATCHED', 'issue' => ''];
    }

    private function matrixRow(array $document, ?array $ledger, ?array $cashflow, array|string $status, ?string $issue = null): array
    {
        $matchStatus = is_array($status) ? (string) ($status['status'] ?? 'UNMATCHED') : $status;
        $issueText = $issue ?? (is_array($status) ? (string) ($status['issue'] ?? '') : '');

        return [
            'source_type' => $document['source_type'] ?? null,
            'source_code' => $document['source_code'] ?? null,
            'source_date' => $document['source_date'] ?? null,
            'source_status' => $document['source_status'] ?? null,
            'source_amount' => $this->number($document['source_amount'] ?? 0),
            'expected_effect' => $this->number($document['expected_effect'] ?? 0),
            'matched_ledger_id' => $ledger['id'] ?? null,
            'matched_ledger_code' => $ledger['code'] ?? $ledger['reference_code'] ?? null,
            'matched_ledger_amount' => $ledger ? $this->number($ledger['amount'] ?? 0) : null,
            'matched_cashflow_id' => $cashflow['id'] ?? null,
            'matched_cashflow_code' => $cashflow['code'] ?? $cashflow['reference_code'] ?? null,
            'matched_cashflow_amount' => $cashflow ? $this->number($cashflow['amount'] ?? 0) : null,
            'match_status' => $matchStatus,
            'issue' => $issueText,
        ];
    }

    private function matchingRows(array $rows, array $document): array
    {
        $code = (string) ($document['source_code'] ?? '');

        return array_values(array_filter($rows, function (array $row) use ($code) {
            return $code !== ''
                && in_array($code, [
                    (string) ($row['code'] ?? ''),
                    (string) ($row['reference_code'] ?? ''),
                    (string) ($row['ref_code'] ?? ''),
                ], true);
        }));
    }

    private function detectedIssues(array $matrix, array $inspection, array $plan): array
    {
        $issues = [];
        foreach ($matrix as $row) {
            if (($row['match_status'] ?? 'UNMATCHED') === 'MATCHED') {
                continue;
            }

            $issues[] = [
                'severity' => $this->issueSeverity((string) $row['match_status']),
                'type' => $row['match_status'],
                'evidence' => [
                    'source_type' => $row['source_type'],
                    'source_code' => $row['source_code'],
                    'issue' => $row['issue'],
                    'fix_group' => $plan['fix_group'] ?? null,
                ],
                'suggested_action' => $this->suggestedAction((string) $row['match_status'], (string) ($plan['fix_group'] ?? '')),
                'can_auto_fix' => false,
            ];
        }

        $diagnosis = (string) ($inspection['diagnosis']['primary_cause'] ?? $plan['diagnosis'] ?? '');
        if ($diagnosis !== '' && !$issues) {
            $issues[] = [
                'severity' => 'info',
                'type' => 'NO_ROW_LEVEL_ISSUE_DETECTED',
                'evidence' => ['diagnosis' => $diagnosis],
                'suggested_action' => 'Keep manual review; row-level matrix did not prove a safe auto fix.',
                'can_auto_fix' => false,
            ];
        }

        return $issues;
    }

    private function proposedResolution(array $plan, array $matrix, array $issues, array $inspection): array
    {
        $group = (string) ($plan['fix_group'] ?? 'Z_NEEDS_MANUAL_REVIEW');
        $preview = [];
        $status = 'manual_review';
        $allowedGroup = null;

        if ($group === 'B_DOCUMENTS_NO_LEDGER' && $this->hasOnlyMissingLedger($matrix)) {
            $status = 'candidate_ready';
            $allowedGroup = $group;
            $preview = $this->documentLedgerPreviews($inspection);
        } elseif ($group === 'A_OPENING_BALANCE_REVIEW' && $this->hasVirtualOpening($inspection)) {
            $status = 'candidate_ready';
            $allowedGroup = $group;
            $preview = $this->openingBalancePreview($inspection);
        } elseif (in_array($group, ['C_LEDGER_DOCUMENT_MISMATCH', 'D_CUSTOMER_ONLY_REVIEW', 'E_DUAL_ROLE_ORIENTATION_REVIEW', 'F_STORED_BALANCE_OPENING_CANDIDATE', 'X_PLAN_INPUT_MISMATCH', 'Z_NEEDS_MANUAL_REVIEW'], true)) {
            $status = 'manual_review';
        }

        if ($group === 'X_PLAN_INPUT_MISMATCH') {
            $status = 'blocked';
        }

        if ($status === 'candidate_ready' && !$preview) {
            $status = 'manual_review';
            $allowedGroup = null;
        }

        return [
            'status' => $status,
            'allowed_group' => $allowedGroup,
            'write_operations_preview' => $preview,
            'requires_confirmation_before_fix' => true,
            'requires_backup' => true,
            'rollback_required' => true,
            'issue_count' => count($issues),
        ];
    }

    private function documentRows(array $raw): array
    {
        $rows = [];
        foreach ($raw['invoices'] ?? [] as $row) {
            $rows[] = $this->documentRow('invoice', $row, $row['outstanding'] ?? $row['debt_amount'] ?? 0, $row['transaction_date'] ?? $row['created_at'] ?? null);
        }
        foreach ($raw['order_returns'] ?? [] as $row) {
            $rows[] = $this->documentRow('order_return', $row, -abs((float) ($row['amount'] ?? $row['total'] ?? 0)), $row['business_date'] ?? $row['created_at'] ?? null);
        }
        foreach ($raw['purchases'] ?? [] as $row) {
            $rows[] = $this->documentRow('purchase', $row, $row['outstanding'] ?? $row['total_amount'] ?? 0, $row['purchase_date'] ?? $row['created_at'] ?? null);
        }
        foreach ($raw['purchase_returns'] ?? [] as $row) {
            $rows[] = $this->documentRow('purchase_return', $row, -abs((float) ($row['amount'] ?? $row['total'] ?? 0)), $row['business_date'] ?? $row['created_at'] ?? null);
        }
        foreach ($raw['debt_offsets'] ?? [] as $row) {
            $rows[] = $this->documentRow('debt_offset', $row, $row['amount'] ?? 0, $row['business_date'] ?? $row['created_at'] ?? null);
        }

        return $rows;
    }

    private function documentRow(string $type, array $row, mixed $effect, mixed $date): array
    {
        return [
            'source_type' => $type,
            'source_id' => $row['id'] ?? null,
            'source_code' => $row['code'] ?? $row['reference_code'] ?? null,
            'source_date' => $date,
            'source_status' => $row['status'] ?? $row['payment_status'] ?? null,
            'source_amount' => $this->number($row['total'] ?? $row['total_amount'] ?? $row['amount'] ?? $row['outstanding'] ?? 0),
            'expected_effect' => $this->number($effect),
            'raw' => $row,
        ];
    }

    private function ledgerRows(array $raw): array
    {
        return array_values(array_merge($raw['customer_debts'] ?? [], $raw['supplier_debt_transactions'] ?? []));
    }

    private function timelineRows(array $inspection): array
    {
        $rows = [];
        foreach ($inspection['timelines'] ?? [] as $key => $timeline) {
            if (!is_array($timeline)) {
                continue;
            }
            foreach ($timeline['entries'] ?? [] as $entry) {
                $entry['timeline'] = $key;
                $rows[] = $entry;
            }
        }

        return $rows;
    }

    private function documentLedgerPreviews(array $inspection): array
    {
        $partner = $inspection['partner'] ?? [];
        $previews = [];
        foreach ($this->documentRows($inspection['raw'] ?? []) as $document) {
            if (abs((float) ($document['expected_effect'] ?? 0)) < 0.01) {
                continue;
            }

            $previews[] = [
                'operation' => ($partner['is_supplier'] ?? false) ? 'insert_supplier_debt_transaction' : 'insert_customer_debt',
                'source_document_type' => $document['source_type'],
                'source_document_id' => $document['source_id'],
                'source_code' => $document['source_code'],
                'amount' => $this->number($document['expected_effect']),
                'direction' => ((float) $document['expected_effect']) >= 0 ? 'increase_debt' : 'decrease_debt',
                'recorded_at' => $document['source_date'],
                'fix_run_id' => 'PREVIEW_ONLY',
            ];
        }

        return $previews;
    }

    private function openingBalancePreview(array $inspection): array
    {
        $partner = $inspection['partner'] ?? [];
        $stored = $inspection['stored_balances'] ?? [];
        $targetTable = ($partner['is_supplier'] ?? false) ? 'supplier_debt_transactions' : 'customer_debts';
        $amount = ($partner['is_supplier'] ?? false)
            ? (float) ($stored['supplier_payable'] ?? $stored['supplier_view'] ?? 0)
            : (float) ($stored['customer_receivable'] ?? $stored['customer_view'] ?? 0);

        if (abs($amount) < 0.01) {
            return [];
        }

        return [[
            'operation' => 'insert_opening_balance',
            'target_table' => $targetTable,
            'amount' => $this->number($amount),
            'direction' => $amount >= 0 ? 'increase_debt' : 'decrease_debt',
            'recorded_at' => $partner['created_at'] ?? now()->toDateString(),
            'note' => 'Opening balance generated from approved debt fix plan',
            'fix_run_id' => 'PREVIEW_ONLY',
        ]];
    }

    private function sourceFromLedger(array $row): array
    {
        return [
            'source_type' => 'ledger',
            'source_code' => $row['code'] ?? $row['reference_code'] ?? null,
            'source_date' => $row['recorded_at'] ?? $row['created_at'] ?? null,
            'source_status' => $row['status'] ?? null,
            'source_amount' => $row['amount'] ?? 0,
            'expected_effect' => $row['amount'] ?? 0,
        ];
    }

    private function sourceFromCashflow(array $row): array
    {
        return [
            'source_type' => 'cashflow',
            'source_code' => $row['reference_code'] ?? $row['code'] ?? null,
            'source_date' => $row['time'] ?? $row['created_at'] ?? null,
            'source_status' => $row['status'] ?? null,
            'source_amount' => $row['amount'] ?? 0,
            'expected_effect' => $row['amount'] ?? 0,
        ];
    }

    private function hasOnlyMissingLedger(array $matrix): bool
    {
        if (!$matrix) {
            return false;
        }

        foreach ($matrix as $row) {
            if (!in_array($row['match_status'], ['MISSING_LEDGER', 'MATCHED'], true)) {
                return false;
            }
        }

        return true;
    }

    private function hasVirtualOpening(array $inspection): bool
    {
        $computed = $inspection['computed'] ?? [];

        return (bool) ($computed['customer_has_virtual_opening'] ?? false)
            || (bool) ($computed['supplier_has_virtual_opening'] ?? false);
    }

    private function amountMismatch(float $documentEffect, float $ledgerAmount): bool
    {
        return abs(abs($documentEffect) - abs($ledgerAmount)) >= 0.01;
    }

    private function signMismatch(float $documentEffect, float $ledgerAmount): bool
    {
        return $documentEffect !== 0.0 && $ledgerAmount !== 0.0
            && ($documentEffect <=> 0.0) !== ($ledgerAmount <=> 0.0);
    }

    private function issueSeverity(string $status): string
    {
        return match ($status) {
            'DUPLICATE_LEDGER', 'DUPLICATE_CASHFLOW', 'STATUS_MISMATCH', 'SIGN_MISMATCH' => 'critical',
            'AMOUNT_MISMATCH', 'MISSING_DOCUMENT', 'MISSING_LEDGER' => 'high',
            default => 'medium',
        };
    }

    private function suggestedAction(string $status, string $group): string
    {
        if ($group === 'C_LEDGER_DOCUMENT_MISMATCH') {
            return 'Manual review each document, ledger, and cashflow row before selecting authority.';
        }

        return match ($status) {
            'MISSING_LEDGER' => 'Prepare ledger backfill preview only after document status/sign/cashflow linkage are confirmed.',
            'MISSING_DOCUMENT' => 'Find source document or mark ledger row as manual review; do not delete automatically.',
            'DUPLICATE_LEDGER', 'DUPLICATE_CASHFLOW' => 'Resolve duplicate authority manually before any fix.',
            'AMOUNT_MISMATCH' => 'Compare document totals, paid/refund amount, and ledger amount.',
            'SIGN_MISMATCH' => 'Confirm customer/supplier orientation and amount sign.',
            default => 'Manual review required before any real fix.',
        };
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

    private function markdown(array $payload): string
    {
        $partner = $payload['partner'];
        $plan = $payload['plan'];
        $resolution = $payload['proposed_resolution'];
        $lines = [
            '# Debt manual diff - ' . ($partner['code'] ?? 'unknown'),
            '',
            '## Scope',
            '',
            '- Partner: `' . ($partner['code'] ?? '') . ' - ' . $this->md($partner['name'] ?? '') . '`.',
            '- Generated at: `' . $payload['generated_at'] . '`.',
            '- Dry-run: `true`.',
            '- Plan JSON: `' . ($plan['plan_json'] ?? '') . '`.',
            '- Fix group: `' . ($plan['fix_group'] ?? '') . '`.',
            '- Diagnosis: `' . ($plan['diagnosis'] ?? '') . '`.',
            '',
            '## Data safety',
            '',
            '- Migration: no.',
            '- Backfill: no.',
            '- Update old data: no.',
            '- Delete: no.',
            '- Recalculate: no.',
            '- Write DB: no.',
            '- Requires confirmation before fix: yes.',
            '',
            '## Summary',
            '',
            '| Metric | Value |',
            '|---|---:|',
            '| Documents | ' . count($payload['document_rows']) . ' |',
            '| Ledger rows | ' . count($payload['ledger_rows']) . ' |',
            '| Cashflow rows | ' . count($payload['cashflow_rows']) . ' |',
            '| Timeline rows | ' . count($payload['timeline_rows']) . ' |',
            '| Issues | ' . count($payload['detected_issues']) . ' |',
            '',
            '## Proposed resolution',
            '',
            '- Status: `' . $resolution['status'] . '`.',
            '- Allowed group: `' . ($resolution['allowed_group'] ?? 'null') . '`.',
            '- Candidate for apply: `' . ($resolution['status'] === 'candidate_ready' ? 'yes' : 'no') . '`.',
            '- Write operations preview: `' . count($resolution['write_operations_preview']) . '`.',
            '- Requires backup: `true`.',
            '- Rollback required: `true`.',
            '',
            '## Matching matrix',
            '',
            '| Source type | Source code | Expected | Ledger | Cashflow | Match status | Issue |',
            '|---|---|---:|---:|---:|---|---|',
        ];

        foreach ($payload['matching_matrix'] as $row) {
            $lines[] = '| ' . $this->md($row['source_type']) . ' | '
                . $this->md($row['source_code']) . ' | '
                . $row['expected_effect'] . ' | '
                . ($row['matched_ledger_amount'] ?? '') . ' | '
                . ($row['matched_cashflow_amount'] ?? '') . ' | '
                . $row['match_status'] . ' | '
                . $this->md($row['issue']) . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Detected issues',
            '',
            '| Severity | Type | Evidence | Suggested action | Auto fix |',
            '|---|---|---|---|---|',
        ]);

        foreach ($payload['detected_issues'] as $issue) {
            $lines[] = '| ' . $issue['severity'] . ' | '
                . $issue['type'] . ' | '
                . $this->md(json_encode($issue['evidence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') . ' | '
                . $this->md($issue['suggested_action']) . ' | '
                . ($issue['can_auto_fix'] ? 'yes' : 'no') . ' |';
        }

        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    private function writeJsonFile(string $path, array $payload): void
    {
        $this->writeTextFile($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private function writeTextFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            throw new \RuntimeException("Cannot prepare export directory: {$dir}");
        }

        file_put_contents($path, $content);
    }

    private function normalizePayload(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizePayload($item);
            }

            return $value;
        }

        if (is_string($value)) {
            return $this->normalizeText($value);
        }

        return $value;
    }

    private function normalizeText(string $text): string
    {
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

    private function number(mixed $value): float
    {
        return (float) ($value ?? 0);
    }

    private function md(mixed $value): string
    {
        return str_replace('|', '\\|', (string) $value);
    }
}
