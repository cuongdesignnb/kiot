<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Debt\PartnerDebtParityAuditService;
use App\Services\Debt\PartnerDebtPopulationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;

class AuditDebtParityCommand extends Command
{
    protected $signature = 'debt:audit-parity
        {--dry-run : Compatibility flag; the command is always read-only}
        {--all-partners : Include every partner row, including missing-role and zero-projection rows}
        {--include-special-status : Include inactive, merged, branchless and other historical statuses}
        {--population-only : Reconcile partner sources without reducing document timelines}
        {--expected-population= : Expected full customer population; fails when the database snapshot differs}
        {--fail-on-mismatch : Exit non-zero when any raw parity difference is non-zero}
        {--role=all : all, customer, supplier or dual}
        {--partner-id= : Audit one local partner ID}
        {--classification= : Filter primary classification or classification flags}
        {--risk= : Filter CRITICAL, HIGH, MEDIUM, LOW or OK}
        {--only-mismatch : Exclude rows classified OK}
        {--limit= : Limit number of audited partners}
        {--export= : CSV path under storage/app/audits}
        {--json= : JSON path under storage/app/audits}
        {--output= : Directory under storage/app/audits for audit.json and audit.csv}';

    protected $description = 'Read-only parity audit across stored debt, document timelines and ledgers';

