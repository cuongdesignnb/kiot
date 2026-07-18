<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Debt\PartnerDebtParityAuditService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use RuntimeException;

class AuditDebtParityCommand extends Command
{
    protected $signature = 'debt:audit-parity
        {--dry-run : Compatibility flag; the command is always read-only}
        {--all-partners : Include every partner row, including missing-role and zero-projection rows}
        {--include-special-status : Include inactive, merged, branchless and other historical statuses}
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

    public function handle(PartnerDebtParityAuditService $audit): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Please pass --dry-run. This command never applies debt changes.');

            return self::FAILURE;
        }

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

        $rows = [];
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
        if ($directory = $this->option('output')) {
            $output = rtrim(str_replace('\\', '/', (string) $directory), '/');
            $this->writeCsv($this->auditPath($output.'/audit.csv'), $rows);
            $this->writeJson($this->auditPath($output.'/audit.json'), $rows);
        }

        $this->printSummary($rows, $eligible, $scanned, $matched);

        $hasMismatch = collect($rows)->contains(fn (array $row): bool => $this->hasRawMismatch($row));

        return ($hasAuditErrors || ($this->option('fail-on-mismatch') && $hasMismatch))
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function partnerQuery(string $role): Builder
    {
        $query = Customer::query()->orderBy('id');
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
