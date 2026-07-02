<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\SerialImei;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audit thẻ kho — so sánh tồn kho tính từ lịch sử giao dịch với product.stock_quantity.
 *
 * Chạy:
 *   php artisan inventory:audit              → audit toàn bộ
 *   php artisan inventory:audit --product=ID  → audit 1 sản phẩm
 *   php artisan inventory:audit --fix         → tự động sửa stock_quantity theo thẻ kho
 *   php artisan inventory:audit --verbose     → hiện chi tiết từng giao dịch
 */
class AuditInventoryCard extends Command
{
    protected $signature = 'inventory:audit
        {--product= : Product ID hoặc SKU}
        {--fix : Tự động sửa stock_quantity nếu sai}
        {--detail : Hiển thị chi tiết từng giao dịch}
        {--only-mismatch : Chỉ hiện sản phẩm bị sai}';

    protected $description = 'Audit thẻ kho — kiểm tra tồn kho tính từ giao dịch vs product.stock_quantity';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $verbose = (bool) $this->option('detail');
        $onlyMismatch = (bool) $this->option('only-mismatch');
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

        $this->info(sprintf('Audit %d sản phẩm...', $products->count()));
        $this->newLine();

        $matchCount = 0;
        $mismatchCount = 0;
        $fixedCount = 0;
        $mismatches = [];

        foreach ($products as $product) {
            $result = $this->auditOne($product, $verbose);

            $dbQty = (int) $product->stock_quantity;
            $calcQty = $result['calculated_qty'];
            $serialQty = $result['serial_qty'];

            $isMatch = ($dbQty === $calcQty);
            $serialMatch = !$product->has_serial || ($dbQty === $serialQty);

            if ($isMatch && $serialMatch) {
                $matchCount++;
                if (!$onlyMismatch) {
                    $this->line(sprintf(
                        '  ✅ #%d %s — DB=%d, Thẻ kho=%d %s',
                        $product->id, $product->sku, $dbQty, $calcQty,
                        $product->has_serial ? "(serial in_stock={$serialQty})" : ''
                    ));
                }
            } else {
                $mismatchCount++;
                $diff = $calcQty - $dbQty;
                $diffStr = $diff > 0 ? "+{$diff}" : "{$diff}";

                $this->warn(sprintf(
                    '  ❌ #%d %s "%s" — DB=%d, Thẻ kho=%d (lệch %s) %s',
                    $product->id, $product->sku, mb_substr($product->name, 0, 30),
                    $dbQty, $calcQty, $diffStr,
                    $product->has_serial ? "| serial in_stock={$serialQty}" : ''
                ));

                // Chi tiết breakdown
                $this->line(sprintf(
                    '       Nhập: +%d | Bán: -%d | KH trả: +%d | Trả NCC: -%d | Kiểm: %s | Hủy: -%d | Chuyển: -%d | SC xuất: -%d | SC nhập: +%d',
                    $result['breakdown']['purchase_in'],
                    $result['breakdown']['sale_out'],
                    $result['breakdown']['return_in'],
                    $result['breakdown']['purchase_return_out'],
                    ($result['breakdown']['stock_take_diff'] >= 0 ? '+' : '') . $result['breakdown']['stock_take_diff'],
                    $result['breakdown']['damage_out'],
                    $result['breakdown']['transfer_out'],
                    $result['breakdown']['repair_out'],
                    $result['breakdown']['repair_in']
                ));

                $mismatches[] = [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'db_qty' => $dbQty,
                    'calc_qty' => $calcQty,
                    'serial_qty' => $serialQty,
                    'diff' => $diff,
                    'has_serial' => $product->has_serial,
                ];

                if ($fix) {
                    $product->stock_quantity = $calcQty;
                    $product->saveQuietly();
                    $fixedCount++;
                    $this->info("       → Đã sửa stock_quantity: {$dbQty} → {$calcQty}");
                }
            }
        }

        $this->newLine();
        $this->info('═══════════ TỔNG KẾT ═══════════');
        $this->line("Tổng SP kiểm tra: {$products->count()}");
        $this->line("  ✅ Đúng: {$matchCount}");
        $this->line("  ❌ Sai: {$mismatchCount}");
        if ($fix) {
            $this->line("  🔧 Đã sửa: {$fixedCount}");
        }

