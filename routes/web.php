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
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TaskPageController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\EndOfDayReportController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\OrderReportController;
use App\Http\Controllers\ProductReportController;
use App\Http\Controllers\CustomerReportController;
use App\Http\Controllers\SupplierReportController;
use App\Http\Controllers\EmployeeReportController;
use App\Http\Controllers\FinancialReportController;

// Auth routes (guest)
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// All app routes require authentication
Route::middleware('auth')->group(function () {

Route::get('/run-migrations', function () {
    return \Illuminate\Support\Facades\Schema::getColumnListing('invoices');
});

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::get('/check-schema', function () {
    return response()->json(\Illuminate\Support\Facades\Schema::getColumnListing('invoices'));
});

// =======================
// 📦 PRODUCTS
// =======================
Route::get('/products', [ProductController::class, 'index'])->middleware('permission:products.view')->name('products.index');
Route::get('/products/create/{type?}', [ProductController::class, 'create'])->middleware('permission:products.create')->name('products.create');
Route::post('/products', [ProductController::class, 'store'])->middleware('permission:products.create')->name('products.store');
Route::post('/products/quick-store', [ProductController::class, 'quickStore'])->middleware('permission:products.create')->name('products.quick-store');
Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->middleware('permission:products.edit')->name('products.edit');
Route::put('/products/{product}', [ProductController::class, 'update'])->middleware('permission:products.edit')->name('products.update');
Route::delete('/products/{product}', [ProductController::class, 'destroy'])->middleware('permission:products.delete')->name('products.destroy');
Route::post('/products/bulk-update-category', [ProductController::class, 'bulkUpdateCategory'])->middleware('permission:products.edit')->name('products.bulk-update-category');
Route::post('/products/bulk-destroy', [ProductController::class, 'bulkDestroy'])->middleware('permission:products.delete')->name('products.bulk-destroy');
Route::get('/products/{product}/inventory-card', [ProductController::class, 'inventoryCard'])->middleware('permission:products.view')->name('products.inventory-card');
Route::get('/products/document-detail', [ProductController::class, 'documentDetail'])->middleware('permission:products.view')->name('products.document-detail');

// Serials
Route::get('/products/{product}/serials', [ProductController::class, 'serials'])->middleware('permission:products.view')->name('products.serials');
Route::post('/products/{product}/serials', [ProductController::class, 'storeSerial'])->middleware('permission:serials.create')->name('products.serials.store');
Route::post('/products/{product}/serials/bulk', [ProductController::class, 'bulkStoreSerials'])->middleware('permission:serials.create')->name('products.serials.bulk');
Route::put('/products/{product}/serials/{serial}', [ProductController::class, 'updateSerial'])->middleware('permission:serials.edit')->name('products.serials.update');
Route::delete('/products/{product}/serials/{serial}', [ProductController::class, 'destroySerial'])->middleware('permission:serials.delete')->name('products.serials.destroy');
Route::get('/products/{product}/warranties', [ProductController::class, 'warranties'])->middleware('permission:warranties.view')->name('products.warranties');

// Product search API (shared by Orders, POS, etc.) - no extra permission, needs auth only
Route::get('/api/products/search', [ProductController::class, 'apiSearch'])->name('api.products.search');

// =======================
// 💰 PRICE SETTINGS
// =======================
Route::get('/price-settings', [PriceSettingController::class, 'index'])->middleware('permission:price_settings.view')->name('price-settings.index');
Route::put('/price-settings/{product}', [PriceSettingController::class, 'update'])->middleware('permission:price_settings.edit')->name('price-settings.update');
Route::post('/price-settings/apply-formula', [PriceSettingController::class, 'applyFormula'])->middleware('permission:price_settings.edit')->name('price-settings.apply-formula');
Route::get('/price-settings/export', [PriceSettingController::class, 'export'])->middleware('permission:price_settings.export')->name('price-settings.export');
Route::post('/price-settings/import', [PriceSettingController::class, 'import'])->middleware('permission:price_settings.import')->name('price-settings.import');
Route::post('/price-settings/price-books', [PriceSettingController::class, 'storePriceBook'])->middleware('permission:price_settings.edit')->name('price-books.store');
Route::put('/price-settings/price-books/{priceBook}', [PriceSettingController::class, 'updatePriceBook'])->middleware('permission:price_settings.edit')->name('price-books.update');
Route::delete('/price-settings/price-books/{priceBook}', [PriceSettingController::class, 'destroyPriceBook'])->middleware('permission:price_settings.edit')->name('price-books.destroy');
Route::put('/price-settings/price-books/{priceBook}/products/{product}', [PriceSettingController::class, 'updateBookPrice'])->middleware('permission:price_settings.edit')->name('price-books.update-price');

// =======================
// 🛡️ WARRANTIES
// =======================
Route::get('/warranties', [WarrantyController::class, 'index'])->middleware('permission:warranties.view')->name('warranties.index');
Route::put('/warranties/{warranty}', [WarrantyController::class, 'update'])->middleware('permission:warranties.edit')->name('warranties.update');

// =======================
// 🏪 STOCK (Kho hàng)
// =======================
Route::get('/stock-transfers', [StockTransferController::class, 'index'])->middleware('permission:stock_transfers.view')->name('stock-transfers.index');
Route::get('/stock-transfers/create', [StockTransferController::class, 'create'])->middleware('permission:stock_transfers.create')->name('stock-transfers.create');
Route::post('/stock-transfers', [StockTransferController::class, 'store'])->middleware('permission:stock_transfers.create')->name('stock-transfers.store');

Route::get('/stock-takes', [StockTakeController::class, 'index'])->middleware('permission:stock_takes.view')->name('stock-takes.index');
Route::get('/stock-takes/create', [StockTakeController::class, 'create'])->middleware('permission:stock_takes.create')->name('stock-takes.create');
Route::post('/stock-takes', [StockTakeController::class, 'store'])->middleware('permission:stock_takes.create')->name('stock-takes.store');

Route::get('/damages', [DamageController::class, 'index'])->middleware('permission:damages.view')->name('damages.index');
Route::get('/damages/create', [DamageController::class, 'create'])->middleware('permission:damages.create')->name('damages.create');
Route::post('/damages', [DamageController::class, 'store'])->middleware('permission:damages.create')->name('damages.store');

// =======================
// 📥 PURCHASES (Nhập hàng)
// =======================
Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('permission:suppliers.view')->name('suppliers.index');
Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('permission:suppliers.create')->name('suppliers.store');

Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchase_orders.view')->name('purchase-orders.index');
Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->middleware('permission:purchase_orders.create')->name('purchase-orders.create');
Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchase_orders.create')->name('purchase-orders.store');

Route::get('/purchases', [PurchaseController::class, 'index'])->middleware('permission:purchases.view')->name('purchases.index');
Route::get('/purchases/create', [PurchaseController::class, 'create'])->middleware('permission:purchases.create')->name('purchases.create');
Route::post('/purchases', [PurchaseController::class, 'store'])->middleware('permission:purchases.create')->name('purchases.store');
Route::get('/purchases/{purchase}/edit', [PurchaseController::class, 'edit'])->middleware('permission:purchases.create')->name('purchases.edit');
Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->middleware('permission:purchases.view')->name('purchases.show');
Route::put('/purchases/{purchase}', [PurchaseController::class, 'update'])->middleware('permission:purchases.create')->name('purchases.update');
Route::delete('/purchases/{purchase}', [PurchaseController::class, 'destroy'])->middleware('permission:purchases.create')->name('purchases.destroy');

// Quick store for categories/brands (shared)
Route::post('/categories/quick-store', [SettingController::class, 'quickStoreCategory'])->name('categories.quick-store');
Route::post('/brands/quick-store', [SettingController::class, 'quickStoreBrand'])->name('brands.quick-store');

// =======================
// 🛒 ORDERS & INVOICES (Đơn hàng)
// =======================
Route::get('/orders', [OrderController::class, 'index'])->middleware('permission:orders.view')->name('orders.index');
Route::get('/orders/create', [OrderController::class, 'create'])->middleware('permission:orders.create')->name('orders.create');
Route::post('/orders', [OrderController::class, 'store'])->middleware('permission:orders.create')->name('orders.store');
Route::put('/orders/{order}', [OrderController::class, 'update'])->middleware('permission:orders.edit')->name('orders.update');

Route::get('/api/customers/search', [CustomerController::class, 'apiSearch'])->middleware('permission:customers.view')->name('api.customers.search');
Route::get('/api/invoices/search', [InvoiceController::class, 'apiSearch'])->middleware('permission:invoices.view')->name('api.invoices.search');

// Suppliers API
Route::get('/api/suppliers/search', [SupplierController::class, 'apiSearch'])->name('api.suppliers.search');
Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('permission:invoices.view')->name('invoices.index');
Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('permission:invoices.create')->name('invoices.store');
Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('permission:invoices.create')->name('invoices.update');
Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->middleware('permission:invoices.delete')->name('invoices.destroy');

Route::get('/returns', [OrderReturnController::class, 'index'])->middleware('permission:returns.view')->name('returns.index');
Route::post('/returns', [OrderReturnController::class, 'store'])->middleware('permission:returns.create')->name('returns.store');

// =======================
// 💵 CASH FLOWS (Sổ quỹ)
// =======================
Route::get('/cash-flows', [CashFlowController::class, 'index'])->middleware('permission:cash_flows.view')->name('cash_flows.index');
Route::post('/cash-flows', [CashFlowController::class, 'store'])->middleware('permission:cash_flows.create')->name('cash_flows.store');
Route::put('/cash-flows/{cash_flow}', [CashFlowController::class, 'update'])->middleware('permission:cash_flows.edit')->name('cash_flows.update');
Route::delete('/cash-flows/{cash_flow}', [CashFlowController::class, 'destroy'])->middleware('permission:cash_flows.delete')->name('cash_flows.destroy');
Route::get('/cash-flows/{cash_flow}/print', [CashFlowController::class, 'print'])->middleware('permission:cash_flows.print')->name('cash_flows.print');
Route::post('/cash-flows/subject', [CashFlowController::class, 'storeSubject'])->middleware('permission:cash_flows.create')->name('cash_flows.subject');

// =======================
// 🏬 POS (Bán hàng)
// =======================
Route::get('/pos', [PosController::class, 'index'])->middleware('permission:pos.use')->name('pos.index');
Route::get('/api/pos/products', [PosController::class, 'searchProducts'])->middleware('permission:pos.use');
Route::post('/api/pos/checkout', [PosController::class, 'checkout'])->middleware('permission:pos.use');
Route::get('/api/products/{product}/serials', [PosController::class, 'getProductSerials']);
Route::get('/api/pos/customers', [PosController::class, 'searchCustomers'])->middleware('permission:pos.use');
Route::post('/api/pos/customers', [PosController::class, 'quickCreateCustomer'])->middleware('permission:pos.use');
Route::get('/api/pos/suppliers', [PosController::class, 'searchSuppliers'])->middleware('permission:pos.use');

// =======================
// 👤 CUSTOMERS (Khách hàng)
// =======================
Route::get('/customers', [CustomerController::class, 'index'])->middleware('permission:customers.view')->name('customers.index');
Route::post('/customers', [CustomerController::class, 'store'])->middleware('permission:customers.create')->name('customers.store');
Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.edit')->name('customers.update');
Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.delete')->name('customers.destroy');
Route::get('/customers/{customer}/sales-history', [CustomerController::class, 'salesHistory'])->middleware('permission:customers.view');
Route::get('/customers/{customer}/debt-history', [CustomerController::class, 'debtHistory'])->middleware('permission:customers.debt_view');
Route::post('/customers/{customer}/debt-payment', [CustomerController::class, 'debtPayment'])->middleware('permission:customers.debt_payment');
Route::post('/customers/{customer}/debt-adjust', [CustomerController::class, 'debtAdjust'])->middleware('permission:customers.debt_adjust');

// =======================
// 🖨️ PRINT routes
// =======================
Route::get('/invoices/{invoice}/print', [InvoiceController::class, 'print'])->middleware('permission:invoices.print')->name('invoices.print');
Route::get('/invoices/{invoice}/payment-history', [InvoiceController::class, 'paymentHistory'])->middleware('permission:invoices.view')->name('invoices.payment-history');
Route::get('/orders/{order}/print', [OrderController::class, 'print'])->middleware('permission:orders.print')->name('orders.print');
Route::get('/returns/{return}/print', [OrderReturnController::class, 'print'])->middleware('permission:returns.print')->name('returns.print');
Route::get('/purchases/{purchase}/print', [PurchaseController::class, 'print'])->middleware('permission:purchases.print')->name('purchases.print');
Route::get('/purchase-orders/{purchase_order}/print', [PurchaseOrderController::class, 'print'])->middleware('permission:purchase_orders.print')->name('purchase_orders.print');
Route::get('/stock-takes/{stock_take}/print', [StockTakeController::class, 'print'])->middleware('permission:stock_takes.print')->name('stock_takes.print');
Route::get('/stock-transfers/{stock_transfer}/print', [StockTransferController::class, 'print'])->middleware('permission:stock_transfers.print')->name('stock_transfers.print');
Route::get('/damages/{damage}/print', [DamageController::class, 'print'])->middleware('permission:damages.print')->name('damages.print');
Route::get('/warranties/{warranty}/print', [WarrantyController::class, 'print'])->middleware('permission:warranties.print')->name('warranties.print');
Route::get('/paysheets/{paysheet}/print', [App\Http\Controllers\PaysheetController::class, 'print'])->middleware('permission:paysheets.print')->name('paysheets.print');

// =======================
// 👥 USER MANAGEMENT
// =======================
Route::get('/users', [App\Http\Controllers\UserController::class, 'index'])->middleware('permission:users.view')->name('users.index');
Route::post('/users', [App\Http\Controllers\UserController::class, 'store'])->middleware('permission:users.create')->name('users.store');
Route::put('/users/{user}', [App\Http\Controllers\UserController::class, 'update'])->middleware('permission:users.edit')->name('users.update');
Route::post('/users/{user}/change-password', [App\Http\Controllers\UserController::class, 'changePassword'])->middleware('permission:users.edit')->name('users.change-password');
Route::post('/users/{user}/toggle-status', [App\Http\Controllers\UserController::class, 'toggleStatus'])->middleware('permission:users.edit')->name('users.toggle-status');
Route::delete('/users/{user}', [App\Http\Controllers\UserController::class, 'destroy'])->middleware('permission:users.delete')->name('users.destroy');

// =======================
// ⚙️ SETTINGS
// =======================
Route::get('/settings', [SettingController::class, 'index'])->middleware('permission:settings.view')->name('settings.index');
Route::post('/settings', [SettingController::class, 'update'])->middleware('permission:settings.manage')->name('settings.update');

Route::post('/settings/categories', [SettingController::class, 'storeCategory'])->middleware('permission:settings.categories')->name('settings.categories.store');
Route::put('/settings/categories/{category}', [SettingController::class, 'updateCategory'])->middleware('permission:settings.categories')->name('settings.categories.update');
Route::delete('/settings/categories/{category}', [SettingController::class, 'destroyCategory'])->middleware('permission:settings.categories')->name('settings.categories.destroy');

Route::post('/settings/brands', [SettingController::class, 'storeBrand'])->middleware('permission:settings.brands')->name('settings.brands.store');
Route::put('/settings/brands/{brand}', [SettingController::class, 'updateBrand'])->middleware('permission:settings.brands')->name('settings.brands.update');
Route::delete('/settings/brands/{brand}', [SettingController::class, 'destroyBrand'])->middleware('permission:settings.brands')->name('settings.brands.destroy');

Route::post('/settings/units', [SettingController::class, 'storeUnit'])->middleware('permission:settings.units')->name('settings.units.store');
Route::put('/settings/units/{unit}', [SettingController::class, 'updateUnit'])->middleware('permission:settings.units')->name('settings.units.update');
Route::delete('/settings/units/{unit}', [SettingController::class, 'destroyUnit'])->middleware('permission:settings.units')->name('settings.units.destroy');

Route::post('/settings/attributes', [SettingController::class, 'storeAttribute'])->middleware('permission:settings.attributes')->name('settings.attributes.store');
Route::put('/settings/attributes/{attribute}', [SettingController::class, 'updateAttribute'])->middleware('permission:settings.attributes')->name('settings.attributes.update');
Route::delete('/settings/attributes/{attribute}', [SettingController::class, 'destroyAttribute'])->middleware('permission:settings.attributes')->name('settings.attributes.destroy');

Route::post('/settings/locations', [SettingController::class, 'storeLocation'])->middleware('permission:settings.locations')->name('settings.locations.store');
Route::put('/settings/locations/{location}', [SettingController::class, 'updateLocation'])->middleware('permission:settings.locations')->name('settings.locations.update');
Route::delete('/settings/locations/{location}', [SettingController::class, 'destroyLocation'])->middleware('permission:settings.locations')->name('settings.locations.destroy');

Route::post('/settings/other-fees', [SettingController::class, 'storeOtherFee'])->middleware('permission:settings.other_fees')->name('settings.other-fees.store');
Route::put('/settings/other-fees/{otherFee}', [SettingController::class, 'updateOtherFee'])->middleware('permission:settings.other_fees')->name('settings.other-fees.update');
Route::delete('/settings/other-fees/{otherFee}', [SettingController::class, 'destroyOtherFee'])->middleware('permission:settings.other_fees')->name('settings.other-fees.destroy');

Route::post('/settings/bank-accounts', [SettingController::class, 'storeBankAccount'])->middleware('permission:settings.bank_accounts')->name('settings.bank-accounts.store');
Route::put('/settings/bank-accounts/{bankAccount}', [SettingController::class, 'updateBankAccount'])->middleware('permission:settings.bank_accounts')->name('settings.bank-accounts.update');
Route::delete('/settings/bank-accounts/{bankAccount}', [SettingController::class, 'destroyBankAccount'])->middleware('permission:settings.bank_accounts')->name('settings.bank-accounts.destroy');

// =======================
// 👷 EMPLOYEES (Nhân viên)
// =======================
Route::get('/employees', [App\Http\Controllers\EmployeeController::class, 'index'])->middleware('permission:employees.view')->name('employees.index');
Route::post('/employees', [App\Http\Controllers\EmployeeController::class, 'store'])->middleware('permission:employees.create')->name('employees.store');
Route::post('/employees/bulk', [App\Http\Controllers\EmployeeController::class, 'bulkStore'])->middleware('permission:employees.create')->name('employees.bulk-store');
Route::put('/employees/{employee}', [App\Http\Controllers\EmployeeController::class, 'update'])->middleware('permission:employees.edit')->name('employees.update');
Route::delete('/employees/{employee}', [App\Http\Controllers\EmployeeController::class, 'destroy'])->middleware('permission:employees.delete')->name('employees.destroy');
Route::get('/employees/settings', [App\Http\Controllers\EmployeeController::class, 'settings'])->middleware('permission:employees.view')->name('employees.settings');

Route::get('/employees/schedules', function () {
    $employees = \App\Models\Employee::where('is_active', true)->orderBy('name')->get();
    $shifts = \App\Models\Shift::where('status', 'active')->orderBy('name')->get();
    return inertia('Employees/Schedules', [
        'employees' => $employees,
        'shifts' => $shifts,
    ]);
})->middleware('permission:schedules.view')->name('employees.schedules');

Route::get('/employees/attendance/settings', [App\Http\Controllers\EmployeeController::class, 'attendanceSettings'])->middleware('permission:attendance_settings.view')->name('employees.attendance-settings');
Route::post('/employees/attendance/settings/preferences', [App\Http\Controllers\EmployeeController::class, 'saveAttendanceSettings'])->middleware('permission:attendance_settings.manage')->name('employees.attendance-settings.save');
Route::get('/employees/attendance/settings/shifts', [App\Http\Controllers\EmployeeController::class, 'attendanceShiftList'])->middleware('permission:attendance_settings.view')->name('employees.attendance-settings.shifts');
Route::get('/employees/attendance/settings/devices', [App\Http\Controllers\EmployeeController::class, 'attendanceDevices'])->middleware('permission:attendance_devices.view')->name('employees.attendance-settings.devices');
Route::get('/employees/payroll/settings', [App\Http\Controllers\EmployeeController::class, 'payrollSettings'])->middleware('permission:payroll_settings.view')->name('employees.payroll-settings');
Route::get('/employees/workday/settings', [App\Http\Controllers\EmployeeController::class, 'workdaySettings'])->middleware('permission:workday_settings.view')->name('employees.workday-settings');
Route::get('/employees/workday/settings/holidays', [App\Http\Controllers\EmployeeController::class, 'holidayManagement'])->middleware('permission:workday_settings.view')->name('employees.workday-settings.holidays');

Route::get('/employees/attendance', function () {
    return inertia('Employees/Attendance');
})->middleware('permission:attendance.view')->name('employees.attendance');

Route::get('/employees/paysheets', function () {
    $branches = \App\Models\Branch::orderBy('name')->get(['id', 'name']);
    $employees = \App\Models\Employee::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
    return inertia('Employees/Paysheets', [
        'branches' => $branches,
        'employees' => $employees,
    ]);
})->middleware('permission:paysheets.view')->name('employees.paysheets');

// =======================
// 📤 EXPORT / IMPORT routes
// =======================
Route::get('/customers/export', [CustomerController::class, 'export'])->middleware('permission:customers.export')->name('customers.export');
Route::post('/customers/import', [CustomerController::class, 'import'])->middleware('permission:customers.import')->name('customers.import');

Route::get('/suppliers/export', [SupplierController::class, 'export'])->middleware('permission:suppliers.export')->name('suppliers.export');
Route::post('/suppliers/import', [SupplierController::class, 'import'])->middleware('permission:suppliers.import')->name('suppliers.import');

Route::get('/employees/export', [App\Http\Controllers\EmployeeController::class, 'export'])->middleware('permission:employees.export')->name('employees.export');
Route::post('/employees/import', [App\Http\Controllers\EmployeeController::class, 'import'])->middleware('permission:employees.import')->name('employees.import');

Route::get('/products/export', [ProductController::class, 'export'])->middleware('permission:products.export')->name('products.export');
Route::post('/products/import', [ProductController::class, 'import'])->middleware('permission:products.import')->name('products.import');

Route::get('/invoices/export', [InvoiceController::class, 'export'])->middleware('permission:invoices.export')->name('invoices.export');
Route::get('/orders/export', [OrderController::class, 'export'])->middleware('permission:orders.export')->name('orders.export');
Route::get('/returns/export', [OrderReturnController::class, 'export'])->middleware('permission:returns.export')->name('returns.export');

Route::get('/cash-flows/export', [CashFlowController::class, 'export'])->middleware('permission:cash_flows.export')->name('cash_flows.export');
Route::post('/cash-flows/import', [CashFlowController::class, 'import'])->middleware('permission:cash_flows.import')->name('cash_flows.import');

Route::get('/purchases/export', [PurchaseController::class, 'export'])->middleware('permission:purchases.export')->name('purchases.export');
Route::get('/purchase-orders/export', [PurchaseOrderController::class, 'export'])->middleware('permission:purchase_orders.export')->name('purchase-orders.export');
Route::get('/stock-takes/export', [StockTakeController::class, 'export'])->middleware('permission:stock_takes.export')->name('stock-takes.export');
Route::get('/stock-transfers/export', [StockTransferController::class, 'export'])->middleware('permission:stock_transfers.export')->name('stock-transfers.export');
Route::get('/damages/export', [DamageController::class, 'export'])->middleware('permission:damages.export')->name('damages.export');
Route::get('/warranties/export', [WarrantyController::class, 'export'])->middleware('permission:warranties.export')->name('warranties.export');
Route::get('/paysheets/export', [App\Http\Controllers\PaysheetController::class, 'export'])->middleware('permission:paysheets.export')->name('paysheets.export');

// =======================
// 🔧 TASKS (unified: repairs + general)
// =======================
Route::get('/tasks', [TaskPageController::class, 'index'])->middleware('permission:tasks.view')->name('tasks.index');
Route::get('/tasks/performance', [TaskPageController::class, 'performance'])->middleware('permission:tasks.view')->name('tasks.performance');
Route::get('/tasks/{id}', [TaskPageController::class, 'show'])->name('tasks.show'); // quyền check ở API level
Route::get('/my-tasks', [TaskPageController::class, 'myTasks'])->name('my-tasks');

// Backward compat: redirect old repair routes
Route::get('/repairs', fn () => redirect('/tasks?type=repair'));
Route::get('/repairs/performance', fn () => redirect('/tasks/performance'));
Route::get('/repairs/{id}', fn ($id) => redirect("/tasks/$id"));

// 📜 Activity Logs (Lịch sử thao tác)
Route::get('/activity-logs', fn () => \Inertia\Inertia::render('ActivityLogs/Index', [
    'employees' => \App\Models\Employee::where('is_active', true)->select('id', 'name')->get(),
]))->name('activity-logs.index');

// Legacy migration routes (admin only, no permission needed - behind auth)
Route::get('/run-migrate', function () {
    try {
        \Illuminate\Support\Facades\Schema::dropIfExists('return_items');
        \Illuminate\Support\Facades\Schema::dropIfExists('returns');

        \Illuminate\Support\Facades\Schema::create('returns', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('status')->default('Đã trả');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('fee', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('paid_to_customer', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->string('created_by_name')->nullable();
            $table->string('seller_name')->nullable();
            $table->string('sales_channel')->nullable();
            $table->string('price_book_name')->nullable();
            $table->timestamps();
        });

        \Illuminate\Support\Facades\Schema::create('return_items', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('returns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->integer('quantity')->default(1);
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('import_price', 15, 2)->default(0);
            $table->timestamps();
        });

        return 'Migrated Returns Tables directly.';
    } catch (\Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
});

Route::get('/run-migrate-2', function () {
    try {
        \Illuminate\Support\Facades\Schema::dropIfExists('return_items');
        \Illuminate\Support\Facades\Schema::dropIfExists('returns');

        \Illuminate\Support\Facades\Schema::create('returns', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('status')->default('Đã trả');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('fee', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('paid_to_customer', 15, 2)->default(0);
            $table->text('note')->nullable();
            $table->string('created_by_name')->nullable();
            $table->string('seller_name')->nullable();
            $table->string('sales_channel')->nullable();
            $table->string('price_book_name')->nullable();
            $table->timestamps();
        });

        \Illuminate\Support\Facades\Schema::create('return_items', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('returns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->integer('quantity')->default(1);
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('import_price', 15, 2)->default(0);
            $table->timestamps();
        });

        return 'Migrated Returns Tables 2 directly.';
    } catch (\Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
});

// =======================
// 📊 REPORTS (Phân tích)
// =======================
Route::group(['prefix' => 'reports'], function () {
    Route::get('/business', [ReportController::class, 'businessOverview'])->name('reports.business');
    Route::get('/cost-profit', [ReportController::class, 'costProfit'])->name('reports.cost-profit');
    Route::get('/products', [ReportController::class, 'productOverview'])->name('reports.products');
    Route::get('/inventory', [ReportController::class, 'inventory'])->name('reports.inventory');
    Route::get('/product-categories', [ReportController::class, 'productCategory'])->name('reports.product-categories');
    Route::get('/customers', [ReportController::class, 'customerOverview'])->name('reports.customers');
    Route::get('/customer-categories', [ReportController::class, 'customerCategory'])->name('reports.customer-categories');
    Route::get('/customer-debt', [ReportController::class, 'customerDebt'])->name('reports.customer-debt');

    // Báo cáo
    Route::get('/end-of-day', [EndOfDayReportController::class, 'index'])->name('reports.end-of-day');
    Route::get('/sales', [SalesReportController::class, 'index'])->name('reports.sales');
    Route::get('/orders', [OrderReportController::class, 'index'])->name('reports.orders');
    Route::get('/products-report', [ProductReportController::class, 'index'])->name('reports.products-report');
    Route::get('/customers-report', [CustomerReportController::class, 'index'])->name('reports.customers-report');
    Route::get('/suppliers-report', [SupplierReportController::class, 'index'])->name('reports.suppliers-report');
    Route::get('/employees-report', [EmployeeReportController::class, 'index'])->name('reports.employees-report');
    Route::get('/financial-report', [FinancialReportController::class, 'index'])->name('reports.financial-report');
});

}); // end auth middleware
