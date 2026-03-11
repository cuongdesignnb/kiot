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

// =======================
// 🔓 DEBUG ROUTES (public, for testing)
// =======================

Route::get('/attendance-agent/recent-logs', function () {
    $logs = \App\Models\AttendanceLog::query()
        ->orderByDesc('created_at')
        ->limit(20)
        ->get(['id', 'device_user_id', 'punched_at', 'event_type', 'employee_id', 'created_at']);

    return response()->json([
        'success' => true,
        'total_logs' => \App\Models\AttendanceLog::count(),
        'recent' => $logs,
    ]);
});

Route::get('/attendance-agent/debug-status', function () {
    $today = now()->toDateString();
    $from = now()->subDays(7)->toDateString();

    $totalLogs = \App\Models\AttendanceLog::count();
    $logsWithEmployee = \App\Models\AttendanceLog::whereNotNull('employee_id')->count();
    $logsWithoutEmployee = \App\Models\AttendanceLog::whereNull('employee_id')->count();

    $schedulesThisWeek = \App\Models\EmployeeWorkSchedule::whereBetween('work_date', [$from, $today])->count();
    $timekeepingRecordsThisWeek = \App\Models\TimekeepingRecord::whereBetween('work_date', [$from, $today])->count();
    $timekeepingWithCheckin = \App\Models\TimekeepingRecord::whereBetween('work_date', [$from, $today])
        ->whereNotNull('check_in_at')
        ->count();

    $employeesWithCode = \App\Models\Employee::whereNotNull('attendance_code')
        ->get(['id', 'code', 'name', 'attendance_code']);

    $recentSchedules = \App\Models\EmployeeWorkSchedule::with(['employee:id,code,name', 'timekeepingRecord'])
        ->whereBetween('work_date', [$from, $today])
        ->orderByDesc('work_date')
        ->limit(10)
        ->get();

    return response()->json([
        'success' => true,
        'today' => $today,
        'summary' => [
            'total_logs' => $totalLogs,
            'logs_with_employee_id' => $logsWithEmployee,
            'logs_without_employee_id' => $logsWithoutEmployee,
            'schedules_this_week' => $schedulesThisWeek,
            'timekeeping_records_this_week' => $timekeepingRecordsThisWeek,
            'timekeeping_with_checkin' => $timekeepingWithCheckin,
        ],
        'employees_with_attendance_code' => $employeesWithCode,
        'recent_schedules' => $recentSchedules,
    ]);
});

Route::post('/attendance-agent/force-recalculate', function (\Illuminate\Http\Request $request) {
    $from = $request->input('from', now()->subDays(7)->toDateString());
    $to = $request->input('to', now()->toDateString());
    $employeeId = $request->input('employee_id');

    $service = app(\App\Services\TimekeepingService::class);
    $result = $service->recalculateForRange(
        \Carbon\Carbon::parse($from),
        \Carbon\Carbon::parse($to),
        $employeeId ? (int) $employeeId : null
    );

    return response()->json([
        'success' => true,
        'message' => 'Đã recalculate timekeeping (sync mode)',
        'range' => ['from' => $from, 'to' => $to],
        'employee_id' => $employeeId,
        'result' => $result,
    ]);
});

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

Route::get('/test', [AttendanceAgentController::class, 'test']);

// Debug HMAC — test tạm, xoá sau khi fix
Route::post('/attendance-agent/debug-hmac', function (\Illuminate\Http\Request $request) {
    $keyRaw = config('services.attendance_agent.hmac_key', 'your-long-random-secret-here-change-me');
    $key = $keyRaw;
    $isHex = ctype_xdigit($keyRaw) && strlen($keyRaw) % 2 === 0;
    if ($isHex) {
        $key = hex2bin($keyRaw);
    }

    $deviceId = $request->header('X-Device-Id', '');
    $timestamp = $request->header('X-Timestamp', '');
    $signature = $request->header('X-Signature', '');
    $rawBody = $request->getContent();

    $payload = "{$timestamp}.{$deviceId}.{$rawBody}";
    $expected = hash_hmac('sha256', $payload, $key);

    return response()->json([
        'key_hint'       => substr($keyRaw, 0, 8) . '...(len=' . strlen($keyRaw) . ')',
        'key_is_hex'     => $isHex,
        'key_bin_length'  => strlen($key),
        'device_id'      => $deviceId,
        'timestamp'      => $timestamp,
        'server_time'    => time(),
        'time_diff'      => abs(time() - (int)$timestamp),
        'body_length'    => strlen($rawBody),
        'body_first80'   => substr($rawBody, 0, 80),
        'payload_first80'=> substr($payload, 0, 80),
        'expected_sig'   => $expected,
        'received_sig'   => $signature,
        'match'          => hash_equals($expected, $signature),
    ]);
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

Route::prefix('salary-templates')->group(function () {
    Route::get('/', [SalaryTemplateController::class, 'index']);
    Route::get('/commission-tables', [SalaryTemplateController::class, 'commissionTables']);
    Route::get('/{salaryTemplate}', [SalaryTemplateController::class, 'show']);
    Route::post('/', [SalaryTemplateController::class, 'store']);
    Route::put('/{salaryTemplate}', [SalaryTemplateController::class, 'update']);
    Route::delete('/{salaryTemplate}', [SalaryTemplateController::class, 'destroy']);
});

Route::prefix('employee-salary-settings')->group(function () {
    Route::get('/{employeeId}', [\App\Http\Controllers\Api\EmployeeSalarySettingController::class, 'show']);
    Route::post('/{employeeId}', [\App\Http\Controllers\Api\EmployeeSalarySettingController::class, 'upsert']);
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
    Route::get('/', [PaysheetController::class, 'index']);
    Route::post('/', [PaysheetController::class, 'store']);
    Route::get('/{id}', [PaysheetController::class, 'show']);
    Route::post('/{id}/recalculate', [PaysheetController::class, 'recalculate']);
    Route::put('/{id}/lock', [PaysheetController::class, 'lock']);
    Route::put('/{id}/cancel', [PaysheetController::class, 'cancel']);
    Route::post('/{id}/pay', [PaysheetController::class, 'pay']);
    Route::put('/{id}/notes', [PaysheetController::class, 'updateNotes']);
    Route::delete('/{id}', [PaysheetController::class, 'destroy']);
});

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
