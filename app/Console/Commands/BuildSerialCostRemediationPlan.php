<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\SerialCostRemediationPlanService;
use Illuminate\Console\Command;
use JsonException;

class BuildSerialCostRemediationPlan extends Command
{
    protected $signature = 'costing:plan-serial-remediation
        {--product= : Product ID or SKU}
        {--invoice= : Exact invoice code}
        {--json : Emit the guarded plan JSON for a reviewed artifact}';

    protected $description = 'Read-only: build a guarded, evidence-backed historical serial COGS remediation plan.';

    public function handle(SerialCostRemediationPlanService $plans): int
    {
        $product = $this->resolveProduct((string) ($this->option('product') ?? ''));
        if ($this->option('product') && ! $product) {
            $this->error('Product not found.');

            return self::FAILURE;
        }

        try {
            $plan = $plans->build($product?->id, $this->option('invoice'));
        } catch (JsonException $exception) {
            $this->error('Could not encode remediation plan: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $summary = (array) $plan['summary'];
        $this->line('Read-only: yes');
        $this->line('Plan hash: '.$plan['plan_hash']);
        $this->line('Auto-apply candidates: '.$summary['auto_apply_candidates']);
        $this->line('Manual-review lines: '.$summary['manual_review_lines']);
        $this->line('Blocked resale history: '.$summary['blocked_resale_history']);
        $this->line('Blocked completed return history: '.$summary['blocked_return_history']);
        $this->line('Blocked missing repair evidence: '.$summary['blocked_missing_repair_evidence']);
        $this->line('Serial snapshot only: '.$summary['serial_snapshot_only']);
        $this->line('Blocked stock movement: '.$summary['blocked_stock_movement']);
        if ($plan['repair_lines'] !== []) {
            $this->table(
                ['invoice', 'item', 'product', 'expected item cost', 'serials'],
                collect($plan['repair_lines'])->map(fn (array $line): array => [
                    $line['invoice_code'],
                    $line['invoice_item_id'],
                    $line['product_sku'],
                    number_format((int) data_get($line, 'expected.invoice_item_cost'), 0, '.', ','),
                    count((array) data_get($line, 'expected.serials', [])),
                ])->all(),
            );
        }
        $this->warn('No database writes were made. Export with --json, obtain accounting approval, then preview a selected batch.');

        return self::SUCCESS;
    }

    private function resolveProduct(string $product): ?Product
    {
        if ($product === '') {
            return null;
        }

        return Product::query()->where('id', $product)->orWhere('sku', $product)->first();
    }
}
