<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Debt\MaterialDebtRootCauseDrilldownService;
use App\Services\Debt\PartnerDebtParityAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class MaterialDebtRootCauseDrilldownCommand extends Command
{
    public const SUMMARY_COLUMNS = [
        'partner_id', 'partner_code', 'role', 'risk_level', 'primary_classification',
        'classification_flags', 'max_abs_difference', 'stored_customer_screen',
        'stored_supplier_screen', 'customer_document_raw_final', 'customer_ledger_final',
        'supplier_document_raw_final', 'supplier_ledger_final', 'observed_patterns',
        'highest_pattern_confidence', 'source_of_truth_status', 'missing_evidence',
        'recommended_next_review', 'drilldown_status', 'error_message',
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
        if (!$this->option('dry-run')) {
            $this->error('Please pass --dry-run. This command never applies debt changes.');

            return self::FAILURE;
        }
        if (!$this->option('audit-file') || !$this->option('export-dir')) {
            $this->error('Both --audit-file and --export-dir are required.');

            return self::FAILURE;
        }
        $role = (string) $this->option('role');
        if (!in_array($role, ['all', 'customer', 'supplier', 'dual'], true)) {
            $this->error('Invalid --role. Use all, customer, supplier or dual.');

            return self::FAILURE;
        }
        $risk = (string) ($this->option('risk') ?? '');
        if ($risk !== '' && !in_array($risk, ['CRITICAL', 'HIGH', 'MEDIUM'], true)) {
            $this->error('Invalid --risk. Use CRITICAL, HIGH or MEDIUM.');

            return self::FAILURE;
        }
        $classification = (string) ($this->option('classification') ?? '');
        if ($classification !== '' && !in_array($classification, PartnerDebtParityAuditService::CLASSIFICATIONS, true)) {
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

        $auditPath = $this->pathUnderAuditRoot((string) $this->option('audit-file'), false);
        if (!is_file($auditPath)) {
            $this->error("Audit file not found: {$auditPath}");

            return self::FAILURE;
        }
        $exportDir = $this->pathUnderAuditRoot((string) $this->option('export-dir'), true);
        File::ensureDirectoryExists($exportDir);
        File::ensureDirectoryExists($exportDir . DIRECTORY_SEPARATOR . 'partners');

        $payload = json_decode((string) file_get_contents($auditPath), true, flags: JSON_THROW_ON_ERROR);
        $auditRows = collect((array) ($payload['rows'] ?? $payload))
            ->filter(fn ($row): bool => is_array($row))
            ->sortBy(fn (array $row): int => (int) ($row['partner_id'] ?? 0))
            ->filter(fn (array $row): bool => $this->matchesFilters($row, $partnerId, $role, $risk, $classification))
            ->values();
        $eligible = $auditRows->count();
        if (is_int($limit)) {
            $auditRows = $auditRows->take($limit)->values();
        }

        $details = [];
        $summaries = [];
        $errors = 0;
        foreach ($auditRows as $auditRow) {
            $id = (int) ($auditRow['partner_id'] ?? 0);
            try {
                $partner = Customer::query()->find($id);
                if (!$partner) {
                    throw new RuntimeException('Partner record is unavailable in the current database.');
                }
                $detail = $service->drilldown($partner, $auditRow);
            } catch (Throwable $exception) {
                $errors++;
                $detail = $this->errorDetail($auditRow, $exception);
            }
            $details[] = $detail;
            $summaries[] = $this->summaryRow($detail, $auditRow);
            $this->writeJson(
                $exportDir . DIRECTORY_SEPARATOR . 'partners' . DIRECTORY_SEPARATOR . $id . '.json',
                $detail,
            );
        }

        $summaries = collect($summaries)->sortBy('partner_id')->values()->all();
        $details = collect($details)->sortBy(fn (array $detail): int => (int) ($detail['partner']['partner_id'] ?? 0))->values()->all();
        $queue = collect($summaries)->sortBy(fn (array $row): string => sprintf(
            '%d|%012d',
            $this->riskRank((string) ($row['risk_level'] ?? 'MEDIUM')),
            (int) ($row['partner_id'] ?? 0),
        ))->values()->all();

        $this->writeCsv($exportDir . DIRECTORY_SEPARATOR . 'material-root-cause-summary.csv', $summaries);
        $this->writeJson($exportDir . DIRECTORY_SEPARATOR . 'material-root-cause-summary.json', [
            'dry_run' => true,
            'source_of_truth_default' => MaterialDebtRootCauseDrilldownService::SOURCE_OF_TRUTH_STATUS,
            'rows' => $summaries,
        ]);
        $this->writeJson($exportDir . DIRECTORY_SEPARATOR . 'material-root-cause-detail.json', [
            'dry_run' => true,
            'details' => $details,
        ]);
        $this->writeCsv($exportDir . DIRECTORY_SEPARATOR . 'manual-review-queue.csv', $queue);
        file_put_contents($exportDir . DIRECTORY_SEPARATOR . 'command.log', implode(PHP_EOL, [
            'command=debt:drilldown-material',
            'dry_run=true',
            'eligible=' . $eligible,
            'processed=' . count($summaries),
            'errors=' . $errors,
            'source_of_truth_default=' . MaterialDebtRootCauseDrilldownService::SOURCE_OF_TRUTH_STATUS,
        ]) . PHP_EOL);

        $this->info('Dry-run: yes');
        $this->info("Total eligible: {$eligible}");
        $this->info('Total processed: ' . count($summaries));
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
        if ($role === 'customer' && !in_array($rowRole, ['customer_only', 'dual_role'], true)) {
            return false;
        }
        if ($role === 'supplier' && !in_array($rowRole, ['supplier_only', 'dual_role'], true)) {
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

    private function summaryRow(array $detail, array $auditRow): array
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
            'source_of_truth_status' => MaterialDebtRootCauseDrilldownService::SOURCE_OF_TRUTH_STATUS,
            'missing_evidence' => (array) ($detail['missing_evidence'] ?? ['Manual source-of-truth confirmation']),
            'recommended_next_review' => (string) ($detail['recommended_next_review'] ?? 'Manual review required.'),
            'drilldown_status' => (string) ($detail['drilldown_status'] ?? 'ERROR'),
            'error_message' => $detail['error_message'] ?? null,
        ];
    }

    private function errorDetail(array $auditRow, Throwable $exception): array
    {
        return [
            'drilldown_status' => 'ERROR',
            'partner' => [
                'partner_id' => (int) ($auditRow['partner_id'] ?? 0),
                'partner_code' => (string) ($auditRow['partner_code'] ?? ''),
                'role' => (string) ($auditRow['role'] ?? ''),
            ],
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
            'error_message' => 'Drilldown failed: ' . class_basename($exception),
        ];
    }

    private function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open drilldown CSV: {$path}");
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::SUMMARY_COLUMNS);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $column): mixed => $this->scalar($row[$column] ?? null), self::SUMMARY_COLUMNS));
        }
        fclose($handle);
    }

    private function writeJson(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function pathUnderAuditRoot(string $path, bool $directory): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '' || str_contains('/' . $normalized . '/', '/../')) {
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
            $absolute = $root . '/' . ltrim($normalized, '/');
        }
        if ($absolute === $root || !str_starts_with($absolute, $root . '/')) {
            throw new RuntimeException('Audit files and output must be under storage/app/audits.');
        }
        if (!$directory && str_ends_with($absolute, '/')) {
            throw new RuntimeException('Audit input must be a file path.');
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $absolute);
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
