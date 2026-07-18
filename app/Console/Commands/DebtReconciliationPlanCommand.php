<?php

namespace App\Console\Commands;

use App\Services\Debt\DebtReconciliationPlanService;
use App\Services\Debt\LegacyOrphanFinancialReferenceService;
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
        {--population-file= : Optional population reconciliation JSON with legacy orphan evidence}
        {--partner-id= : Filter one partner ID}
        {--classification= : Filter primary classification or classification flags}
        {--risk= : Filter risk level}
        {--export= : Plan CSV under storage/app/audits}
        {--json= : Plan JSON under storage/app/audits}';

    protected $description = 'Generate a proposal-only debt reconciliation plan from parity audit JSON';

    public function handle(
        DebtReconciliationPlanService $plans,
        LegacyOrphanFinancialReferenceService $orphanEvidence,
    ): int {
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
        $populationReportSha256 = null;
        if ($populationFile = (string) ($this->option('population-file') ?? '')) {
            $populationPath = $this->auditPath($populationFile);
            if (! is_file($populationPath)) {
                $this->error("Population file not found: {$populationPath}");

                return self::FAILURE;
            }
            $populationBytes = (string) file_get_contents($populationPath);
            $populationPayload = json_decode($populationBytes, true, flags: JSON_THROW_ON_ERROR);
            $populationReportSha256 = hash('sha256', $populationBytes);
            foreach ((array) ($populationPayload['orphan_financial_references'] ?? []) as $orphan) {
                if ((string) ($orphan['reason'] ?? '') !== 'LEGACY_ORPHAN_FINANCIAL_REFERENCE'
                    || (bool) ($orphan['affects_canonical_balance'] ?? true)) {
                    continue;
                }

                $rows[] = $this->orphanPlanRow(
                    (int) ($orphan['partner_id'] ?? 0),
                    $sourceReportSha256,
                    $populationReportSha256,
                    $databaseFingerprint,
                    $orphanEvidence,
                );
            }
        }

        if ($path = $this->option('export')) {
            $this->writeCsv($this->auditPath((string) $path), $rows);
        }
        if ($path = $this->option('json')) {
            $this->writeJson(
                $this->auditPath((string) $path),
                $rows,
                $sourceReportSha256,
                $databaseFingerprint,
                $populationReportSha256,
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
        ?string $populationReportSha256,
    ): void {
        File::ensureDirectoryExists(dirname($path));
        $planHash = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $approvalHash = hash('sha256', implode('|', [$sourceReportSha256, $databaseFingerprint, $planHash]));
        file_put_contents($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'dry_run' => true,
            'apply_supported' => true,
            'source_report_sha256' => $sourceReportSha256,
            'population_report_sha256' => $populationReportSha256,
            'database_fingerprint' => $databaseFingerprint,
            'plan_hash' => $planHash,
            'approval_hash' => $approvalHash,
            'rows' => $rows,
            'plans' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function orphanPlanRow(
        int $partnerId,
        string $sourceReportSha256,
        string $populationReportSha256,
        string $databaseFingerprint,
        LegacyOrphanFinancialReferenceService $orphanEvidence,
    ): array {
        if ($partnerId < 1) {
            throw new RuntimeException('Legacy orphan partner ID must be a positive integer.');
        }

        $snapshot = $orphanEvidence->snapshot($partnerId);
        if ($snapshot['customer_exists'] || (int) $snapshot['source_count'] < 1) {
            throw new RuntimeException("Legacy orphan evidence changed for partner ID {$partnerId}.");
        }

        $plan = [
            'partner_id' => $partnerId,
            'partner_code' => 'LEGACY-ORPHAN-'.$partnerId,
            'role' => 'orphan',
            'risk_level' => 'HIGH',
            'primary_classification' => 'ORPHAN_CASH_FLOW',
            'classification_flags' => ['ORPHAN_CASH_FLOW'],
            'proposed_action_type' => 'MARK_LEGACY_ORPHAN_EXCLUDED',
            'customer_delta' => 0.0,
            'supplier_delta' => 0.0,
            'proposed_voucher' => null,
            'confidence' => 'high',
            'requires_backup' => true,
            'requires_manual_approval' => true,
            'rollback_strategy' => 'Retain source rows; remove only the idempotent classification log and operation if rollback is approved.',
            'evidence_required' => [
                'Persisted orphan financial source identities.',
                'Population report SHA-256.',
                'Approval hash.',
            ],
            'status' => 'PROPOSED',
            'before_snapshot' => [
                'customer_exists' => false,
                'source_count' => (int) $snapshot['source_count'],
                'evidence_hash' => (string) $snapshot['evidence_hash'],
            ],
            'canonical_target' => [
                'affects_canonical_balance' => false,
                'affects_any_partner_balance' => false,
                'classification' => 'LEGACY_ORPHAN_EXCLUDED_WITH_AUDIT_TRAIL',
            ],
            'event_evidence' => (array) $snapshot['sources'],
            'orphan_evidence_hash' => (string) $snapshot['evidence_hash'],
            'blocking_flags' => [],
            'source_report_sha256' => $sourceReportSha256,
            'population_report_sha256' => $populationReportSha256,
            'database_fingerprint' => $databaseFingerprint,
        ];
        $plan['approval_hash'] = hash('sha256', json_encode(
            $plan,
            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));

        return $plan;
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