    public function handle(
        PartnerDebtParityAuditService $audit,
        PartnerDebtPopulationService $population,
    ): int {
        $role = (string) $this->option('role');
        if (! in_array($role, ['all', 'customer', 'supplier', 'dual'], true)) {
            $this->error('Invalid --role. Use all, customer, supplier or dual.');

            return self::FAILURE;
        }

        $classification = (string) ($this->option('classification') ?? '');
        if ($classification !== '' && ! in_array($classification, PartnerDebtParityAuditService::CLASSIFICATIONS, true)) {
            $this->error('Invalid --classification. Use a supported audit classification.');

            return self::FAILURE;
        }
        $risk = (string) ($this->option('risk') ?? '');
        if ($risk !== '' && ! in_array($risk, PartnerDebtParityAuditService::RISK_LEVELS, true)) {
            $this->error('Invalid --risk. Use CRITICAL, HIGH, MEDIUM, LOW or OK.');

            return self::FAILURE;
        }
        $limitOption = $this->option('limit');
        if ($limitOption !== null && (preg_match('/^[1-9][0-9]*$/', (string) $limitOption) !== 1)) {
            $this->error('Invalid --limit. Use a positive integer.');

            return self::FAILURE;
        }
        $limit = $limitOption === null ? null : (int) $limitOption;
        $expectedPopulationOption = $this->option('expected-population');
        if ($expectedPopulationOption !== null
            && preg_match('/^[1-9][0-9]*$/', (string) $expectedPopulationOption) !== 1) {
            $this->error('Invalid --expected-population. Use a positive integer.');

            return self::FAILURE;
        }
        $expectedPopulation = $expectedPopulationOption === null ? null : (int) $expectedPopulationOption;

        if ($this->option('population-only')) {
            if (! $this->option('all-partners') || ! $this->option('output')) {
                $this->error('--population-only requires --all-partners and --output.');

                return self::FAILURE;
            }
            $scannedPartnerIds = $this->partnerQuery('all')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $populationResult = $population->reconcile($scannedPartnerIds, $expectedPopulation);
            $output = rtrim(str_replace('\\', '/', (string) $this->option('output')), '/');
            $this->writePopulationArtifacts($output, $populationResult);
            $this->printPopulationSummary($populationResult['summary']);

            return (bool) ($populationResult['summary']['population_reconciliation_pass'] ?? false)
                ? self::SUCCESS
                : self::FAILURE;
        }

        $rows = [];
        $scannedPartnerIds = [];
        $scanned = 0;
        $matched = 0;
        $hasAuditErrors = false;
        $query = $this->partnerQuery($role);
        $eligible = (clone $query)->count();
        foreach ($query->lazyById(25) as $partner) {
            if ($limit !== null && $scanned >= $limit) {
                break;
            }
            $scanned++;
            $scannedPartnerIds[] = (int) $partner->id;
            $row = $audit->audit($partner);
            $hasAuditErrors = $hasAuditErrors || $row['primary_classification'] === 'AUDIT_ERROR';
            if (! $this->matchesFilters($row, $classification, $risk)) {
                continue;
            }
            $matched++;
            if ($this->option('only-mismatch') && $row['primary_classification'] === 'OK') {
                continue;
            }
            $rows[] = $row;
        }

        if ($path = $this->option('export')) {
            $this->writeCsv($this->auditPath((string) $path), $rows);
        }
        if ($path = $this->option('json')) {
            $this->writeJson($this->auditPath((string) $path), $rows);
        }
        $populationResult = null;
        if ($directory = $this->option('output')) {
            $output = rtrim(str_replace('\\', '/', (string) $directory), '/');
            $this->writeCsv($this->auditPath($output.'/audit.csv'), $rows);
            $this->writeJson($this->auditPath($output.'/audit.json'), $rows);
            if ($this->option('all-partners')) {
                $populationResult = $population->reconcile($scannedPartnerIds, $expectedPopulation);
                $this->writePopulationArtifacts($output, $populationResult);
                $this->writeTimelineArtifacts($output, $rows);
            }
        }

        $this->printSummary($rows, $eligible, $scanned, $matched);
        if ($populationResult !== null) {
            $this->printPopulationSummary($populationResult['summary']);
        }

        $hasMismatch = collect($rows)->contains(fn (array $row): bool => $this->hasRawMismatch($row));

        $populationFailed = $populationResult !== null
            && ! (bool) ($populationResult['summary']['population_reconciliation_pass'] ?? false);

        return ($hasAuditErrors || $populationFailed || ($this->option('fail-on-mismatch') && $hasMismatch))
            ? self::FAILURE
            : self::SUCCESS;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function writeTimelineArtifacts(string $output, array $rows): void
    {
        $directory = $this->auditPath($output);
        File::ensureDirectoryExists($directory);
        $collection = collect($rows);
        $summary = [
            'generated_at' => now()->toIso8601String(),
            'read_only' => true,
            'total_partners' => $collection->count(),
            'persisted_customer_count' => $collection->whereIn('persisted_role', ['customer_only', 'dual_role'])->count(),
            'persisted_supplier_count' => $collection->whereIn('persisted_role', ['supplier_only', 'dual_role'])->count(),
            'persisted_dual_role_count' => $collection->where('persisted_role', 'dual_role')->count(),
            'role_flag_evidence_mismatches' => $collection->where('role_integrity_status', 'ROLE_FLAG_EVIDENCE_MISMATCH')->count(),
            'owner_confirmed_role_mismatches' => $collection->where('role_integrity_status', 'OWNER_CONFIRMED_ROLE_MISMATCH')->count(),
            'customer_list_scope_mismatches' => $collection->where('customer_list_scope_mismatch', true)->count(),
            'supplier_list_scope_mismatches' => $collection->where('supplier_list_scope_mismatch', true)->count(),
            'domain_parity_pass' => $collection->where('domain_parity_pass', true)->count(),
            'customer_view_parity_pass' => $collection->where('customer_view_parity_pass', true)->count(),
            'supplier_view_parity_pass' => $collection->where('supplier_view_parity_pass', true)->count(),
            'cross_view_parity_pass' => $collection->where('cross_view_applicable', true)->where('cross_view_parity_pass', true)->count(),
            'customer_view_warnings' => $collection->where('customer_view_warning', true)->count(),
            'supplier_view_warnings' => $collection->where('supplier_view_warning', true)->count(),
            'cross_view_event_missing_count' => (int) $collection->sum('cross_view_event_missing_count'),
            'cross_view_event_extra_count' => (int) $collection->sum('cross_view_event_extra_count'),
            'cross_view_sign_mismatch_count' => (int) $collection->sum('cross_view_sign_mismatch_count'),
            'cross_view_order_mismatch_count' => (int) $collection->sum('cross_view_order_mismatch_count'),
            'cross_view_running_mismatch_count' => (int) $collection->sum('cross_view_running_mismatch_count'),
            'virtual_opening_event_count' => (int) $collection->sum('virtual_opening_event_count'),
            'display_alignment_event_count' => (int) $collection->sum('display_alignment_event_count'),
            'stored_balance_event_count' => (int) $collection->sum('stored_balance_event_count'),
            'mirror_counted_as_financial_event_count' => (int) $collection->sum('mirror_counted_as_financial_event_count'),
            'real_and_fallback_double_count' => (int) $collection->sum('real_and_fallback_double_count'),
            'cancel_reversal_asymmetry_count' => (int) $collection->sum('cancel_reversal_asymmetry_count'),
        ];
        $this->writeArtifactJson($directory.DIRECTORY_SEPARATOR.'summary.json', $summary);

        $roleRows = $collection
            ->filter(fn (array $row): bool => ($row['role_integrity_status'] ?? 'OK') !== 'OK')
            ->values()
            ->all();
        $this->writeArtifactJson($directory.DIRECTORY_SEPARATOR.'role-integrity.json', [
            'generated_at' => now()->toIso8601String(),
            'rows' => $roleRows,
        ]);
        $this->writeArtifactJson(
            $directory.DIRECTORY_SEPARATOR.'role-repair-plan.json',
            $this->roleRepairPlan($collection),
        );

        $listColumns = [
            'partner_id', 'partner_code', 'persisted_role',
            'customer_list_expected', 'customer_list_actual', 'customer_list_scope_mismatch',
            'supplier_list_expected', 'supplier_list_actual', 'supplier_list_scope_mismatch',
        ];
        $this->writeArtifactCsv($directory.DIRECTORY_SEPARATOR.'customer-list-scope.csv', $listColumns, $rows);
        $this->writeArtifactCsv($directory.DIRECTORY_SEPARATOR.'supplier-list-scope.csv', $listColumns, $rows);

        $timelineColumns = [
            'partner_id', 'partner_code', 'persisted_role', 'role_integrity_status',
            'canonical_customer_receivable', 'canonical_supplier_payable',
            'customer_view_target', 'customer_view_final', 'customer_view_difference', 'customer_view_warning',
            'supplier_view_target', 'supplier_view_final', 'supplier_view_difference', 'supplier_view_warning',
            'timeline_primary_classification', 'all_applicable_layers_pass',
        ];
        $this->writeArtifactCsv($directory.DIRECTORY_SEPARATOR.'timeline-parity.csv', $timelineColumns, $rows);

        $crossColumns = [
            'partner_id', 'partner_code', 'cross_view_applicable',
            'customer_view_entry_count', 'supplier_view_entry_count',
            'customer_source_identity_hash', 'supplier_source_identity_hash',
            'cross_view_event_missing_count', 'cross_view_event_extra_count',
            'cross_view_sign_mismatch_count', 'cross_view_order_mismatch_count',
            'cross_view_running_mismatch_count', 'cross_view_first_divergence', 'cross_view_parity_pass',
        ];
        $this->writeArtifactCsv(
            $directory.DIRECTORY_SEPARATOR.'cross-view-parity.csv',
            $crossColumns,
            $collection->where('cross_view_applicable', true)->values()->all(),
        );

        $this->writePartnerDossier($directory, '80', 'partner-80-dossier.json', true);
        $this->writePartnerDossier($directory, 'NCC177950763826', 'partner-ncc177950763826-dossier.json');
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function roleRepairPlan(Collection $rows): array
    {
        $actions = $rows
            ->where('role_integrity_status', 'OWNER_CONFIRMED_ROLE_MISMATCH')
            ->filter(fn (array $row): bool => in_array(
                (string) ($row['partner_code'] ?? ''),
                \App\Services\Debt\PartnerDebtRoleResolver::OWNER_CONFIRMED_DUAL_ROLE_CODES,
                true,
            ))
            ->map(fn (array $row): array => [
                'partner_id' => (int) $row['partner_id'],
                'partner_code' => (string) $row['partner_code'],
                'action' => 'set_persisted_role_dual',
                'before' => [
                    'persisted_role' => $row['persisted_role'],
                    'is_customer' => in_array($row['persisted_role'], ['customer_only', 'dual_role'], true),
                    'is_supplier' => in_array($row['persisted_role'], ['supplier_only', 'dual_role'], true),
                    'debt_amount' => $row['raw_customer_debt'],
                    'supplier_debt_amount' => $row['raw_supplier_debt'],
                ],
                'after' => ['is_customer' => true, 'is_supplier' => true],
                'owner_confirmation_reference' => 'KIOTVIET-PARTNER-DEBT-TIMELINE-CONTRACT-01#17',
                'idempotency_key' => 'owner-role-confirmation:'.(string) $row['partner_code'].':dual-role:v1',
            ])
            ->values()
            ->all();

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'dry_run' => true,
            'clone_only' => true,
            'financial_fields_must_not_change' => true,
            'actions' => $actions,
        ];
        $payload['plan_hash'] = hash('sha256', json_encode($payload['actions'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $payload;
    }

    private function writePartnerDossier(string $directory, string $identifier, string $filename, bool $byId = false): void
    {
        $query = Customer::query();
        $partner = $byId ? $query->whereKey((int) $identifier)->first() : $query->where('code', $identifier)->first();
        if (! $partner) {
            $this->writeArtifactJson($directory.DIRECTORY_SEPARATOR.$filename, [
                'found' => false,
                'identifier' => $identifier,
            ]);

            return;
        }

        $events = app(\App\Services\Debt\CanonicalPartnerDebtEventService::class)->build($partner);
        $orientation = app(\App\Services\Debt\PartnerDebtTimelineOrientationService::class);
        $customer = $orientation->customer($partner, ['audit' => true]);
        $supplier = $orientation->supplier($partner, ['audit' => true]);
        $this->writeArtifactJson($directory.DIRECTORY_SEPARATOR.$filename, [
            'found' => true,
            'partner' => [
                'id' => (int) $partner->id,
                'code' => (string) $partner->code,
                'is_customer' => (bool) $partner->is_customer,
                'is_supplier' => (bool) $partner->is_supplier,
                'customer_receivable' => (float) $partner->debt_amount,
                'supplier_payable' => (float) $partner->supplier_debt_amount,
            ],
            'canonical_events' => $events->all(),
            'customer_view' => $customer,
            'supplier_view' => $supplier,
            'first_divergence' => app(PartnerDebtParityAuditService::class)->audit($partner)['cross_view_first_divergence'] ?? null,
        ]);
    }

    /** @param array<int, string> $columns @param array<int, array<string, mixed>> $rows */
    private function writeArtifactCsv(string $path, array $columns, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open timeline CSV: {$path}");
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $column): mixed => $this->scalar($row[$column] ?? null), $columns));
        }
        fclose($handle);
    }

