<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AttendanceAgentController;
use App\Http\Controllers\Api\SalaryTemplateController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\EmployeeWorkScheduleController;
use App\Http\Controllers\TimekeepingRecordController;
use App\Http\Controllers\PaysheetController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\WorkdaySettingController;
use App\Http\Controllers\TimekeepingSettingController;
use App\Http\Controllers\PayrollSettingController;
use App\Http\Controllers\AttendanceDeviceController;
use App\Http\Controllers\AttendanceLogController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Step 24.2: removed public debug endpoints
//   - GET /api/attendance-agent/recent-logs (exposed attendance logs no auth)
//   - GET /api/attendance-agent/debug-status (exposed employee codes no auth)
//   - POST /api/attendance-agent/force-recalculate (wrote DB no auth — HIGH RISK)
//   - GET /api/test (test endpoint)
//   - POST /api/attendance-agent/debug-hmac (exposed HMAC config)
// Để debug attendance giờ phải dùng Artisan CLI hoặc qua HMAC group bên dưới.

// =======================
// 🔒 ATTENDANCE AGENT (HMAC signed)
// =======================

Route::prefix('attendance-agent')->middleware('attendance.agent')->group(function () {
    Route::post('/push-logs', [AttendanceAgentController::class, 'pushLogs']);
    Route::get('/users', [AttendanceAgentController::class, 'getUsers']);
    Route::post('/sync-status', [AttendanceAgentController::class, 'syncStatus']);
    Route::get('/bridge/latest', [AttendanceAgentController::class, 'getLatestVersion']);
    Route::post('/refresh-mapping', [AttendanceAgentController::class, 'refreshMapping']);
});

// =======================
// 📋 HR & ATTENDANCE MANAGEMENT
// =======================

Route::prefix('shifts')->group(function () {
    Route::get('/', [ShiftController::class, 'index']);
    Route::get('/{shift}', [ShiftController::class, 'show']);
    Route::post('/', [ShiftController::class, 'store']);
    Route::put('/{shift}', [ShiftController::class, 'update']);
    Route::patch('/{shift}/toggle', [ShiftController::class, 'toggle']);
    Route::delete('/{shift}', [ShiftController::class, 'destroy']);
});

Route::prefix('employee-schedules')->group(function () {
    Route::get('/', [EmployeeWorkScheduleController::class, 'index']);
    Route::post('/', [EmployeeWorkScheduleController::class, 'store']);
    Route::post('/bulk-destroy', [EmployeeWorkScheduleController::class, 'bulkDestroy']);
    Route::delete('/{id}', [EmployeeWorkScheduleController::class, 'destroy']);
});

Route::prefix('timekeeping-records')->group(function () {
    Route::get('/', [TimekeepingRecordController::class, 'index']);
    Route::post('/', [TimekeepingRecordController::class, 'store']);
    Route::post('/recalculate', [TimekeepingRecordController::class, 'recalculate']);
});

Route::prefix('holidays')->group(function () {
    Route::get('/', [HolidayController::class, 'index']);
    Route::get('/{holiday}', [HolidayController::class, 'show']);
    Route::post('/', [HolidayController::class, 'store']);
    Route::post('/range', [HolidayController::class, 'storeRange']);
    Route::post('/auto-generate', [HolidayController::class, 'autoGenerate']);
    Route::put('/{holiday}', [HolidayController::class, 'update']);
    Route::delete('/{holiday}', [HolidayController::class, 'destroy']);
});

Route::prefix('workday-settings')->group(function () {
    Route::get('/', [WorkdaySettingController::class, 'show']);
    Route::post('/', [WorkdaySettingController::class, 'upsert']);
});

Route::prefix('timekeeping-settings')->group(function () {
    Route::get('/', [TimekeepingSettingController::class, 'show']);
    Route::post('/', [TimekeepingSettingController::class, 'upsert']);
});

Route::prefix('payroll-settings')->group(function () {
    Route::get('/', [PayrollSettingController::class, 'show']);
    Route::post('/', [PayrollSettingController::class, 'upsert']);
});

