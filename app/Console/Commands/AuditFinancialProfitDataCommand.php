<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\CashFlow;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Models\ReturnItem;
use App\Models\Product;
use App\Models\Paysheet;
use App\Support\Reports\MetricService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class AuditFinancialProfitDataCommand extends Command
{
    protected $signature = 'audit:financial-profit-data
        {--from=2026-04-01 : Start date for audit (YYYY-MM-DD)}
        {--to=2026-05-31 : End date for audit (YYYY-MM-DD)}
        {--branch_id= : Filter by branch ID}
        {--export-csv : Export tables to CSV files}
        {--limit=100 : Limit for output lists (default: 100)}';

    protected $description = 'Perform a dry-run data quality audit for financial profit, margins, cogs, cashflows, and payroll';

    public function handle(): int
    {
        // Enable query log to perform safety check
        DB::connection()->enableQueryLog();

        $fromOpt = $this->option('from');
        $toOpt = $this->option('to');
        $branchId = $this->option('branch_id');
        $exportCsv = $this->option('export-csv');
        $limit = (int) $this->option('limit');

        $startDate = Carbon::parse($fromOpt)->startOfDay();
        $endDate = Carbon::parse($toOpt)->endOfDay();

        $this->info("=== FINANCIAL PROFIT DATA QUALITY DRY-RUN AUDIT ===");
        $this->info("Period: {$startDate->toDateTimeString()} to {$endDate->toDateTimeString()}");
        if ($branchId) {
            $this->info("Branch ID: {$branchId}");
        } else {
            $this->info("Branch: All Branches");
        }
        $this->newLine();

        // 1. Compute general report metrics
        $metrics = MetricService::compute($startDate, $endDate, $branchId);
        $grossRevenue = $metrics['gross_revenue'];
        $invoiceDiscount = $metrics['invoice_discount'];
        $returnValue = $metrics['return_value'];
        $netRevenue = $metrics['net_revenue'];
        $cogsSold = $metrics['cogs_sold'];
        $cogsReturned = $metrics['cogs_returned'];
        $cogsNet = $metrics['cogs_net'];
        $grossProfit = $metrics['gross_profit'];
        $grossMarginPercent = $netRevenue > 0 ? ($grossProfit / $netRevenue) * 100 : 0;
        $invoiceCount = $metrics['invoice_count'];
        $returnCount = $metrics['return_count'];

        // Expenses and other income calculations mirroring FinancialReportController
        $payrollExpense = $this->payrollExpenseAmount($startDate, $endDate, $branchId);
        
        $otherExpenseCategories = ['Chi phí khác', 'Chi phi khac', 'Khác', 'Khac'];
        $expenseQ = $this->pnlCashFlowBaseQuery('payment', $startDate, $endDate, $branchId)
            ->where(function ($q) use ($otherExpenseCategories) {
                $q->whereNull('category')
                  ->orWhereNotIn('category', $otherExpenseCategories);
            });
        
        $expensesByCategory = (clone $expenseQ)
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->get()
            ->map(fn($row) => [
                'name' => $row->category ?: 'Chi khác',
                'amount' => (float) $row->total,
            ])
            ->toArray();

        if ($payrollExpense > 0) {
            $expensesByCategory[] = [
                'name' => 'Chi lương nhân viên',
                'amount' => $payrollExpense,
            ];
        }
        $totalExpenses = array_sum(array_column($expensesByCategory, 'amount'));

        $otherIncomeQ = $this->pnlCashFlowBaseQuery('receipt', $startDate, $endDate, $branchId)
            ->where(function ($q) {
                $q->whereNull('category')
                  ->orWhereNotIn('category', [
                      'Thu tiền khách trả', 'Thu tien khach tra',
                      'Thu nợ khách hàng', 'Thu no khach hang',
                      'Bán hàng', 'Ban hang', ''
                  ]);
            });
        
        $otherIncomeByCategory = (clone $otherIncomeQ)
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->get()
            ->map(fn($row) => [
                'name' => $row->category ?: 'Thu nhập khác',
                'amount' => (float) $row->total,
            ])
            ->toArray();
        $totalOtherIncome = array_sum(array_column($otherIncomeByCategory, 'amount'));

        $otherExpenseQ = $this->pnlCashFlowBaseQuery('payment', $startDate, $endDate, $branchId)
            ->whereIn('category', $otherExpenseCategories);

        $otherExpensesByCategory = (clone $otherExpenseQ)
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->get()
            ->map(fn($row) => [
                'name' => $row->category ?: 'Chi phí khác',
                'amount' => (float) $row->total,
            ])
            ->toArray();
        $totalOtherExpenses = array_sum(array_column($otherExpensesByCategory, 'amount'));

        $operatingProfit = $grossProfit - $totalExpenses;
        $netProfit = $operatingProfit + $totalOtherIncome - $totalOtherExpenses;
        $netMarginPercent = $netRevenue > 0 ? ($netProfit / $netRevenue) * 100 : 0;

        $this->info("--- TỔNG SỐ LIỆU BÁO CÁO ---");
        $this->table(
            ['Chỉ tiêu', 'Giá trị'],
            [
                ['Doanh thu bán hàng (gross_revenue)', number_format($grossRevenue, 0, '.', ',') . 'đ'],
                ['Chiết khấu hóa đơn (invoice_discount)', number_format($invoiceDiscount, 0, '.', ',') . 'đ'],
                ['Giá trị hàng bán bị trả lại (return_value)', number_format($returnValue, 0, '.', ',') . 'đ'],
                ['Doanh thu thuần (net_revenue)', number_format($netRevenue, 0, '.', ',') . 'đ'],
                ['Giá vốn hàng đã bán (cogs_sold)', number_format($cogsSold, 0, '.', ',') . 'đ'],
                ['Giá vốn hàng trả lại (cogs_returned)', number_format($cogsReturned, 0, '.', ',') . 'đ'],
                ['Giá vốn hàng bán thuần (cogs_net)', number_format($cogsNet, 0, '.', ',') . 'đ'],
                ['Lợi nhuận gộp (gross_profit)', number_format($grossProfit, 0, '.', ',') . 'đ'],
                ['Tỷ suất lợi nhuận gộp (gross_margin_percent)', number_format($grossMarginPercent, 2, '.', ',') . '%'],
                ['Tổng chi phí (total_expenses)', number_format($totalExpenses, 0, '.', ',') . 'đ'],
                ['Chi phí lương (payroll_expense)', number_format($payrollExpense, 0, '.', ',') . 'đ'],
                ['Thu nhập khác (other_income)', number_format($totalOtherIncome, 0, '.', ',') . 'đ'],
                ['Chi phí khác (other_expenses)', number_format($totalOtherExpenses, 0, '.', ',') . 'đ'],
                ['Lợi nhuận thuần (net_profit)', number_format($netProfit, 0, '.', ',') . 'đ'],
                ['Tỷ suất lợi nhuận thuần (net_margin_percent)', number_format($netMarginPercent, 2, '.', ',') . '%'],
                ['Số lượng hóa đơn (invoice_count)', $invoiceCount],
                ['Số lượng phiếu trả (return_count)', $returnCount],
            ]
        );
        $this->newLine();

        // 2. Top sản phẩm kéo tụt lợi nhuận
        $invoiceIds = MetricService::invoiceScope($startDate, $endDate, $branchId)->pluck('id');
        
        $topProductsQuery = DB::table('invoice_items')
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->where('invoices.status', '!=', 'Đã hủy')
            ->whereIn('invoice_items.invoice_id', $invoiceIds);

        $topProducts = (clone $topProductsQuery)
            ->select(
                'invoice_items.product_id',
                'products.sku',
                'products.name',
                DB::raw('SUM(invoice_items.quantity) as qty'),
                DB::raw('SUM(invoice_items.quantity * invoice_items.price) as revenue'),
                DB::raw('SUM(invoice_items.quantity * COALESCE(NULLIF(invoice_items.cost_price, 0), products.cost_price, 0)) as cogs'),
                DB::raw('SUM(invoice_items.quantity * invoice_items.price) - SUM(invoice_items.quantity * COALESCE(NULLIF(invoice_items.cost_price, 0), products.cost_price, 0)) as gross_profit'),
                DB::raw('SUM(CASE WHEN invoice_items.cost_price IS NULL OR invoice_items.cost_price = 0 THEN 1 ELSE 0 END) as count_missing_snapshot'),
                DB::raw('SUM(CASE WHEN COALESCE(NULLIF(invoice_items.cost_price, 0), products.cost_price, 0) > invoice_items.price THEN 1 ELSE 0 END) as count_loss_items'),
                DB::raw('SUM(CASE WHEN invoice_items.price = 0 THEN invoice_items.quantity ELSE 0 END) as zero_price_qty')
            )
            ->groupBy('invoice_items.product_id', 'products.sku', 'products.name')
            ->orderBy('gross_profit', 'ASC')
            ->limit($limit)
            ->get();

        $this->info("--- TOP SẢN PHẨM KÉO TỤT LỢI NHUẬN (Sắp xếp theo Lãi gộp tăng dần) ---");
        $this->table(
            ['SKU', 'Tên sản phẩm', 'SL', 'Doanh thu', 'Giá vốn', 'Lãi gộp', 'Margin %', 'Dòng thiếu SS', 'Dòng bán lỗ', 'SL tặng (0đ)'],
            $topProducts->map(function ($row) {
                $margin = $row->revenue > 0 ? ($row->gross_profit / $row->revenue) * 100 : 0;
                return [
                    $row->sku,
                    $row->name,
                    $row->qty,
                    number_format($row->revenue, 0, '.', ','),
                    number_format($row->cogs, 0, '.', ','),
                    number_format($row->gross_profit, 0, '.', ','),
                    number_format($margin, 2) . '%',
                    $row->count_missing_snapshot,
                    $row->count_loss_items,
                    $row->zero_price_qty,
                ];
            })->toArray()
        );
        $this->newLine();

        // 3. Top hóa đơn bán lỗ/lãi thấp
        $topInvoices = DB::table('invoices')
            ->leftJoin('invoice_items', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->leftJoin('products', 'invoice_items.product_id', '=', 'products.id')
            ->where('invoices.status', '!=', 'Đã hủy')
            ->whereIn('invoices.id', $invoiceIds)
            ->select(
                'invoices.id as invoice_id',
                'invoices.code as invoice_code',
                'invoices.created_at',
                'invoices.transaction_date',
                'invoices.subtotal',
                'invoices.discount',
                'invoices.total',
                DB::raw('SUM(invoice_items.quantity * invoice_items.price) as item_revenue'),
                DB::raw('SUM(invoice_items.quantity * COALESCE(NULLIF(invoice_items.cost_price, 0), products.cost_price, 0)) as item_cogs'),
                DB::raw('COUNT(invoice_items.id) as item_count'),
                DB::raw('MAX(CASE WHEN invoice_items.cost_price IS NULL OR invoice_items.cost_price = 0 THEN 1 ELSE 0 END) as has_missing_cost_snapshot'),
                DB::raw('MAX(CASE WHEN invoice_items.price = 0 THEN 1 ELSE 0 END) as has_zero_price_item')
            )
            ->groupBy('invoices.id', 'invoices.code', 'invoices.created_at', 'invoices.transaction_date', 'invoices.subtotal', 'invoices.discount', 'invoices.total')
            ->get()
            ->map(function ($row) {
                $row->invoice_gross_profit = ($row->subtotal - $row->discount) - $row->item_cogs;
                $row->gross_margin_percent = ($row->subtotal - $row->discount) > 0 ? ($row->invoice_gross_profit / ($row->subtotal - $row->discount)) * 100 : 0;
                return $row;
            })
            ->sortBy('invoice_gross_profit')
            ->take($limit)
            ->values();

        $this->info("--- TOP HÓA ĐƠN BÁN LỖ/LÃI THẤP (Sắp xếp theo Lãi gộp tăng dần) ---");
        $this->table(
            ['Mã HĐ', 'Ngày giao dịch', 'Subtotal', 'Discount', 'Total', 'Item Revenue', 'Item COGS', 'Lãi gộp HĐ', 'Margin %', 'SL mặt hàng', 'Thiếu SS', 'Có hàng 0đ'],
            $topInvoices->map(function ($row) {
                return [
                    $row->invoice_code,
                    $row->transaction_date ?: $row->created_at,
                    number_format($row->subtotal, 0, '.', ','),
                    number_format($row->discount, 0, '.', ','),
                    number_format($row->total, 0, '.', ','),
                    number_format($row->item_revenue, 0, '.', ','),
                    number_format($row->item_cogs, 0, '.', ','),
                    number_format($row->invoice_gross_profit, 0, '.', ','),
                    number_format($row->gross_margin_percent, 2) . '%',
                    $row->item_count,
                    $row->has_missing_cost_snapshot ? 'Có' : 'Không',
                    $row->has_zero_price_item ? 'Có' : 'Không',
                ];
            })->toArray()
        );
        $this->newLine();

        // 4. Dòng thiếu snapshot giá vốn
        $missingCostItems = DB::table('invoice_items')
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->where('invoices.status', '!=', 'Đã hủy')
            ->whereIn('invoice_items.invoice_id', $invoiceIds)
            ->where(function ($q) {
                $q->whereNull('invoice_items.cost_price')
                  ->orWhere('invoice_items.cost_price', 0);
            })
            ->select(
                'invoices.code as invoice_code',
                DB::raw('COALESCE(invoices.transaction_date, invoices.created_at) as invoice_date'),
                'invoice_items.product_id',
                'products.sku',
                'products.name as product_name',
                'invoice_items.quantity',
                'invoice_items.price',
                'invoice_items.discount',
                'invoice_items.subtotal',
                'invoice_items.cost_price as item_cost_price',
                'products.cost_price as current_product_cost_price',
                'products.retail_price'
            )
            ->get()
            ->map(function ($row) {
                $row->effective_cost_used_by_report = (float) ($row->current_product_cost_price ?: 0);
                $row->estimated_cogs_by_fallback = $row->quantity * $row->effective_cost_used_by_report;
                return $row;
            });

        $totalMissingCostRows = $missingCostItems->count();
        $affectedRevenue = $missingCostItems->sum('subtotal');
        $fallbackCogsTotal = $missingCostItems->sum('estimated_cogs_by_fallback');

        $this->info("--- DÒNG THIẾU SNAPSHOT GIÁ VỐN ---");
        $this->line("Tổng số dòng thiếu: {$totalMissingCostRows}");
        $this->line("Tổng doanh thu bị ảnh hưởng: " . number_format($affectedRevenue, 0, '.', ',') . 'đ');
        $this->line("Tổng giá vốn ước tính (fallback): " . number_format($fallbackCogsTotal, 0, '.', ',') . 'đ');
        $this->table(
            ['Mã HĐ', 'Ngày HĐ', 'SKU', 'Tên sản phẩm', 'SL', 'Giá bán', 'Doanh thu dòng', 'Current Cost', 'Fallback COGS'],
            $missingCostItems->take(15)->map(fn ($row) => [
                $row->invoice_code,
                $row->invoice_date,
                $row->sku,
                strlen($row->product_name) > 30 ? substr($row->product_name, 0, 27) . '...' : $row->product_name,
                $row->quantity,
                number_format($row->price, 0, '.', ','),
                number_format($row->subtotal, 0, '.', ','),
                number_format($row->current_product_cost_price, 0, '.', ','),
                number_format($row->estimated_cogs_by_fallback, 0, '.', ','),
            ])->toArray()
        );
        $this->newLine();

        // 5. Dòng bán lỗ do effective_cost > price
        $lossItems = DB::table('invoice_items')
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->where('invoices.status', '!=', 'Đã hủy')
            ->whereIn('invoice_items.invoice_id', $invoiceIds)
            ->select(
                'invoices.code as invoice_code',
                DB::raw('COALESCE(invoices.transaction_date, invoices.created_at) as invoice_date'),
                'invoice_items.product_id',
                'products.sku',
                'products.name as product_name',
                'invoice_items.quantity',
                'invoice_items.price',
                'invoice_items.cost_price as item_cost_price',
                'products.cost_price as current_product_cost_price'
            )
            ->get()
            ->map(function ($row) {
                $row->effective_cost = (float) (($row->item_cost_price && (float)$row->item_cost_price > 0) ? $row->item_cost_price : $row->current_product_cost_price);
                $row->unit_margin = (float)($row->price - $row->effective_cost);
                $row->line_margin = $row->quantity * $row->unit_margin;
                $row->is_snapshot_or_fallback = ($row->item_cost_price && (float)$row->item_cost_price > 0) ? 'snapshot' : 'fallback';
                return $row;
            })
            ->filter(fn ($row) => $row->effective_cost > $row->price)
            ->sortBy('line_margin')
            ->values();

        $this->info("--- DÒNG SẢN PHẨM BÁN LỖ (Effective Cost > Price) ---");
        $this->table(
            ['Mã HĐ', 'SKU', 'Tên sản phẩm', 'SL', 'Giá bán', 'Effective Cost', 'Lỗ đơn vị', 'Tổng lỗ dòng', 'Loại giá vốn'],
            $lossItems->take(15)->map(fn ($row) => [
                $row->invoice_code,
                $row->sku,
                strlen($row->product_name) > 30 ? substr($row->product_name, 0, 27) . '...' : $row->product_name,
                $row->quantity,
                number_format($row->price, 0, '.', ','),
                number_format($row->effective_cost, 0, '.', ','),
                number_format($row->unit_margin, 0, '.', ','),
                number_format($row->line_margin, 0, '.', ','),
                $row->is_snapshot_or_fallback,
            ])->toArray()
        );
        $this->newLine();

        // 6. Sản phẩm có cost_price > retail_price
        $costPriceGtRetailProducts = Product::where('cost_price', '>', 'retail_price')
            ->orderByRaw('cost_price - retail_price DESC')
            ->get()
            ->map(function ($product) use ($startDate, $endDate, $invoiceIds) {
                $salesData = DB::table('invoice_items')
                    ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
                    ->where('invoices.status', '!=', 'Đã hủy')
                    ->whereIn('invoice_items.invoice_id', $invoiceIds)
                    ->where('invoice_items.product_id', $product->id)
                    ->select(
                        DB::raw('SUM(invoice_items.quantity) as qty'),
                        DB::raw('SUM(invoice_items.quantity * invoice_items.price) as revenue'),
                        DB::raw('SUM(invoice_items.quantity * COALESCE(NULLIF(invoice_items.cost_price, 0), products.cost_price, 0)) as cogs')
                    )
                    ->join('products', 'invoice_items.product_id', '=', 'products.id') // to allow cost fallback expression
                    ->first();

                $product->diff = $product->cost_price - $product->retail_price;
                $product->sold_qty_in_period = (float) ($salesData->qty ?? 0);
                $product->revenue_in_period = (float) ($salesData->revenue ?? 0);
                $product->cogs_in_period = (float) ($salesData->cogs ?? 0);
                $product->gross_profit_in_period = $product->revenue_in_period - $product->cogs_in_period;

                return $product;
            });

        $this->info("--- SẢN PHẨM CÓ GIÁ VỐN > GIÁ BÁN LẺ (PRODUCTS.COST_PRICE > RETAIL_PRICE) ---");
        $this->table(
            ['SKU', 'Tên sản phẩm', 'Giá vốn', 'Giá bán lẻ', 'Chênh lệch', 'Tồn kho', 'SL Bán (Kỳ)', 'Doanh thu (Kỳ)', 'Lãi gộp (Kỳ)'],
            $costPriceGtRetailProducts->take(15)->map(fn ($p) => [
                $p->sku,
                strlen($p->name) > 30 ? substr($p->name, 0, 27) . '...' : $p->name,
                number_format($p->cost_price, 0, '.', ','),
                number_format($p->retail_price, 0, '.', ','),
                number_format($p->diff, 0, '.', ','),
                $p->stock_quantity,
                $p->sold_qty_in_period,
                number_format($p->revenue_in_period, 0, '.', ','),
                number_format($p->gross_profit_in_period, 0, '.', ','),
            ])->toArray()
        );
        $this->newLine();

        // 7. Ghost invoice
        $ghostInvoices = DB::table('invoices')
            ->leftJoin('invoice_items', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.status', '!=', 'Đã hủy')
            ->whereIn('invoices.id', $invoiceIds)
            ->groupBy('invoices.id', 'invoices.code', 'invoices.created_at', 'invoices.transaction_date', 'invoices.subtotal', 'invoices.total', 'invoices.status', 'invoices.customer_id', 'invoices.created_by_name')
            ->having(DB::raw('COUNT(invoice_items.id)'), '=', 0)
            ->having(DB::raw('invoices.subtotal > 0 OR invoices.total > 0'), true)
            ->select(
                'invoices.id as invoice_id',
                'invoices.code as invoice_code',
                'invoices.created_at',
                'invoices.transaction_date',
                'invoices.subtotal',
                'invoices.total',
                'invoices.status',
                'invoices.customer_id',
                'invoices.created_by_name'
            )
            ->get();

        $this->info("--- HÓA ĐƠN RÁC (GHOST INVOICES - CÓ TIỀN NHƯNG KHÔNG CÓ CHI TIẾT HÀNG) ---");
        if ($ghostInvoices->isEmpty()) {
            $this->line("Không có hóa đơn rác nào.");
        } else {
            $this->table(
                ['Mã HĐ', 'Ngày tạo', 'Ngày GD', 'Subtotal', 'Total', 'Status', 'Người tạo'],
                $ghostInvoices->map(fn ($row) => [
                    $row->invoice_code,
                    $row->created_at,
                    $row->transaction_date,
                    number_format($row->subtotal, 0, '.', ','),
                    number_format($row->total, 0, '.', ','),
                    $row->status,
                    $row->created_by_name,
                ])->toArray()
            );
        }
        $this->newLine();

        // 8. Invoice subtotal mismatch
        $subtotalMismatches = DB::table('invoices')
            ->join('invoice_items', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.status', '!=', 'Đã hủy')
            ->whereIn('invoices.id', $invoiceIds)
            ->select(
                'invoices.id as invoice_id',
                'invoices.code as invoice_code',
                'invoices.created_at',
                'invoices.subtotal',
                'invoices.discount',
                'invoices.total',
                DB::raw('SUM(invoice_items.quantity * invoice_items.price) as item_revenue')
            )
            ->groupBy('invoices.id', 'invoices.code', 'invoices.created_at', 'invoices.subtotal', 'invoices.discount', 'invoices.total')
            ->get()
            ->map(function ($row) {
                $row->diff = abs((float)$row->subtotal - (float)$row->item_revenue);
                return $row;
            })
            ->filter(fn ($row) => $row->diff > 1000)
            ->values();

        $this->info("--- HÓA ĐƠN LỆCH SUBTOTAL VỚI TỔNG CHI TIẾT (ABS(subtotal - SUM(item_price * qty)) > 1000) ---");
        if ($subtotalMismatches->isEmpty()) {
            $this->line("Không có hóa đơn lệch subtotal nào.");
        } else {
            $this->table(
                ['Mã HĐ', 'Ngày tạo', 'Subtotal', 'Tổng chi tiết', 'Chênh lệch', 'Discount', 'Total'],
                $subtotalMismatches->map(fn ($row) => [
                    $row->invoice_code,
                    $row->created_at,
                    number_format($row->subtotal, 0, '.', ','),
                    number_format($row->item_revenue, 0, '.', ','),
                    number_format($row->diff, 0, '.', ','),
                    number_format($row->discount, 0, '.', ','),
                    number_format($row->total, 0, '.', ','),
                ])->toArray()
            );
        }
        $this->newLine();

        // 9. Quà tặng / hàng giá bán 0đ gánh COGS
        $zeroPriceItems = DB::table('invoice_items')
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->join('invoices', 'invoice_items.invoice_id', '=', 'invoices.id')
            ->where('invoices.status', '!=', 'Đã hủy')
            ->whereIn('invoice_items.invoice_id', $invoiceIds)
            ->where(function ($q) {
                $q->where('invoice_items.price', 0)
                  ->orWhere('invoice_items.subtotal', 0);
            })
            ->select(
                'invoices.code as invoice_code',
                'invoice_items.product_id',
                'products.sku',
                'products.name as product_name',
                'invoice_items.quantity',
                'invoice_items.price',
                'invoice_items.subtotal',
                'invoice_items.cost_price as item_cost_price',
                'products.cost_price as current_product_cost_price',
                'invoice_items.note'
            )
            ->get()
            ->map(function ($row) {
                $row->effective_cost = (float) (($row->item_cost_price && (float)$row->item_cost_price > 0) ? $row->item_cost_price : $row->current_product_cost_price);
                $row->cogs = $row->quantity * $row->effective_cost;
                return $row;
            })
            ->filter(fn ($row) => $row->effective_cost > 0)
            ->values();

        $zeroPriceItemCount = $zeroPriceItems->count();
        $zeroPriceTotalCogs = $zeroPriceItems->sum('cogs');

        $this->info("--- HÀNG QUÀ TẶNG (GIÁ BÁN 0đ) GÁNH GIÁ VỐN ---");
        $this->line("Tổng số dòng quà tặng gánh COGS: {$zeroPriceItemCount}");
        $this->line("Tổng giá vốn quà tặng: " . number_format($zeroPriceTotalCogs, 0, '.', ',') . 'đ');
        $this->table(
            ['Mã HĐ', 'SKU', 'Tên sản phẩm', 'SL', 'Giá bán', 'Doanh thu', 'Effective Cost', 'Tổng COGS gánh', 'Ghi chú'],
            $zeroPriceItems->take(15)->map(fn ($row) => [
                $row->invoice_code,
                $row->sku,
                strlen($row->product_name) > 30 ? substr($row->product_name, 0, 27) . '...' : $row->product_name,
                $row->quantity,
                number_format($row->price, 0, '.', ','),
                number_format($row->subtotal, 0, '.', ','),
                number_format($row->effective_cost, 0, '.', ','),
                number_format($row->cogs, 0, '.', ','),
                $row->note ?: '',
            ])->toArray()
        );
        $this->newLine();

        // 10. CashFlow P&L category audit
        $timeColumn = Schema::hasColumn('cash_flows', 'time') ? 'time' : 'created_at';
        $cashflowsAudit = CashFlow::active()
            ->whereBetween($timeColumn, [$startDate, $endDate])
            ->select('type', 'category', 'reference_type', 'status', DB::raw('COUNT(*) as flow_count'), DB::raw('SUM(amount) as total_amount'))
            ->groupBy('type', 'category', 'reference_type', 'status')
            ->get()
            ->map(function ($row) use ($otherExpenseCategories) {
                // Classify proposed category
                $cat = $row->category;
                $type = $row->type;
                
                // Exclude checks matching the pnlCashFlowBaseQuery logic
                $excludedReferenceTypes = [
                    'OrderReturn', 'PurchaseReturn', 'DebtOffset', 'DebtOffsetCancel', 'paysheet', 'Paysheet', 'PaysheetPayment'
                ];
                $excludedCategories = [
                    'Chi tiền trả hàng khách', 'Chi tien tra hang khach', 'Chi trả hàng khách', 'Chi tra hang khach',
                    'Thu tiền NCC trả hàng', 'Thu tien NCC tra hang', 'NCC hoàn tiền trả hàng', 'NCC hoan tien tra hang',
                    'Đối trừ công nợ', 'Doi tru cong no', 'Hủy đối trừ công nợ', 'Huy doi tru cong no',
                    'Chi lương nhân viên', 'Chi luong nhan vien', 'Lương nhân viên', 'Luong nhan vien', 'Thanh toán lương', 'Thanh toan luong',
                    'Chi tiền trả NCC', 'Chi thanh toan NCC', 'Chi tiền trả NCC hàng', 'Chi tien tra NCC hang',
                    'Điều chỉnh công nợ', 'Dieu chinh cong no', 'Chuyển/Rút', 'Chuyen/Rut', ''
                ];

                if (in_array($row->reference_type, $excludedReferenceTypes) || in_array($cat, $excludedCategories) || $cat === null) {
                    $row->proposed_classification = 'non_pnl';
                } elseif ($type === 'payment') {
                    if (in_array($cat, ['Lãi ngân hàng', 'Lai ngan hang'])) {
                        $row->proposed_classification = 'financial_expense';
                    } elseif (in_array($cat, $otherExpenseCategories)) {
                        $row->proposed_classification = 'other_expense';
                    } else {
                        $row->proposed_classification = 'operating_expense';
                    }
                } elseif ($type === 'receipt') {
                    $row->proposed_classification = 'other_income';
                } else {
                    $row->proposed_classification = 'unknown_need_review';
                }
                return $row;
            });

        $this->info("--- KIỂM TRA PHÂN LOẠI CÁC PHIẾU THU/CHI (CASHFLOW AUDIT) ---");
        $this->table(
            ['Thu/Chi', 'Category', 'Ref Type', 'Trạng thái', 'Số lượng', 'Tổng tiền', 'Phân loại đề xuất'],
            $cashflowsAudit->map(fn ($row) => [
                $row->type === 'payment' ? 'Chi (payment)' : 'Thu (receipt)',
                $row->category ?: '(Trống / Null)',
                $row->reference_type ?: '(Trống)',
                $row->status,
                $row->flow_count,
                number_format($row->total_amount, 0, '.', ','),
                $row->proposed_classification,
            ])->toArray()
        );
        $this->newLine();

        // 11. Bảng lương
        $paysheetsInPeriod = Paysheet::query()
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('period_start', [$startDate, $endDate])
                  ->orWhereBetween('period_end', [$startDate, $endDate])
                  ->orWhere(function ($q2) use ($startDate, $endDate) {
                      $q2->where('period_start', '<=', $startDate)
                         ->where('period_end', '>=', $endDate);
                  });
            })
            ->get();

        $payrollIncludedTotal = $paysheetsInPeriod->whereIn('status', ['calculated', 'locked'])->sum('total_salary');
        $payrollExcludedCancelledTotal = $paysheetsInPeriod->where('status', 'cancelled')->sum('total_salary');
        $payrollNeedsRecalcTotal = $paysheetsInPeriod->where('needs_recalc', 1)->sum('total_salary');

        $this->info("--- KIỂM TRA BẢNG LƯƠNG TRONG KỲ ---");
        $this->line("Tổng chi phí lương hợp lệ (calculated/locked): " . number_format($payrollIncludedTotal, 0, '.', ',') . 'đ');
        $this->line("Tổng lương bảng đã hủy: " . number_format($payrollExcludedCancelledTotal, 0, '.', ',') . 'đ');
        $this->line("Tổng lương bảng cần tính toán lại: " . number_format($payrollNeedsRecalcTotal, 0, '.', ',') . 'đ');
        $this->table(
            ['ID', 'Mã bảng', 'Tên bảng lương', 'Period Start', 'Period End', 'Trạng thái', 'Yêu cầu tính lại', 'Chi nhánh', 'Tổng lương', 'Đã trả', 'Còn lại', 'Số NV'],
            $paysheetsInPeriod->map(fn ($row) => [
                $row->id,
                $row->code,
                $row->name,
                $row->period_start,
                $row->period_end,
                $row->status,
                $row->needs_recalc ? 'Có' : 'Không',
                $row->branch_id ?: 'Tất cả',
                number_format($row->total_salary, 0, '.', ','),
                number_format($row->total_paid, 0, '.', ','),
                number_format($row->total_remaining, 0, '.', ','),
                $row->employee_count,
            ])->toArray()
        );
        $this->newLine();

        // 12. CSV Export
        if ($exportCsv) {
            $timestamp = date('Ymd-His');
            $dir = storage_path("app/audit/financial-profit-data/{$timestamp}");
            
            $this->info("Exporting CSV tables to directory: {$dir}");

            // 12.1 summary.csv
            $this->writeCsv($dir, 'summary.csv', ['Indicator', 'Value'], [
                ['gross_revenue', $grossRevenue],
                ['invoice_discount', $invoiceDiscount],
                ['return_value', $returnValue],
                ['net_revenue', $netRevenue],
                ['cogs_sold', $cogsSold],
                ['cogs_returned', $cogsReturned],
                ['cogs_net', $cogsNet],
                ['gross_profit', $grossProfit],
                ['gross_margin_percent', $grossMarginPercent],
                ['total_expenses', $totalExpenses],
                ['payroll_expense', $payrollExpense],
                ['other_income', $totalOtherIncome],
                ['other_expenses', $totalOtherExpenses],
                ['net_profit', $netProfit],
                ['net_margin_percent', $netMarginPercent],
                ['invoice_count', $invoiceCount],
                ['return_count', $returnCount],
            ]);

            // 12.2 top_products_low_margin.csv
            $this->writeCsv(
                $dir,
                'top_products_low_margin.csv',
                ['product_id', 'sku', 'name', 'qty', 'revenue', 'cogs', 'gross_profit', 'gross_margin_percent', 'count_missing_snapshot', 'count_loss_items', 'zero_price_qty'],
                $topProducts->map(fn($row) => [
                    $row->product_id,
                    $row->sku,
                    $row->name,
                    $row->qty,
                    $row->revenue,
                    $row->cogs,
                    $row->gross_profit,
                    $row->revenue > 0 ? ($row->gross_profit / $row->revenue) * 100 : 0,
                    $row->count_missing_snapshot,
                    $row->count_loss_items,
                    $row->zero_price_qty,
                ])->toArray()
            );

            // 12.3 top_invoices_low_margin.csv
            $this->writeCsv(
                $dir,
                'top_invoices_low_margin.csv',
                ['invoice_id', 'invoice_code', 'created_at', 'transaction_date', 'subtotal', 'discount', 'total', 'item_revenue', 'item_cogs', 'invoice_gross_profit', 'gross_margin_percent', 'item_count', 'has_missing_cost_snapshot', 'has_zero_price_item'],
                $topInvoices->map(fn($row) => [
                    $row->invoice_id,
                    $row->invoice_code,
                    $row->created_at,
                    $row->transaction_date,
                    $row->subtotal,
                    $row->discount,
                    $row->total,
                    $row->item_revenue,
                    $row->item_cogs,
                    $row->invoice_gross_profit,
                    $row->gross_margin_percent,
                    $row->item_count,
                    $row->has_missing_cost_snapshot,
                    $row->has_zero_price_item,
                ])->toArray()
            );

            // 12.4 missing_cost_snapshot.csv
            $this->writeCsv(
                $dir,
                'missing_cost_snapshot.csv',
                ['invoice_code', 'invoice_date', 'product_id', 'sku', 'product_name', 'quantity', 'price', 'discount', 'subtotal', 'item_cost_price', 'current_product_cost_price', 'retail_price', 'effective_cost_used_by_report', 'estimated_cogs_by_fallback'],
                $missingCostItems->map(fn($row) => [
                    $row->invoice_code,
                    $row->invoice_date,
                    $row->product_id,
                    $row->sku,
                    $row->product_name,
                    $row->quantity,
                    $row->price,
                    $row->discount,
                    $row->subtotal,
                    $row->item_cost_price,
                    $row->current_product_cost_price,
                    $row->retail_price,
                    $row->effective_cost_used_by_report,
                    $row->estimated_cogs_by_fallback,
                ])->toArray()
            );

            // 12.5 loss_items.csv
            $this->writeCsv(
                $dir,
                'loss_items.csv',
                ['invoice_code', 'invoice_date', 'product_id', 'sku', 'product_name', 'quantity', 'price', 'item_cost_price', 'current_product_cost_price', 'effective_cost', 'unit_margin', 'line_margin', 'is_snapshot_or_fallback'],
                $lossItems->map(fn($row) => [
                    $row->invoice_code,
                    $row->invoice_date,
                    $row->product_id,
                    $row->sku,
                    $row->product_name,
                    $row->quantity,
                    $row->price,
                    $row->item_cost_price,
                    $row->current_product_cost_price,
                    $row->effective_cost,
                    $row->unit_margin,
                    $row->line_margin,
                    $row->is_snapshot_or_fallback,
                ])->toArray()
            );

            // 12.6 products_cost_gt_retail.csv
            $this->writeCsv(
                $dir,
                'products_cost_gt_retail.csv',
                ['product_id', 'sku', 'name', 'cost_price', 'retail_price', 'stock_quantity', 'diff', 'sold_qty_in_period', 'revenue_in_period', 'cogs_in_period', 'gross_profit_in_period'],
                $costPriceGtRetailProducts->map(fn($p) => [
                    $p->id,
                    $p->sku,
                    $p->name,
                    $p->cost_price,
                    $p->retail_price,
                    $p->stock_quantity,
                    $p->diff,
                    $p->sold_qty_in_period,
                    $p->revenue_in_period,
                    $p->cogs_in_period,
                    $p->gross_profit_in_period,
                ])->toArray()
            );

            // 12.7 ghost_invoices.csv
            $this->writeCsv(
                $dir,
                'ghost_invoices.csv',
                ['invoice_id', 'invoice_code', 'created_at', 'transaction_date', 'subtotal', 'total', 'status', 'customer_id', 'created_by_name'],
                $ghostInvoices->map(fn($row) => [
                    $row->invoice_id,
                    $row->invoice_code,
                    $row->created_at,
                    $row->transaction_date,
                    $row->subtotal,
                    $row->total,
                    $row->status,
                    $row->customer_id,
                    $row->created_by_name,
                ])->toArray()
            );

            // 12.8 subtotal_mismatch.csv
            $this->writeCsv(
                $dir,
                'subtotal_mismatch.csv',
                ['invoice_id', 'invoice_code', 'created_at', 'subtotal', 'item_revenue', 'diff', 'discount', 'total'],
                $subtotalMismatches->map(fn($row) => [
                    $row->invoice_id,
                    $row->invoice_code,
                    $row->created_at,
                    $row->subtotal,
                    $row->item_revenue,
                    $row->diff,
                    $row->discount,
                    $row->total,
                ])->toArray()
            );

            // 12.9 zero_price_items.csv
            $this->writeCsv(
                $dir,
                'zero_price_items.csv',
                ['invoice_code', 'product_id', 'sku', 'product_name', 'quantity', 'price', 'revenue', 'effective_cost', 'cogs', 'note'],
                $zeroPriceItems->map(fn($row) => [
                    $row->invoice_code,
                    $row->product_id,
                    $row->sku,
                    $row->product_name,
                    $row->quantity,
                    $row->price,
                    $row->subtotal,
                    $row->effective_cost,
                    $row->cogs,
                    $row->note,
                ])->toArray()
            );

            // 12.10 cashflow_pnl_category_audit.csv
            $this->writeCsv(
                $dir,
                'cashflow_pnl_category_audit.csv',
                ['type', 'category', 'reference_type', 'status', 'flow_count', 'total_amount', 'proposed_classification'],
                $cashflowsAudit->map(fn($row) => [
                    $row->type,
                    $row->category,
                    $row->reference_type,
                    $row->status,
                    $row->flow_count,
                    $row->total_amount,
                    $row->proposed_classification,
                ])->toArray()
            );

            // 12.11 paysheets_in_period.csv
            $this->writeCsv(
                $dir,
                'paysheets_in_period.csv',
                ['id', 'code', 'name', 'period_start', 'period_end', 'status', 'needs_recalc', 'branch_id', 'total_salary', 'total_paid', 'total_remaining', 'employee_count'],
                $paysheetsInPeriod->map(fn($row) => [
                    $row->id,
                    $row->code,
                    $row->name,
                    $row->period_start,
                    $row->period_end,
                    $row->status,
                    $row->needs_recalc,
                    $row->branch_id,
                    $row->total_salary,
                    $row->total_paid,
                    $row->total_remaining,
                    $row->employee_count,
                ])->toArray()
            );

            $this->info("CSV files export completed successfully.");
        }

        // Safety check to verify absolutely no modifications were written to the database
        $log = DB::getQueryLog();
        foreach ($log as $item) {
            $sql = strtolower($item['query']);
            if (str_contains($sql, 'insert ') || str_contains($sql, 'update ') || str_contains($sql, 'delete ') || str_contains($sql, 'truncate ') || str_contains($sql, 'alter ') || str_contains($sql, 'drop ')) {
                $this->error("CRITICAL: Write query detected: " . $item['query']);
                return self::FAILURE;
            }
        }

        $this->info("Dry-run audit completed successfully with absolute read-only safety.");
        return self::SUCCESS;
    }

    private function writeCsv(string $dir, string $filename, array $headers, array $rows): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir . '/' . $filename;
        $fp = fopen($path, 'w');
        // Add UTF-8 BOM
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($fp, $headers);
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);
    }

    private function pnlCashFlowBaseQuery(string $type, Carbon $startDate, Carbon $endDate, $branchId = null)
    {
        $timeColumn = Schema::hasColumn('cash_flows', 'time') ? 'time' : 'created_at';

        $excludedReferenceTypes = [
            'OrderReturn', 'PurchaseReturn', 'DebtOffset', 'DebtOffsetCancel', 'paysheet', 'Paysheet', 'PaysheetPayment'
        ];

        $excludedCategories = [
            'Chi tiền trả hàng khách', 'Chi tien tra hang khach', 'Chi trả hàng khách', 'Chi tra hang khach',
            'Thu tiền NCC trả hàng', 'Thu tien NCC tra hang', 'NCC hoàn tiền trả hàng', 'NCC hoan tien tra hang',
            'Đối trừ công nợ', 'Doi tru cong no', 'Hủy đối trừ công nợ', 'Huy doi tru cong no',
            'Chi lương nhân viên', 'Chi luong nhan vien', 'Lương nhân viên', 'Luong nhan vien', 'Thanh toán lương', 'Thanh toan luong',
            'Chi tiền trả NCC', 'Chi thanh toan NCC', 'Chi tiền trả NCC hàng', 'Chi tien tra NCC hang',
            'Điều chỉnh công nợ', 'Dieu chinh cong no', 'Chuyển/Rút', 'Chuyen/Rut', ''
        ];

        $query = CashFlow::active()
            ->where('type', $type)
            ->whereBetween($timeColumn, [$startDate, $endDate])
            ->where(function ($q) use ($excludedReferenceTypes) {
                $q->whereNull('reference_type')
                  ->orWhereNotIn('reference_type', $excludedReferenceTypes);
            })
            ->where(function ($q) use ($excludedCategories) {
                $q->whereNull('category')
                  ->orWhereNotIn('category', $excludedCategories);
            });

        if (Schema::hasColumn('cash_flows', 'accounting_result')) {
            $query->where('accounting_result', true);
        }

        if ($branchId && Schema::hasColumn('cash_flows', 'branch_id')) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    private function payrollExpenseAmount(Carbon $startDate, Carbon $endDate, $branchId = null): float
    {
        $query = Paysheet::query()
            ->whereIn('status', ['calculated', 'locked'])
            ->whereDate('period_start', '>=', $startDate->toDateString())
            ->whereDate('period_end', '<=', $endDate->toDateString());

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return (float) $query->sum('total_salary');
    }
}
