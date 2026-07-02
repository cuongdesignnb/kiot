<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceAgentSyncLog;
use App\Models\AttendanceBridgeVersion;
use App\Models\AttendanceDevice;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Jobs\RecalculateTimekeepingForRangeJob;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceAgentController extends Controller
{
    /**
     * GET /api/test
     * Test API connection
     */
    public function test(Request $request)
    {
        return response()->json([
            'success' => true,
            'message' => 'API is working',
            'timestamp' => now()->toIso8601String(),
            'version' => '1.0.0',
            'device_id' => $request->attributes->get('attendance_device_id'),
        ]);
    }

    /**
     * POST /api/attendance-agent/push-logs
     */
    public function pushLogs(Request $request)
    {
        $data = $request->validate([
            'device_id' => ['nullable', 'string', 'max:100'],
            'logs' => ['required', 'array', 'max:5000'],
            'logs.*.device_id' => ['nullable', 'string', 'max:100'],
            'logs.*.device_user_id' => ['required', 'string', 'max:50'],
            'logs.*.punched_at' => ['required', 'date'],
            'logs.*.event_type' => ['nullable', 'in:in,out'],
            'logs.*.raw' => ['nullable', 'array'],
        ]);

        // Tìm hoặc tạo device theo device_id string
        $deviceIdStr = $data['device_id']
            ?? $request->attributes->get('attendance_device_id')
            ?? $request->header('X-Device-Id');
        $device = $this->findOrCreateDevice($deviceIdStr);

        $incoming = $data['logs'];
        $rows = [];
        $deviceUserIds = [];
        $skipped = 0;
        $minPunchedAt = null;
        $maxPunchedAt = null;

        foreach ($incoming as $log) {
            $deviceUserId = (string) $log['device_user_id'];
            if ($deviceUserId === '0') {
                $skipped++;
                continue;
            }

            $punchedAt = Carbon::parse($log['punched_at']);

            // Guard against obviously invalid device time
            if ($punchedAt->greaterThan(now()->addDays(2))) {
                $skipped++;
                continue;
            }

            $deviceUserIds[] = $deviceUserId;

            if (!$minPunchedAt || $punchedAt->lessThan($minPunchedAt)) {
                $minPunchedAt = $punchedAt->copy();
            }
            if (!$maxPunchedAt || $punchedAt->greaterThan($maxPunchedAt)) {
                $maxPunchedAt = $punchedAt->copy();
            }

            $rows[] = [
                'attendance_device_id' => $device->id,
                'employee_id' => null,
                'device_user_id' => $deviceUserId,
                'punched_at' => $punchedAt->format('Y-m-d H:i:s'),
                'event_type' => $log['event_type'] ?? null,
                'raw' => isset($log['raw']) ? json_encode($log['raw']) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (empty($rows)) {
            return response()->json([
                'success' => true,
                'message' => 'No valid logs to save',
                'data' => ['saved' => 0],
            ]);
        }

        // Map device_user_id to employee_id qua attendance_code
        $deviceUserIds = array_values(array_unique($deviceUserIds));
        $employeeMap = [];
        if (!empty($deviceUserIds)) {
            $employeeMap = Employee::query()
                ->whereIn('attendance_code', $deviceUserIds)
                ->pluck('id', 'attendance_code')
                ->all();
        }

        foreach ($rows as &$row) {
            $row['employee_id'] = $employeeMap[$row['device_user_id']] ?? null;
        }
        unset($row);

        // Upsert (idempotent)
        DB::transaction(function () use ($rows, $device) {
            AttendanceLog::query()->upsert(
                $rows,
                ['attendance_device_id', 'device_user_id', 'punched_at'],
                ['employee_id', 'event_type', 'raw', 'updated_at']
            );
            $device->forceFill(['last_sync_at' => now()])->save();
        });

        // Auto recalculate timekeeping for impacted employees
        $employeeIdsToRecalc = array_values(array_unique(array_filter(array_map(
            fn($r) => (int) ($r['employee_id'] ?? 0),
            $rows
        ))));

        if (!empty($employeeIdsToRecalc) && $minPunchedAt && $maxPunchedAt) {
            $from = $minPunchedAt->copy()->subDay()->toDateString();
            $to = $maxPunchedAt->copy()->addDay()->toDateString();
            RecalculateTimekeepingForRangeJob::dispatchSync($from, $to, $employeeIdsToRecalc);
        }

        Log::info('AttendanceAgent: Received logs', [
            'device_id' => $deviceIdStr,
            'count' => count($incoming),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Đã nhận log từ agent',
            'data' => [
                'device_id' => $device->device_id,
                'received' => count($incoming),
                'saved' => count($rows),
                'skipped' => $skipped,
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/attendance-agent/refresh-mapping
     */
    public function refreshMapping(Request $request)
    {
        $data = $request->validate([
            'device_id' => ['nullable', 'string', 'max:100'],
            'device_user_ids' => ['nullable', 'array'],
            'device_user_ids.*' => ['string', 'max:50'],
        ]);

        $deviceIdStr = $data['device_id'] ?? null;
        $device = $deviceIdStr ? AttendanceDevice::where('device_id', $deviceIdStr)->first() : null;
        $deviceUserIds = array_values(array_filter(array_map('strval', $data['device_user_ids'] ?? [])));

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

        if ($device) {
            $sql .= ' AND al.attendance_device_id = ?';
            $bindings[] = $device->id;
        }

        if (!empty($deviceUserIds)) {
            $placeholders = implode(',', array_fill(0, count($deviceUserIds), '?'));
            $sql .= " AND al.device_user_id IN ($placeholders)";
            $bindings = array_merge($bindings, $deviceUserIds);
        }

        $updated = DB::affectingStatement($sql, $bindings);

        if ($updated > 0) {
            $impacted = AttendanceLog::query()
                ->whereNotNull('employee_id')
                ->where('updated_at', '>=', $start)
                ->when($device, fn($q) => $q->where('attendance_device_id', $device->id))
                ->when(!empty($deviceUserIds), fn($q) => $q->whereIn('device_user_id', $deviceUserIds))
                ->selectRaw('MIN(punched_at) as min_punched_at, MAX(punched_at) as max_punched_at')
                ->first();

            $min = $impacted?->min_punched_at ? Carbon::parse($impacted->min_punched_at) : null;
            $max = $impacted?->max_punched_at ? Carbon::parse($impacted->max_punched_at) : null;

            $employeeIds = AttendanceLog::query()
                ->whereNotNull('employee_id')
                ->where('updated_at', '>=', $start)
                ->when($device, fn($q) => $q->where('attendance_device_id', $device->id))
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
            'data' => [
                'updated' => $updated,
                'device_id' => $deviceIdStr,
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/attendance-agent/users
     */
    public function getUsers(Request $request)
    {
        $page = (int) $request->input('page', 1);
        $perPage = min((int) $request->input('per_page', 200), 500);
        $updatedSince = $request->input('updated_since');

        $query = Employee::query()
            ->whereNotNull('attendance_code')
            ->where('attendance_code', '!=', '');

        if ($updatedSince) {
            try {
                $since = Carbon::parse($updatedSince);
                $query->where('updated_at', '>', $since);
            } catch (\Exception $e) {
                // Ignore invalid date
            }
        }

        $query->orderBy('updated_at', 'asc');
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $data = $paginator->getCollection()->map(function (Employee $employee) {
            return [
                'employee_id' => $employee->id,
                'attendance_code' => $employee->attendance_code,
                'name' => $employee->name,
                'status' => $employee->is_active ? 'active' : 'inactive',
                'department' => $employee->department?->name ?? null,
                'updated_at' => $employee->updated_at?->toIso8601String(),
            ];
        });

        $maxUpdatedAt = $paginator->getCollection()->max('updated_at');

        return response()->json([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'meta' => [
                'server_time' => now()->toIso8601String(),
                'next_updated_since' => $maxUpdatedAt?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/attendance-agent/sync-status
     */
    public function syncStatus(Request $request)
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'sync_type' => ['required', 'string', 'max:50', 'in:users,logs,full'],
            'started_at' => ['required', 'date'],
            'finished_at' => ['nullable', 'date'],
            'result' => ['required', 'string', 'max:20', 'in:ok,partial,failed'],
            'counts' => ['nullable', 'array'],
            'counts.fetched' => ['nullable', 'integer', 'min:0'],
            'counts.created' => ['nullable', 'integer', 'min:0'],
            'counts.updated' => ['nullable', 'integer', 'min:0'],
            'counts.skipped' => ['nullable', 'integer', 'min:0'],
            'counts.failed' => ['nullable', 'integer', 'min:0'],
            'errors' => ['nullable', 'array'],
            'errors.*.attendance_code' => ['nullable', 'string'],
            'errors.*.message' => ['nullable', 'string'],
        ]);

        $syncLog = AttendanceAgentSyncLog::create([
            'device_id' => $data['device_id'],
            'app_version' => $data['app_version'] ?? null,
            'sync_type' => $data['sync_type'],
            'started_at' => Carbon::parse($data['started_at']),
            'finished_at' => isset($data['finished_at']) ? Carbon::parse($data['finished_at']) : null,
            'result' => $data['result'],
            'counts' => $data['counts'] ?? null,
            'errors' => $data['errors'] ?? null,
        ]);

        Log::info('AttendanceAgent: Sync status received', [
            'device_id' => $data['device_id'],
            'sync_type' => $data['sync_type'],
            'result' => $data['result'],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'sync_log_id' => $syncLog->id,
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/attendance-agent/bridge/latest
     */
    public function getLatestVersion(Request $request)
    {
        $channel = $request->input('channel', 'stable');
        $currentVersion = $request->input('current_version');

        if (!in_array($channel, ['stable', 'beta'])) {
            $channel = 'stable';
        }

        $latestVersion = AttendanceBridgeVersion::getLatest($channel);

        if (!$latestVersion) {
            return response()->json([
                'success' => true,
                'data' => null,
                'meta' => [
                    'message' => 'Không có phiên bản nào cho channel: ' . $channel,
                    'server_time' => now()->toIso8601String(),
                ],
            ]);
        }

        $needsUpdate = true;
        $isSupported = true;
        if ($currentVersion) {
            $needsUpdate = $latestVersion->needsUpdate($currentVersion);
            $isSupported = $latestVersion->isSupported($currentVersion);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'version' => $latestVersion->version,
                'channel' => $latestVersion->channel,
                'mandatory' => $latestVersion->mandatory,
                'min_supported' => $latestVersion->min_supported,
                'released_at' => $latestVersion->released_at->toIso8601String(),
                'notes' => $latestVersion->notes,
                'download' => [
                    'url' => $latestVersion->download_url,
                    'sha256' => $latestVersion->sha256,
                    'size_bytes' => $latestVersion->size_bytes,
                ],
            ],
            'meta' => [
                'current_version' => $currentVersion,
                'needs_update' => $needsUpdate,
                'is_supported' => $isSupported,
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Tìm hoặc tạo device theo device_id string
     */
    private function findOrCreateDevice(string $deviceId): AttendanceDevice
    {
        $device = AttendanceDevice::where('device_id', $deviceId)->first();
        if ($device) return $device;

        // Tìm device placeholder chưa gán device_id
        $placeholderDevice = AttendanceDevice::whereNull('device_id')
            ->where('ip_address', '0.0.0.0')
            ->first();

        if ($placeholderDevice) {
            $placeholderDevice->update([
                'device_id' => $deviceId,
                'name' => 'Auto-created: ' . $deviceId,
                'status' => 'pending',
            ]);
            return $placeholderDevice;
        }

        // Tạo mới với IP unique
        $uniqueIp = '10.255.' . (crc32($deviceId) % 256) . '.' . ((crc32($deviceId) >> 8) % 256);
        $uniquePort = 4370 + (crc32($deviceId) % 1000);

        return AttendanceDevice::create([
            'device_id' => $deviceId,
            'name' => 'Auto-created: ' . $deviceId,
            'ip_address' => $uniqueIp,
            'tcp_port' => $uniquePort,
            'status' => 'pending',
        ]);
    }
}
