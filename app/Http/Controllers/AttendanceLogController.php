<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Jobs\RecalculateTimekeepingForRangeJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AttendanceLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AttendanceLog::query()->with([
            'employee:id,code,name',
            'device:id,name,ip_address,tcp_port',
        ]);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->filled('device_id')) {
            $query->where('attendance_device_id', $request->integer('device_id'));
        }
        if ($request->filled('from')) {
            $query->where('punched_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->where('punched_at', '<=', $request->date('to')->endOfDay());
        }
        if ($request->boolean('unmapped')) {
            $query->whereNull('employee_id');
        }

        $perPage = (int) $request->get('per_page', 50);
        $logs = $query->orderByDesc('punched_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
            ],
        ]);
    }

    /**
     * Get device_user_ids that have no employee mapping
     */
    public function unmappedUsers(Request $request)
    {
        $query = AttendanceLog::query()
            ->whereNull('employee_id')
            ->select('device_user_id', DB::raw('COUNT(*) as log_count'), DB::raw('MAX(punched_at) as last_punch'))
            ->groupBy('device_user_id')
            ->orderByDesc('log_count');

        if ($request->filled('device_id')) {
            $query->where('attendance_device_id', $request->integer('device_id'));
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    /**
     * Refresh employee mapping for existing logs (UPDATE JOIN)
     */
    public function refreshMapping(Request $request)
    {
        $validated = $request->validate([
            'device_id' => ['nullable', 'integer'],
            'device_user_ids' => ['nullable', 'array'],
            'device_user_ids.*' => ['string', 'max:50'],
        ]);

        $connection = DB::connection();
        $driver = $connection->getDriverName();

        $deviceId = $validated['device_id'] ?? null;
        $deviceUserIds = array_values(array_filter(array_map('strval', $validated['device_user_ids'] ?? [])));

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $start = now();

            $sql = <<<'SQL'
UPDATE attendance_logs al
JOIN employees e ON e.attendance_code = al.device_user_id
SET al.employee_id = e.id,
    al.updated_at = NOW()
WHERE al.employee_id IS NULL
  AND al.device_user_id <> '0'
  AND e.attendance_code IS NOT NULL
  AND e.attendance_code <> ''
SQL;

            $bindings = [];

            if ($deviceId) {
                $sql .= ' AND al.attendance_device_id = ?';
                $bindings[] = $deviceId;
            }

            if (!empty($deviceUserIds)) {
                $placeholders = implode(',', array_fill(0, count($deviceUserIds), '?'));
                $sql .= " AND al.device_user_id IN ($placeholders)";
                $bindings = array_merge($bindings, $deviceUserIds);
            }

            $updated = $connection->affectingStatement($sql, $bindings);

            // Auto recalculate timekeeping for impacted employees
            if ($updated > 0) {
                $impacted = AttendanceLog::query()
                    ->whereNotNull('employee_id')
                    ->where('updated_at', '>=', $start)
                    ->when($deviceId, fn($q) => $q->where('attendance_device_id', $deviceId))
                    ->when(!empty($deviceUserIds), fn($q) => $q->whereIn('device_user_id', $deviceUserIds))
                    ->selectRaw('MIN(punched_at) as min_punched_at, MAX(punched_at) as max_punched_at')
                    ->first();

                $min = $impacted?->min_punched_at ? Carbon::parse($impacted->min_punched_at) : null;
                $max = $impacted?->max_punched_at ? Carbon::parse($impacted->max_punched_at) : null;

                $employeeIds = AttendanceLog::query()
                    ->whereNotNull('employee_id')
                    ->where('updated_at', '>=', $start)
                    ->when($deviceId, fn($q) => $q->where('attendance_device_id', $deviceId))
                    ->when(!empty($deviceUserIds), fn($q) => $q->whereIn('device_user_id', $deviceUserIds))
                    ->distinct()
                    ->pluck('employee_id')
                    ->map(fn($id) => (int) $id)
                    ->values()
                    ->all();

                if (!empty($employeeIds) && $min && $max) {
                    $from = $min->copy()->subDay()->toDateString();
                    $to = $max->copy()->addDay()->toDateString();
                    RecalculateTimekeepingForRangeJob::dispatchSync($from, $to, $employeeIds);
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Đã cập nhật $updated bản ghi",
                'data' => ['updated' => $updated],
            ]);
        }

        // Fallback: loop mapping
        $employeeMap = \App\Models\Employee::query()
            ->whereNotNull('attendance_code')
            ->where('attendance_code', '<>', '')
            ->pluck('id', 'attendance_code')
            ->all();

        if (empty($employeeMap)) {
            return response()->json(['success' => false, 'message' => 'Không có nhân viên nào có mã chấm công'], 400);
        }

        $updated = 0;
        $updatedEmployeeIds = [];
        $minPunchedAt = null;
        $maxPunchedAt = null;

        foreach ($employeeMap as $code => $employeeId) {
            if ($code === '0') continue;

            $q = AttendanceLog::query()
                ->where('device_user_id', (string) $code)
                ->whereNull('employee_id');

            if ($deviceId) $q->where('attendance_device_id', $deviceId);
            if (!empty($deviceUserIds) && !in_array((string) $code, $deviceUserIds, true)) continue;

            $count = $q->update(['employee_id' => $employeeId]);
            if ($count > 0) {
                $updated += $count;
                $updatedEmployeeIds[] = (int) $employeeId;

                $range = AttendanceLog::query()
                    ->where('device_user_id', (string) $code)
                    ->where('employee_id', (int) $employeeId)
                    ->selectRaw('MIN(punched_at) as min_punched_at, MAX(punched_at) as max_punched_at')
                    ->first();

                if ($range?->min_punched_at) {
                    $min = Carbon::parse($range->min_punched_at);
                    $max = Carbon::parse($range->max_punched_at);
                    if (!$minPunchedAt || $min->lessThan($minPunchedAt)) $minPunchedAt = $min;
                    if (!$maxPunchedAt || $max->greaterThan($maxPunchedAt)) $maxPunchedAt = $max;
                }
            }
        }

        $updatedEmployeeIds = array_values(array_unique(array_filter($updatedEmployeeIds)));
        if ($updated > 0 && !empty($updatedEmployeeIds) && $minPunchedAt && $maxPunchedAt) {
            $from = $minPunchedAt->copy()->subDay()->toDateString();
            $to = $maxPunchedAt->copy()->addDay()->toDateString();
            RecalculateTimekeepingForRangeJob::dispatchSync($from, $to, $updatedEmployeeIds);
        }

        return response()->json([
            'success' => true,
            'message' => "Đã cập nhật $updated bản ghi",
            'data' => ['updated' => $updated],
        ]);
    }
}
