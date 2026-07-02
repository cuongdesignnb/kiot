<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconcile tồn kho — tạo phiếu "Tồn đầu kỳ" cho sản phẩm thiếu lịch sử nhập hàng.
 *
 * Phát hiện SP có stock nhưng không có Purchase gốc → tạo StockMovement "initial_balance"
 * để thẻ kho có đúng running balance từ đầu.
 *
 * Chạy:
 *   php artisan inventory:reconcile --dry-run      → xem trước, không ghi DB
 *   php artisan inventory:reconcile                → tạo thật
 *   php artisan inventory:reconcile --product=ID   → chỉ 1 SP
 */
class ReconcileInventory extends Command
{
    protected $signature = 'inventory:reconcile
        {--product= : Product ID hoặc SKU}
        {--dry-run : Chỉ xem trước, không ghi DB}
        {--force : Xóa initial_balance cũ rồi tạo lại}';

    protected $description = 'Tạo phiếu "Tồn đầu kỳ" cho SP thiếu lịch sử nhập hàng';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $productOpt = $this->option('product');

        $query = Product::query();
        if ($productOpt) {
            $query->where(function ($q) use ($productOpt) {
                $q->where('id', $productOpt)->orWhere('sku', $productOpt);
            });
        }
        $products = $query->orderBy('id')->get();

        if ($products->isEmpty()) {
            $this->error('Không tìm thấy sản phẩm.');
            return self::FAILURE;
        }

        $this->info(($dry ? '[DRY-RUN] ' : '') . "Reconcile {$products->count()} sản phẩm...");
        $this->newLine();

