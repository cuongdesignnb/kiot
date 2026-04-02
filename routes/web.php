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

Route::get('/', [DashboardController::class, 'index'])->middleware('permission:dashboard.view')->name('dashboard');

Route::get('/check-schema', function () {
    return response()->json(\Illuminate\Support\Facades\Schema::getColumnListing('invoices'));
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
Route::middleware('permission:customers.view')->group(function () {
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/search-for-merge', [CustomerController::class, 'searchForMerge']);
    Route::get('/customers/{customer}/sales-history', [CustomerController::class, 'salesHistory']);
    Route::get('/customers/{customer}/debt-history', [CustomerController::class, 'debtHistory']);
    Route::get('/customers/{customer}/export-debt', [CustomerController::class, 'exportDebtHistory']);
    Route::get('/customers/{customer}/export-sales', [CustomerController::class, 'exportSalesHistory']);
});
Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store')->middleware('permission:customers.create');
Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update')->middleware('permission:customers.edit');
Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy')->middleware('permission:customers.delete');
Route::post('/customers/{customer}/merge', [CustomerController::class, 'merge'])->middleware('permission:customers.edit');

// ===== SUPPLIERS =====
Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index')->middleware('permission:suppliers.view');
Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store')->middleware('permission:suppliers.create');

// ===== PURCHASES =====
Route::middleware('permission:purchases.view')->group(function () {
    Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
    Route::get('/purchases/{purchase}/detail', [PurchaseController::class, 'detail']);
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->name('purchases.show');
});
Route::middleware('permission:purchases.create')->group(function () {
    Route::get('/purchases/create', [PurchaseController::class, 'create'])->name('purchases.create');
    Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
    Route::put('/purchases/{purchase}', [PurchaseController::class, 'update'])->name('purchases.update');
    Route::delete('/purchases/{purchase}', [PurchaseController::class, 'destroy'])->name('purchases.destroy');
});

// ===== PURCHASE RETURNS =====
Route::middleware('permission:purchases.view')->group(function () {
    Route::get('/purchase-returns', [PurchaseReturnController::class, 'index'])->name('purchase-returns.index');
    Route::get('/purchase-returns/{purchaseReturn}', [PurchaseReturnController::class, 'show'])->name('purchase-returns.show');
});
Route::middleware('permission:purchases.create')->group(function () {
    Route::get('/purchase-returns/create', [PurchaseReturnController::class, 'create'])->name('purchase-returns.create');
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
            $table->string('status')->default('Đã trả'); // Đã trả, Đã hủy
            $table->decimal('subtotal', 15, 2)->default(0); // Tổng tiền hàng trả
            $table->decimal('discount', 15, 2)->default(0); // Giảm giá phiếu trả
            $table->decimal('fee', 15, 2)->default(0); // Phí trả hàng
            $table->decimal('total', 15, 2)->default(0); // Cần trả khách (Tổng)
            $table->decimal('paid_to_customer', 15, 2)->default(0); // Đã trả khách
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
            $table->decimal('price', 15, 2)->default(0); // Giá trả hàng
            $table->decimal('discount', 15, 2)->default(0); // Giảm giá
            $table->decimal('import_price', 15, 2)->default(0); // Giá nhập lại
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