    /** @param array<string, mixed> $payload */
    private function writeArtifactJson(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function writePopulationArtifacts(string $output, array $population): void
    {
        $directory = $this->auditPath($output);
        File::ensureDirectoryExists($directory);
        file_put_contents(
            $directory.DIRECTORY_SEPARATOR.'population-reconciliation.json',
            json_encode([
                'generated_at' => now()->toIso8601String(),
                'dry_run' => true,
                ...$population,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
        $this->writePopulationCsv(
            $directory.DIRECTORY_SEPARATOR.'population-excluded.csv',
            PartnerDebtPopulationService::EXCLUDED_CSV_COLUMNS,
            $population['excluded'] ?? [],
        );
        $this->writePopulationCsv(
            $directory.DIRECTORY_SEPARATOR.'population-unscannable.csv',
            ['partner_id', 'partner_code', 'reason', 'sources', 'stored_customer_debt', 'stored_supplier_debt'],
            $population['unscannable'] ?? [],
        );
        $this->writePopulationCsv(
            $directory.DIRECTORY_SEPARATOR.'population-orphan-financial-references.csv',
            PartnerDebtPopulationService::ORPHAN_CSV_COLUMNS,
            $population['orphan_financial_references'] ?? [],
        );
    }

    private function writePopulationCsv(string $path, array $columns, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open population CSV: {$path}");
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (string $column): mixed => $this->scalar($row[$column] ?? null),
                $columns,
            ));
        }
        fclose($handle);
    }

    private function printPopulationSummary(array $summary): void
    {
        $this->line('Population reconciliation:');
        foreach ($summary as $key => $value) {
            $formatted = is_bool($value) ? ($value ? 'yes' : 'no') : ($value ?? 'not-set');
            $this->line("- {$key}: {$formatted}");
        }
    }

    private function partnerQuery(string $role): Builder
    {
        $query = Customer::query();
        if ($this->option('include-special-status')
            && in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(Customer::class), true)) {
            $query->withTrashed();
        }
        $query->orderBy('id');
        if ($id = $this->option('partner-id')) {
            return $query->whereKey((int) $id);
        }

        if ($this->option('all-partners')) {
            return $query;
        }

        if ($role === 'customer') {
            return $query->where('is_customer', true);
        }
        if ($role === 'supplier') {
            return $query->where('is_supplier', true);
        }
        if ($role === 'dual') {
            return $query->where('is_customer', true)->where('is_supplier', true);
        }

        return $query->where(function (Builder $builder): void {
            $builder->where('is_customer', true)
                ->orWhere('is_supplier', true)
                ->orWhere('debt_amount', '!=', 0)
                ->orWhere('supplier_debt_amount', '!=', 0);
        });
    }

