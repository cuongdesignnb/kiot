<?php

namespace App\Console\Commands;

use App\Services\Debt\DebtReconciliationPlanService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class DebtReconciliationPlanCommand extends Command
{
    protected $signature = 'debt:reconcile-plan
        {--dry-run : Required read-only gate}
        {--audit-file= : Audit JSON under storage/app/audits}
        {--export= : Plan CSV under storage/app/audits}
        {--json= : Plan JSON under storage/app/audits}';

    protected $description = 'Generate a proposal-only debt reconciliation plan from parity audit JSON';

    public function handle(DebtReconciliationPlanService $plans): int
    {
        if (!$this->option('dry-run')) {
            $this->error('Please pass --dry-run. This command never applies debt changes.');

            return self::FAILURE;
        }
        if (!$this->option('audit-file')) {
            $this->error('Missing required --audit-file.');

            return self::FAILURE;
        }

        $auditPath = $this->auditPath((string) $this->option('audit-file'));
        if (!is_file($auditPath)) {
            $this->error("Audit file not found: {$auditPath}");

            return self::FAILURE;
        }
        $payload = json_decode((string) file_get_contents($auditPath), true, flags: JSON_THROW_ON_ERROR);
        $rows = $plans->generate((array) ($payload['rows'] ?? $payload));

        if ($path = $this->option('export')) {
            $this->writeCsv($this->auditPath((string) $path), $rows);
        }
        if ($path = $this->option('json')) {
            $this->writeJson($this->auditPath((string) $path), $rows);
        }

        $this->info('Dry-run: yes');
        $this->info('Plans generated: ' . count($rows));
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

    private function writeJson(string $path, array $rows): void
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'dry_run' => true,
            'apply_supported' => false,
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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
        if ($absolute !== $root && !str_starts_with($absolute, $root . '/')) {
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