Route::get('/payroll-cycles', function (\Illuminate\Http\Request $request) {
    $service = new \App\Services\PayrollCycleService();
    $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;

    if ($request->filled('year')) {
        $cycles = $service->getCyclesForYear($branchId, $request->integer('year'));
    } else {
        $count = $request->integer('count', 12);
        $cycles = $service->getRecentCycles($branchId, min($count, 24));
    }

    return response()->json(['success' => true, 'data' => $cycles]);
});

Route::prefix('salary-templates')->group(function () {
    Route::get('/', [SalaryTemplateController::class, 'index']);
    Route::get('/commission-tables', [SalaryTemplateController::class, 'commissionTables']);
    Route::get('/{salaryTemplate}', [SalaryTemplateController::class, 'show']);
    Route::post('/', [SalaryTemplateController::class, 'store']);
    Route::put('/{salaryTemplate}', [SalaryTemplateController::class, 'update']);
    Route::delete('/{salaryTemplate}', [SalaryTemplateController::class, 'destroy']);
});

Route::prefix('employee-salary-settings')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\EmployeeSalarySettingController::class, 'index']);
    Route::get('/{employeeId}', [\App\Http\Controllers\Api\EmployeeSalarySettingController::class, 'show']);
    Route::post('/{employeeId}', [\App\Http\Controllers\Api\EmployeeSalarySettingController::class, 'upsert']);
});

// HOTFIX 24.19 — live search for the Nhập hàng supplier selector.
// Returns active suppliers only; deactivated rows stay visible on the
// /suppliers admin page but must NOT show up here.
Route::get('/suppliers/search', [\App\Http\Controllers\SupplierController::class, 'search']);
Route::post('/suppliers/quick-store', [\App\Http\Controllers\SupplierController::class, 'quickStore']);
Route::prefix('suppliers/{id}')->group(function () {
    Route::get('/purchase-history', [\App\Http\Controllers\SupplierController::class, 'purchaseHistory']);
    Route::get('/debt-transactions', [\App\Http\Controllers\SupplierController::class, 'debtTransactions']);
    Route::get('/debt-voucher-detail', [\App\Http\Controllers\SupplierController::class, 'debtVoucherDetail']);
    Route::get('/export-debt', [\App\Http\Controllers\SupplierController::class, 'exportDebtHistory']);
    Route::get('/export-purchases', [\App\Http\Controllers\SupplierController::class, 'exportPurchaseHistory']);
    Route::post('/payment', [\App\Http\Controllers\SupplierController::class, 'recordPayment']);
    Route::post('/adjust-debt', [\App\Http\Controllers\SupplierController::class, 'adjustDebt']);
});

Route::prefix('attendance-devices')->group(function () {
    Route::get('/', [AttendanceDeviceController::class, 'index']);
    Route::get('/{device}', [AttendanceDeviceController::class, 'show']);
    Route::post('/', [AttendanceDeviceController::class, 'store']);
    Route::put('/{device}', [AttendanceDeviceController::class, 'update']);
    Route::delete('/{device}', [AttendanceDeviceController::class, 'destroy']);
    Route::post('/{device}/test-connection', [AttendanceDeviceController::class, 'testConnection']);
});

Route::prefix('attendance-logs')->group(function () {
    Route::get('/', [AttendanceLogController::class, 'index']);
    Route::get('/unmapped-users', [AttendanceLogController::class, 'unmappedUsers']);
    Route::post('/refresh-mapping', [AttendanceLogController::class, 'refreshMapping']);
});

// =======================
// 💰 PAYROLL
// =======================

