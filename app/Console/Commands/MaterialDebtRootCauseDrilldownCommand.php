<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Debt\MaterialDebtRootCauseDrilldownService;
use App\Services\Debt\PartnerDebtParityAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class MaterialDebtRootCauseDrilldownCommand extends Command
{
    private const VALID_ARTIFACT_ROLES = ['customer_only', 'supplier_only', 'dual_role'];

    private const NON_MATERIAL_FLAGS = [
        'OK',
        'TARGET_TYPE_ALIAS_SUSPECT',
        'TECHNICAL_LEDGER_EXCLUDED',
        'VIRTUAL_DISPLAY_ALIGNMENT_ONLY',
    ];

    public const SUMMARY_COLUMNS = [
        'partner_id', 'partner_code', 'role', 'risk_level', 'primary_classification',
        'classification_flags', 'max_abs_difference', 'stored_customer_screen',
        'stored_supplier_screen', 'customer_document_raw_final', 'customer_ledger_final',
        'supplier_document_raw_final', 'supplier_ledger_final', 'observed_patterns',
        'highest_pattern_confidence', 'source_of_truth_status', 'missing_evidence',
        'recommended_next_review', 'drilldown_status', 'error_code', 'error_message',
        'input_audit_sha256',
    ];

    protected $signature = 'debt:drilldown-material
        {--dry-run : Required read-only gate}
        {--audit-file= : Material audit JSON under storage/app/audits}
        {--partner-id= : Drilldown one partner ID}
        {--role=all : all, customer, supplier or dual}
        {--risk= : CRITICAL, HIGH or MEDIUM}
        {--classification= : Filter primary or classification flags}
        {--limit= : Positive integer limit}
        {--export-dir= : Output directory under storage/app/audits}';

    protected $description = 'Generate read-only root-cause evidence for material debt review rows';

    public function handle(MaterialDebtRootCauseDrilldownService $service): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Please pass --dry-run. This command never applies debt changes.');

            return self::FAILURE;
        }
        if (! $this->option('audit-file') || ! $this->option('export-dir')) {
            $this->error('Both --audit-file and --export-dir are required.');

            return self::FAILURE;
        }
        $role = (string) $this->option('role');
        if (! in_array($role, ['all', 'customer', 'supplier', 'dual'], true)) {
            $this->error('Invalid --role. Use all, customer, supplier or dual.');

            return self::FAILURE;
        }
        $risk = (string) ($this->option('risk') ?? '');
        if ($risk !== '' && ! in_array($risk, ['CRITICAL', 'HIGH', 'MEDIUM'], true)) {
            $this->error('Invalid --risk. Use CRITICAL, HIGH or MEDIUM.');

            return self::FAILURE;
        }
        $classification = (string) ($this->option('classification') ?? '');
        if ($classification !== '' && ! in_array($classification, PartnerDebtParityAuditService::CLASSIFICATIONS, true)) {
            $this->error('Invalid --classification. Use a supported audit classification.');

            return self::FAILURE;
        }
        $partnerId = $this->positiveIntegerOption('partner-id');
        if ($partnerId === false) {
            $this->error('Invalid --partner-id. Use a positive integer.');

            return self::FAILURE;
        }
        $limit = $this->positiveIntegerOption('limit');
        if ($limit === false) {
            $this->error('Invalid --limit. Use a positive integer.');

            return self::FAILURE;
        }

        try {
            $auditPath = $this->pathUnderAuditRoot((string) $this->option('audit-file'), false);
            if (! is_file($auditPath)) {
                $this->error('Audit input file was not found under storage/app/audits.');

                return self::FAILURE;
            }
            $exportDir = $this->pathUnderAuditRoot((string) $this->option('export-dir'), true);
            $this->assertEmptyExportDirectory($exportDir);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $inputBytes = file_get_contents($auditPath);
            if ($inputBytes === false) {
                throw new RuntimeException('Audit input cannot be read.');
            }
            $payload = json_decode($inputBytes, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($payload) || ! array_key_exists('rows', $payload) || ! is_array($payload['rows'])) {
                throw new RuntimeException('Audit input has an invalid schema.');
            }
            if (collect($payload['rows'])->contains(fn ($row): bool => ! is_array($row))) {
                throw new RuntimeException('Audit input contains an invalid row.');
            }
        } catch (Throwable) {
            $this->error('Invalid audit artifact.');

            return self::FAILURE;
        }

        $inputSha256 = hash('sha256', $inputBytes);
        $inputRows = collect($payload['rows'])
            ->values()
            ->map(fn (array $row, int $index): array => array_merge($row, ['_input_index' => $index]));
        $materialRows = $inputRows->filter(fn (array $row): bool => $this->isMaterialRow($row))->values();
        $nonMaterialSkipped = $inputRows->count() - $materialRows->count();
        $duplicateIds = $materialRows->filter(fn (array $row): bool => $this->validPartnerId($row) !== null)
            ->groupBy(fn (array $row): int => $this->validPartnerId($row))
            ->filter(fn (Collection $rows): bool => $rows->count() > 1)
            ->keys()->map(fn ($id): int => (int) $id)->sort()->values();
        $auditRows = $materialRows
            ->filter(fn (array $row): bool => $this->matchesFilters($row, $partnerId, $role, $risk, $classification))
            ->sortBy(fn (array $row): string => sprintf(
                '%020d|%08d',
                filter_var($row['partner_id'] ?? null, FILTER_VALIDATE_INT) ?: 0,
                (int) $row['_input_index'],
            ))
            ->values();
        if (is_int($limit)) {
            $auditRows = $auditRows->take($limit)->values();
        }

        $stagingDir = null;
        try {
            $stagingDir = $this->createStagingDirectory($exportDir);
            File::ensureDirectoryExists($stagingDir.DIRECTORY_SEPARATOR.'partners');

            $details = [];
            $summaries = [];
            $errors = 0;
            $processed = 0;
            $identityMismatches = 0;
            $emittedDuplicateIds = collect();
            foreach ($auditRows as $auditRow) {
                $id = $this->validPartnerId($auditRow);
                $detail = null;

                if ($id === null) {
                    $detail = $this->errorDetail($auditRow, 'INVALID_PARTNER_ID', 'Artifact partner ID must be a positive integer.');
                } elseif ($duplicateIds->contains($id)) {
                    if ($emittedDuplicateIds->contains($id)) {
                        continue;
                    }
                    $emittedDuplicateIds->push($id);
                    $detail = $this->errorDetail($auditRow, 'DUPLICATE_PARTNER_ID', 'Artifact contains duplicate partner IDs.');
                } elseif (trim((string) ($auditRow['partner_code'] ?? '')) === '') {
                    $detail = $this->errorDetail($auditRow, 'INVALID_PARTNER_CODE', 'Artifact partner code is required.');
                } elseif (! in_array((string) ($auditRow['role'] ?? ''), self::VALID_ARTIFACT_ROLES, true)) {
                    $detail = $this->errorDetail($auditRow, 'INVALID_PARTNER_ROLE', 'Artifact partner role is invalid.');
                } else {
                    $partner = Customer::query()->find($id);
                    if (! $partner) {
                        $detail = $this->errorDetail($auditRow, 'PARTNER_NOT_FOUND', 'Partner record is unavailable in the current database.');
                    } else {
                        $mismatchFields = $this->identityMismatchFields($auditRow, $partner);
                        if ($mismatchFields !== []) {
                            $identityMismatches++;
                            $detail = $this->errorDetail(
                                $auditRow,
                                'AUDIT_ARTIFACT_PARTNER_MISMATCH',
                                'Artifact partner identity does not match the current database.',
                                ['mismatch_fields' => $mismatchFields],
                            );
                        } else {
                            try {
                                $detail = $service->drilldown($partner, $auditRow);
                                $processed++;
                            } catch (Throwable $exception) {
                                $detail = $this->errorDetail(
                                    $auditRow,
                                    'DRILLDOWN_EXECUTION_ERROR',
                                    'Drilldown failed: '.class_basename($exception),
                                );
                            }
                        }
                    }
                }

                if (($detail['drilldown_status'] ?? 'ERROR') === 'ERROR') {
                    $errors++;
                }
                $details[] = $detail;
                $summaries[] = $this->summaryRow($detail, $auditRow, $inputSha256);
                if (($detail['drilldown_status'] ?? 'ERROR') === 'OK') {
                    $this->writeJson(
                        $stagingDir.DIRECTORY_SEPARATOR.'partners'.DIRECTORY_SEPARATOR.$id.'.json',
                        $detail,
                    );
                }
            }

            $summaries = collect($summaries)->sortBy('partner_id')->values()->all();
            $details = collect($details)->sortBy(fn (array $detail): int => (int) ($detail['partner']['partner_id'] ?? 0))->values()->all();
            $queue = collect($summaries)
                ->filter(fn (array $row): bool => ($row['source_of_truth_status'] ?? '') !== MaterialDebtRootCauseDrilldownService::DETERMINISTIC_SOURCE_OF_TRUTH_STATUS
                    || ($row['drilldown_status'] ?? 'ERROR') !== 'OK')
                ->sortBy(fn (array $row): string => sprintf(
                    '%d|%012d',
                    $this->riskRank((string) ($row['risk_level'] ?? 'MEDIUM')),
                    (int) ($row['partner_id'] ?? 0),
                ))->values()->all();

            $metadata = [
                'input_audit_file' => $this->relativeAuditPath($auditPath),
                'input_audit_sha256' => $inputSha256,
                'input_row_count' => $inputRows->count(),
                'material_row_count' => $materialRows->count(),
                'non_material_skipped' => $nonMaterialSkipped,
                'unique_partner_ids' => $auditRows->map(fn (array $row): ?int => $this->validPartnerId($row))->filter()->unique()->count(),
                'duplicate_partner_ids' => $duplicateIds->all(),
                'identity_mismatch_count' => $identityMismatches,
                'processed_count' => $processed,
                'error_count' => $errors,
                'generated_at' => now()->toIso8601String(),
            ];

            $this->writeCsv($stagingDir.DIRECTORY_SEPARATOR.'material-root-cause-summary.csv', $summaries);
            $this->writeJson($stagingDir.DIRECTORY_SEPARATOR.'material-root-cause-summary.json', [
                'dry_run' => true,
                'source_of_truth_default' => MaterialDebtRootCauseDrilldownService::SOURCE_OF_TRUTH_STATUS,
                'metadata' => $metadata,
                'rows' => $summaries,
            ]);
            $this->writeJson($stagingDir.DIRECTORY_SEPARATOR.'material-root-cause-detail.json', [
                'dry_run' => true,
                'source_of_truth_default' => MaterialDebtRootCauseDrilldownService::SOURCE_OF_TRUTH_STATUS,
                'metadata' => $metadata,
                'details' => $details,
            ]);
            $this->writeCsv($stagingDir.DIRECTORY_SEPARATOR.'manual-review-queue.csv', $queue);
            $logResult = file_put_contents($stagingDir.DIRECTORY_SEPARATOR.'command.log', implode(PHP_EOL, [
                'command=debt:drilldown-material',
                'dry_run=true',
                'source_of_truth_default='.MaterialDebtRootCauseDrilldownService::SOURCE_OF_TRUTH_STATUS,
                ...collect($metadata)->map(fn ($value, string $key): string => $key.'='.$this->scalar($value))->values()->all(),
            ]).PHP_EOL);
            if ($logResult === false) {
                throw new RuntimeException('Cannot write drilldown command log.');
            }
            $this->publishStagedExport($stagingDir, $exportDir);
            $stagingDir = null;
        } catch (Throwable $exception) {
            if (is_string($stagingDir) && is_dir($stagingDir)) {
                File::deleteDirectory($stagingDir);
            }
            $this->error('Drilldown export failed ('.class_basename($exception).'); no completed export was published.');

            return self::FAILURE;
        }

        $this->info('Dry-run: yes');
        $this->info('Input rows: '.$inputRows->count());
        $this->info('Material rows: '.$materialRows->count());
        $this->info("Non-material skipped: {$nonMaterialSkipped}");
        $this->info("Total processed: {$processed}");
        $this->info("Total errors: {$errors}");
        $this->info("Output directory: {$exportDir}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function matchesFilters(array $row, int|false|null $partnerId, string $role, string $risk, string $classification): bool
    {
        if (is_int($partnerId) && (int) ($row['partner_id'] ?? 0) !== $partnerId) {
            return false;
        }
        $rowRole = (string) ($row['role'] ?? '');
        if ($role === 'customer' && ! in_array($rowRole, ['customer_only', 'dual_role'], true)) {
            return false;
        }
        if ($role === 'supplier' && ! in_array($rowRole, ['supplier_only', 'dual_role'], true)) {
            return false;
        }
        if ($role === 'dual' && $rowRole !== 'dual_role') {
            return false;
        }
        if ($risk !== '' && (string) ($row['risk_level'] ?? '') !== $risk) {
            return false;
        }

        return $classification === ''
            || (string) ($row['primary_classification'] ?? '') === $classification
            || in_array($classification, (array) ($row['classification_flags'] ?? []), true);
    }

    private function summaryRow(array $detail, array $auditRow, string $inputSha256): array
    {
        $patterns = collect($detail['observed_patterns'] ?? []);
        $confidenceRank = ['low' => 1, 'medium' => 2, 'high' => 3];
        $highest = $patterns->pluck('confidence')->sortByDesc(fn ($value): int => $confidenceRank[$value] ?? 0)->first();

        return [
            'partner_id' => (int) ($detail['partner']['partner_id'] ?? $auditRow['partner_id'] ?? 0),
            'partner_code' => (string) ($detail['partner']['partner_code'] ?? $auditRow['partner_code'] ?? ''),
            'role' => (string) ($detail['partner']['role'] ?? $auditRow['role'] ?? ''),
            'risk_level' => (string) ($auditRow['risk_level'] ?? 'MEDIUM'),
            'primary_classification' => (string) ($auditRow['primary_classification'] ?? ''),
            'classification_flags' => (array) ($auditRow['classification_flags'] ?? []),
            'max_abs_difference' => $this->maxDifference($auditRow),
            'stored_customer_screen' => (float) ($detail['stored_balance']['stored_customer_screen'] ?? 0),
            'stored_supplier_screen' => (float) ($detail['stored_balance']['stored_supplier_screen'] ?? 0),
            'customer_document_raw_final' => (float) ($detail['customer_document']['raw_document_final_balance'] ?? 0),
            'customer_ledger_final' => (float) ($detail['customer_ledger']['ledger_final'] ?? 0),
            'supplier_document_raw_final' => (float) ($detail['supplier_document']['raw_document_final_balance'] ?? 0),
            'supplier_ledger_final' => (float) ($detail['supplier_ledger']['ledger_final'] ?? 0),
            'observed_patterns' => $patterns->pluck('pattern')->all(),
            'highest_pattern_confidence' => (string) ($highest ?? 'low'),
            'source_of_truth_status' => (string) ($detail['source_of_truth_status']
                ?? MaterialDebtRootCauseDrilldownService::SOURCE_OF_TRUTH_STATUS),
            'missing_evidence' => (array) ($detail['missing_evidence'] ?? ['Manual source-of-truth confirmation']),
            'recommended_next_review' => (string) ($detail['recommended_next_review'] ?? 'Manual review required.'),
            'drilldown_status' => (string) ($detail['drilldown_status'] ?? 'ERROR'),
            'error_code' => $detail['error_code'] ?? null,
            'error_message' => $detail['error_message'] ?? null,
            'input_audit_sha256' => $inputSha256,
        ];
    }

    private function errorDetail(array $auditRow, string $errorCode, string $message, array $context = []): array
    {
        return [
            'drilldown_status' => 'ERROR',
            'partner' => [
                'partner_id' => (int) ($auditRow['partner_id'] ?? 0),
                'partner_code' => (string) ($auditRow['partner_code'] ?? ''),
                'role' => (string) ($auditRow['role'] ?? ''),
            ],
            'artifact_partner_id' => $this->safeArtifactValue($auditRow['partner_id'] ?? null),
            'artifact_partner_code' => $this->safeArtifactValue($auditRow['partner_code'] ?? ''),
            'artifact_role' => $this->safeArtifactValue($auditRow['role'] ?? ''),
            'mismatch_fields' => array_values((array) ($context['mismatch_fields'] ?? [])),
            'observed_patterns' => [[
                'pattern' => 'UNRESOLVED',
                'confidence' => 'low',
                'evidence_codes' => [],
                'evidence_ids' => [],
                'reason' => 'Drilldown could not collect complete evidence.',
            ]],
            'missing_evidence' => ['Manual source-of-truth confirmation'],
            'source_of_truth_status' => MaterialDebtRootCauseDrilldownService::SOURCE_OF_TRUTH_STATUS,
            'recommended_next_review' => 'Resolve drilldown execution error before evidence review.',
            'error_code' => $errorCode,
            'error_message' => $message,
        ];
    }

    private function isMaterialRow(array $row): bool
    {
        $flags = collect((array) ($row['classification_flags'] ?? []))
            ->push((string) ($row['primary_classification'] ?? ''))
            ->filter()->unique();

        return $flags->diff(self::NON_MATERIAL_FLAGS)->isNotEmpty();
    }

    private function validPartnerId(array $row): ?int
    {
        $value = $row['partner_id'] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function identityMismatchFields(array $auditRow, Customer $partner): array
    {
        $mismatches = [];
        if ((int) $auditRow['partner_id'] !== (int) $partner->id) {
            $mismatches[] = 'partner_id';
        }
        if ((string) $auditRow['partner_code'] !== (string) $partner->code) {
            $mismatches[] = 'partner_code';
        }
        if ((string) $auditRow['role'] !== MaterialDebtRootCauseDrilldownService::roleFor($partner)) {
            $mismatches[] = 'role';
        }

        return $mismatches;
    }

    private function safeArtifactValue(mixed $value): int|string|null
    {
        if (is_int($value)) {
            return $value;
        }
        if (! is_scalar($value)) {
            return null;
        }

        return mb_substr(trim((string) $value), 0, 128);
    }

    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open drilldown CSV: {$path}");
        }
        if (fwrite($handle, "\xEF\xBB\xBF") === false || fputcsv($handle, self::SUMMARY_COLUMNS) === false) {
            fclose($handle);
            throw new RuntimeException('Cannot write drilldown CSV.');
        }
        foreach ($rows as $row) {
            if (fputcsv($handle, array_map(fn (string $column): mixed => $this->scalar($row[$column] ?? null), self::SUMMARY_COLUMNS)) === false) {
                fclose($handle);
                throw new RuntimeException('Cannot write drilldown CSV.');
            }
        }
        if (! fclose($handle)) {
            throw new RuntimeException('Cannot close drilldown CSV.');
        }
    }

    private function writeJson(string $path, array $payload): void
    {
        if (file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Cannot write drilldown JSON.');
        }
    }

    private function pathUnderAuditRoot(string $path, bool $directory): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '' || str_contains('/'.$normalized.'/', '/../')) {
            throw new RuntimeException('Audit path cannot be empty or contain parent traversal.');
        }
        $root = rtrim(str_replace('\\', '/', storage_path('app/audits')), '/');
        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1 || str_starts_with($normalized, '/')) {
            $absolute = rtrim($normalized, '/');
        } elseif (str_starts_with($normalized, 'storage/app/audits')) {
            $absolute = rtrim(str_replace('\\', '/', base_path($normalized)), '/');
        } elseif (str_starts_with($normalized, 'storage/')) {
            throw new RuntimeException('Audit files and output must be under storage/app/audits.');
        } else {
            $absolute = $root.'/'.ltrim($normalized, '/');
        }
        if (! $this->pathIsWithin($absolute, $root) || $absolute === $root) {
            throw new RuntimeException('Audit files and output must be under storage/app/audits.');
        }
        if (! $directory && str_ends_with($absolute, '/')) {
            throw new RuntimeException('Audit input must be a file path.');
        }

        $nativeAbsolute = str_replace('/', DIRECTORY_SEPARATOR, $absolute);
        $canonicalRoot = realpath(str_replace('/', DIRECTORY_SEPARATOR, $root));
        if ($canonicalRoot === false) {
            throw new RuntimeException('Audit root is unavailable.');
        }
        if (file_exists($nativeAbsolute) || is_link($nativeAbsolute)) {
            $canonicalPath = realpath($nativeAbsolute);
            if ($canonicalPath === false || ! $this->pathIsWithin($canonicalPath, $canonicalRoot)) {
                throw new RuntimeException('Audit path resolves outside storage/app/audits.');
            }

            return $canonicalPath;
        }
        if (! $directory) {
            return $nativeAbsolute;
        }

        $ancestor = dirname($nativeAbsolute);
        while (! file_exists($ancestor) && dirname($ancestor) !== $ancestor) {
            $ancestor = dirname($ancestor);
        }
        $canonicalAncestor = realpath($ancestor);
        if ($canonicalAncestor === false || ! $this->pathIsWithin($canonicalAncestor, $canonicalRoot)) {
            throw new RuntimeException('Audit path resolves outside storage/app/audits.');
        }

        return $nativeAbsolute;
    }

    private function assertEmptyExportDirectory(string $path): void
    {
        if (file_exists($path) && ! is_dir($path)) {
            throw new RuntimeException('Output path must be a directory.');
        }
        if (! is_dir($path)) {
            return;
        }

        $entries = array_values(array_diff(scandir($path) ?: [], ['.', '..']));
        if ($entries !== []) {
            throw new RuntimeException('BLOCKER: export directory is not empty');
        }
    }

    private function createStagingDirectory(string $exportDir): string
    {
        File::ensureDirectoryExists(dirname($exportDir));
        $stagingDir = $exportDir.'.tmp-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($stagingDir);

        return $stagingDir;
    }

    private function publishStagedExport(string $stagingDir, string $exportDir): void
    {
        foreach (['material-root-cause-summary.csv', 'material-root-cause-summary.json',
            'material-root-cause-detail.json', 'manual-review-queue.csv', 'command.log'] as $requiredFile) {
            if (! is_file($stagingDir.DIRECTORY_SEPARATOR.$requiredFile)) {
                throw new RuntimeException('Required drilldown export file is missing.');
            }
        }
        if (is_dir($exportDir) && ! rmdir($exportDir)) {
            throw new RuntimeException('Cannot replace empty export directory.');
        }
        if (! rename($stagingDir, $exportDir)) {
            throw new RuntimeException('Cannot publish completed drilldown export.');
        }
    }

    private function pathIsWithin(string $path, string $root): bool
    {
        $normalize = static fn (string $value): string => rtrim(str_replace('\\', '/', $value), '/');
        $path = $normalize($path);
        $root = $normalize($root);
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = mb_strtolower($path);
            $root = mb_strtolower($root);
        }

        return $path === $root || str_starts_with($path, $root.'/');
    }

    private function relativeAuditPath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', storage_path('app/audits')), '/');
        $normalized = str_replace('\\', '/', $path);

        return 'storage/app/audits/'.ltrim(substr($normalized, strlen($root)), '/');
    }

    private function positiveIntegerOption(string $name): int|false|null
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        return preg_match('/^[1-9][0-9]*$/', (string) $value) === 1 ? (int) $value : false;
    }

    private function scalar(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_array($value)) {
            return implode('|', array_map(fn ($item): string => is_scalar($item) ? (string) $item : json_encode($item, JSON_UNESCAPED_UNICODE), $value));
        }

        return $value;
    }

    private function maxDifference(array $auditRow): float
    {
        return max(array_map(fn (string $key): float => abs((float) ($auditRow[$key] ?? 0)), [
            'customer_stored_vs_document_raw', 'customer_stored_vs_ledger', 'customer_document_vs_ledger',
            'supplier_stored_vs_document_raw', 'supplier_stored_vs_ledger', 'supplier_document_vs_ledger',
            'dual_role_screen_symmetry_difference',
        ]));
    }

    private function riskRank(string $risk): int
    {
        return ['CRITICAL' => 1, 'HIGH' => 2, 'MEDIUM' => 3][$risk] ?? 4;
    }
}
