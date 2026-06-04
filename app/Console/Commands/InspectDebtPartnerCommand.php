<?php

namespace App\Console\Commands;

use App\Services\DebtPartnerInspectionService;
use Illuminate\Console\Command;

class InspectDebtPartnerCommand extends Command
{
    protected $signature = 'debt:inspect-partner
        {--dry-run : Required. Read-only inspection, do not write DB}
        {--customer-id= : Customer/partner id to inspect}
        {--code= : Customer/supplier code to inspect}
        {--phone= : Phone to inspect}
        {--export= : Export JSON report path}
        {--pretty : Pretty print JSON}
        {--include-timeline : Include timeline service entries}
        {--include-raw : Include raw source records}';

    protected $description = 'Read-only drill-down inspection for one customer/supplier debt partner';

    public function handle(DebtPartnerInspectionService $service): int
    {
        if (!$this->option('dry-run')) {
            $this->error('This command is read-only. Please pass --dry-run. No data was modified.');
            return self::FAILURE;
        }

        $partner = $service->findPartner(
            $this->option('customer-id') ? (string) $this->option('customer-id') : null,
            $this->option('code') ? (string) $this->option('code') : null,
            $this->option('phone') ? (string) $this->option('phone') : null
        );

        if (!$partner) {
            $this->error('Partner not found. Pass --customer-id, --code, or --phone.');
            return self::FAILURE;
        }

        $payload = $service->inspect(
            $partner,
            (bool) $this->option('include-raw'),
            (bool) $this->option('include-timeline')
        );
        $json = $this->encodeJson($payload, (bool) $this->option('pretty'));

        if ($export = $this->option('export')) {
            $this->writeJsonFile((string) $export, $json);
            $this->info('Inspection exported: ' . $export);
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }

    private function encodeJson(array $payload, bool $pretty): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($payload, $flags) ?: '{}';
    }

    private function writeJsonFile(string $path, string $json): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            throw new \RuntimeException("Cannot prepare JSON export directory: {$dir}");
        }

        file_put_contents($path, $json . PHP_EOL);
    }
}
