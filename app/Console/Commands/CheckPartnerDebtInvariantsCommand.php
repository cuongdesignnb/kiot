<?php

namespace App\Console\Commands;

use App\Services\Debt\PartnerDebtInvariantChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckPartnerDebtInvariantsCommand extends Command
{
    protected $signature = 'debt:check-invariants
        {--dry-run : Required read-only gate}
        {--all-partners : Include missing-role, merged, inactive and zero-projection rows}
        {--fail-on-mismatch : Exit non-zero when material drift exists}
        {--output= : JSON file or directory under storage/app/audits}
        {--partner-id=* : Limit the scan to one or more partner IDs}
        {--role=all : all, customer, supplier or dual}
        {--status=all : all, active or inactive}
        {--limit= : Limit the number of checked partners}
        {--benchmark : Print query, runtime and memory metrics for a manual scan}';

    protected $description = 'Read-only debt invariant scan; never repairs or mutates debt data';

    public function handle(PartnerDebtInvariantChecker $checker): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Please pass --dry-run. This command never applies debt changes.');

            return self::INVALID;
        }

        $partnerIds = array_values(array_unique(array_map('intval', (array) $this->option('partner-id'))));
        if (in_array(0, $partnerIds, true) || count(array_filter($partnerIds, fn (int $id): bool => $id < 1)) > 0) {
            $this->error('Invalid --partner-id. Use positive integer IDs.');

            return self::INVALID;
        }

        $role = (string) $this->option('role');
        if (! in_array($role, ['all', 'customer', 'supplier', 'dual'], true)) {
            $this->error('Invalid --role. Use all, customer, supplier or dual.');

            return self::INVALID;
        }

        $status = (string) $this->option('status');
        if (! in_array($status, ['all', 'active', 'inactive'], true)) {
            $this->error('Invalid --status. Use all, active or inactive.');

            return self::INVALID;
        }

        $limitOption = $this->option('limit');
        if ($limitOption !== null && preg_match('/^[1-9][0-9]*$/', (string) $limitOption) !== 1) {
            $this->error('Invalid --limit. Use a positive integer.');

            return self::INVALID;
        }

        try {
            $arguments = [
                $partnerIds,
                $limitOption === null ? null : (int) $limitOption,
                $role,
                $status,
                (bool) $this->option('benchmark'),
            ];
            if ($this->option('all-partners')) {
                $arguments[] = true;
            }
            $result = $checker->scan(...$arguments);
        } catch (Throwable $e) {
            Log::error('Debt integrity scan failed', ['exception' => $e]);
            $this->error('Debt integrity scan failed. No debt data was changed.');

            return self::INVALID;
        }
        $driftRows = collect($result['rows'])->where('drift_detected', true)->values();
        if ($output = $this->option('output')) {
            $path = $this->outputPath((string) $output);
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        $this->info('Read-only: yes');
        $this->line('Total checked: '.$result['total_checked']);
        $this->line('Matched: '.$result['matched']);
        $this->line('Material drift: '.$result['drift_detected']);
        $this->line('Insufficient evidence: '.$result['insufficient_evidence']);
        $this->line('Technical warnings: '.$result['technical_warnings']);
        $this->line('Audit errors: '.$result['audit_errors']);
        if (is_array($result['benchmark'] ?? null)) {
            $benchmark = $result['benchmark'];
            $this->line('Benchmark SQL queries: '.$benchmark['query_count']);
            $this->line('Benchmark queries/partner: '.number_format($benchmark['queries_per_partner'], 2));
            $this->line('Benchmark runtime ms: '.number_format($benchmark['runtime_ms'], 2));
            $this->line('Benchmark peak memory MB: '.number_format($benchmark['peak_memory_mb'], 2));
            $this->line('Benchmark slowest partner ms: '.number_format($benchmark['slowest_partner_runtime_ms'], 2));
        }

        if ($result['audit_errors'] > 0) {
            Log::error('Debt integrity scan completed with audit errors', [
                'checked_at' => $result['checked_at'],
                'total_checked' => $result['total_checked'],
                'audit_errors' => $result['audit_errors'],
            ]);

            return self::INVALID;
        }

        if ($driftRows->isNotEmpty()) {
            $this->table(
                ['Partner', 'Code', 'Role', 'Difference', 'Root cause', 'Risk'],
                $driftRows->map(fn (array $row): array => [
                    $row['partner_id'],
                    $row['partner_code'],
                    $row['role'],
                    $row['difference'],
                    $row['root_cause'],
                    $row['risk_level'],
                ])->all(),
            );

            Log::warning('Debt integrity drift detected', [
                'checked_at' => $result['checked_at'],
                'total_checked' => $result['total_checked'],
                'material_drift' => $result['drift_detected'],
                'technical_warnings' => $result['technical_warnings'],
                'audit_errors' => $result['audit_errors'],
                'partner_ids' => $driftRows->pluck('partner_id')->all(),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function outputPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (str_contains($normalized, '..')) {
            throw new \RuntimeException('Invariant output cannot traverse parent directories.');
        }
        $absolute = preg_match('/^[A-Za-z]:\//', $normalized) === 1
            ? $normalized
            : str_replace('\\', '/', base_path($normalized));
        $root = rtrim(str_replace('\\', '/', storage_path('app/audits')), '/');
        if (! str_starts_with($absolute, $root.'/')) {
            throw new \RuntimeException('Invariant output must be under storage/app/audits.');
        }
        if (! str_ends_with(mb_strtolower($absolute), '.json')) {
            $absolute = rtrim($absolute, '/').'/invariants.json';
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $absolute);
    }
}
