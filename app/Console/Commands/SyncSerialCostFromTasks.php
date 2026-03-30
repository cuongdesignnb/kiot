<?php

namespace App\Console\Commands;

use App\Models\SerialImei;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSerialCostFromTasks extends Command
{
    protected $signature = 'serial:sync-cost-from-tasks';
    protected $description = 'Sync serial cost_price from their latest repair task total_cost, and backfill original_cost from purchase items';

    public function handle()
    {
        // ── Step 1: Backfill original_cost từ purchase_items ──
        $this->info('=== Step 1: Backfill original_cost từ phiếu nhập ===');

        $backfilled = DB::update("
            UPDATE serial_imeis s
            LEFT JOIN purchase_items pi ON pi.purchase_id = s.purchase_id
                AND pi.product_id = s.product_id
            SET s.original_cost = COALESCE(pi.price, s.cost_price)
            WHERE s.original_cost = 0 OR s.original_cost IS NULL
        ");
        $this->info("Backfilled original_cost cho {$backfilled} serial(s).");

        // ── Step 2: Sync cost_price từ task.total_cost ──
        $this->info('=== Step 2: Sync cost_price từ task.total_cost ===');

        $tasks = Task::where('type', 'repair')
            ->whereNotNull('serial_imei_id')
            ->whereIn('status', ['completed', 'in_progress', 'pending'])
            ->with('serialImei')
            ->get();

        $updated = 0;

        foreach ($tasks as $task) {
            $serial = $task->serialImei;
            if (!$serial) continue;

            // Recalculate task costs from parts
            $task->recalculateCosts();

            $oldCost = $serial->cost_price;
            $newCost = max(0, (float) $task->total_cost);

            if (abs($oldCost - $newCost) > 0.01) {
                $serial->cost_price = $newCost;
                $serial->save();

                $this->info("Serial {$serial->serial_number}: cost_price {$oldCost} → {$newCost} (Task {$task->code})");
                $updated++;
            }
        }

        // ── Step 3: Sync product average costs ──
        $this->info('=== Step 3: Sync product cost (avg of serials) ===');

        $productIds = $tasks->pluck('product_id')->unique()->filter();
        foreach ($productIds as $productId) {
            $product = \App\Models\Product::find($productId);
            if (!$product) continue;

            $avgCost = SerialImei::where('product_id', $productId)
                ->where('status', 'in_stock')
                ->where('cost_price', '>', 0)
                ->avg('cost_price');

            if ($avgCost !== null) {
                $old = $product->cost_price;
                $product->cost_price = round((float) $avgCost, 0);
                $product->save();
                $this->info("Product #{$productId} ({$product->name}): cost_price {$old} → {$product->cost_price}");
            }
        }

        $this->info("Done! Updated {$updated} serial cost_price(s).");
    }
}
