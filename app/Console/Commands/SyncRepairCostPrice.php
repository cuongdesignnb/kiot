<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use App\Models\Product;

class SyncRepairCostPrice extends Command
{
    protected $signature = 'repair:sync-cost';
    protected $description = 'Sync product cost_price with repair parts cost for existing tasks';

    public function handle()
    {
        $tasks = Task::where('type', 'repair')
            ->whereNotNull('product_id')
            ->where('parts_cost', '>', 0)
            ->with('parts')
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('No repair tasks with parts found.');
            return;
        }

        $updated = 0;
        foreach ($tasks as $task) {
            $partsCost = $task->parts->sum('total_cost');
            if ($partsCost <= 0) continue;

            if ($task->serial_imei_id) {
                // Sản phẩm có serial → cập nhật giá vốn serial
                $serial = \App\Models\SerialImei::find($task->serial_imei_id);
                if ($serial) {
                    $expectedCost = (float) $task->original_cost + $partsCost;
                    if ((float) $serial->cost_price < $expectedCost) {
                        $old = $serial->cost_price;
                        $serial->cost_price = $expectedCost;
                        $serial->save();
                        $this->line("  [{$task->code}] Serial #{$serial->serial_number}: {$old} → {$expectedCost}");
                        $updated++;
                    }
                }
            } elseif ($task->product_id) {
                // Sản phẩm không có serial → cập nhật giá vốn product
                $product = Product::find($task->product_id);
                if ($product) {
                    $expectedCost = (float) $task->original_cost + $partsCost;
                    if ((float) $product->cost_price < $expectedCost) {
                        $old = $product->cost_price;
                        $product->cost_price = $expectedCost;
                        $product->save();
                        $this->line("  [{$task->code}] {$product->name}: {$old} → {$expectedCost}");
                        $updated++;
                    }
                }
            }
        }

        $this->info("Done. Updated {$updated} product(s).");
    }
}