Route::prefix('paysheets')->group(function () {
    Route::get('/', [PaysheetController::class, 'index'])->middleware('permission:payroll.view');
    Route::post('/', [PaysheetController::class, 'store'])->middleware('permission:payroll.create');
    Route::get('/{id}', [PaysheetController::class, 'show'])->middleware('permission:payroll.view');
    Route::post('/{id}/recalculate', [PaysheetController::class, 'recalculate'])->middleware('permission:payroll.edit');
    Route::put('/{id}/lock', [PaysheetController::class, 'lock'])->middleware('permission:payroll.lock');
    Route::put('/{id}/cancel', [PaysheetController::class, 'cancel'])->middleware('permission:payroll.cancel');
    Route::post('/{id}/cancel', [PaysheetController::class, 'cancel'])->middleware('permission:payroll.cancel');
    Route::post('/{id}/pay', [PaysheetController::class, 'pay'])->middleware('permission:payroll.pay');
    Route::put('/{id}/notes', [PaysheetController::class, 'updateNotes'])->middleware('permission:payroll.edit');
    // Step 24.12 — update standard_working_days + auto-recalc payslips
    Route::put('/{id}/standard-working-days', [PaysheetController::class, 'updateStandardWorkingDays'])->middleware('permission:payroll.edit');
    // HOTFIX 24.12B — bulk replace + reset-default for popup adjustments
    Route::put('/{id}/payslips/{slipId}/adjustments/{type}/bulk', [PaysheetController::class, 'bulkSaveAdjustments'])->middleware('permission:payroll.edit');
    Route::post('/{id}/payslips/{slipId}/adjustments/{type}/reset-default', [PaysheetController::class, 'resetDefaultAdjustments'])->middleware('permission:payroll.edit');
    Route::put('/{id}/payslips/{slipId}', [PaysheetController::class, 'updatePayslip'])->middleware('permission:payroll.edit');
    Route::get('/{id}/payslips/{slipId}/adjustments', [PaysheetController::class, 'listAdjustments'])->middleware('permission:payroll.view');
    Route::post('/{id}/payslips/{slipId}/adjustments', [PaysheetController::class, 'storeAdjustment'])->middleware('permission:payroll.edit');
    Route::put('/{id}/payslips/{slipId}/adjustments/{adjId}', [PaysheetController::class, 'updateAdjustment'])->middleware('permission:payroll.edit');
    Route::delete('/{id}/payslips/{slipId}/adjustments/{adjId}', [PaysheetController::class, 'deleteAdjustment'])->middleware('permission:payroll.edit');
    Route::delete('/{id}', [PaysheetController::class, 'destroy'])->middleware('permission:payroll.cancel');
});

Route::post('/paysheet-payments/{payment}/cancel', [PaysheetController::class, 'cancelPayment'])
    ->middleware('permission:payroll.pay.cancel');

Route::prefix('employees/{employee}')->group(function () {
    Route::get('/salary-ledger', [\App\Http\Controllers\EmployeeSalaryLedgerController::class, 'index'])
        ->middleware('permission:payroll.ledger.view');
    Route::post('/salary-ledger/adjust', [\App\Http\Controllers\EmployeeSalaryLedgerController::class, 'adjust'])
        ->middleware('permission:payroll.adjust');
    Route::get('/salary-ledger/export', [\App\Http\Controllers\EmployeeSalaryLedgerController::class, 'export'])
        ->middleware('permission:payroll.ledger.export');
    Route::get('/salary-advances', [\App\Http\Controllers\SalaryAdvanceController::class, 'index'])
        ->middleware('permission:payroll.ledger.view');
    Route::post('/salary-advances', [\App\Http\Controllers\SalaryAdvanceController::class, 'store'])
        ->middleware('permission:payroll.advance.create');
    Route::post('/rebuild-salary-balance', [\App\Http\Controllers\EmployeeSalaryLedgerController::class, 'rebuild'])
        ->middleware('permission:payroll.rebuild_balance');
});
Route::post('/salary-advances/{advance}/cancel', [\App\Http\Controllers\SalaryAdvanceController::class, 'cancel'])
    ->middleware('permission:payroll.advance.cancel');
Route::get('/employee-salary-ledger-entries/{entry}', [\App\Http\Controllers\EmployeeSalaryLedgerController::class, 'show'])
    ->middleware('permission:payroll.ledger.view');
Route::get('/payroll/date-policy', [\App\Http\Controllers\PayrollMetaController::class, 'datePolicy'])
    ->middleware('permission:payroll.view');
Route::get('/payroll/reconciliation', [\App\Http\Controllers\PayrollReconciliationController::class, 'index'])
    ->middleware('permission:payroll.reconciliation.view');
Route::get('/payroll/reconciliation/export', [\App\Http\Controllers\PayrollReconciliationController::class, 'export'])
    ->middleware('permission:payroll.reconciliation.export');
Route::post('/payroll/rebuild-salary-balances', [\App\Http\Controllers\EmployeeSalaryLedgerController::class, 'rebuildAll'])
    ->middleware('permission:payroll.rebuild_balance');

