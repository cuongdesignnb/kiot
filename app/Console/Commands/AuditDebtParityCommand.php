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
        {--dry-run : Required read-only gate}
        {--role=all : all, customer, supplier or dual}
        {--partner-id= : Audit one local partner ID}
        {--only-mismatch : Exclude rows classified OK}
        {--export= : CSV path under storage/app/audits}
        {--json= : JSON path under storage/app/audits}';

    protected $description = 'Read-only parity audit across stored debt, document timelines and ledgers';

    public function handle(PartnerDebtParityAuditService $audit): int
    {
        if (!$this->option('dry-run')) {
            $this->error('Please pass --dry-run. This command never applies debt changes.');

            return self::FAILURE;
        }

        $role = (string) $this->option('role');
        if (!in_array($role, ['all', 'customer', 'supplier', 'dual'], true)) {
            $this->error('Invalid --role. Use all, customer, supplier or dual.');

            return self::FAILURE;
        }

        $rows = [];
        $scanned = 0;
        $this->partnerQuery($role)->chunkById(25, function ($partners) use ($audit, &$rows, &$scanned): void {
            foreach ($partners as $partner) {
                $scanned++;
                $row = $audit->audit($partner);
                if ($this->option('only-mismatch') && $row['primary_classification'] === 'OK') {
                    continue;
                }
                $rows[] = $row;
            }
        });

        if ($path = $this->option('export')) {
            $this->writeCsv($this->auditPath((string) $path), $rows);
        }
        if ($path = $this->option('json')) {
            $this->writeJson($this->auditPath((string) $path), $rows);
        }

        $this->printSummary($rows, $scanned);

        return collect($rows)->contains(fn (array $row): bool => $row['primary_classification'] === 'AUDIT_ERROR')
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function partnerQuery(string $role): Builder
    {
        $query = Customer::query()->orderBy('id');
        if ($id = $this->option('partner-id')) {
            return $query->whereKey((int) $id);
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
        if ($absolute !== $root && !str_starts_with($absolute, $root . '/')) {
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

    private function printSummary(array $rows, int $scanned): void
    {
        $classifications = collect($rows)->countBy('primary_classification')->sortKeys();
        $risks = collect($rows)->countBy('risk_level')->sortKeys();
        $this->info('Dry-run: yes');
        $this->info("Total scanned: {$scanned}");
        $this->info('Total exported: ' . count($rows));
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
