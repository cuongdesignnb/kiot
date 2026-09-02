<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\SerialCostLifecycleRemediationPlanService;
use Illuminate\Console\Command;
use Throwable;

class BuildSerialCostLifecycleRemediationPlan extends Command
{
    protected $signature = 'costing:plan-serial-lifecycle-remediation
        {--product= : Optional product ID or SKU}
        {--json : Emit the complete guarded JSON plan}';

    protected $description = 'Read-only: plan evidence-backed sale-return-resale serial COGS remediation.';

    public function handle(SerialCostLifecycleRemediationPlanService $plans): int
    {
        $product = $this->resolveProduct((string) ($this->option('product') ?? ''));
        if ($this->option('product') && ! $product) {
            $this->error('Product not found.');

            return self::FAILURE;
        }

        try {
            $plan = $plans->build($product?->id);
            if ($this->option('json')) {
                $this->line(json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $summary = (array) $plan['summary'];
            $this->line('Read-only: yes');
            $this->line('Plan hash: '.$plan['plan_hash']);
            $this->line('Lifecycle lines to repair: '.$summary['repair_lines']);
            $this->line('Verified lines requiring no write: '.$summary['verified_lines']);
            $this->line('Blocked lifecycle lines: '.$summary['blocked_lines']);
            $this->line('Invoice serial links to update: '.$summary['invoice_item_serials_to_update']);
            $this->line('Return items to update: '.$summary['return_items_to_update']);
            $this->line('Current serial snapshots to update: '.$summary['current_serial_snapshots_to_update']);
            $this->line('Sale COGS delta: '.$summary['sale_cogs_delta']);
            $this->line('Return COGS delta: '.$summary['return_cogs_delta']);
            $this->line('Net report COGS delta: '.$summary['net_report_cogs_delta']);
            $this->warn('No database writes were made. Export with --json before creating approval.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveProduct(string $value): ?Product
    {
        if ($value === '') {
            return null;
        }

        return Product::query()->where('id', $value)->orWhere('sku', $value)->first();
    }
}