// =======================
// 🔧 DEVICE REPAIR
// =======================

Route::prefix('device-repairs')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\DeviceRepairController::class, 'index']);
    Route::get('/performance', [\App\Http\Controllers\Api\DeviceRepairController::class, 'performance']);
    Route::get('/search-serials', [\App\Http\Controllers\Api\DeviceRepairController::class, 'searchSerials']);
    Route::get('/search-products', [\App\Http\Controllers\Api\DeviceRepairController::class, 'searchProducts']);
    Route::post('/', [\App\Http\Controllers\Api\DeviceRepairController::class, 'store']);
    Route::get('/{deviceRepair}', [\App\Http\Controllers\Api\DeviceRepairController::class, 'show']);
    Route::put('/{deviceRepair}', [\App\Http\Controllers\Api\DeviceRepairController::class, 'update']);
    Route::post('/{deviceRepair}/assign', [\App\Http\Controllers\Api\DeviceRepairController::class, 'assign']);
    Route::post('/{deviceRepair}/parts', [\App\Http\Controllers\Api\DeviceRepairController::class, 'addPart']);
    Route::delete('/{deviceRepair}/parts/{partId}', [\App\Http\Controllers\Api\DeviceRepairController::class, 'removePart']);
    Route::post('/{deviceRepair}/complete', [\App\Http\Controllers\Api\DeviceRepairController::class, 'complete']);
});

// =======================
// 📋 TASKS (unified: repairs + general)
// =======================

// Step 24.0B: enforce permission middleware cho API task/repair routes.
// Read endpoints dùng `tasks.view`; write endpoints có permission tách riêng.
Route::prefix('tasks')->group(function () {
    Route::middleware('permission:tasks.view')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\TaskController::class, 'index']);
        Route::get('/categories', [\App\Http\Controllers\Api\TaskController::class, 'categories']);
        Route::get('/performance', [\App\Http\Controllers\Api\TaskController::class, 'performance']);
        Route::get('/search-serials', [\App\Http\Controllers\Api\TaskController::class, 'searchSerials']);
        Route::get('/search-products', [\App\Http\Controllers\Api\TaskController::class, 'searchProducts']);
        Route::get('/product-serials', [\App\Http\Controllers\Api\TaskController::class, 'productSerials']);
        // Step 23.8D — warranty lookup
        Route::get('/lookup-warranty', [\App\Http\Controllers\Api\TaskController::class, 'lookupWarranty'])->middleware('permission:tasks.attach_warranty');
        Route::get('/{task}', [\App\Http\Controllers\Api\TaskController::class, 'show']);
    });
    Route::middleware('permission:tasks.create')->group(function () {
        Route::post('/batch-repair', [\App\Http\Controllers\Api\TaskController::class, 'batchCreateRepair']);
        Route::post('/', [\App\Http\Controllers\Api\TaskController::class, 'store']);
        Route::put('/{task}', [\App\Http\Controllers\Api\TaskController::class, 'update']);
    });
    Route::delete('/{task}', [\App\Http\Controllers\Api\TaskController::class, 'destroy'])->middleware('permission:tasks.create');
    Route::post('/{task}/assign', [\App\Http\Controllers\Api\TaskController::class, 'assign'])->middleware('permission:tasks.assign');
    // Step 24.0B: parts management tách quyền `tasks.manage_parts`.
    Route::middleware('permission:tasks.manage_parts')->group(function () {
        Route::post('/{task}/parts', [\App\Http\Controllers\Api\TaskController::class, 'addPart']);
        Route::delete('/{task}/parts/{partId}', [\App\Http\Controllers\Api\TaskController::class, 'removePart']);
    });
    Route::post('/{task}/disassemble-part', [\App\Http\Controllers\Api\TaskController::class, 'disassemblePart'])->middleware('permission:tasks.disassemble');
    // HOTFIX 24.11B — separate rollback endpoint for direction='import' parts.
    Route::post('/{task}/parts/{partId}/rollback-disassembly', [\App\Http\Controllers\Api\TaskController::class, 'rollbackDisassemblyPart'])->middleware('permission:tasks.disassemble');
    // HOTFIX 24.18 — operator-triggered "đã lắp lại xong" restore for a
    // stuck dismantled+ready serial. Sits under /tasks because the
    // service lives in TaskService and the action belongs to the repair
    // workflow. Permission gate uses tasks.complete (matches who closes
    // a repair task today).
    Route::post('/serials/{serial}/restore-reassembled', [\App\Http\Controllers\Api\TaskController::class, 'restoreReassembledSerial'])->middleware('permission:tasks.complete');
    Route::post('/{task}/complete', [\App\Http\Controllers\Api\TaskController::class, 'complete'])->middleware('permission:tasks.complete');
    Route::post('/{task}/progress', [\App\Http\Controllers\Api\TaskController::class, 'updateProgress'])->middleware('permission:tasks.complete');
    Route::post('/{task}/comments', [\App\Http\Controllers\Api\TaskController::class, 'addComment'])->middleware('permission:tasks.view');
    // Step 23.8D — attach warranty vào task
    Route::post('/{task}/attach-warranty', [\App\Http\Controllers\Api\TaskController::class, 'attachWarranty'])->middleware('permission:tasks.attach_warranty');
});

