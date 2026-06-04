<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PlanDebtFixCommand extends Command
{
    protected $signature = 'debt:plan-fix
        {--dry-run : Required. Build fix plan only, do not write DB}
        {--csv=storage/app/audits/debt-ledger-audit-mismatch.csv : Mismatch CSV from audit}
        {--inspect-dir=storage/app/audits/debt-inspections/top-20 : JSON inspection directory}
        {--limit=20 : Max rows to plan}
        {--classification= : Optional classification filter}
        {--diagnosis= : Optional diagnosis filter}
        {--export-csv=storage/app/audits/debt-fix-plan.csv : Export fix plan CSV}
        {--export-json=storage/app/audits/debt-fix-plan.json : Export fix plan JSON}
        {--export-md=docs/audit/STEP-DEBT-FIX-PLAN-DRY-RUN.md : Export markdown report}';

    protected $description = 'Build a read-only debt fix plan from audit CSV and partner inspection JSON files';

    private const CSV_HEADERS = [
        'id',
        'code',
        'name',
        'phone',
        'classification',
        'diagnosis',
        'confidence',
        'risk_level',
        'stored_customer_view',
        'stored_supplier_view',
        'ledger_count',
        'document_count',
        'cash_flow_count',
        'customer_has_virtual_opening',
        'supplier_has_virtual_opening',
        'authority_candidate',
        'fix_group',
        'proposed_action',
        'requires_backup',
        'requires_confirmation_before_fix',
        'rollback_plan_required',
        'manual_review_required',
        'blocked_reason',
        'notes',
    ];

    private const GROUP_DETAILS = [
        'A_OPENING_BALANCE_REVIEW' => [
            'risk' => 'MEDIUM',
            'required_checks' => [
                'Verify stored balance and display balance are explained by virtual opening.',
                'Confirm whether a real opening balance document is required.',
            ],
            'next_dry_run_command' => 'debt:plan-fix --dry-run --diagnosis=virtual_opening_display_resolved',
            'forbidden_without_confirmation' => [
                'insert opening balance',
                'insert customer debt',
                'insert supplier debt transaction',
                'update customer balances',
            ],
            'rollback_requirement' => 'Required before any real opening balance is created.',
        ],
        'B_DOCUMENTS_NO_LEDGER' => [
            'risk' => 'HIGH',
            'required_checks' => [
                'Check document status.',
                'Check reference_code mapping.',
                'Check amount sign.',
                'Check paid/refund amount.',
                'Check cashflow linkage.',
            ],
            'next_dry_run_command' => 'debt:plan-fix --dry-run --diagnosis=documents_exist_but_no_ledger',
            'forbidden_without_confirmation' => [
                'backfill ledger',
                'insert customer debt',
                'insert supplier debt transaction',
                'recalculate debt',
            ],
            'rollback_requirement' => 'Required before any ledger backfill.',
        ],
        'C_LEDGER_DOCUMENT_MISMATCH' => [
            'risk' => 'CRITICAL',
            'required_checks' => [
                'Compare every document with ledger and cashflow rows.',
                'Detect missing rows, duplicate rows, wrong status, or wrong sign.',
            ],
            'next_dry_run_command' => 'debt:plan-fix --dry-run --diagnosis=ledger_and_documents_mismatch',
            'forbidden_without_confirmation' => [
                'choose ledger as authority automatically',
                'choose document as authority automatically',
                'update balances',
                'delete duplicate rows',
            ],
            'rollback_requirement' => 'Required before any mismatch repair.',
        ],
        'D_CUSTOMER_ONLY_REVIEW' => [
            'risk' => 'HIGH',
            'required_checks' => [
                'Review customer ledger.',
                'Review invoices, returns, and receipt cashflows.',
                'Confirm source authority before any fix.',
            ],
            'next_dry_run_command' => 'debt:plan-fix --dry-run --classification=CUSTOMER_ONLY_MISMATCH',
            'forbidden_without_confirmation' => [
                'update customers.debt_amount',
                'insert customer debt',
                'recalculate customer debt',
            ],
            'rollback_requirement' => 'Required before customer-only debt repair.',
        ],
        'E_DUAL_ROLE_ORIENTATION_REVIEW' => [
            'risk' => 'CRITICAL',
            'required_checks' => [
                'Review customer orientation.',
                'Review supplier orientation.',
                'Do not auto-offset customer and supplier views.',
            ],
            'next_dry_run_command' => 'debt:plan-fix --dry-run --diagnosis=dual_role_orientation_risk',
            'forbidden_without_confirmation' => [
                'auto offset',
                'merge customer and supplier balances',
                'update either side without explicit approval',
            ],
            'rollback_requirement' => 'Required before dual-role orientation repair.',
        ],
        'F_STORED_BALANCE_OPENING_CANDIDATE' => [
            'risk' => 'HIGH',
            'required_checks' => [
                'Confirm no reliable historical ledger exists.',
                'Confirm stored balance source.',
                'Confirm opening date and sign.',
            ],
            'next_dry_run_command' => 'debt:plan-fix --dry-run --diagnosis=stored_balance_without_source_history',
            'forbidden_without_confirmation' => [
                'insert opening balance',
                'update stored balance',
                'recalculate historical debt',
            ],
            'rollback_requirement' => 'Required before opening balance materialization.',
        ],
        'Z_NEEDS_MANUAL_REVIEW' => [
            'risk' => 'MEDIUM',
            'required_checks' => [
                'Manual review required because authority is not clear.',
            ],
            'next_dry_run_command' => 'debt:inspect-partner --dry-run --include-raw --include-timeline',
            'forbidden_without_confirmation' => [
                'any DB write',
                'any backfill',
                'any recalculation',
            ],
            'rollback_requirement' => 'Required before any real fix.',
        ],
    ];

    public function handle(): int
    {
        if (!$this->option('dry-run')) {
            $this->error('This command is plan-only. Please pass --dry-run. No data was modified.');
            return self::FAILURE;
        }

        $csvPath = (string) $this->option('csv');
        if (!file_exists($csvPath)) {
            $this->error('CSV not found: ' . $csvPath);
            return self::FAILURE;
        }

        $auditRows = $this->readCsv($csvPath);
        $inspectionIndex = $this->loadInspectionIndex((string) $this->option('inspect-dir'));
        $plans = [];

        foreach ($this->topRows($auditRows) as $auditRow) {
            $inspection = $this->findInspection($inspectionIndex, $auditRow);
            $plan = $this->buildPlan($auditRow, $inspection);

            if ($this->option('classification') && $plan['classification'] !== (string) $this->option('classification')) {
                continue;
            }
            if ($this->option('diagnosis') && $plan['diagnosis'] !== (string) $this->option('diagnosis')) {
                continue;
            }

            $plans[] = $plan;
            if (count($plans) >= max(1, (int) $this->option('limit'))) {
                break;
            }
        }

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'dry_run' => true,
            'data_safety' => [
                'migration' => false,
                'backfill' => false,
                'update_old_data' => false,
                'delete' => false,
                'recalculate' => false,
                'write_db' => false,
                'requires_confirmation_before_fix' => true,
            ],
            'summary' => $this->summary($plans),
            'plans' => $plans,
            'fix_group_details' => self::GROUP_DETAILS,
        ];

        $this->exportCsv((string) $this->option('export-csv'), $plans);
        $this->exportJson((string) $this->option('export-json'), $payload);
        $this->exportMarkdown((string) $this->option('export-md'), $payload, [
            'csv' => $csvPath,
            'inspect_dir' => (string) $this->option('inspect-dir'),
            'limit' => (string) $this->option('limit'),
            'classification' => (string) ($this->option('classification') ?: ''),
            'diagnosis' => (string) ($this->option('diagnosis') ?: ''),
        ]);

        $this->info('Debt fix plan generated: ' . count($plans));
        $this->info('CSV: ' . $this->option('export-csv'));
        $this->info('JSON: ' . $this->option('export-json'));
        $this->info('Markdown: ' . $this->option('export-md'));

        return self::SUCCESS;
    }

    private function buildPlan(array $auditRow, ?array $inspection): array
    {
        $diagnosis = $this->diagnosis($auditRow, $inspection);
        $decision = $this->decideAuthority($auditRow, $inspection ?? []);
        $counts = $inspection['source_counts'] ?? [];
        $stored = $inspection['stored_balances'] ?? [];
        $computed = $inspection['computed'] ?? [];
        $partner = $inspection['partner'] ?? [];

        return [
            'id' => (string) ($auditRow['id'] ?? $partner['id'] ?? ''),
            'code' => (string) ($auditRow['code'] ?? $partner['code'] ?? ''),
            'name' => (string) ($auditRow['name'] ?? $partner['name'] ?? ''),
            'phone' => (string) ($auditRow['phone'] ?? $partner['phone'] ?? ''),
            'classification' => (string) ($auditRow['classification'] ?? ''),
            'diagnosis' => $diagnosis,
            'confidence' => $decision['confidence'],
            'risk_level' => (string) ($auditRow['risk_level'] ?? self::GROUP_DETAILS[$decision['fix_group']]['risk'] ?? 'MEDIUM'),
            'stored_customer_view' => $this->number($stored['customer_view'] ?? $auditRow['stored_customer_view'] ?? 0),
            'stored_supplier_view' => $this->number($stored['supplier_view'] ?? $auditRow['stored_supplier_view'] ?? 0),
            'ledger_count' => (int) ($counts['ledger_count'] ?? $this->ledgerCount($auditRow)),
            'document_count' => (int) ($counts['document_count'] ?? $this->documentCount($auditRow)),
            'cash_flow_count' => (int) ($counts['cash_flow_count'] ?? $auditRow['cashflow_receipt_count'] ?? 0),
            'customer_has_virtual_opening' => (bool) ($computed['customer_has_virtual_opening'] ?? $this->bool($auditRow['customer_has_virtual_opening'] ?? false)),
            'supplier_has_virtual_opening' => (bool) ($computed['supplier_has_virtual_opening'] ?? $this->bool($auditRow['supplier_has_virtual_opening'] ?? false)),
            'authority_candidate' => $decision['authority_candidate'],
            'fix_group' => $decision['fix_group'],
            'proposed_action' => $decision['proposed_action'],
            'proposed_write_operations' => [],
            'requires_backup' => true,
            'requires_confirmation_before_fix' => true,
            'rollback_plan_required' => true,
            'blocked_reason' => 'Can xac nhan truoc khi trien khai. Dry-run only, no DB write.',
            'manual_review_required' => true,
            'notes' => implode('; ', $decision['reason']),
        ];
    }

    private function decideAuthority(array $auditRow, array $inspection): array
    {
        $diagnosis = $this->diagnosis($auditRow, $inspection ?: null);
        $classification = (string) ($auditRow['classification'] ?? '');
        $computed = $inspection['computed'] ?? [];
        $displayResolved = (bool) ($computed['customer_display_resolved'] ?? $this->bool($auditRow['customer_display_resolved'] ?? true))
            && (bool) ($computed['supplier_display_resolved'] ?? $this->bool($auditRow['supplier_display_resolved'] ?? true));

        if ($diagnosis === 'virtual_opening_display_resolved' && $displayResolved) {
            return $this->decision(
                'virtual_opening_readonly',
                'A_OPENING_BALANCE_REVIEW',
                'medium',
                'Giu virtual opening read-only. Neu can chung tu that, tao opening balance sau backup/xac nhan.',
                ['diagnosis=virtual_opening_display_resolved', 'display_resolved=true']
            );
        }

        if ($diagnosis === 'documents_exist_but_no_ledger') {
            return $this->decision(
                'document',
                'B_DOCUMENTS_NO_LEDGER',
                'high',
                'Lap dry-run mapping tu chung tu sang ledger; chua backfill.',
                ['document_count>0', 'ledger_count=0', 'check status/reference/sign/paid/refund/cashflow']
            );
        }

        if ($diagnosis === 'dual_role_orientation_risk') {
            return $this->decision(
                'manual_review',
                'E_DUAL_ROLE_ORIENTATION_REVIEW',
                'medium',
                'Review customer/supplier orientation; khong tu offset.',
                ['dual-role orientation risk']
            );
        }

        if ($diagnosis === 'stored_balance_without_source_history') {
            return $this->decision(
                'stored_balance',
                'F_STORED_BALANCE_OPENING_CANDIDATE',
                'high',
                'Co the tao opening balance that theo stored balance sau backup/xac nhan.',
                ['stored balance exists', 'no reliable source history']
            );
        }

        if ($classification === 'CUSTOMER_ONLY_MISMATCH' || $diagnosis === 'needs_manual_review') {
            return $this->decision(
                'manual_review',
                $classification === 'CUSTOMER_ONLY_MISMATCH' ? 'D_CUSTOMER_ONLY_REVIEW' : 'Z_NEEDS_MANUAL_REVIEW',
                'low',
                'Manual review customer ledger/documents/cashflows truoc khi fix.',
                ['classification=' . $classification, 'diagnosis=' . $diagnosis]
            );
        }

        if ($diagnosis === 'ledger_and_documents_mismatch') {
            return $this->decision(
                'manual_review',
                'C_LEDGER_DOCUMENT_MISMATCH',
                'high',
                'So tung chung tu voi ledger/cashflow de xac dinh missing/duplicate/status sai.',
                ['documents and ledger both exist', 'ledger mismatch remains']
            );
        }

        return $this->decision(
            'manual_review',
            'Z_NEEDS_MANUAL_REVIEW',
            'low',
            'Manual review truoc khi fix du lieu that.',
            ['fallback plan rule', 'diagnosis=' . $diagnosis]
        );
    }

    private function decision(string $authority, string $group, string $confidence, string $action, array $reason): array
    {
        return [
            'authority_candidate' => $authority,
            'fix_group' => $group,
            'confidence' => $confidence,
            'proposed_action' => $action,
            'reason' => $reason,
        ];
    }

    private function diagnosis(array $auditRow, ?array $inspection): string
    {
        $cause = $inspection['diagnosis']['primary_cause'] ?? null;
        if ($cause) {
            return (string) $cause;
        }

        return match ((string) ($auditRow['classification'] ?? '')) {
            'STORED_BALANCE_NO_HISTORY' => 'stored_balance_without_source_history',
            'HAS_DOCUMENTS_NO_LEDGER' => 'documents_exist_but_no_ledger',
            'DOCUMENT_LEDGER_MISMATCH' => 'ledger_and_documents_mismatch',
            'VIRTUAL_OPENING_REQUIRED' => 'virtual_opening_display_resolved',
            'DUAL_ROLE_NET_MISMATCH' => 'dual_role_orientation_risk',
            default => 'needs_manual_review',
        };
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $headers = array_map(fn ($header) => ltrim((string) $header, "\xEF\xBB\xBF"), fgetcsv($handle) ?: []);
        $rows = [];

        while (($values = fgetcsv($handle)) !== false) {
            $row = array_combine($headers, $values);
            if ($row) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    private function loadInspectionIndex(string $dir): array
    {
        $index = ['id' => [], 'code' => []];
        if (!is_dir($dir)) {
            return $index;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'json') {
                continue;
            }

            $payload = json_decode((string) file_get_contents($file->getPathname()), true);
            if (!is_array($payload)) {
                continue;
            }

            $partner = $payload['partner'] ?? [];
            if (!empty($partner['id'])) {
                $index['id'][(string) $partner['id']] = $payload;
            }
            if (!empty($partner['code'])) {
                $index['code'][(string) $partner['code']] = $payload;
            }
        }

        return $index;
    }

    private function findInspection(array $index, array $row): ?array
    {
        $id = (string) ($row['id'] ?? '');
        $code = (string) ($row['code'] ?? '');

        return $index['id'][$id] ?? $index['code'][$code] ?? null;
    }

    private function topRows(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => [$this->riskRank($b), $this->riskAmount($b)] <=> [$this->riskRank($a), $this->riskAmount($a)]);

        return $rows;
    }

    private function riskRank(array $row): int
    {
        return match ((string) ($row['risk_level'] ?? 'LOW')) {
            'CRITICAL' => 4,
            'HIGH' => 3,
            'MEDIUM' => 2,
            default => 1,
        };
    }

    private function riskAmount(array $row): float
    {
        return max(array_map(
            fn (string $key) => abs((float) ($row[$key] ?? 0)),
            [
                'customer_virtual_opening_balance',
                'supplier_virtual_opening_balance',
                'stored_customer_view',
                'stored_supplier_view',
                'debt_amount',
                'supplier_debt_amount',
            ]
        ));
    }

    private function summary(array $plans): array
    {
        return [
            'total_planned' => count($plans),
            'by_fix_group' => $this->countsBy($plans, 'fix_group'),
            'by_authority_candidate' => $this->countsBy($plans, 'authority_candidate'),
            'by_risk_level' => $this->countsBy($plans, 'risk_level'),
        ];
    }

    private function countsBy(array $rows, string $key): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = (string) ($row[$key] ?? 'unknown');
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    private function exportCsv(string $path, array $plans): void
    {
        $this->prepareDirectory(dirname($path));
        $handle = fopen($path, 'w');
        fputcsv($handle, self::CSV_HEADERS);

        foreach ($plans as $plan) {
            fputcsv($handle, array_map(fn ($header) => $this->csvValue($plan[$header] ?? ''), self::CSV_HEADERS));
        }

        fclose($handle);
    }

    private function exportJson(string $path, array $payload): void
    {
        $this->prepareDirectory(dirname($path));
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private function exportMarkdown(string $path, array $payload, array $input): void
    {
        $this->prepareDirectory(dirname($path));
        file_put_contents($path, $this->markdown($payload, $input));
    }

    private function markdown(array $payload, array $input): string
    {
        $summary = $payload['summary'];
        $plans = $payload['plans'];
        $lines = [
            '# STEP - Debt fix plan dry-run',
            '',
            '## Pham vi audit',
            '',
            '- Module: cong no khach hang, nha cung cap, dual-role partner.',
            '- Man hinh: customer debt history, supplier debt transactions, supplier partner view.',
            '- Nghiep vu: stored balance, ledger, document references, cashflow linkage, virtual opening read-only.',
            '- Rui ro chinh: authority chua ro, ledger/chung tu lech, thieu ledger, dual-role orientation.',
            '',
            '## Source da kiem tra',
            '',
            '- Audit command: `app/Console/Commands/AuditDebtLedgerCommand.php`.',
            '- Inspect command: `app/Console/Commands/InspectDebtPartnerCommand.php`, `app/Console/Commands/InspectTopDebtRisksCommand.php`.',
            '- Plan command: `app/Console/Commands/PlanDebtFixCommand.php`.',
            '- Service: `app/Services/DebtPartnerInspectionService.php`, `app/Services/PartnerDebtLedgerService.php`.',
            '- Models: `Customer`, `CustomerDebt`, `SupplierDebtTransaction`, `CashFlow`, `Invoice`, `OrderReturn`, `Purchase`, `PurchaseReturn`, `DebtOffset`.',
            '- Tests: `tests/Feature/Console/PlanDebtFixCommandTest.php`.',
            '- Commit: pending in this report.',
            '',
            '## Data safety',
            '',
            '- Migration: khong chay.',
            '- Backfill: khong chay.',
            '- Update du lieu cu: khong chay.',
            '- Delete: khong chay.',
            '- Recalculate: khong chay.',
            '- Ghi DB: khong. Command bat buoc `--dry-run`.',
            '- Export CSV/JSON: co, chi ghi local vao `storage/app/audits`.',
            '- Co chay migrate:fresh khong: khong.',
            '- Can xac nhan truoc khi fix that: co.',
            '',
            '## Input',
            '',
            '- Mismatch CSV: `' . $input['csv'] . '`.',
            '- Inspect dir: `' . $input['inspect_dir'] . '`.',
            '- Limit: `' . $input['limit'] . '`.',
            '- Classification filter: `' . ($input['classification'] ?: 'none') . '`.',
            '- Diagnosis filter: `' . ($input['diagnosis'] ?: 'none') . '`.',
            '',
            '## Summary',
            '',
            '| Fix group | Count | Risk | Next step |',
            '|---|---:|---|---|',
        ];

        foreach ($summary['by_fix_group'] as $group => $count) {
            $detail = self::GROUP_DETAILS[$group] ?? self::GROUP_DETAILS['Z_NEEDS_MANUAL_REVIEW'];
            $lines[] = '| ' . $group . ' | ' . $count . ' | ' . $detail['risk'] . ' | `' . $detail['next_dry_run_command'] . '` |';
        }

        $lines = array_merge($lines, [
            '',
            '## Authority candidates',
            '',
            '| Authority | Count | Meaning |',
            '|---|---:|---|',
        ]);

        foreach ($summary['by_authority_candidate'] as $authority => $count) {
            $lines[] = '| ' . $authority . ' | ' . $count . ' | ' . $this->authorityMeaning($authority) . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Top planned cases',
            '',
            '| Code | Name | Classification | Diagnosis | Authority | Fix group | Proposed action |',
            '|---|---|---|---|---|---|---|',
        ]);

        foreach (array_slice($plans, 0, 20) as $plan) {
            $lines[] = '| ' . $this->md($plan['code']) . ' | ' . $this->md($plan['name']) . ' | ' . $plan['classification'] . ' | ' . $plan['diagnosis'] . ' | ' . $plan['authority_candidate'] . ' | ' . $plan['fix_group'] . ' | ' . $this->md($plan['proposed_action']) . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Fix group details',
            '',
        ]);

        foreach (self::GROUP_DETAILS as $group => $detail) {
            $caseCount = $summary['by_fix_group'][$group] ?? 0;
            $lines[] = '### ' . $group;
            $lines[] = '';
            $lines[] = '- Dieu kien: xem authority decision rules trong `PlanDebtFixCommand`.';
            $lines[] = '- Case: ' . $caseCount . '.';
            $lines[] = '- Action: `' . $detail['next_dry_run_command'] . '`.';
            $lines[] = '- Rui ro: ' . $detail['risk'] . '.';
            $lines[] = '- Required checks: ' . implode('; ', $detail['required_checks']) . '.';
            $lines[] = '- Can backup: co.';
            $lines[] = '- Can xac nhan: co.';
            $lines[] = '- Rollback plan: ' . $detail['rollback_requirement'];
            $lines[] = '';
        }

        $lines[] = '## Forbidden without confirmation';
        $lines[] = '';
        foreach ($this->forbiddenOperations() as $operation) {
            $lines[] = '- ' . $operation . '.';
        }

        $lines = array_merge($lines, [
            '',
            '## Ket luan',
            '',
            '- Dat/chua dat: dat cho buoc plan dry-run neu tests/build PASS.',
            '- Co the deploy plan command chua: co sau khi tests PASS.',
            '- Co the backfill/fix du lieu that chua: chua.',
            '- Can xac nhan gi tiep theo: Senior Auditor review plan CSV/JSON/Markdown va phe duyet rieng truoc moi data-fix.',
            '',
        ]);

        return implode(PHP_EOL, $lines);
    }

    private function forbiddenOperations(): array
    {
        $items = [];
        foreach (self::GROUP_DETAILS as $detail) {
            foreach ($detail['forbidden_without_confirmation'] as $operation) {
                $items[$operation] = $operation;
            }
        }
        ksort($items);

        return array_values($items);
    }

    private function authorityMeaning(string $authority): string
    {
        return match ($authority) {
            'stored_balance' => 'Stored balance may be source for opening candidate after confirmation.',
            'ledger' => 'Ledger is candidate authority after manual proof.',
            'document' => 'Documents are candidate authority; ledger mapping must be dry-run first.',
            'cashflow' => 'Cashflow is candidate authority after linkage review.',
            'virtual_opening_readonly' => 'Virtual opening explains display only; no real write.',
            default => 'Manual review required before choosing authority.',
        };
    }

    private function prepareDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            throw new \RuntimeException("Cannot prepare export directory: {$dir}");
        }
    }

    private function ledgerCount(array $row): int
    {
        return (int) ($row['customer_debt_count'] ?? 0) + (int) ($row['supplier_debt_transaction_count'] ?? 0);
    }

    private function documentCount(array $row): int
    {
        return (int) ($row['invoice_count'] ?? 0)
            + (int) ($row['cashflow_receipt_count'] ?? 0)
            + (int) ($row['order_return_count'] ?? 0)
            + (int) ($row['purchase_count'] ?? 0)
            + (int) ($row['purchase_return_count'] ?? 0)
            + (int) ($row['debt_offset_count'] ?? 0);
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes'], true);
    }

    private function number(mixed $value): float
    {
        return (float) $value;
    }

    private function csvValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    private function md(mixed $value): string
    {
        return str_replace('|', '\\|', (string) $value);
    }
}
