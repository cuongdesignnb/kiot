<?php

namespace App\Console\Commands;

use App\Services\Debt\DebtReconciliationPlanService;
use App\Services\Debt\PartnerDebtParityAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class DebtReconciliationPlanCommand extends Command
{
    protected $signature = 'debt:reconcile-plan
        {--dry-run : Required read-only gate}
        {--audit-file= : Audit JSON under storage/app/audits}
        {--partner-id= : Filter one partner ID}
        {--classification= : Filter primary classification or classification flags}
        {--risk= : Filter risk level}
        {--export= : Plan CSV under storage/app/audits}
        {--json= : Plan JSON under storage/app/audits}';

    protected $description = 'Generate a proposal-only debt reconciliation plan from parity audit JSON';

    public function handle(DebtReconciliationPlanService $plans): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Please pass --dry-run. This command never applies debt changes.');

            return self::FAILURE;
        }
        if (! $this->option('audit-file')) {
            $this->error('Missing required --audit-file.');

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
        $partnerId = $this->option('partner-id');
        if ($partnerId !== null && preg_match('/^[1-9][0-9]*$/', (string) $partnerId) !== 1) {
            $this->error('Invalid --partner-id. Use a positive integer.');

            return self::FAILURE;
        }

        $auditPath = $this->auditPath((string) $this->option('audit-file'));
        if (! is_file($auditPath)) {
            $this->error("Audit file not found: {$auditPath}");

            return self::FAILURE;
        }
        $auditBytes = (string) file_get_contents($auditPath);
        $sourceReportSha256 = hash('sha256', $auditBytes);
        $databaseFingerprint = $this->databaseFingerprint();
        $payload = json_decode($auditBytes, true, flags: JSON_THROW_ON_ERROR);
        $auditRows = collect((array) ($payload['rows'] ?? $payload))
            ->filter(function (array $row) use ($partnerId, $classification, $risk): bool {
                if ($partnerId !== null && (int) ($row['partner_id'] ?? 0) !== (int) $partnerId) {
                    return false;
                }
                if ($classification !== ''
                    && ($row['primary_classification'] ?? '') !== $classification
                    && ! in_array($classification, (array) ($row['classification_flags'] ?? []), true)) {
                    return false;
                }

                return $risk === '' || ($row['risk_level'] ?? '') === $risk;
            })
            ->values()
            ->all();
        $rows = $plans->generate($auditRows, $sourceReportSha256, $databaseFingerprint);

        if ($path = $this->option('export')) {
            $this->writeCsv($this->auditPath((string) $path), $rows);
        }
        if ($path = $this->option('json')) {
            $this->writeJson(
                $this->auditPath((string) $path),
                $rows,
                $sourceReportSha256,
                $databaseFingerprint,
            );
        }

        $this->info('Dry-run: yes');
        $this->info('Plans generated: '.count($rows));
        foreach (collect($rows)->countBy('proposed_action_type')->sortKeys() as $action => $count) {
            $this->line("- {$action}: {$count}");
        }

        return self::SUCCESS;
    }

    private function writeCsv(string $path, array $rows): void
    {
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open reconciliation CSV: {$path}");
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, DebtReconciliationPlanService::CSV_COLUMNS);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (string $column): mixed => $this->scalar($row[$column] ?? null),
                DebtReconciliationPlanService::CSV_COLUMNS,
            ));
        }
        fclose($handle);
    }

    private function writeJson(
        string $path,
        array $rows,
        string $sourceReportSha256,
        string $databaseFingerprint,
    ): void {
        File::ensureDirectoryExists(dirname($path));
        $planHash = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $approvalHash = hash('sha256', implode('|', [$sourceReportSha256, $databaseFingerprint, $planHash]));
        file_put_contents($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'dry_run' => true,
            'apply_supported' => true,
            'source_report_sha256' => $sourceReportSha256,
            'database_fingerprint' => $databaseFingerprint,
            'plan_hash' => $planHash,
            'approval_hash' => $approvalHash,
            'rows' => $rows,
            'plans' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function databaseFingerprint(): string
    {
        $connection = DB::connection();
        $payload = implode('|', [
            $connection->getDriverName(),
            $connection->getDatabaseName(),
            (string) $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
        ]);

        return hash('sha256', $payload);
    }

    private function auditPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (str_contains($normalized, '/../') || str_ends_with($normalized, '/..')) {
            throw new RuntimeException('Audit path cannot contain parent traversal.');
        }
        $absolute = preg_match('/^[A-Za-z]:\//', $normalized) === 1 || str_starts_with($normalized, '/')
            ? $normalized
            : str_replace('\\', '/', base_path($normalized));
        $root = rtrim(str_replace('\\', '/', storage_path('app/audits')), '/');
        if ($absolute !== $root && ! str_starts_with($absolute, $root.'/')) {
            throw new RuntimeException('Audit files must be under storage/app/audits.');
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
}
