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
            // Set serial_id đã sold trong timeline — dùng để bỏ qua repair trên serial đã bán
            $soldSerials = [];
            $skippedRepairs = 0;
            $skippedRepairTotal = 0.0;

            foreach ($events as $e) {
                switch ($e['kind']) {
                    case 'purchase':
                        $qty += $e['qty'];
                        $total += $e['qty'] * $e['unit_cost'];
                        $this->writeMovement($e['movement_id'], $e['unit_cost'], $qty, $total, $dry, $stats);
                        break;

                    case 'repair_in':
                        // Bỏ qua nếu task gắn với serial đã bán
                        if (!empty($e['serial_imei_id']) && isset($soldSerials[$e['serial_imei_id']])) {
                            $skippedRepairs++;
                            $skippedRepairTotal += $e['delta'];
                            break;
                        }
                        // Nhập bóc máy → tăng tồn kho + giá trị
                        $repairQty = (int) ($e['qty'] ?? 1);
                        $qty += $repairQty;
                        $total += $e['delta'];
                        break;

                    case 'repair_out':
                        if (!empty($e['serial_imei_id']) && isset($soldSerials[$e['serial_imei_id']])) {
                            $skippedRepairs++;
                            $skippedRepairTotal += $e['delta'];
                            break;
                        }
                        // Xuất sửa chữa → giảm tồn kho + giá trị
                        $repairQty = (int) ($e['qty'] ?? 1);
                        $qty = max(0, $qty - $repairQty);
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
                                $soldSerials[$sid] = true;
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
                        if (!empty($e['serial_imei_ids'])) {
                            foreach ($e['serial_imei_ids'] as $sid) {
                                unset($soldSerials[$sid]); // trả về in_stock
                            }
                        }
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
            if ($skippedRepairs > 0) {
                $this->warn(sprintf(
                    '    ⓘ Bỏ qua %d repair part trên serial đã bán (tổng %s) — không cộng vào BQ.',
                    $skippedRepairs,
                    number_format($skippedRepairTotal, 0)
                ));
            }

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

        // ═══════════════════════════════════════════════════════════════
        // STRATEGY: Rebuild LUÔN synthesize từ source tables (purchase_items,
        // invoice_items, return_items, etc.) — đây là source of truth duy nhất.
        // Chỉ đọc initial_balance movements từ DB (tạo bởi reconcile).
        // KHÔNG đọc in_purchase/out_invoice movements vì chúng có thể là
        // synthesized từ lần rebuild trước → gây đếm đôi.
        // ═══════════════════════════════════════════════════════════════

        $hasInitialBalance = false;

        // 1) Đọc initial_balance movements (từ reconcile)
        $initialMoves = StockMovement::where('product_id', $product->id)
            ->where('type', 'initial_balance')
            ->orderBy('moved_at')->orderBy('id')
            ->get();

        foreach ($initialMoves as $m) {
            $hasInitialBalance = true;
            $ts = $m->moved_at ?: $m->created_at;
            $events[] = [
                'kind' => 'purchase',
                'ts' => $ts,
                'sort_key' => $ts ? strtotime($ts) - 2 : 0, // -2 để xếp trước mọi thứ
                'movement_id' => $m->id,
                'qty' => (int) $m->qty,
                'unit_cost' => (float) $m->unit_cost,
            ];
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
            $tasksById = Task::whereIn('id', $taskIds)->get(['id', 'serial_imei_id'])->keyBy('id');
            $parts = TaskPart::whereIn('task_id', $taskIds)->orderBy('created_at')->orderBy('id')->get();
            foreach ($parts as $p) {
                $ts = $p->created_at;
                $kind = ($p->direction ?? 'export') === 'import' ? 'repair_in' : 'repair_out';
                $task = $tasksById->get($p->task_id);
                $events[] = [
                    'kind' => $kind,
                    'ts' => $ts,
                    'sort_key' => $ts ? strtotime($ts) : 0,
                    'qty' => (int) ($p->quantity ?? 1),
                    'delta' => (float) $p->total_cost,
                    'serial_imei_id' => $task?->serial_imei_id,
                    'task_id' => $p->task_id,
                ];
            }
        }

        // 3) LUÔN synthesize từ source tables — đây là source of truth duy nhất.
        //    (Không đọc in_purchase/out_invoice movements để tránh đếm đôi)

        // 3a) Purchase items → purchase events
        $purchaseItems = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchase_items.product_id', $product->id)
            ->where('purchases.status', 'completed')
            ->orderBy('purchases.created_at')
            ->get(['purchase_items.*', 'purchases.created_at as p_created_at']);
        foreach ($purchaseItems as $pi) {
            $unitCost = (float) ($pi->unit_cost_allocated ?? $pi->price ?? 0);
            if ($unitCost <= 0 || $pi->quantity <= 0) continue;
            $ts = $pi->p_created_at;
            $events[] = [
                'kind' => 'purchase',
                'ts' => $ts,
                'sort_key' => $ts ? strtotime($ts) - 1 : 0,
                'movement_id' => null,
                'qty' => (int) $pi->quantity,
                'unit_cost' => $unitCost,
            ];
        }

        // 3b) Invoice items → sale events
        $invItems = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.product_id', $product->id)
            ->where(function ($q) {
                $q->whereNull('invoices.status')
                  ->orWhere('invoices.status', '!=', 'cancelled');
            })
            ->orderBy('invoices.created_at')
            ->get(['invoice_items.*', 'invoices.created_at as i_created_at']);
        foreach ($invItems as $ii) {
            if ($ii->quantity <= 0) continue;
            $ts = $ii->i_created_at;
            $events[] = [
                'kind' => 'sale',
                'ts' => $ts,
                'sort_key' => $ts ? strtotime($ts) : 0,
                'movement_id' => null,
                'qty' => (int) $ii->quantity,
                'unit_cost' => (float) ($ii->cost_price ?? 0),
                'invoice_id' => (int) $ii->invoice_id,
                'invoice_item_id' => (int) $ii->id,
                'serial_imei_ids' => [],
            ];
        }

        // 3c) Return items → sale_return events
        $returnItems = DB::table('return_items')
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->where('return_items.product_id', $product->id)
            ->where(function ($q) {
                $q->where('returns.status', '!=', 'Đã hủy')
                  ->orWhereNull('returns.status');
            })
            ->orderBy('returns.created_at')
            ->get(['return_items.*', 'returns.created_at as r_created_at', 'returns.invoice_id']);
        foreach ($returnItems as $ri) {
            if ($ri->quantity <= 0) continue;
            $ts = $ri->r_created_at;
            $events[] = [
                'kind' => 'sale_return',
                'ts' => $ts,
                'sort_key' => $ts ? strtotime($ts) : 0,
                'movement_id' => null,
                'qty' => (int) $ri->quantity,
                'unit_cost' => (float) ($ri->cost_price ?? 0),
                'invoice_id' => $ri->invoice_id,
            ];
        }

        // 3d) Purchase return items → purchase_return events
        $prItems = DB::table('purchase_return_items')
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->where('purchase_return_items.product_id', $product->id)
            ->where('purchase_returns.status', 'completed')
            ->orderBy('purchase_returns.created_at')
            ->get(['purchase_return_items.*', 'purchase_returns.created_at as pr_created_at']);
        foreach ($prItems as $pri) {
            if ($pri->quantity <= 0) continue;
            $ts = $pri->pr_created_at;
            $events[] = [
                'kind' => 'purchase_return',
                'ts' => $ts,
                'sort_key' => $ts ? strtotime($ts) : 0,
                'movement_id' => null,
                'qty' => (int) $pri->quantity,
                'unit_cost' => (float) ($pri->cost_price ?? $pri->price ?? 0),
            ];
        }

        // 3e) Serial: bán hàng serial (nếu chưa có trong invoice_items)
        if ($product->has_serial) {
            $serials = SerialImei::where('product_id', $product->id)->get();
            foreach ($serials as $s) {
                if ($s->status === 'sold' && $s->invoice_id) {
                    $invoiceId = (int) $s->invoice_id;
                    // Check if invoice_item already covered this
                    $alreadyCovered = collect($invItems)->contains(fn($ii) => (int) $ii->invoice_id === $invoiceId);
                    if (!$alreadyCovered) {
                        [$invoiceItemId, $_] = $this->resolveInvoiceContext($product->id, $invoiceId, $s->id);
                        $ts = $s->sold_at ?: $s->updated_at;
                        $events[] = [
                            'kind' => 'sale',
                            'ts' => $ts,
                            'sort_key' => $ts ? strtotime($ts) : 0,
                            'movement_id' => null,
                            'qty' => 1,
                            'unit_cost' => (float) ($s->sold_cost_price ?? $s->cost_price ?? 0),
                            'invoice_id' => $invoiceId,
                            'invoice_item_id' => $invoiceItemId,
                            'serial_imei_ids' => [$s->id],
                        ];
                    }
                }
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

    private function writeMovement(?int $movementId, float $unitCost, int $balanceQty, float $balanceCost, bool $dry, array &$stats): void
    {
        if (!$movementId) return; // synthesized event — no movement row to update
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