        if (!empty($mismatches) && !$fix) {
            $this->newLine();
            $this->warn('Để tự động sửa, chạy: php artisan inventory:audit --fix');
            $this->warn('Hoặc rebuild giá vốn trước: php artisan costing:rebuild-moving-avg --all');
        }

        return self::SUCCESS;
    }

    private function auditOne(Product $product, bool $verbose): array
    {
        $breakdown = [
            'purchase_in' => 0,
            'sale_out' => 0,
            'return_in' => 0,
            'purchase_return_out' => 0,
            'stock_take_diff' => 0,
            'damage_out' => 0,
            'transfer_out' => 0,
            'repair_out' => 0,
            'repair_in' => 0,
        ];
        $transactions = [];

        // 1. Nhập hàng (Purchase Items) — chỉ từ phiếu completed
        $purchaseItems = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchase_items.product_id', $product->id)
            ->where('purchases.status', 'completed')
            ->get(['purchase_items.quantity', 'purchases.code', 'purchases.created_at']);

        foreach ($purchaseItems as $pi) {
            $qty = (int) $pi->quantity;
            $breakdown['purchase_in'] += $qty;
            if ($verbose) {
                $transactions[] = ['type' => 'Nhập', 'code' => $pi->code, 'qty' => "+{$qty}", 'date' => $pi->created_at];
            }
        }

        // 2. Bán hàng (Invoice Items) — chỉ từ invoice chưa bị hủy
        $invoiceItems = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.product_id', $product->id)
            ->where(function ($q) {
                $q->whereNull('invoices.status')
                  ->orWhere('invoices.status', '!=', 'cancelled');
            })
            ->get(['invoice_items.quantity', 'invoices.code', 'invoices.created_at']);

        foreach ($invoiceItems as $ii) {
            $qty = (int) $ii->quantity;
            $breakdown['sale_out'] += $qty;
            if ($verbose) {
                $transactions[] = ['type' => 'Bán', 'code' => $ii->code, 'qty' => "-{$qty}", 'date' => $ii->created_at];
            }
        }

        // 3. Khách trả hàng (Return Items) — chỉ từ phiếu chưa hủy
        $returnItems = DB::table('return_items')
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->where('return_items.product_id', $product->id)
            ->where(function ($q) {
                $q->where('returns.status', '!=', 'Đã hủy')
                  ->orWhereNull('returns.status');
            })
            ->get(['return_items.quantity', 'returns.code', 'returns.created_at']);

        foreach ($returnItems as $ri) {
            $qty = (int) $ri->quantity;
            $breakdown['return_in'] += $qty;
            if ($verbose) {
                $transactions[] = ['type' => 'KH trả', 'code' => $ri->code, 'qty' => "+{$qty}", 'date' => $ri->created_at];
            }
        }

        // 4. Trả hàng NCC (Purchase Return Items) — chỉ từ phiếu completed
        $prItems = DB::table('purchase_return_items')
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->where('purchase_return_items.product_id', $product->id)
            ->where('purchase_returns.status', 'completed')
            ->get(['purchase_return_items.quantity', 'purchase_returns.code', 'purchase_returns.created_at']);

        foreach ($prItems as $pri) {
            $qty = (int) $pri->quantity;
            $breakdown['purchase_return_out'] += $qty;
            if ($verbose) {
                $transactions[] = ['type' => 'Trả NCC', 'code' => $pri->code, 'qty' => "-{$qty}", 'date' => $pri->created_at];
            }
        }

        // 5. Kiểm kho (Stock Take Items)
        $stItems = DB::table('stock_take_items')
            ->join('stock_takes', 'stock_takes.id', '=', 'stock_take_items.stock_take_id')
            ->where('stock_take_items.product_id', $product->id)
            ->where('stock_takes.status', 'balanced')
            ->get(['stock_take_items.actual_stock', 'stock_take_items.system_stock', 'stock_takes.code', 'stock_takes.created_at']);

        foreach ($stItems as $sti) {
            $diff = (int) $sti->actual_stock - (int) $sti->system_stock;
            $breakdown['stock_take_diff'] += $diff;
            if ($verbose) {
                $sign = $diff >= 0 ? '+' : '';
                $transactions[] = ['type' => 'Kiểm kho', 'code' => $sti->code, 'qty' => "{$sign}{$diff}", 'date' => $sti->created_at];
            }
        }

        // 6. Xuất hủy (Damage Items)
        $dmgItems = DB::table('damage_items')
            ->join('damages', 'damages.id', '=', 'damage_items.damage_id')
            ->where('damage_items.product_id', $product->id)
            ->where('damages.status', 'completed')
            ->get(['damage_items.qty', 'damages.code', 'damages.created_at']);

        foreach ($dmgItems as $di) {
            $qty = (int) $di->qty;
            $breakdown['damage_out'] += $qty;
            if ($verbose) {
                $transactions[] = ['type' => 'Xuất hủy', 'code' => $di->code, 'qty' => "-{$qty}", 'date' => $di->created_at];
            }
        }

        // 7. Chuyển kho (Stock Transfer Items)
        $trItems = DB::table('stock_transfer_items')
            ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_items.stock_transfer_id')
            ->where('stock_transfer_items.product_id', $product->id)
            ->get(['stock_transfer_items.quantity', 'stock_transfers.code', 'stock_transfers.created_at']);

        foreach ($trItems as $tri) {
            $qty = (int) $tri->quantity;
            $breakdown['transfer_out'] += $qty;
            if ($verbose) {
                $transactions[] = ['type' => 'Chuyển kho', 'code' => $tri->code, 'qty' => "-{$qty}", 'date' => $tri->created_at];
            }
        }

        // 8. Xuất sửa chữa / Nhập bóc máy (Task Parts)
        if (DB::getSchemaBuilder()->hasTable('task_parts')) {
            $taskParts = DB::table('task_parts')
                ->where('product_id', $product->id)
                ->get(['quantity', 'direction', 'task_id', 'created_at']);

            foreach ($taskParts as $tp) {
                $qty = (int) $tp->quantity;
                $isImport = ($tp->direction ?? 'export') === 'import';
                if ($isImport) {
                    $breakdown['repair_in'] += $qty;
                } else {
                    $breakdown['repair_out'] += $qty;
                }
                if ($verbose) {
                    $label = $isImport ? 'Nhập bóc máy' : 'Xuất SC';
                    $sign = $isImport ? '+' : '-';
                    $transactions[] = ['type' => $label, 'code' => "task#{$tp->task_id}", 'qty' => "{$sign}{$qty}", 'date' => $tp->created_at];
                }
            }
        }

        // Tính tổng (không cần tồn đầu kỳ — tất cả SP đều có phiếu nhập)
        $calculatedQty = $breakdown['purchase_in']
            - $breakdown['sale_out']
            + $breakdown['return_in']
            - $breakdown['purchase_return_out']
            + $breakdown['stock_take_diff']
            - $breakdown['damage_out']
            - $breakdown['transfer_out']
            - $breakdown['repair_out']
            + $breakdown['repair_in'];

        // Serial count (cho hàng có serial)
        $serialQty = null;
        if ($product->has_serial) {
            $serialQty = SerialImei::where('product_id', $product->id)
                ->where('status', 'in_stock')
                ->count();
        }

        // In chi tiết nếu verbose
        if ($verbose && !empty($transactions)) {
            usort($transactions, fn($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));
            $balance = 0;
            foreach ($transactions as $tx) {
                $change = (int) str_replace('+', '', $tx['qty']);
                $balance += $change;
                $this->line(sprintf(
                    '       %s | %-12s | %-20s | qty: %s | balance: %d',
                    substr($tx['date'] ?? '', 0, 16),
                    $tx['type'],
                    $tx['code'],
                    $tx['qty'],
                    $balance
                ));
            }
        }

        return [
            'calculated_qty' => $calculatedQty,
            'serial_qty' => $serialQty,
            'breakdown' => $breakdown,
        ];
    }
}
