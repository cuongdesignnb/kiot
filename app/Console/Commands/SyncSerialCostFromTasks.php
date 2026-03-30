<?php

namespace App\Console\Commands;

use App\Models\SerialImei;
use App\Models\Task;
use Illuminate\Console\Command;

class SyncSerialCostFromTasks extends Command
{
    protected $signature = 'serial:sync-cost-from-tasks';
    protected $description = 'Sync serial cost_price from their latest completed/in-progress repair task total_cost';

    public function handle()
    {
        // Lấy tất cả task repair có serial
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

                $this->info("Serial {$serial->serial_number}: {$oldCost} → {$newCost} (Task {$task->code})");
                $updated++;
            }
        }

        // Sync product average costs
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

        $this->info("Done! Updated {$updated} serial(s).");
    }
}