// 📜 ACTIVITY LOGS (Step 24.0C)
Route::prefix('activity-logs')->middleware('permission:system.audit.view')->group(function () {
    Route::get('/', [\App\Http\Controllers\ActivityLogController::class, 'api']);
    Route::get('/action-types', [\App\Http\Controllers\ActivityLogController::class, 'actionTypes']);
});

// 🔔 NOTIFICATIONS
Route::prefix('notifications')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::get('/unread-count', [\App\Http\Controllers\Api\NotificationController::class, 'unreadCount']);
    Route::post('/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'markAsRead']);
    Route::post('/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'markAllAsRead']);
});

// 👤 MY TASKS (employee portal)
Route::prefix('my-tasks')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\MyTasksController::class, 'index']);
    Route::post('/accept-all', [\App\Http\Controllers\Api\MyTasksController::class, 'acceptAll']);
    Route::post('/{assignment}/respond', [\App\Http\Controllers\Api\MyTasksController::class, 'respond']);
    Route::post('/{task}/progress', [\App\Http\Controllers\Api\MyTasksController::class, 'updateProgress']);
});

Route::prefix('repair-performance-tiers')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\RepairPerformanceTierController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\RepairPerformanceTierController::class, 'store']);
    Route::put('/{tier}', [\App\Http\Controllers\Api\RepairPerformanceTierController::class, 'update']);
    Route::delete('/{tier}', [\App\Http\Controllers\Api\RepairPerformanceTierController::class, 'destroy']);
});

// ── Roles & Users ──
Route::prefix('roles')->group(function () {
    Route::get('/permissions-map', [\App\Http\Controllers\Api\RoleController::class, 'permissionsMap']);
    Route::get('/', [\App\Http\Controllers\Api\RoleController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\RoleController::class, 'store']);
    Route::get('/{role}', [\App\Http\Controllers\Api\RoleController::class, 'show']);
    Route::put('/{role}', [\App\Http\Controllers\Api\RoleController::class, 'update']);
    Route::delete('/{role}', [\App\Http\Controllers\Api\RoleController::class, 'destroy']);
    Route::post('/{role}/duplicate', [\App\Http\Controllers\Api\RoleController::class, 'duplicate']);
});

Route::prefix('users')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\UserController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\UserController::class, 'store']);
    Route::put('/{user}', [\App\Http\Controllers\Api\UserController::class, 'update']);
    Route::delete('/{user}', [\App\Http\Controllers\Api\UserController::class, 'destroy']);
});

// 📁 MEDIA LIBRARY
Route::prefix('media')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\MediaController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\MediaController::class, 'store']);
    Route::delete('/{media}', [\App\Http\Controllers\Api\MediaController::class, 'destroy']);
});

// 🏷️ PRODUCT ATTRIBUTES
Route::prefix('product-attributes')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\ProductAttributeController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\ProductAttributeController::class, 'store']);
    Route::post('/{attribute}/values', [\App\Http\Controllers\Api\ProductAttributeController::class, 'storeValue']);
    Route::delete('/{attribute}', [\App\Http\Controllers\Api\ProductAttributeController::class, 'destroy']);
    Route::delete('/values/{value}', [\App\Http\Controllers\Api\ProductAttributeController::class, 'destroyValue']);
});