    private function writeCsv(string $path, array $rows): void
    {
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open audit CSV: {$path}");
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, PartnerDebtParityAuditService::CSV_COLUMNS);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (string $column): mixed => $this->scalar($row[$column] ?? null),
                PartnerDebtParityAuditService::CSV_COLUMNS,
            ));
        }
        fclose($handle);
    }

    private function writeJson(string $path, array $rows): void
    {
        File::ensureDirectoryExists(dirname($path));
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'dry_run' => true,
            'tolerance' => PartnerDebtParityAuditService::TOLERANCE,
            'rows' => $rows,
        ];
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function auditPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (str_contains($normalized, '/../') || str_ends_with($normalized, '/..')) {
            throw new RuntimeException('Audit output path cannot contain parent traversal.');
        }
        $absolute = preg_match('/^[A-Za-z]:\//', $normalized) === 1 || str_starts_with($normalized, '/')
            ? $normalized
            : str_replace('\\', '/', base_path($normalized));
        $root = rtrim(str_replace('\\', '/', storage_path('app/audits')), '/');
        if ($absolute !== $root && ! str_starts_with($absolute, $root.'/')) {
            throw new RuntimeException('Audit output must be under storage/app/audits.');
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $absolute);
    }

    private function scalar(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_array($value)) {
            return implode('|', array_map('strval', $value));
        }

        return $value;
    }

    private function matchesFilters(array $row, string $classification, string $risk): bool
    {
        $classificationMatches = $classification === ''
            || ($row['primary_classification'] ?? '') === $classification
            || in_array($classification, (array) ($row['classification_flags'] ?? []), true);

        return $classificationMatches && ($risk === '' || ($row['risk_level'] ?? '') === $risk);
    }

    private function hasRawMismatch(array $row): bool
    {
        if (array_key_exists('all_applicable_layers_pass', $row)
            && ! (bool) $row['all_applicable_layers_pass']) {
            return true;
        }

        foreach ([
            'customer_stored_vs_document_raw',
            'customer_stored_vs_ledger',
            'customer_document_vs_ledger',
            'supplier_stored_vs_document_raw',
            'supplier_stored_vs_ledger',
            'supplier_document_vs_ledger',
            'dual_role_screen_symmetry_difference',
        ] as $key) {
            if (abs((float) ($row[$key] ?? 0)) > PartnerDebtParityAuditService::TOLERANCE) {
                return true;
            }
        }

        return (string) ($row['primary_classification'] ?? '') === 'AUDIT_ERROR';
    }

    private function printSummary(array $rows, int $eligible, int $scanned, int $matched): void
    {
        $classifications = collect($rows)->countBy('primary_classification')->sortKeys();
        $risks = collect($rows)->countBy('risk_level')->sortKeys();
        $this->info('Dry-run: yes');
        $this->info("Total eligible: {$eligible}");
        $this->info("Total scanned: {$scanned}");
        $this->info("Total matched: {$matched}");
        $this->info('Total exported: '.count($rows));
        $this->line('Classification counts:');
        foreach ($classifications as $classification => $count) {
            $this->line("- {$classification}: {$count}");
        }
        $this->line('Risk counts:');
        foreach ($risks as $risk => $count) {
            $this->line("- {$risk}: {$count}");
        }
    }
}
