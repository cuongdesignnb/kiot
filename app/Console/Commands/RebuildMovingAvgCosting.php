<?php

namespace App\Console\Commands;

use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\Task;
use App\Models\TaskPart;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuild giá vốn bình quân di động (moving weighted average) cho toàn bộ
 * lịch sử của 1 hoặc nhiều sản phẩm theo chuẩn KiotViet.
 *
 * Replay timeline gồm:
 *  - stock_movements: in_purchase, out_invoice, in_invoice_return, out_purchase_return
 *  - task_parts: direction=in (lắp linh kiện) / direction=out (tháo linh kiện)
 *
 * Cập nhật:
 *  - products.stock_quantity, cost_price (BQ), inventory_total_cost
 *  - stock_movements.unit_cost, total_cost, balance_qty, balance_cost
 *  - invoice_items.cost_price (per-unit COGS = BQ tại lúc bán)
 *  - invoice_item_serials.cost_price
 *  - serial_imeis.sold_cost_price
 *
 * Chạy DRY-RUN trước:
 *   php artisan costing:rebuild-moving-avg --product=ID --dry-run
 *   php artisan costing:rebuild-moving-avg --all --dry-run
 *
 * Chạy thật:
 *   php artisan costing:rebuild-moving-avg --product=ID
 *   php artisan costing:rebuild-moving-avg --all
 */
class RebuildMovingAvgCosting extends Command
{
    protected $signature = 'costing:rebuild-moving-avg
        {--product= : Product ID hoặc SKU}
        {--all : Rebuild toàn bộ sản phẩm}
        {--dry-run : Chỉ tính toán, không ghi DB}';

    protected $description = 'Rebuild giá vốn bình quân di động (moving avg) từ lịch sử ledger + task_parts';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $all = (bool) $this->option('all');
        $productOpt = $this->option('product');

        if (!$all && !$productOpt) {
            $this->error('Phải cung cấp --product=ID|SKU hoặc --all');
            return self::FAILURE;
        }

        $query = Product::query();
        if (!$all) {
            $query->where(function ($q) use ($productOpt) {
                $q->where('id', $productOpt)->orWhere('sku', $productOpt);
            });
        }
        $products = $query->orderBy('id')->get();
        if ($products->isEmpty()) {
            $this->error('Không tìm thấy sản phẩm.');
            return self::FAILURE;
        }

        $this->info(($dry ? '[DRY-RUN] ' : '') . 'Rebuild ' . $products->count() . ' sản phẩm');

        $totals = ['products' => 0, 'movements_updated' => 0, 'invoice_items_updated' => 0, 'serials_updated' => 0];

        foreach ($products as $product) {
            $stats = $this->rebuildOne($product, $dry);
            $totals['products']++;
            $totals['movements_updated'] += $stats['movements_updated'];
            $totals['invoice_items_updated'] += $stats['invoice_items_updated'];
            $totals['serials_updated'] += $stats['serials_updated'];
        }

