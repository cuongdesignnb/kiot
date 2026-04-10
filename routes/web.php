<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PriceSettingController;
use App\Http\Controllers\WarrantyController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\StockTakeController;
use App\Http\Controllers\DamageController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\OrderReturnController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TaskPageController;
use App\Http\Controllers\ReportController;

// Auth routes (guest)
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// All app routes require authentication
Route::middleware('auth')->group(function () {

Route::get('/', [DashboardController::class, 'index'])->middleware('permission:dashboard.view')->name('dashboard');

// ONE-TIME: Fix TẤT CẢ dữ liệu chấm công theo ca hiện tại
// Truy cập: /fix-schedules → xóa route này sau khi chạy xong
Route::get('/fix-schedules', function () {
    // Clear OPcache để đảm bảo code mới nhất
    if (function_exists('opcache_reset')) opcache_reset();

    $shifts = \App\Models\Shift::all()->keyBy('id');
    $result = ['version' => 'v5-direct-db', 'schedules_fixed' => 0, 'timekeeping_fixed' => 0, 'total_records' => 0, 'details' => []];

    // Bước 1: Sync EmployeeWorkSchedule times với Shift
    foreach ($shifts as $shift) {
        $count = \App\Models\EmployeeWorkSchedule::where('shift_id', $shift->id)
            ->where(function ($q) use ($shift) {
                $q->where('start_time', '!=', $shift->start_time)
                  ->orWhere('end_time', '!=', $shift->end_time);
            })
            ->update([
                'start_time' => $shift->start_time,
                'end_time' => $shift->end_time,
                'shift_name' => $shift->name,
            ]);
        $result['schedules_fixed'] += $count;
    }

    // Bước 2: Fix TẤT CẢ timekeeping_records (không chỉ sai schedule_end)
    $tkRecords = \App\Models\TimekeepingRecord::whereNotNull('shift_id')
        ->whereBetween('work_date', ['2026-03-01', '2026-03-31'])
        ->get();

    foreach ($tkRecords as $tk) {
        $shift = $shifts[$tk->shift_id] ?? null;
        if (!$shift) continue;

        $workDate = \Carbon\Carbon::parse($tk->work_date)->startOfDay();
        $newStart = $workDate->copy()->setTimeFromTimeString((string) $shift->start_time);
        $newEnd = $workDate->copy()->setTimeFromTimeString((string) $shift->end_time);
        if ($newEnd <= $newStart) $newEnd->addDay();

        // Tính lại OT bằng diffInSeconds + round() (khớp KiotViet)
        $otMinutes = 0;
        if ($tk->check_out_at && !$tk->is_holiday) {
            $checkOut = \Carbon\Carbon::parse($tk->check_out_at);
            if ($checkOut->greaterThan($newEnd)) {
                $otMinutes = (int) round(abs($checkOut->diffInSeconds($newEnd)) / 60);
            }
        }

        $oldOt = $tk->ot_minutes;
        if ($oldOt != $otMinutes || $tk->scheduled_end_at != $newEnd->toDateTimeString()) {
            // Dùng raw DB update để bypass model fillable/guarded
            \Illuminate\Support\Facades\DB::table('timekeeping_records')
                ->where('id', $tk->id)
                ->update([
                    'scheduled_start_at' => $newStart,
                    'scheduled_end_at' => $newEnd,
                    'ot_minutes' => $otMinutes,
                ]);

            $result['details'][] = [
                'date' => $workDate->format('d/m D'),
                'employee_id' => $tk->employee_id,
                'old_end' => $tk->scheduled_end_at ? \Carbon\Carbon::parse($tk->scheduled_end_at)->format('H:i') : null,
                'new_end' => $newEnd->format('H:i'),
                'checkout' => $tk->check_out_at ? \Carbon\Carbon::parse($tk->check_out_at)->format('H:i:s') : null,
                'old_ot' => $oldOt,
                'new_ot' => $otMinutes,
            ];
            $result['timekeeping_fixed']++;
        }
    }

    return response()->json($result);
});

// TEMP: Debug chi tiết OT theo ngày (có giây) — xóa sau debug
Route::get('/debug-ot2', function (\Illuminate\Http\Request $request) {
    $code = $request->query('employee', 'NV000028');
    $emp = \App\Models\Employee::where('code', $code)->first();
    if (!$emp) return response()->json(['error' => 'Not found']);

    $recs = \App\Models\TimekeepingRecord::where('employee_id', $emp->id)
        ->whereBetween('work_date', ['2026-03-01', '2026-03-31'])
        ->orderBy('work_date')->get();

    $weekdayOt = 0; $satOt = 0; $rows = [];

    foreach ($recs as $r) {
        $dow = \Carbon\Carbon::parse($r->work_date)->dayOfWeek;
        $type = $r->is_holiday ? 'Holiday' : ($dow === 0 ? 'CN' : ($dow === 6 ? 'T7' : 'Weekday'));

        if ($r->ot_minutes > 0) {
            if ($type === 'T7') $satOt += $r->ot_minutes;
            elseif ($type === 'Weekday') $weekdayOt += $r->ot_minutes;
        }

        // Tính toán OT (manual check) để so sánh
        $manualOt = 0;
        if ($r->check_out_at && $r->scheduled_end_at) {
            $co = \Carbon\Carbon::parse($r->check_out_at);
            $se = \Carbon\Carbon::parse($r->scheduled_end_at);
            if ($co->greaterThan($se)) {
                $manualOt = (int) round(abs($co->diffInSeconds($se)) / 60);
            }
        }

        $rows[] = [
            'date' => \Carbon\Carbon::parse($r->work_date)->format('d/m D'),
            'type' => $type,
            'check_in' => $r->check_in_at ? \Carbon\Carbon::parse($r->check_in_at)->format('H:i:s') : null,
            'check_out' => $r->check_out_at ? \Carbon\Carbon::parse($r->check_out_at)->format('H:i:s') : null,
            'schedule_end' => $r->scheduled_end_at ? \Carbon\Carbon::parse($r->scheduled_end_at)->format('H:i:s') : null,
            'shift_id' => $r->shift_id,
            'ot_db' => $r->ot_minutes,
            'ot_round' => $manualOt,
            'diff' => $manualOt - $r->ot_minutes,
            'manual_override' => $r->manual_override,
        ];
    }

    return response()->json([
        'employee' => $emp->name,
        'weekday_ot' => $weekdayOt . 'min = ' . floor($weekdayOt/60) . 'h' . ($weekdayOt%60) . 'p',
        'saturday_ot' => $satOt . 'min = ' . floor($satOt/60) . 'h' . ($satOt%60) . 'p',
        'total' => ($weekdayOt + $satOt) . 'min',
        'kiotviet' => 'weekday=339min(5h39p), sat=146min(2h26p), total=485min',
        'records' => $rows,
    ]);
});

// ONE-TIME: Fix timekeeping + recalculate salary (KHÔNG gọi recalculateForRange)
Route::get('/fix-and-recalc', function () {
    if (function_exists('opcache_reset')) opcache_reset();

    // Bước 1: Fix timekeeping OT
    $shifts = \App\Models\Shift::all()->keyBy('id');
    $fixed = 0;

    $tkRecords = \App\Models\TimekeepingRecord::whereNotNull('shift_id')
        ->whereBetween('work_date', ['2026-03-01', '2026-03-31'])
        ->get();

    foreach ($tkRecords as $tk) {
        $shift = $shifts[$tk->shift_id] ?? null;
        if (!$shift) continue;

        $workDate = \Carbon\Carbon::parse($tk->work_date)->startOfDay();
        $newStart = $workDate->copy()->setTimeFromTimeString((string) $shift->start_time);
        $newEnd = $workDate->copy()->setTimeFromTimeString((string) $shift->end_time);
        if ($newEnd <= $newStart) $newEnd->addDay();

        $otMinutes = 0;
        if ($tk->check_out_at && !$tk->is_holiday) {
            $checkOut = \Carbon\Carbon::parse($tk->check_out_at);
            if ($checkOut->greaterThan($newEnd)) {
                $otMinutes = (int) round(abs($checkOut->diffInSeconds($newEnd)) / 60);
            }
        }

        \Illuminate\Support\Facades\DB::table('timekeeping_records')
            ->where('id', $tk->id)
            ->update([
                'scheduled_start_at' => $newStart,
                'scheduled_end_at' => $newEnd,
                'ot_minutes' => $otMinutes,
            ]);
        if ($tk->ot_minutes != $otMinutes) $fixed++;
    }

    // Bước 2: Tính lại lương — tìm paysheet THÁNG 3/2026 (KHÔNG cancelled, KHÔNG locked)
    $allPaysheets = \App\Models\Paysheet::whereNotIn('status', ['locked', 'cancelled'])
        ->with('payslips')
        ->orderBy('period_start', 'desc')
        ->get();

    // Debug: liệt kê TẤT CẢ paysheets (kể cả cancelled) để tìm đúng
    $allPsDebug = \App\Models\Paysheet::orderBy('period_start', 'desc')
        ->get(['id', 'period_start', 'period_end', 'status', 'branch_id']);

    // Tìm paysheet tháng 3 cụ thể — ưu tiên period_end mới nhất (full tháng)
    $marchPaysheets = $allPaysheets->filter(function ($ps) {
        $start = \Carbon\Carbon::parse($ps->period_start);
        return $start->month == 3 && $start->year == 2026;
    })->sortByDesc('period_end');

    $paysheet = $marchPaysheets->first();

    // Fallback: paysheet đầu tiên overlap tháng 3
    if (!$paysheet) {
        $paysheet = $allPaysheets->filter(function ($ps) {
            return $ps->period_start <= '2026-03-31' && $ps->period_end >= '2026-03-01';
        })->sortByDesc('period_end')->first();
    }

    $salaryResults = [];
    $paysheetInfo = null;
    if ($paysheet) {
        $paysheetInfo = [
            'id' => $paysheet->id,
            'period_start' => $paysheet->period_start,
            'period_end' => $paysheet->period_end,
            'status' => $paysheet->status,
        ];
        $periodStart = \Carbon\Carbon::parse($paysheet->period_start);
        $periodEnd = \Carbon\Carbon::parse($paysheet->period_end);

        foreach ($paysheet->payslips as $slip) {
            $employee = \App\Models\Employee::with(['salarySetting'])->find($slip->employee_id);
            if (!$employee) continue;

            $calc = $employee->calculateSalaryForRange($periodStart, $periodEnd);

            $adjs = $slip->adjustments()->get();
            $adjBonus = $adjs->where('type', 'bonus')->sum('amount');
            $adjAllowance = $adjs->where('type', 'allowance')->sum('amount');
            $adjDeduction = $adjs->where('type', 'deduction')->sum('amount');
            $adjOt = $adjs->where('type', 'ot')->sum('amount');

            $autoOt = ($calc['ot_pay'] ?? 0) + ($calc['holiday_pay'] ?? 0);
            $autoLatePenalty = $calc['late_penalty'] ?? 0;

            $totalBonus = $adjs->where('type', 'bonus')->count() > 0 ? $adjBonus : ($calc['bonus'] ?? 0);
            $totalAllowance = $adjs->where('type', 'allowance')->count() > 0 ? $adjAllowance : ($calc['allowances'] ?? 0);
            $totalDeduction = $adjs->where('type', 'deduction')->count() > 0
                ? ($adjDeduction + $autoLatePenalty)
                : ($calc['deductions'] ?? 0);
            $totalOt = $autoOt + $adjOt;
            $totalSalary = max(0, $calc['base'] + $totalBonus + ($calc['commission'] ?? 0) + $totalAllowance + $totalOt - $totalDeduction);

            $slip->update([
                'base_salary' => $calc['base'],
                'bonus' => $totalBonus,
                'commission' => $calc['commission'] ?? 0,
                'allowances' => $totalAllowance,
                'deductions' => $totalDeduction,
                'ot_pay' => $totalOt,
                'total_salary' => $totalSalary,
                'remaining' => max(0, $totalSalary - $slip->paid_amount),
                'work_units' => $calc['work_units'],
                'paid_leave_units' => $calc['paid_leave_units'] ?? 0,
                'ot_minutes' => $calc['ot_minutes'] ?? 0,
                'details' => $calc,
            ]);

            $salaryResults[] = [
                'employee' => $employee->name,
                'ot_minutes' => $calc['ot_minutes'] ?? 0,
                'ot_pay_calc' => $calc['ot_pay'] ?? 0,
                'holiday_pay_calc' => $calc['holiday_pay'] ?? 0,
                'ot_pay_total' => $totalOt,
                'base' => $calc['base'] ?? 0,
                'work_units' => $calc['work_units'] ?? 0,
                'standard_work_units' => $calc['standard_work_units'] ?? 0,
                'repair_performance' => $calc['repair_performance'] ?? null,
                'total_salary' => $totalSalary,
                'ot_breakdown' => $calc['details']['ot'] ?? [],
            ];
        }

        $paysheet->status = 'calculated';
        $paysheet->needs_recalc = false;
        $paysheet->save();
        $paysheet->recalculateTotals();
    }

    return response()->json([
        'timekeeping_fixed' => $fixed,
        'paysheet_selected' => $paysheetInfo,
        'all_paysheets' => $allPsDebug,
        'salary_results' => $salaryResults,
    ]);
});

// ===== PRODUCTS =====
Route::middleware('permission:products.view')->group(function () {
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::get('/products/{product}/inventory-card', [ProductController::class, 'inventoryCard'])->name('products.inventory-card');
    Route::get('/products/{product}/serials', [ProductController::class, 'serials'])->name('products.serials');
    Route::get('/products/{product}/warranties', [ProductController::class, 'warranties'])->name('products.warranties');
    Route::get('/products/document-detail', [ProductController::class, 'documentDetail'])->name('products.document-detail');
});
Route::middleware('permission:products.create')->group(function () {
    Route::get('/products/create/{type?}', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::post('/products/quick-store', [ProductController::class, 'quickStore'])->name('products.quick-store');
    Route::post('/categories/quick-store', [SettingController::class, 'quickStoreCategory'])->name('categories.quick-store');
    Route::post('/brands/quick-store', [SettingController::class, 'quickStoreBrand'])->name('brands.quick-store');
});
Route::middleware('permission:products.edit')->group(function () {
    Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::post('/products/bulk-update-category', [ProductController::class, 'bulkUpdateCategory'])->name('products.bulk-update-category');
});
Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy')->middleware('permission:products.delete');
Route::middleware('permission:serials.create')->group(function () {
    Route::post('/products/{product}/serials', [ProductController::class, 'storeSerial'])->name('products.serials.store');
    Route::post('/products/{product}/serials/bulk', [ProductController::class, 'bulkStoreSerials'])->name('products.serials.bulk');
});
Route::put('/products/{product}/serials/{serial}', [ProductController::class, 'updateSerial'])->name('products.serials.update')->middleware('permission:serials.edit');
Route::delete('/products/{product}/serials/{serial}', [ProductController::class, 'destroySerial'])->name('products.serials.destroy')->middleware('permission:serials.delete');

// ===== CUSTOMERS =====
Route::get('/customers/search-for-merge', [CustomerController::class, 'searchForMerge']);
Route::middleware('permission:customers.view')->group(function () {
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{customer}/sales-history', [CustomerController::class, 'salesHistory']);
    Route::get('/customers/{customer}/debt-history', [CustomerController::class, 'debtHistory']);
    Route::get('/customers/{customer}/export-debt', [CustomerController::class, 'exportDebtHistory']);
    Route::get('/customers/{customer}/export-sales', [CustomerController::class, 'exportSalesHistory']);
});
Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store')->middleware('permission:customers.create');
Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update')->middleware('permission:customers.edit');
Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy')->middleware('permission:customers.delete');
Route::post('/customers/{customer}/merge', [CustomerController::class, 'merge'])->middleware('permission:customers.edit');
Route::post('/customers/{customer}/debt-offset', [CustomerController::class, 'debtOffset'])->middleware('permission:customers.edit');
Route::post('/customers/{customer}/cancel-debt-offset/{debtOffset}', [CustomerController::class, 'cancelDebtOffset'])->middleware('permission:customers.edit');
Route::get('/customers/{customer}/debt-offset-history', [CustomerController::class, 'debtOffsetHistory'])->middleware('permission:customers.view');

// ===== SUPPLIERS =====
Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index')->middleware('permission:suppliers.view');
Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store')->middleware('permission:suppliers.create');

// ===== PURCHASES =====
Route::get('/purchases/create', [PurchaseController::class, 'create'])->name('purchases.create')->middleware('permission:purchases.create');
Route::middleware('permission:purchases.view')->group(function () {
    Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
    Route::get('/purchases/{purchase}/detail', [PurchaseController::class, 'detail']);
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->name('purchases.show');
});
Route::middleware('permission:purchases.create')->group(function () {
    Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
    Route::put('/purchases/{purchase}', [PurchaseController::class, 'update'])->name('purchases.update');
    Route::delete('/purchases/{purchase}', [PurchaseController::class, 'destroy'])->name('purchases.destroy');
});

// ===== PURCHASE RETURNS =====
Route::get('/purchase-returns/create', [PurchaseReturnController::class, 'create'])->name('purchase-returns.create')->middleware('permission:purchases.create');
Route::middleware('permission:purchases.view')->group(function () {
    Route::get('/purchase-returns', [PurchaseReturnController::class, 'index'])->name('purchase-returns.index');
    Route::get('/purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'show'])->name('purchase-returns.show');
});
Route::middleware('permission:purchases.create')->group(function () {
    Route::post('/purchase-returns', [PurchaseReturnController::class, 'store'])->name('purchase-returns.store');
    Route::delete('/purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'destroy'])->name('purchase-returns.destroy');
});

// ===== PRICE SETTINGS =====
Route::middleware('permission:price_settings.view')->group(function () {
    Route::get('/price-settings', [PriceSettingController::class, 'index'])->name('price-settings.index');
});
Route::middleware('permission:price_settings.edit')->group(function () {
    Route::put('/price-settings/{product}', [PriceSettingController::class, 'update'])->name('price-settings.update');
    Route::post('/price-settings/apply-formula', [PriceSettingController::class, 'applyFormula'])->name('price-settings.apply-formula');
    Route::post('/price-settings/price-books', [PriceSettingController::class, 'storePriceBook'])->name('price-books.store');
    Route::put('/price-settings/price-books/{priceBook}', [PriceSettingController::class, 'updatePriceBook'])->name('price-books.update');
    Route::delete('/price-settings/price-books/{priceBook}', [PriceSettingController::class, 'destroyPriceBook'])->name('price-books.destroy');
    Route::put('/price-settings/price-books/{priceBook}/products/{product}', [PriceSettingController::class, 'updateBookPrice'])->name('price-books.update-price');
});
Route::get('/price-settings/export', [PriceSettingController::class, 'export'])->name('price-settings.export')->middleware('permission:price_settings.export');
Route::post('/price-settings/import', [PriceSettingController::class, 'import'])->name('price-settings.import')->middleware('permission:price_settings.import');

// ===== WARRANTIES =====
Route::get('/warranties', [WarrantyController::class, 'index'])->name('warranties.index')->middleware('permission:warranties.view');
Route::put('/warranties/{warranty}', [WarrantyController::class, 'update'])->name('warranties.update')->middleware('permission:warranties.edit');

// ===== STOCK TRANSFERS =====
Route::get('/stock-transfers', [StockTransferController::class, 'index'])->name('stock-transfers.index')->middleware('permission:stock_transfers.view');
Route::middleware('permission:stock_transfers.create')->group(function () {
    Route::get('/stock-transfers/create', [StockTransferController::class, 'create'])->name('stock-transfers.create');
    Route::post('/stock-transfers', [StockTransferController::class, 'store'])->name('stock-transfers.store');
});

// ===== STOCK TAKES =====
Route::get('/stock-takes', [StockTakeController::class, 'index'])->name('stock-takes.index')->middleware('permission:stock_takes.view');
Route::middleware('permission:stock_takes.create')->group(function () {
    Route::get('/stock-takes/create', [StockTakeController::class, 'create'])->name('stock-takes.create');
    Route::post('/stock-takes', [StockTakeController::class, 'store'])->name('stock-takes.store');
});

// ===== DAMAGES =====
Route::get('/damages', [DamageController::class, 'index'])->name('damages.index')->middleware('permission:damages.view');
Route::middleware('permission:damages.create')->group(function () {
    Route::get('/damages/create', [DamageController::class, 'create'])->name('damages.create');
    Route::post('/damages', [DamageController::class, 'store'])->name('damages.store');
});

// ===== PURCHASE ORDERS =====
Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index')->middleware('permission:purchase_orders.view');
Route::middleware('permission:purchase_orders.create')->group(function () {
    Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('purchase-orders.create');
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
});


// ===== INVOICES =====
Route::middleware('permission:invoices.view')->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/api/invoices/search', [InvoiceController::class, 'apiSearch'])->name('api.invoices.search');
    Route::get('/invoices/{invoice}/detail', [InvoiceController::class, 'detail']);
});
Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store')->middleware('permission:invoices.create');
Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy')->middleware('permission:invoices.delete');

// ===== RETURNS =====
Route::get('/returns', [OrderReturnController::class, 'index'])->name('returns.index')->middleware('permission:returns.view');
Route::post('/returns', [OrderReturnController::class, 'store'])->name('returns.store')->middleware('permission:returns.create');

// ===== ORDERS =====
Route::middleware('permission:orders.view')->group(function () {
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
});
Route::middleware('permission:orders.create')->group(function () {
    Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
});
Route::put('/orders/{order}', [OrderController::class, 'update'])->name('orders.update')->middleware('permission:orders.edit');

// ===== CASH FLOWS =====
Route::get('/cash-flows', [App\Http\Controllers\CashFlowController::class, 'index'])->name('cash_flows.index')->middleware('permission:cash_flows.view');
Route::post('/cash-flows', [App\Http\Controllers\CashFlowController::class, 'store'])->name('cash_flows.store')->middleware('permission:cash_flows.create');
Route::put('/cash-flows/{cash_flow}', [App\Http\Controllers\CashFlowController::class, 'update'])->name('cash_flows.update')->middleware('permission:cash_flows.edit');
Route::delete('/cash-flows/{cash_flow}', [App\Http\Controllers\CashFlowController::class, 'destroy'])->name('cash_flows.destroy')->middleware('permission:cash_flows.delete');
Route::get('/cash-flows/{cash_flow}/print', [App\Http\Controllers\CashFlowController::class, 'print'])->name('cash_flows.print')->middleware('permission:cash_flows.print');
Route::post('/cash-flows/subject', [App\Http\Controllers\CashFlowController::class, 'storeSubject'])->name('cash_flows.subject')->middleware('permission:cash_flows.create');

// ===== POS =====
Route::middleware('permission:pos.use')->group(function () {
    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::get('/api/pos/products', [PosController::class, 'searchProducts']);
    Route::post('/api/pos/checkout', [PosController::class, 'checkout']);
    Route::get('/api/products/{product}/serials', [PosController::class, 'getProductSerials']);
    Route::get('/api/pos/customers', [PosController::class, 'searchCustomers']);
    Route::post('/api/pos/customers', [PosController::class, 'quickCreateCustomer']);
});

// Product search API (shared by Orders, POS, etc.)
Route::get('/api/products/search', [ProductController::class, 'apiSearch'])->name('api.products.search');

// Customer debt management
Route::post('/customers/{customer}/debt-payment', [CustomerController::class, 'debtPayment'])->middleware('permission:customers.debt_payment');
Route::post('/customers/{customer}/debt-adjust', [CustomerController::class, 'debtAdjust'])->middleware('permission:customers.debt_adjust');

// Print & show routes
Route::get('/invoices/{invoice}/print', [InvoiceController::class, 'print'])->name('invoices.print')->middleware('permission:invoices.print');
Route::get('/invoices/{invoice}/show', [InvoiceController::class, 'show'])->name('invoices.show')->middleware('permission:invoices.view');
Route::get('/invoices/{invoice}/payment-history', [InvoiceController::class, 'paymentHistory'])->name('invoices.payment-history')->middleware('permission:invoices.view');
Route::get('/orders/{order}/print', [OrderController::class, 'print'])->name('orders.print')->middleware('permission:orders.print');
Route::get('/returns/{return}/print', [\App\Http\Controllers\OrderReturnController::class, 'print'])->name('returns.print')->middleware('permission:returns.print');
Route::get('/returns/{return}/show', [\App\Http\Controllers\OrderReturnController::class, 'show'])->name('returns.show')->middleware('permission:returns.view');
Route::get('/purchases/{purchase}/print', [\App\Http\Controllers\PurchaseController::class, 'print'])->name('purchases.print')->middleware('permission:purchases.print');
Route::get('/purchase-orders/{purchase_order}/print', [\App\Http\Controllers\PurchaseOrderController::class, 'print'])->name('purchase_orders.print')->middleware('permission:purchase_orders.print');
Route::get('/stock-takes/{stock_take}/print', [\App\Http\Controllers\StockTakeController::class, 'print'])->name('stock_takes.print')->middleware('permission:stock_takes.print');
Route::get('/stock-transfers/{stock_transfer}/print', [\App\Http\Controllers\StockTransferController::class, 'print'])->name('stock_transfers.print')->middleware('permission:stock_transfers.print');
Route::get('/damages/{damage}/print', [\App\Http\Controllers\DamageController::class, 'print'])->name('damages.print')->middleware('permission:damages.print');
Route::get('/warranties/{warranty}/print', [\App\Http\Controllers\WarrantyController::class, 'print'])->name('warranties.print')->middleware('permission:warranties.print');
Route::get('/paysheets/{paysheet}/print', [\App\Http\Controllers\PaysheetController::class, 'print'])->name('paysheets.print')->middleware('permission:paysheets.print');

// ===== SETTINGS =====
Route::get('/settings', [SettingController::class, 'index'])->middleware('permission:settings.view')->name('settings.index');
Route::post('/settings', [SettingController::class, 'update'])->middleware('permission:settings.manage')->name('settings.update');

// Category CRUD from Settings
Route::middleware('permission:settings.categories')->group(function () {
    Route::post('/settings/categories', [SettingController::class, 'storeCategory'])->name('settings.categories.store');
    Route::put('/settings/categories/{category}', [SettingController::class, 'updateCategory'])->name('settings.categories.update');
    Route::delete('/settings/categories/{category}', [SettingController::class, 'destroyCategory'])->name('settings.categories.destroy');
});

// Brand CRUD from Settings
Route::middleware('permission:settings.brands')->group(function () {
    Route::post('/settings/brands', [SettingController::class, 'storeBrand'])->name('settings.brands.store');
    Route::put('/settings/brands/{brand}', [SettingController::class, 'updateBrand'])->name('settings.brands.update');
    Route::delete('/settings/brands/{brand}', [SettingController::class, 'destroyBrand'])->name('settings.brands.destroy');
});

// Unit CRUD from Settings
Route::middleware('permission:settings.units')->group(function () {
    Route::post('/settings/units', [SettingController::class, 'storeUnit'])->name('settings.units.store');
    Route::put('/settings/units/{unit}', [SettingController::class, 'updateUnit'])->name('settings.units.update');
    Route::delete('/settings/units/{unit}', [SettingController::class, 'destroyUnit'])->name('settings.units.destroy');
});

// Attribute CRUD from Settings
Route::middleware('permission:settings.attributes')->group(function () {
    Route::post('/settings/attributes', [SettingController::class, 'storeAttribute'])->name('settings.attributes.store');
    Route::put('/settings/attributes/{attribute}', [SettingController::class, 'updateAttribute'])->name('settings.attributes.update');
    Route::delete('/settings/attributes/{attribute}', [SettingController::class, 'destroyAttribute'])->name('settings.attributes.destroy');
});

// Location CRUD from Settings
Route::middleware('permission:settings.locations')->group(function () {
    Route::post('/settings/locations', [SettingController::class, 'storeLocation'])->name('settings.locations.store');
    Route::put('/settings/locations/{location}', [SettingController::class, 'updateLocation'])->name('settings.locations.update');
    Route::delete('/settings/locations/{location}', [SettingController::class, 'destroyLocation'])->name('settings.locations.destroy');
});

// OtherFee CRUD from Settings
Route::middleware('permission:settings.other_fees')->group(function () {
    Route::post('/settings/other-fees', [SettingController::class, 'storeOtherFee'])->name('settings.other-fees.store');
    Route::put('/settings/other-fees/{otherFee}', [SettingController::class, 'updateOtherFee'])->name('settings.other-fees.update');
    Route::delete('/settings/other-fees/{otherFee}', [SettingController::class, 'destroyOtherFee'])->name('settings.other-fees.destroy');
});

// BankAccount CRUD from Settings
Route::middleware('permission:settings.bank_accounts')->group(function () {
    Route::post('/settings/bank-accounts', [SettingController::class, 'storeBankAccount'])->name('settings.bank-accounts.store');
    Route::put('/settings/bank-accounts/{bankAccount}', [SettingController::class, 'updateBankAccount'])->name('settings.bank-accounts.update');
    Route::delete('/settings/bank-accounts/{bankAccount}', [SettingController::class, 'destroyBankAccount'])->name('settings.bank-accounts.destroy');
});

// ===== EMPLOYEES =====
Route::middleware('permission:employees.view')->group(function () {
    Route::get('/employees/settings', [App\Http\Controllers\EmployeeController::class, 'settings'])->name('employees.settings');
    Route::get('/employees', [App\Http\Controllers\EmployeeController::class, 'index'])->name('employees.index');
});
Route::middleware('permission:employees.create')->group(function () {
    Route::post('/employees/bulk', [App\Http\Controllers\EmployeeController::class, 'bulkStore'])->name('employees.bulk-store');
    Route::post('/employees', [App\Http\Controllers\EmployeeController::class, 'store'])->name('employees.store');
});
Route::put('/employees/{employee}', [App\Http\Controllers\EmployeeController::class, 'update'])->name('employees.update')->middleware('permission:employees.edit');
Route::delete('/employees/{employee}', [App\Http\Controllers\EmployeeController::class, 'destroy'])->name('employees.destroy')->middleware('permission:employees.delete');

// Employee Sub-features
Route::middleware('permission:schedules.view')->group(function () {
    Route::get('/employees/schedules', function () {
        $employees = \App\Models\Employee::where('is_active', true)->orderBy('name')->get();
        $shifts = \App\Models\Shift::where('status', 'active')->orderBy('name')->get();
        return inertia('Employees/Schedules', [
            'employees' => $employees,
            'shifts' => $shifts,
        ]);
    })->name('employees.schedules');
});

Route::get('/employees/attendance/settings', [App\Http\Controllers\EmployeeController::class, 'attendanceSettings'])->name('employees.attendance-settings')->middleware('permission:attendance_settings.view');
Route::post('/employees/attendance/settings/preferences', [App\Http\Controllers\EmployeeController::class, 'saveAttendanceSettings'])->name('employees.attendance-settings.save')->middleware('permission:attendance_settings.manage');
Route::get('/employees/attendance/settings/shifts', [App\Http\Controllers\EmployeeController::class, 'attendanceShiftList'])->name('employees.attendance-settings.shifts')->middleware('permission:attendance_settings.view');
Route::get('/employees/attendance/settings/devices', [App\Http\Controllers\EmployeeController::class, 'attendanceDevices'])->name('employees.attendance-settings.devices')->middleware('permission:attendance_devices.view');
Route::get('/employees/payroll/settings', [App\Http\Controllers\EmployeeController::class, 'payrollSettings'])->name('employees.payroll-settings')->middleware('permission:payroll_settings.view');
Route::get('/employees/workday/settings', [App\Http\Controllers\EmployeeController::class, 'workdaySettings'])->name('employees.workday-settings')->middleware('permission:workday_settings.view');
Route::get('/employees/workday/settings/holidays', [App\Http\Controllers\EmployeeController::class, 'holidayManagement'])->name('employees.workday-settings.holidays')->middleware('permission:workday_settings.manage');

// ═══════════════════════════════════════
// REPORT routes
// ═══════════════════════════════════════
Route::get('/reports/debt-reconciliation', [ReportController::class, 'debtReconciliation'])->name('reports.debt-reconciliation');
Route::get('/reports/debt-reconciliation/export', [ReportController::class, 'exportDebtReconciliation'])->name('reports.debt-reconciliation.export');

Route::get('/employees/attendance', function () {
    return inertia('Employees/Attendance');
})->name('employees.attendance')->middleware('permission:attendance.view');

Route::get('/employees/paysheets', function () {
    $branches = \App\Models\Branch::orderBy('name')->get(['id', 'name']);
    $employees = \App\Models\Employee::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
    return inertia('Employees/Paysheets', [
        'branches' => $branches,
        'employees' => $employees,
    ]);
})->name('employees.paysheets')->middleware('permission:paysheets.view');

Route::get('/employees/paysheets/{id}/edit', [\App\Http\Controllers\PaysheetController::class, 'edit'])
    ->name('employees.paysheets.edit')->middleware('permission:paysheets.view');

// ===== Export / Import routes =====
Route::get('/customers/export', [App\Http\Controllers\CustomerController::class, 'export'])->name('customers.export')->middleware('permission:customers.export');
Route::post('/customers/import', [App\Http\Controllers\CustomerController::class, 'import'])->name('customers.import')->middleware('permission:customers.import');

Route::get('/suppliers/export', [App\Http\Controllers\SupplierController::class, 'export'])->name('suppliers.export')->middleware('permission:suppliers.export');
Route::post('/suppliers/import', [App\Http\Controllers\SupplierController::class, 'import'])->name('suppliers.import')->middleware('permission:suppliers.import');

Route::get('/employees/export', [App\Http\Controllers\EmployeeController::class, 'export'])->name('employees.export')->middleware('permission:employees.export');
Route::post('/employees/import', [App\Http\Controllers\EmployeeController::class, 'import'])->name('employees.import')->middleware('permission:employees.import');

Route::get('/products/export', [App\Http\Controllers\ProductController::class, 'export'])->name('products.export')->middleware('permission:products.export');
Route::post('/products/import', [App\Http\Controllers\ProductController::class, 'import'])->name('products.import')->middleware('permission:products.import');

Route::get('/invoices/export', [App\Http\Controllers\InvoiceController::class, 'export'])->name('invoices.export')->middleware('permission:invoices.export');
Route::get('/orders/export', [App\Http\Controllers\OrderController::class, 'export'])->name('orders.export')->middleware('permission:orders.export');
Route::get('/returns/export', [App\Http\Controllers\OrderReturnController::class, 'export'])->name('returns.export')->middleware('permission:returns.export');

Route::get('/cash-flows/export', [App\Http\Controllers\CashFlowController::class, 'export'])->name('cash_flows.export')->middleware('permission:cash_flows.export');
Route::post('/cash-flows/import', [App\Http\Controllers\CashFlowController::class, 'import'])->name('cash_flows.import')->middleware('permission:cash_flows.import');

Route::get('/purchases/export', [App\Http\Controllers\PurchaseController::class, 'export'])->name('purchases.export')->middleware('permission:purchases.export');
Route::get('/purchase-returns/export', [App\Http\Controllers\PurchaseReturnController::class, 'export'])->name('purchase-returns.export')->middleware('permission:purchases.export');
Route::get('/purchase-orders/export', [App\Http\Controllers\PurchaseOrderController::class, 'export'])->name('purchase-orders.export')->middleware('permission:purchase_orders.export');
Route::get('/stock-takes/export', [App\Http\Controllers\StockTakeController::class, 'export'])->name('stock-takes.export')->middleware('permission:stock_takes.export');
Route::get('/stock-transfers/export', [App\Http\Controllers\StockTransferController::class, 'export'])->name('stock-transfers.export')->middleware('permission:stock_transfers.export');
Route::get('/damages/export', [App\Http\Controllers\DamageController::class, 'export'])->name('damages.export')->middleware('permission:damages.export');
Route::get('/warranties/export', [App\Http\Controllers\WarrantyController::class, 'export'])->name('warranties.export')->middleware('permission:warranties.export');
Route::get('/paysheets/export', [App\Http\Controllers\PaysheetController::class, 'export'])->name('paysheets.export')->middleware('permission:paysheets.export');

// ======================
// � TASKS (unified: repairs + general)
// =======================
Route::get('/tasks', [TaskPageController::class, 'index'])->middleware('permission:tasks.view')->name('tasks.index');
Route::get('/tasks/performance', [TaskPageController::class, 'performance'])->middleware('permission:tasks.view')->name('tasks.performance');
Route::get('/tasks/{id}', [TaskPageController::class, 'show'])->middleware('permission:tasks.view')->name('tasks.show');
Route::get('/my-tasks', [TaskPageController::class, 'myTasks'])->name('my-tasks');

// Backward compat: redirect old repair routes
Route::get('/repairs', fn () => redirect('/tasks?type=repair'));
Route::get('/repairs/performance', fn () => redirect('/tasks/performance'));
Route::get('/repairs/{id}', fn ($id) => redirect("/tasks/$id"));

}); // end auth middleware
