<?php

namespace App\Console\Commands;

use App\Models\SerialImei;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSerialCostFromTasks extends Command
{
    protected $signature = 'serial:sync-cost-from-tasks';
    protected $description = 'Sync serial cost_price from repair tasks, backfill original_cost, and fix product.cost_price';

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
        $this->info('=== Step 2: Sync serial.cost_price từ task.total_cost ===');

        $tasks = Task::where('type', 'repair')
            ->whereNotNull('serial_imei_id')
            ->whereIn('status', ['completed', 'in_progress', 'pending'])
            ->with('serialImei')
            ->get();

        $updated = 0;
        $affectedProductIds = collect();

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

            $affectedProductIds->push($serial->product_id);
        }

        // ── Step 3: Reset product.cost_price về giá nhập gốc (original_cost) ──
        $this->info('=== Step 3: Reset product.cost_price về giá nhập gốc ===');

        $productIds = $affectedProductIds->unique()->filter();
        foreach ($productIds as $productId) {
            $product = \App\Models\Product::find($productId);
            if (!$product) continue;

            // Lấy trung bình original_cost (giá nhập gốc) thay vì cost_price (giá cuối)
            $avgOriginalCost = SerialImei::where('product_id', $productId)
                ->where('status', 'in_stock')
                ->where('original_cost', '>', 0)
                ->avg('original_cost');

            if ($avgOriginalCost !== null) {
                $old = $product->cost_price;
                $product->cost_price = round((float) $avgOriginalCost, 0);
                $product->save();
                $this->info("Product #{$productId} ({$product->name}): cost_price {$old} → {$product->cost_price} (avg original_cost)");
            }
        }

        $this->info("Done! Updated {$updated} serial cost_price(s).");
    }
}
