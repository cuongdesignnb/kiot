<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\SerialCostSnapshotAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class AuditSerialCostSnapshots extends Command
{
    protected $signature = 'costing:audit-serial-snapshots
        {--product= : Product ID or SKU}
        {--invoice= : Exact invoice code}
        {--all : Include rows that do not have an independent repair-cost source}
        {--limit=100 : Maximum rows to print; use 0 for all rows}
        {--json : Emit machine-readable output}';

    protected $description = 'Read-only audit: reconcile serial sale snapshots against completed repair cost evidence.';

    public function handle(): int
    {
        $product = $this->resolveProduct($this->option('product'));
        if ($this->option('product') && ! $product) {
            $this->error('Product not found.');

            return self::FAILURE;
        }

        $rows = app(SerialCostSnapshotAuditService::class)->inspect(
            $product?->id,
            $this->option('invoice'),
        );
        $summary = $this->summary($rows);
        $actionableRows = $rows->filter(fn (array $row) => $this->isActionable($row))->values();
        $displayRows = $this->option('all') ? $rows : $actionableRows;
        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $displayRows = $displayRows->take($limit)->values();
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'summary' => $summary,
                'rows' => $displayRows->all(),
                'read_only' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->line('Read-only: yes');
            $this->line('Completed normalized serial sale links: '.$summary['checked']);
            $this->line('Evidence-backed repair snapshots: '.$summary['with_repair_evidence']);
            $this->line('Verified repair snapshots: '.$summary['verified']);
            $this->line('Confirmed repair snapshot mismatches: '.$summary['confirmed_mismatch']);
            $this->line('Invoice lines with unprotected document/COGS mismatch: '.$summary['unprotected_financial_mismatch_invoice_lines']);
            $this->line('Serial rows with unprotected document/COGS mismatch: '.$summary['unprotected_financial_mismatch_rows']);
            $this->line('Protected resale invoice lines with document/COGS mismatch: '.$summary['protected_financial_mismatch_invoice_lines']);
            $this->line('Protected resale serial rows with document/COGS mismatch: '.$summary['protected_financial_mismatch_rows']);
            $this->line('Serial snapshot-only mismatches (document total matches): '.$summary['serial_snapshot_only_mismatch']);
            $this->line('Protected resale snapshot mismatches: '.$summary['protected_resale_mismatch']);
            $this->line('Stored snapshot conflicts without independent cost evidence: '.$summary['storage_conflict']);
            $this->line('No independent repair-cost evidence: '.$summary['no_independent_evidence']);

            if ($displayRows->isNotEmpty()) {
                $this->table(
                    ['invoice', 'item', 'serial', 'repair_ticket', 'expected', 'item_cost', 'link_cost', 'sold_cost', 'movement_cost', 'impact', 'classification', 'mismatch_fields'],
                    $displayRows->map(fn (array $row) => [
                        $row['invoice_code'],
                        $row['invoice_item_id'],
                        $row['serial_number'],
                        $row['repair_task_code'] ?? '-',
                        $this->money($row['expected_cost']),
                        $this->money($row['invoice_item_cost_price']),
                        $this->money($row['invoice_item_serial_cost_price']),
                        $this->money($row['serial_sold_cost_price']),
                        $this->money($row['stock_movement_cost']),
                        $row['impact_scope'],
                        $row['classification'],
                        implode(', ', $row['mismatch_types']),
                    ])->all(),
                );
            }
        }

        return $actionableRows->isEmpty() ? self::SUCCESS : self::FAILURE;
    }

    private function resolveProduct(?string $productOpt): ?Product
    {
        if (! $productOpt) {
            return null;
        }

        return Product::where('id', $productOpt)->orWhere('sku', $productOpt)->first();
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function summary(Collection $rows): array
    {
        $financialRows = $rows->filter(fn (array $row) => (bool) $row['financial_impact']);
        $unprotectedFinancialRows = $financialRows->filter(fn (array $row) => ! (bool) $row['line_resale_protected']);
        $protectedFinancialRows = $financialRows->filter(fn (array $row) => (bool) $row['line_resale_protected']);

        return [
            'checked' => $rows->count(),
            'with_repair_evidence' => $rows->whereNotNull('expected_cost')->count(),
            'verified' => $rows->where('classification', SerialCostSnapshotAuditService::VERIFIED_REPAIR_SNAPSHOT)->count(),
            'confirmed_mismatch' => $rows->where('classification', SerialCostSnapshotAuditService::REPAIR_COST_MISMATCH)->count(),
            'financial_mismatch_rows' => $financialRows->count(),
            'financial_mismatch_invoice_lines' => $financialRows
                ->pluck('invoice_item_id')
                ->unique()
                ->count(),
            'unprotected_financial_mismatch_rows' => $unprotectedFinancialRows->count(),
            'unprotected_financial_mismatch_invoice_lines' => $unprotectedFinancialRows
                ->pluck('invoice_item_id')
                ->unique()
                ->count(),
            'protected_financial_mismatch_rows' => $protectedFinancialRows->count(),
            'protected_financial_mismatch_invoice_lines' => $protectedFinancialRows
                ->pluck('invoice_item_id')
                ->unique()
                ->count(),
            'serial_snapshot_only_mismatch' => $rows
                ->filter(fn (array $row) => $row['classification'] === SerialCostSnapshotAuditService::REPAIR_COST_MISMATCH
                    && ! (bool) $row['financial_impact'])
                ->count(),
            'protected_resale_mismatch' => $rows->where('classification', SerialCostSnapshotAuditService::REPAIR_COST_MISMATCH_PROTECTED_RESALE)->count(),
            'storage_conflict' => $rows->where('classification', SerialCostSnapshotAuditService::SNAPSHOT_STORAGE_MISMATCH)->count(),
            'no_independent_evidence' => $rows->where('classification', SerialCostSnapshotAuditService::NO_INDEPENDENT_COST_EVIDENCE)->count(),
        ];
    }

    /** @param array<string, mixed> $row */
    private function isActionable(array $row): bool
    {
        return in_array($row['classification'], [
            SerialCostSnapshotAuditService::REPAIR_COST_MISMATCH,
            SerialCostSnapshotAuditService::REPAIR_COST_MISMATCH_PROTECTED_RESALE,
            SerialCostSnapshotAuditService::SNAPSHOT_STORAGE_MISMATCH,
        ], true);
    }

    private function money(?float $value): string
    {
        return $value === null ? '-' : number_format($value, 0, '.', ',');
    }
}
