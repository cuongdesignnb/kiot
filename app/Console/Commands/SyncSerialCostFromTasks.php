<?php

namespace App\Console\Commands;

use App\Models\SerialImei;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSerialCostFromTasks extends Command
{
    protected $signature = 'serial:sync-cost-from-tasks
        {--dry-run : Chỉ hiển thị thay đổi, không lưu DB}
        {--product= : Chỉ chạy cho 1 product_id}
        {--recompute-products : Sau khi sync, gọi recomputeFromSerials cho các product bị ảnh hưởng}';
    protected $description = 'Sync serial cost_price from repair tasks, backfill original_cost, and fix missing cost_price';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        $productFilter = $this->option('product');
        $recompute = (bool) $this->option('recompute-products');

        if ($dryRun) {
            $this->warn('[DRY-RUN] Không có thay đổi nào được lưu vào DB.');
        }
        if ($productFilter) {
            $this->info("Chỉ chạy cho product_id = {$productFilter}");
        }

        $affectedProductIds = [];

        // ── Step 1: Backfill original_cost từ purchase_items ──
        $this->info('=== Step 1: Backfill original_cost từ phiếu nhập ===');

        if (\Schema::hasColumn('serial_imeis', 'original_cost')) {
            $bindings = [];
            $where = "WHERE (s.original_cost = 0 OR s.original_cost IS NULL)";
            if ($productFilter) {
                $where .= " AND s.product_id = ?";
                $bindings[] = $productFilter;
            }
            if ($dryRun) {
                $count = DB::selectOne("SELECT COUNT(*) as c FROM serial_imeis s LEFT JOIN purchase_items pi ON pi.purchase_id = s.purchase_id AND pi.product_id = s.product_id $where", $bindings);
                $this->info("[DRY-RUN] Sẽ backfill original_cost cho {$count->c} serial(s).");
            } else {
                $backfilled = DB::update("
                    UPDATE serial_imeis
                    SET original_cost = COALESCE(
                        (SELECT pi.price FROM purchase_items pi
                         WHERE pi.purchase_id = serial_imeis.purchase_id
                           AND pi.product_id = serial_imeis.product_id LIMIT 1),
                        cost_price
                    )
                    " . str_replace('s.', '', $where), $bindings);
                $this->info("Backfilled original_cost cho {$backfilled} serial(s).");
            }
        } else {
            $this->warn("Cột original_cost chưa tồn tại. Hãy chạy php artisan migrate trước.");
        }

        // ── Step 2: Backfill cost_price cho serial CHƯA có repair task ──
        // Nếu serial chưa qua sửa chữa → cost_price = original_cost (giá nhập gốc)
        $this->info('=== Step 2: Backfill cost_price cho serial chưa sửa chữa ===');

        $q = SerialImei::where(function ($q) {
                $q->where('cost_price', 0)->orWhereNull('cost_price');
            });
        if ($productFilter) {
            $q->where('product_id', $productFilter);
        }
        $serialsNoCost = $q->get();

        $fixedCount = 0;
        foreach ($serialsNoCost as $serial) {
            // Lấy giá nhập từ purchase_items
            $purchasePrice = 0;
            if ($serial->purchase_id) {
                $purchasePrice = DB::table('purchase_items')
                    ->where('purchase_id', $serial->purchase_id)
                    ->where('product_id', $serial->product_id)
                    ->value('unit_cost_allocated') ?? DB::table('purchase_items')
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
                $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Serial {$serial->serial_number}: cost_price 0 → {$purchasePrice}");
                if (!$dryRun) {
                    $serial->cost_price = $purchasePrice;
                    if (\Schema::hasColumn('serial_imeis', 'original_cost') && ($serial->original_cost == 0 || $serial->original_cost === null)) {
                        $serial->original_cost = $purchasePrice;
                    }
                    $serial->save();
                }
                $affectedProductIds[$serial->product_id] = true;
                $fixedCount++;
            }
        }
        $this->info("Fixed cost_price cho {$fixedCount} serial(s) chưa có giá.");

        // ── Step 3: Sync cost_price từ task.total_cost (cho serial ĐÃ sửa chữa) ──
        $this->info('=== Step 3: Sync serial.cost_price từ task.total_cost ===');

        $tq = Task::where('type', 'repair')
            ->whereNotNull('serial_imei_id')
            ->whereIn('status', ['completed', 'in_progress', 'pending'])
            ->with('serialImei');
        if ($productFilter) {
            $tq->whereHas('serialImei', fn($q) => $q->where('product_id', $productFilter));
        }
        $tasks = $tq->get();

        $updated = 0;

        foreach ($tasks as $task) {
            $serial = $task->serialImei;
            if (!$serial) continue;

            if (!$dryRun) {
                $task->recalculateCosts();
            }

            $oldCost = (float) $serial->cost_price;
            $newCost = max(0, (float) $task->total_cost);

            if (abs($oldCost - $newCost) > 0.01) {
                $this->info(($dryRun ? '[DRY-RUN] ' : '') . "Serial {$serial->serial_number}: cost_price {$oldCost} → {$newCost} (Task {$task->code})");
                if (!$dryRun) {
                    $serial->cost_price = $newCost;
                    $serial->save();

                    // BQ DI ĐỘNG: nếu serial còn in_stock, propagate ΔC vào product.inventory_total_cost
                    // Sold serials không ảnh hưởng tồn kho hiện tại.
                    if ($serial->status === 'in_stock') {
                        $delta = $newCost - $oldCost;
                        $product = \App\Models\Product::find($serial->product_id);
                        if ($product) {
                            \App\Services\MovingAvgCostingService::applyRepairAdjustment($product, $delta);
                        }
                    }
                }
                $affectedProductIds[$serial->product_id] = true;
                $updated++;
            }
        }

        $this->info("Done! Updated {$updated} serial cost_price(s) từ repair tasks.");

        // ── Step 4: Sync stock_quantity audit từ serial in_stock count ──
        // (Cost đã được cập nhật từng bước qua MovingAvgCostingService trong Step 3.)
        if ($recompute && !empty($affectedProductIds)) {
            $this->info('=== Step 4: Sync stock_quantity audit (cost đã cập nhật ở Step 3) ===');
            foreach (array_keys($affectedProductIds) as $pid) {
                $product = \App\Models\Product::find($pid);
                if ($product && $product->has_serial) {
                    if ($dryRun) {
                        $this->info("[DRY-RUN] Sẽ sync stock_quantity product {$product->id} ({$product->name})");
                    } else {
                        $product->recomputeFromSerials();
                        $this->info("Synced product {$product->id} ({$product->name}): cost={$product->cost_price}, stock={$product->stock_quantity}, total={$product->inventory_total_cost}");
                    }
                }
            }
        }

        $this->info("=== Hoàn tất! ===");
    }
}
