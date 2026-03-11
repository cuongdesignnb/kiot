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
        ]);

        try {

        // Load schedule + shift + setting
        $schedule = EmployeeWorkSchedule::with('shift')->findOrFail($data['employee_work_schedule_id']);
        $setting = TimekeepingSetting::where('branch_id', $schedule->branch_id)->first()
            ?? TimekeepingSetting::whereNull('branch_id')->first();

        // Tính scheduleStart / scheduleEnd
        $scheduleStart = $this->buildScheduleDateTime($schedule->work_date, $schedule->start_time, $schedule->shift?->start_time);
        $scheduleEnd = $this->buildScheduleDateTime($schedule->work_date, $schedule->end_time, $schedule->shift?->end_time);
        if ($scheduleStart && $scheduleEnd && $scheduleEnd <= $scheduleStart)
            $scheduleEnd->addDay(); // ca đêm

        // Tính checkIn / checkOut từ input
        $checkInAt = !empty($data['check_in_time']) ? Carbon::parse($schedule->work_date . ' ' . $data['check_in_time']) : null;
        $checkOutAt = !empty($data['check_out_time']) ? Carbon::parse($schedule->work_date . ' ' . $data['check_out_time']) : null;
        if ($checkOutAt && $checkInAt && $checkOutAt <= $checkInAt)
            $checkOutAt->addDay(); // ca đêm

        $useShiftAllowances = (bool) ($setting?->use_shift_allowances ?? true);
        $allowLate = $useShiftAllowances ? ($schedule->shift?->allow_late_minutes ?? 0) : ($setting?->late_grace_minutes ?? 0);
        $allowEarly = $useShiftAllowances ? ($schedule->shift?->allow_early_minutes ?? 0) : ($setting?->early_grace_minutes ?? 0);

        // Tính late, early, OT, worked
        $lateMinutes = 0;
        $earlyMinutes = 0;
        $otMinutes = $data['ot_minutes'] ?? 0;
        $workedMinutes = $checkInAt && $checkOutAt ? $checkOutAt->diffInMinutes($checkInAt) : 0;

        if ($scheduleStart && $checkInAt) {
            $diff = $checkInAt->diffInMinutes($scheduleStart, false);
            $lateMinutes = max(0, $diff - $allowLate);
        }

        if ($scheduleEnd && $checkOutAt && $checkOutAt->lessThan($scheduleEnd)) {
            $diffEarly = $scheduleEnd->diffInMinutes($checkOutAt);
            $earlyMinutes = max(0, $diffEarly - $allowEarly);
        }

        $record = TimekeepingRecord::updateOrCreate(
            ['employee_work_schedule_id' => $schedule->id],
            [
                'employee_id' => $schedule->employee_id,
                'branch_id' => $schedule->branch_id,
                'shift_id' => $schedule->shift_id,
                'work_date' => $schedule->work_date,
                'slot' => $schedule->slot ?? 1,
                'scheduled_start_at' => $scheduleStart,
                'scheduled_end_at' => $scheduleEnd,
                'check_in_at' => $checkInAt,
                'check_out_at' => $checkOutAt,
                'source' => 'manual',
                'attendance_type' => $data['attendance_type'] ?? 'work',
                'manual_override' => true,
                'late_minutes' => $lateMinutes,
                'early_minutes' => $earlyMinutes,
                'ot_minutes' => $otMinutes,
                'worked_minutes' => $workedMinutes,
                'notes' => $data['notes'] ?? null,
            ]
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
