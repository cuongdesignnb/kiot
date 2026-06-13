<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TimekeepingRecord;
use App\Models\EmployeeWorkSchedule;
use App\Models\TimekeepingSetting;
use App\Services\TimekeepingService;
use Carbon\Carbon;

class TimekeepingRecordController extends Controller
{
    public function __construct(private readonly TimekeepingService $timekeepingService)
    {
    }

    // GET /api/timekeeping-records
    public function index(Request $request)
    {
        $query = TimekeepingRecord::with(['employee', 'schedule', 'shift', 'branch'])
            ->orderBy('work_date', 'desc');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('from')) {
            $query->where('work_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('work_date', '<=', $request->to);
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate(500)->items(),
        ]);
    }

    // POST /api/timekeeping-records — Chấm công thủ công
    public function store(Request $request)
    {
        // Normalize empty strings to null
        $input = $request->all();
        foreach (['check_in_time', 'check_out_time', 'notes'] as $field) {
            if (isset($input[$field]) && $input[$field] === '') {
                $input[$field] = null;
            }
        }
        $request->merge($input);

        $data = $request->validate([
            'employee_work_schedule_id' => 'required|integer|exists:employee_work_schedules,id',
            'attendance_type' => 'nullable|in:work,leave_paid,leave_unpaid',
            'check_in_time' => 'nullable|date_format:H:i',
            'check_out_time' => 'nullable|date_format:H:i',
            'ot_minutes' => 'nullable|integer|min:0|max:1440',
            'notes' => 'nullable|string',
            'confirm_downgrade' => 'nullable|boolean',
            'confirm_clear_time' => 'nullable|boolean',
        ]);

        try {
            $schedule = EmployeeWorkSchedule::with('shift')->findOrFail($data['employee_work_schedule_id']);

            $attributes = $this->timekeepingService->buildManualRecordAttributes(
                $schedule,
                $data['attendance_type'] ?? 'work',
                $data['check_in_time'] ?? null,
                $data['check_out_time'] ?? null,
                (int) ($data['ot_minutes'] ?? 0),
                $data['notes'] ?? null
            );

            $oldRecord = TimekeepingRecord::where('employee_work_schedule_id', $schedule->id)->first();
            if ($oldRecord) {
                $isDowngrade = (float)$oldRecord->work_units > (float)$attributes['work_units'];
                $isClearTime = ($oldRecord->check_in_at && !$attributes['check_in_at']) ||
                               ($oldRecord->check_out_at && !$attributes['check_out_at']);

                if ($isDowngrade && !$request->boolean('confirm_downgrade')) {
                    return response()->json([
                        'success' => false,
                        'requires_confirmation' => true,
                        'confirm_type' => 'downgrade',
                        'message' => "Lưu chấm công sẽ làm ngày công giảm từ " . (float)$oldRecord->work_units . " xuống " . (float)$attributes['work_units'] . ". Vui lòng xác nhận.",
                        'diff' => [
                            'old_work_units' => (float)$oldRecord->work_units,
                            'new_work_units' => (float)$attributes['work_units'],
                            'old_worked_minutes' => $oldRecord->worked_minutes,
                            'new_worked_minutes' => $attributes['worked_minutes'],
                        ]
                    ], 422);
                }

                if ($isClearTime && !$request->boolean('confirm_clear_time')) {
                    return response()->json([
                        'success' => false,
                        'requires_confirmation' => true,
                        'confirm_type' => 'clear_time',
                        'message' => "Bạn đang bỏ giờ vào/ra đã có sẵn. Việc này có thể làm giảm công. Vui lòng xác nhận.",
                        'diff' => [
                            'old_work_units' => (float)$oldRecord->work_units,
                            'new_work_units' => (float)$attributes['work_units'],
                            'old_worked_minutes' => $oldRecord->worked_minutes,
                            'new_worked_minutes' => $attributes['worked_minutes'],
                        ]
                    ], 422);
                }

                if ($isDowngrade) {
                    \Illuminate\Support\Facades\Log::info("Manual attendance downgrade confirmed by user for employee schedule {$schedule->id}");
                }
                if ($isClearTime) {
                    \Illuminate\Support\Facades\Log::info("Manual attendance clear time confirmed by user for employee schedule {$schedule->id}");
                }
            }

            $record = TimekeepingRecord::updateOrCreate(
                ['employee_work_schedule_id' => $schedule->id],
                $attributes
            );

            return response()->json(['success' => true, 'data' => $record]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // POST /api/timekeeping-records/recalculate
    public function recalculate(Request $request)
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
            'employee_id' => 'nullable|integer',
        ]);

        $result = $this->timekeepingService->recalculateForRange(
            Carbon::parse($data['from']),
            Carbon::parse($data['to']),
            $data['employee_id'] ?? null
        );

        return response()->json(['success' => true, 'data' => $result]);
    }

    private function buildScheduleDateTime($workDate, $scheduleTime, $fallbackShiftTime): ?Carbon
    {
        $time = $scheduleTime ?? $fallbackShiftTime;
        if (!$time)
            return null;
        return Carbon::parse($workDate)->startOfDay()->setTimeFromTimeString((string) $time);
    }
}
