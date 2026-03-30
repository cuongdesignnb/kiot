<?php

namespace App\Console\Commands;

use App\Models\SerialImei;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSerialCostFromTasks extends Command
{
    protected $signature = 'serial:sync-cost-from-tasks';
    protected $description = 'Sync serial cost_price from repair tasks, backfill original_cost, and fix missing cost_price';

    public function handle()
    {
        // ── Step 1: Backfill original_cost từ purchase_items ──
        $this->info('=== Step 1: Backfill original_cost từ phiếu nhập ===');

        if (\Schema::hasColumn('serial_imeis', 'original_cost')) {
            $backfilled = DB::update("
                UPDATE serial_imeis s
                LEFT JOIN purchase_items pi ON pi.purchase_id = s.purchase_id
                    AND pi.product_id = s.product_id
                SET s.original_cost = COALESCE(pi.price, s.cost_price)
                WHERE s.original_cost = 0 OR s.original_cost IS NULL
            ");
            $this->info("Backfilled original_cost cho {$backfilled} serial(s).");
        } else {
            $this->warn("Cột original_cost chưa tồn tại. Hãy chạy php artisan migrate trước.");
        }

        // ── Step 2: Backfill cost_price cho serial CHƯA có repair task ──
        // Nếu serial chưa qua sửa chữa → cost_price = original_cost (giá nhập gốc)
        $this->info('=== Step 2: Backfill cost_price cho serial chưa sửa chữa ===');

        $serialsNoCost = SerialImei::where(function ($q) {
                $q->where('cost_price', 0)->orWhereNull('cost_price');
            })
            ->get();

        $fixedCount = 0;
        foreach ($serialsNoCost as $serial) {
            // Lấy giá nhập từ purchase_items
            $purchasePrice = 0;
            if ($serial->purchase_id) {
                $purchasePrice = DB::table('purchase_items')
                    ->where('purchase_id', $serial->purchase_id)
                    ->where('product_id', $serial->product_id)
                    ->value('price') ?? 0;
            }

            // Fallback: lấy từ original_cost hoặc product.cost_price
            if ($purchasePrice <= 0 && \Schema::hasColumn('serial_imeis', 'original_cost')) {
                $purchasePrice = (float) ($serial->original_cost ?? 0);
            }
            if ($purchasePrice <= 0) {
                $product = \App\Models\Product::find($serial->product_id);
                $purchasePrice = $product ? (float) $product->cost_price : 0;
            }

            if ($purchasePrice > 0) {
                $serial->cost_price = $purchasePrice;
                if (\Schema::hasColumn('serial_imeis', 'original_cost') && ($serial->original_cost == 0 || $serial->original_cost === null)) {
                    $serial->original_cost = $purchasePrice;
                }
                $serial->save();
                $this->info("Serial {$serial->serial_number}: cost_price 0 → {$purchasePrice}");
                $fixedCount++;
            }
        }
        $this->info("Fixed cost_price cho {$fixedCount} serial(s) chưa có giá.");

        // ── Step 3: Sync cost_price từ task.total_cost (cho serial ĐÃ sửa chữa) ──
        $this->info('=== Step 3: Sync serial.cost_price từ task.total_cost ===');

        $tasks = Task::where('type', 'repair')
            ->whereNotNull('serial_imei_id')
            ->whereIn('status', ['completed', 'in_progress', 'pending'])
            ->with('serialImei')
            ->get();

        $updated = 0;

        foreach ($tasks as $task) {
            $serial = $task->serialImei;
            if (!$serial) continue;

            $task->recalculateCosts();

            $oldCost = (float) $serial->cost_price;
            $newCost = max(0, (float) $task->total_cost);

            if (abs($oldCost - $newCost) > 0.01) {
                $serial->cost_price = $newCost;
                $serial->save();

                $this->info("Serial {$serial->serial_number}: cost_price {$oldCost} → {$newCost} (Task {$task->code})");
                $updated++;
            }
        }

        $this->info("Done! Updated {$updated} serial cost_price(s) từ repair tasks.");
        $this->info("=== Hoàn tất! ===");
    }
}