        $this->newLine();
        $this->info('───────── TỔNG KẾT ─────────');
        $this->line('Sản phẩm: ' . $totals['products']);
        $this->line('Stock movements cập nhật: ' . $totals['movements_updated']);
        $this->line('Invoice items cập nhật: ' . $totals['invoice_items_updated']);
        $this->line('Serials cập nhật: ' . $totals['serials_updated']);
        if ($dry) {
            $this->warn('DRY-RUN: KHÔNG có gì được ghi vào DB.');
        }
        return self::SUCCESS;
    }

    /** @return array{movements_updated:int,invoice_items_updated:int,serials_updated:int} */
    private function rebuildOne(Product $product, bool $dry): array
    {
        $stats = ['movements_updated' => 0, 'invoice_items_updated' => 0, 'serials_updated' => 0];

        // Build event timeline
        $events = $this->buildTimeline($product);
        if (empty($events)) {
            $this->line(sprintf('  • #%d %s: không có lịch sử, bỏ qua.', $product->id, $product->sku));
            return $stats;
        }

        $cb = function () use ($product, $events, $dry, &$stats) {
            $qty = 0;
            $total = 0.0;
            // map[invoice_id] = ['cogs_per_unit' => float, 'qty' => int] để hoàn lại đúng số khi return
            $invoiceCogsMap = [];

            foreach ($events as $e) {
                switch ($e['kind']) {
                    case 'purchase':
                        $qty += $e['qty'];
                        $total += $e['qty'] * $e['unit_cost'];
                        $this->writeMovement($e['movement_id'], $e['unit_cost'], $qty, $total, $dry, $stats);
                        break;

                    case 'repair_in':
                        $total += $e['delta'];
                        // qty unchanged
                        break;

                    case 'repair_out':
                        $total -= $e['delta'];
                        if ($total < 0) $total = 0;
                        break;

                    case 'sale':
                        $bq = $qty > 0 ? ($total / $qty) : 0;
                        $cogsTotal = $bq * $e['qty'];
                        $total = max(0, $total - $cogsTotal);
                        $qty = max(0, $qty - $e['qty']);
                        $this->writeMovement($e['movement_id'], $bq, $qty, $total, $dry, $stats);
                        $this->writeInvoiceItemCost($e['invoice_item_id'], $bq, $dry, $stats);
                        if (!empty($e['serial_imei_ids'])) {
                            foreach ($e['serial_imei_ids'] as $sid) {
                                $this->writeSerialSoldCost($sid, $bq, $dry, $stats);
                                $this->writeInvoiceItemSerialCost($e['invoice_item_id'], $sid, $bq, $dry, $stats);
                            }
                        }
                        $invoiceCogsMap[$e['invoice_id']] = ['cogs_per_unit' => $bq];
                        break;

                    case 'sale_return':
                        $cogs = $invoiceCogsMap[$e['invoice_id']]['cogs_per_unit'] ?? ($e['unit_cost'] ?: 0);
                        $total += $e['qty'] * $cogs;
                        $qty += $e['qty'];
                        $this->writeMovement($e['movement_id'], $cogs, $qty, $total, $dry, $stats);
                        break;

                    case 'purchase_return':
                        $unitCost = $e['unit_cost'];
                        $total = max(0, $total - $e['qty'] * $unitCost);
                        $qty = max(0, $qty - $e['qty']);
                        $this->writeMovement($e['movement_id'], $unitCost, $qty, $total, $dry, $stats);
                        break;
                }
            }

            // Cập nhật product cuối cùng
            $bq = $qty > 0 ? round($total / $qty, 2) : 0;
            $this->line(sprintf(
                '  • #%d %s: qty=%d total=%s BQ=%s (cũ: qty=%d BQ=%s total=%s)',
                $product->id,
                $product->sku,
                $qty,
                number_format($total, 2),
                number_format($bq, 2),
                $product->stock_quantity,
                number_format((float) $product->cost_price, 2),
                number_format((float) $product->inventory_total_cost, 2)
            ));

            if (!$dry) {
                $product->stock_quantity = $qty;
                $product->cost_price = $bq;
                $product->inventory_total_cost = round($total, 2);
                $product->saveQuietly();
            }
        };

        if ($dry) {
            $cb();
        } else {
            DB::transaction($cb);
        }

        return $stats;
    }

    /**
     * Build sự kiện timeline cho 1 product, sắp xếp theo thời gian rồi id.
     * @return array<int, array<string, mixed>>
     */
    private function buildTimeline(Product $product): array
    {
        $events = [];

        // 1) stock_movements
        $movs = StockMovement::where('product_id', $product->id)
            ->orderBy('moved_at')->orderBy('id')
            ->get();
        foreach ($movs as $m) {
            $ts = $m->moved_at ?: $m->created_at;
            $base = [
                'ts' => $ts,
                'sort_key' => $ts ? strtotime($ts) : 0,
                'movement_id' => $m->id,
                'qty' => (int) $m->qty,
                'unit_cost' => (float) $m->unit_cost,
            ];
            switch ($m->type) {
                case 'in_purchase':
                    $events[] = $base + ['kind' => 'purchase'];
                    break;
                case 'out_invoice':
                    // tìm invoice_item & serials để cập nhật COGS
                    $invoiceId = $m->ref_id;
                    [$invoiceItemId, $serialIds] = $this->resolveInvoiceContext($product->id, $invoiceId, $m->serial_imei_id);
                    $events[] = $base + [
                        'kind' => 'sale',
                        'invoice_id' => $invoiceId,
                        'invoice_item_id' => $invoiceItemId,
                        'serial_imei_ids' => $serialIds,
                    ];
                    break;
                case 'in_invoice_return':
                    // ref là OrderReturn / Invoice tùy controller. Cần map về invoice_id để lookup COGS.
                    $events[] = $base + [
                        'kind' => 'sale_return',
                        'invoice_id' => $this->resolveReturnInvoiceId($m),
                    ];
                    break;
                case 'out_purchase_return':
                    $events[] = $base + ['kind' => 'purchase_return'];
                    break;
                // adjust/transfer/repair_* hiện không được record bởi service hiện tại; bỏ qua an toàn
            }
        }

        // 2) task_parts (repair adjustments) — không phản ánh trong stock_movements
        // Chỉ lấy parts của task có product_id hoặc serial_imei_id thuộc về product này.
        $taskQuery = Task::query()
            ->where(function ($q) use ($product) {
                $q->where('product_id', $product->id);
                // Nếu có serial: task gắn với serial của product này
                $q->orWhereHas('serialImei', fn($s) => $s->where('product_id', $product->id));
            });
        $taskIds = $taskQuery->pluck('id')->toArray();
        if (!empty($taskIds)) {
            $parts = TaskPart::whereIn('task_id', $taskIds)->orderBy('created_at')->orderBy('id')->get();
            foreach ($parts as $p) {
                $ts = $p->created_at;
                $kind = ($p->direction ?? 'in') === 'out' ? 'repair_out' : 'repair_in';
                $events[] = [
                    'kind' => $kind,
                    'ts' => $ts,
                    'sort_key' => $ts ? strtotime($ts) : 0,
                    'delta' => (float) $p->total_cost,
                ];
            }
        }

        // Sort: theo timestamp, sau đó theo loại (repair trước sale cùng giây để parts vô tồn rồi mới bán)
        $orderRank = ['purchase' => 0, 'repair_in' => 1, 'repair_out' => 1, 'sale' => 2, 'sale_return' => 3, 'purchase_return' => 4];
        usort($events, function ($a, $b) use ($orderRank) {
            if ($a['sort_key'] !== $b['sort_key']) return $a['sort_key'] <=> $b['sort_key'];
            $ra = $orderRank[$a['kind']] ?? 99;
            $rb = $orderRank[$b['kind']] ?? 99;
            return $ra <=> $rb;
        });

        return $events;
    }

    /** @return array{0:?int, 1:array<int,int>} [invoice_item_id, [serial_imei_ids]] */
    private function resolveInvoiceContext(int $productId, ?int $invoiceId, ?int $serialId): array
    {
        if (!$invoiceId) return [null, []];
        $item = InvoiceItem::where('invoice_id', $invoiceId)->where('product_id', $productId)->first();
        if (!$item) return [null, []];

        $serialIds = [];
        if ($serialId) {
            $serialIds[] = $serialId;
        } else {
            // hàng không serial: bỏ trống
        }
        return [$item->id, $serialIds];
    }

    private function resolveReturnInvoiceId(StockMovement $m): ?int
    {
        // ref_type có thể là OrderReturn hoặc Invoice. Nếu OrderReturn → lookup invoice_id.
        if (!$m->ref_id || !$m->ref_type) return null;
        if (str_contains((string) $m->ref_type, 'OrderReturn') || str_contains((string) $m->ref_type, 'Return')) {
            $row = DB::table('returns')->where('id', $m->ref_id)->first();
            return $row?->invoice_id;
        }
        if (str_contains((string) $m->ref_type, 'Invoice')) {
            return (int) $m->ref_id;
        }
        return null;
    }

    private function writeMovement(int $movementId, float $unitCost, int $balanceQty, float $balanceCost, bool $dry, array &$stats): void
    {
        if ($dry) { $stats['movements_updated']++; return; }
        $totalCost = $unitCost * (StockMovement::find($movementId)?->qty ?? 0);
        StockMovement::where('id', $movementId)->update([
            'unit_cost' => round($unitCost, 0),
            'total_cost' => round($totalCost, 0),
            'balance_qty' => $balanceQty,
            'balance_cost' => round($balanceQty > 0 ? $balanceCost / $balanceQty : 0, 0),
        ]);
        $stats['movements_updated']++;
    }

    private function writeInvoiceItemCost(?int $invoiceItemId, float $bq, bool $dry, array &$stats): void
    {
        if (!$invoiceItemId) return;
        if ($dry) { $stats['invoice_items_updated']++; return; }
        InvoiceItem::where('id', $invoiceItemId)->update(['cost_price' => round($bq, 0)]);
        $stats['invoice_items_updated']++;
    }

    private function writeInvoiceItemSerialCost(?int $invoiceItemId, int $serialId, float $bq, bool $dry, array &$stats): void
    {
        if (!$invoiceItemId) return;
        if ($dry) return;
        InvoiceItemSerial::where('invoice_item_id', $invoiceItemId)
            ->where('serial_imei_id', $serialId)
            ->update(['cost_price' => round($bq, 0)]);
    }

    private function writeSerialSoldCost(int $serialId, float $bq, bool $dry, array &$stats): void
    {
        if ($dry) { $stats['serials_updated']++; return; }
        SerialImei::where('id', $serialId)
            ->whereNotNull('sold_at')
            ->update(['sold_cost_price' => round($bq, 0)]);
        $stats['serials_updated']++;
    }
}
