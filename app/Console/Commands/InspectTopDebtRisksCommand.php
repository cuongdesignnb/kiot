<?php

namespace App\Console\Commands;

use App\Services\DebtPartnerInspectionService;
use Illuminate\Console\Command;

class InspectTopDebtRisksCommand extends Command
{
    protected $signature = 'debt:inspect-top-risks
        {--dry-run : Required}
        {--csv=storage/app/audits/debt-ledger-audit-mismatch.csv}
        {--limit=20}
        {--export-dir=storage/app/audits/debt-inspections}';

    protected $description = 'Read-only JSON drill-down for top debt audit risk rows';

    public function handle(DebtPartnerInspectionService $service): int
    {
        if (!$this->option('dry-run')) {
            $this->error('This command is read-only. Please pass --dry-run. No data was modified.');
            return self::FAILURE;
        }

        $csv = (string) $this->option('csv');
        if (!file_exists($csv)) {
            $this->error('CSV not found: ' . $csv);
            return self::FAILURE;
        }

        $exportDir = (string) $this->option('export-dir');
        $this->prepareDirectory($exportDir);

        $rows = array_slice($this->topRows($csv), 0, max(1, (int) $this->option('limit')));
        $exported = 0;

        foreach ($rows as $row) {
            $partner = $service->findPartner($row['id'] ?? null, $row['code'] ?? null, $row['phone'] ?? null);
            if (!$partner) {
                $this->warn('Partner not found for CSV row: ' . ($row['code'] ?? 'unknown'));
                continue;
            }

            $payload = $service->inspect($partner, true, true);
            $payload['audit_row'] = $row;
            $path = $exportDir . DIRECTORY_SEPARATOR . $this->fileName($partner->code, $partner->id);
            file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            $exported++;
            $this->line($path);
        }

        $this->info("Inspection files exported: {$exported}");

        return self::SUCCESS;
    }

    private function topRows(string $csv): array
    {
        $handle = fopen($csv, 'r');
        $headers = fgetcsv($handle) ?: [];
        $rows = [];

        while (($values = fgetcsv($handle)) !== false) {
            $row = array_combine($headers, $values);
            if (!$row) {
                continue;
            }
            $row['_risk_rank'] = $this->riskRank((string) ($row['risk_level'] ?? 'LOW'));
            $row['_amount'] = $this->riskAmount($row);
            $rows[] = $row;
        }

        fclose($handle);

        usort($rows, fn (array $a, array $b) => [$b['_risk_rank'], $b['_amount']] <=> [$a['_risk_rank'], $a['_amount']]);

        return $rows;
    }

    private function riskRank(string $risk): int
    {
        return match ($risk) {
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

    private function prepareDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            throw new \RuntimeException("Cannot prepare JSON export directory: {$dir}");
        }
    }

    private function fileName(?string $code, int $id): string
    {
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($code ?: 'partner'));
        $base = trim((string) $base, '-') ?: 'partner';

        return $base . '-' . $id . '.json';
    }
}