        $created = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($products as $product) {
            try {
                $result = $this->reconcileOne($product, $dry, $force);
                if ($result === 'created') {
                    $created++;
                } elseif ($result === 'skipped') {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $errors++;
                $this->error("  ✗ #{$product->id} {$product->sku}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info('═══════════ TỔNG KẾT ═══════════');
        $this->line("Tổng SP: {$products->count()}");
        $this->line("  📝 Tạo tồn đầu kỳ: {$created}");
        $this->line("  ⏭ Bỏ qua (đã đúng): {$skipped}");
        if ($errors > 0) $this->line("  ❌ Lỗi: {$errors}");
        if ($dry) $this->warn('DRY-RUN: Không ghi DB.');

        return self::SUCCESS;
    }

    private function reconcileOne(Product $product, bool $dry, bool $force): string
    {
        // Bước 1: Tính tồn kho từ lịch sử giao dịch (purchases, sales, returns, etc.)
        $calcQty = $this->calculateFromTransactions($product);

        // Bước 2: Lấy tồn kho đúng — ưu tiên:
        //   - Hàng serial: đếm serial in_stock
        //   - Hàng thường: product.stock_quantity (đã rebuild)
        $targetQty = $product->has_serial
            ? SerialImei::where('product_id', $product->id)->where('status', 'in_stock')->count()
            : (int) $product->stock_quantity;

        // Bước 3: Tính gap = target - calculated
        // Gap > 0 = thiếu nhập (cần tạo tồn đầu kỳ)
        // Gap < 0 = thừa xuất (cần tạo điều chỉnh giảm)
        $gap = $targetQty - $calcQty;

        // Kiểm tra nếu đã có initial_balance trước đó
        $existingInitial = StockMovement::where('product_id', $product->id)
            ->where('type', 'initial_balance')
            ->first();

        if ($existingInitial && !$force) {
            // Tính lại gap có tính cả initial_balance cũ
            $calcWithInitial = $calcQty + (int) $existingInitial->qty;
            $gapWithInitial = $targetQty - $calcWithInitial;

            if ($gapWithInitial === 0) {
                $this->line(sprintf(
                    '  ⏭ #%d %s — đã có tồn đầu kỳ (%d), thẻ kho đúng.',
                    $product->id, $product->sku, $existingInitial->qty
                ));
                return 'skipped';
            }

            // Nếu vẫn sai, cần force để tạo lại
            $this->warn(sprintf(
                '  ⚠ #%d %s — có tồn đầu kỳ cũ (%d) nhưng vẫn lệch %d. Dùng --force để tạo lại.',
                $product->id, $product->sku, $existingInitial->qty, $gapWithInitial
            ));
            return 'skipped';
        }

        if ($gap === 0 && !$existingInitial) {
            $this->line(sprintf(
                '  ✅ #%d %s — thẻ kho đã khớp (qty=%d), không cần tồn đầu kỳ.',
                $product->id, $product->sku, $targetQty
            ));
            return 'skipped';
        }

        // Tính giá vốn cho tồn đầu kỳ
        $unitCost = $this->resolveInitialCostPrice($product);

        // Force: xóa initial cũ
        if ($existingInitial && $force && !$dry) {
            $existingInitial->delete();
            $this->line(sprintf(
                '    🗑 Xóa tồn đầu kỳ cũ: qty=%d, unit_cost=%s',
                $existingInitial->qty, number_format((float) $existingInitial->unit_cost)
            ));
        }

        // Gap âm → cần điều chỉnh giảm? Hiếm trường hợp, nhưng xử lý
        $absGap = abs($gap);

        if ($gap > 0) {
            // Thiếu nhập → tạo tồn đầu kỳ (direction: in)
            $this->info(sprintf(
                '  📝 #%d %s "%s" — tạo TỒN ĐẦU KỲ: +%d (giá vốn: %s)',
                $product->id, $product->sku, mb_substr($product->name, 0, 30),
                $absGap, number_format($unitCost)
            ));

            if (!$dry) {
                $this->createInitialBalance($product, $absGap, $unitCost, 'in');
            }
        } else {
            // Thừa xuất → tạo điều chỉnh giảm (direction: out)
            $this->warn(sprintf(
                '  📝 #%d %s "%s" — tạo ĐIỀU CHỈNH GIẢM: -%d (giá vốn: %s)',
                $product->id, $product->sku, mb_substr($product->name, 0, 30),
                $absGap, number_format($unitCost)
            ));

            if (!$dry) {
                $this->createInitialBalance($product, $absGap, $unitCost, 'out');
            }
        }

        // Cập nhật product.stock_quantity = targetQty nếu chưa đúng
        if (!$dry && (int) $product->stock_quantity !== $targetQty) {
            $product->stock_quantity = $targetQty;
            $product->saveQuietly();
        }

        return 'created';
    }

    /**
     * Tính tồn kho chỉ từ các giao dịch thực (không tính initial_balance).
     */
    private function calculateFromTransactions(Product $product): int
    {
        $qty = 0;

        // Nhập hàng (purchase_items từ phiếu completed)
        $qty += (int) DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchase_items.product_id', $product->id)
            ->where('purchases.status', 'completed')
            ->sum('purchase_items.quantity');

        // Bán hàng (invoice_items từ invoice chưa hủy)
        $qty -= (int) DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.product_id', $product->id)
            ->where(function ($q) {
                $q->whereNull('invoices.status')
                  ->orWhere('invoices.status', '!=', 'cancelled');
            })
            ->sum('invoice_items.quantity');

        // Khách trả hàng
        $qty += (int) DB::table('return_items')
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->where('return_items.product_id', $product->id)
            ->where(function ($q) {
                $q->where('returns.status', '!=', 'Đã hủy')
                  ->orWhereNull('returns.status');
            })
            ->sum('return_items.quantity');

        // Trả NCC
        $qty -= (int) DB::table('purchase_return_items')
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->where('purchase_return_items.product_id', $product->id)
            ->where('purchase_returns.status', 'completed')
            ->sum('purchase_return_items.quantity');

        // Kiểm kho
        $stockTakeDiff = DB::table('stock_take_items')
            ->join('stock_takes', 'stock_takes.id', '=', 'stock_take_items.stock_take_id')
            ->where('stock_take_items.product_id', $product->id)
            ->where(function ($q) {
                $q->where('stock_takes.status', 'completed')
                  ->orWhereNull('stock_takes.status');
            })
            ->selectRaw('SUM(stock_take_items.actual_stock - stock_take_items.system_stock) as diff')
            ->value('diff');
        $qty += (int) $stockTakeDiff;

        // Xuất hủy
        $qty -= (int) DB::table('damage_items')
            ->join('damages', 'damages.id', '=', 'damage_items.damage_id')
            ->where('damage_items.product_id', $product->id)
            ->sum('damage_items.qty');

        // Chuyển kho
        $qty -= (int) DB::table('stock_transfer_items')
            ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_items.stock_transfer_id')
            ->where('stock_transfer_items.product_id', $product->id)
            ->sum('stock_transfer_items.quantity');

        // Sửa chữa (task_parts)
        if (DB::getSchemaBuilder()->hasTable('task_parts')) {
            $repairIn = (int) DB::table('task_parts')
                ->where('product_id', $product->id)
                ->where('direction', 'import')
                ->sum('quantity');
            $repairOut = (int) DB::table('task_parts')
                ->where('product_id', $product->id)
                ->where(function ($q) {
                    $q->where('direction', 'export')
                      ->orWhereNull('direction');
                })
                ->sum('quantity');
            $qty += $repairIn;
            $qty -= $repairOut;
        }

        return $qty;
    }

    /**
     * Xác định giá vốn cho tồn đầu kỳ.
     */
    private function resolveInitialCostPrice(Product $product): float
    {
        // Ưu tiên 1: BQ hiện tại từ product (đã rebuild)
        if ($product->cost_price > 0) {
            return (float) $product->cost_price;
        }

        // Ưu tiên 2: Trung bình giá vốn serial
        if ($product->has_serial) {
            $avgCost = SerialImei::where('product_id', $product->id)
                ->where('cost_price', '>', 0)
                ->avg('cost_price');
            if ($avgCost > 0) return (float) $avgCost;
        }

        // Ưu tiên 3: Giá bán (nếu không có gì)
        return (float) ($product->price ?? 0);
    }

    /**
     * Tạo StockMovement "initial_balance" và ghi phiếu nhập tồn đầu kỳ.
     */
    private function createInitialBalance(Product $product, int $qty, float $unitCost, string $direction): void
    {
        // Tìm thời điểm sớm nhất của SP này (để đặt tồn đầu kỳ TRƯỚC mọi giao dịch)
        $earliestDate = $this->getEarliestTransactionDate($product);

        // Đặt tồn đầu kỳ 1 giây trước giao dịch đầu tiên, hoặc thời điểm tạo SP
        $initialDate = $earliestDate
            ? $earliestDate->copy()->subSecond()
            : ($product->created_at ?? now());

        $type = $direction === 'in' ? 'initial_balance' : 'adjust_out';

        StockMovement::create([
            'product_id' => $product->id,
            'type' => $type,
            'direction' => $direction,
            'qty' => $qty,
            'unit_cost' => round($unitCost, 0),
            'total_cost' => round($qty * $unitCost, 0),
            'balance_qty' => $direction === 'in' ? $qty : -$qty,
            'balance_cost' => round($unitCost, 0),
            'ref_type' => null,
            'ref_id' => null,
            'ref_code' => 'TDK-' . $product->sku,
            'note' => 'Tồn đầu kỳ - Reconcile tự động',
            'moved_at' => $initialDate,
        ]);
        // NOTE: Chỉ tạo 1 record aggregate. Rebuild command sẽ đánh dấu tất cả serial covered.
    }

    /**
     * Tạo StockMovement cho từng serial chưa có movement gốc.
     */
    private function createSerialInitialMovements(Product $product, float $unitCost, $initialDate): void
    {
        $serials = SerialImei::where('product_id', $product->id)->get();

        // Kiểm tra serial nào đã có movement
        $coveredSerialIds = StockMovement::where('product_id', $product->id)
            ->whereIn('type', ['in_purchase', 'initial_balance'])
            ->whereNotNull('serial_imei_id')
            ->pluck('serial_imei_id')
            ->toArray();

        foreach ($serials as $serial) {
            if (in_array($serial->id, $coveredSerialIds)) continue;

            $cost = (float) ($serial->original_cost ?? $serial->cost_price ?? $unitCost);

            StockMovement::create([
                'product_id' => $product->id,
                'serial_imei_id' => $serial->id,
                'type' => 'initial_balance',
                'direction' => 'in',
                'qty' => 1,
                'unit_cost' => round($cost, 0),
                'total_cost' => round($cost, 0),
                'balance_qty' => 0, // sẽ được rebuild tính lại
                'balance_cost' => 0,
                'ref_code' => 'TDK-' . $serial->serial_number,
                'note' => 'Tồn đầu kỳ serial - ' . $serial->serial_number,
                'moved_at' => $serial->created_at ?? $initialDate,
            ]);
        }
    }

    private function getEarliestTransactionDate(Product $product)
    {
        $dates = collect();

        // Purchase
        $d = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchase_items.product_id', $product->id)
            ->min('purchases.created_at');
        if ($d) $dates->push(\Carbon\Carbon::parse($d));

        // Invoice
        $d = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.product_id', $product->id)
            ->min('invoices.created_at');
        if ($d) $dates->push(\Carbon\Carbon::parse($d));

        // Serial created_at
        if ($product->has_serial) {
            $d = SerialImei::where('product_id', $product->id)->min('created_at');
            if ($d) $dates->push(\Carbon\Carbon::parse($d));
        }

        return $dates->min();
    }
}
