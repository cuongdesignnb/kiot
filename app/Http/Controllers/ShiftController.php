<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Shift;
use App\Models\Paysheet;
use App\Models\Payslip;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Services\TimekeepingService;
use Carbon\Carbon;

class ShiftController extends Controller
{
    public function index(Request $request)
    {
        $query = Shift::query()->with(['branch:id,name']);
        if ($request->filled('branch_id'))
            $query->where('branch_id', $request->integer('branch_id'));
        if ($request->filled('status'))
            $query->where('status', $request->string('status'));
        return response()->json(['success' => true, 'data' => $query->orderByDesc('id')->get()]);
    }

    public function show(Shift $shift)
    {
        $shift->load(['branch:id,name']);
        return response()->json(['success' => true, 'data' => $shift]);
    }

    public function store(Request $request)
    {
        // Normalize time fields to H:i
        foreach (['start_time', 'end_time', 'checkin_start_time', 'checkin_end_time'] as $f) {
            if ($request->has($f) && $request->$f) {
                $request->merge([$f => substr($request->$f, 0, 5)]);
            }
        }

        $data = $request->validate([
            'branch_id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'checkin_start_time' => 'nullable|date_format:H:i|required_with:checkin_end_time',
            'checkin_end_time' => 'nullable|date_format:H:i|required_with:checkin_start_time',
            'allow_late_minutes' => 'nullable|integer|min:0|max:1440',
            'allow_early_minutes' => 'nullable|integer|min:0|max:1440',
            'rounding_minutes' => 'nullable|integer|min:1|max:240',
            'is_overnight' => 'nullable|boolean',
            'status' => 'nullable|string|max:30',
            'notes' => 'nullable|string',
        ]);

        $isOvernight = array_key_exists('is_overnight', $data)
            ? (bool) $data['is_overnight']
            : ($this->timeToMinutes($data['end_time']) <= $this->timeToMinutes($data['start_time']));

        $shift = Shift::create([
            'branch_id' => $data['branch_id'] ?? null,
            'name' => $data['name'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'checkin_start_time' => $data['checkin_start_time'] ?? null,
            'checkin_end_time' => $data['checkin_end_time'] ?? null,
            'allow_late_minutes' => $data['allow_late_minutes'] ?? 0,
            'allow_early_minutes' => $data['allow_early_minutes'] ?? 0,
            'rounding_minutes' => $data['rounding_minutes'] ?? 15,
            'is_overnight' => $isOvernight,
            'status' => $data['status'] ?? 'active',
            'notes' => $data['notes'] ?? null,
        ]);

        $shift->load(['branch:id,name']);
        return response()->json(['success' => true, 'message' => 'Tạo ca làm việc thành công', 'data' => $shift], 201);
    }

    public function update(Request $request, Shift $shift)
    {
        // Normalize time fields to H:i
        foreach (['start_time', 'end_time', 'checkin_start_time', 'checkin_end_time'] as $f) {
            if ($request->has($f) && $request->$f) {
                $request->merge([$f => substr($request->$f, 0, 5)]);
            }
        }

        $data = $request->validate([
            'branch_id' => 'nullable|integer',
            'name' => 'required|string|max:255',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'checkin_start_time' => 'nullable|date_format:H:i|required_with:checkin_end_time',
            'checkin_end_time' => 'nullable|date_format:H:i|required_with:checkin_start_time',
            'allow_late_minutes' => 'nullable|integer|min:0|max:1440',
            'allow_early_minutes' => 'nullable|integer|min:0|max:1440',
            'rounding_minutes' => 'nullable|integer|min:1|max:240',
            'is_overnight' => 'nullable|boolean',
            'status' => 'nullable|string|max:30',
            'notes' => 'nullable|string',
        ]);

        $isOvernight = array_key_exists('is_overnight', $data)
            ? (bool) $data['is_overnight']
            : ($this->timeToMinutes($data['end_time']) <= $this->timeToMinutes($data['start_time']));

        $shift->update([
            'branch_id' => $data['branch_id'] ?? null,
            'name' => $data['name'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'checkin_start_time' => $data['checkin_start_time'] ?? $shift->checkin_start_time,
            'checkin_end_time' => $data['checkin_end_time'] ?? $shift->checkin_end_time,
            'allow_late_minutes' => $data['allow_late_minutes'] ?? $shift->allow_late_minutes,
            'allow_early_minutes' => $data['allow_early_minutes'] ?? $shift->allow_early_minutes,
            'rounding_minutes' => $data['rounding_minutes'] ?? $shift->rounding_minutes,
            'is_overnight' => $isOvernight,
            'status' => $data['status'] ?? $shift->status,
            'notes' => $data['notes'] ?? $shift->notes,
        ]);

        $shift->load(['branch:id,name']);

        // Tự động tính lại chấm công + bảng lương chưa chốt
        $this->recalcUnlockedPaysheets($shift);

        return response()->json(['success' => true, 'message' => 'Cập nhật ca làm việc thành công', 'data' => $shift]);
    }

    public function destroy(Shift $shift)
    {
        $shift->delete();
        return response()->json(['success' => true, 'message' => 'Xóa ca làm việc thành công']);
    }

    public function toggle(Shift $shift)
    {
        $shift->update([
            'status' => $shift->status === 'active' ? 'inactive' : 'active',
        ]);

        $shift->load(['branch:id,name']);
        return response()->json(['success' => true, 'message' => 'Đã cập nhật trạng thái ca làm việc', 'data' => $shift]);
    }

    private function timeToMinutes($time)
    {
        if (!$time)
            return null;
        $parts = explode(':', $time);
        if (count($parts) >= 2) {
            return (int) $parts[0] * 60 + (int) $parts[1];
        }
        return null;
    }

    /**
     * Khi sửa ca làm việc → tự động tính lại chấm công + bảng lương chưa chốt
     */
    private function recalcUnlockedPaysheets(Shift $shift): void
    {
        // Tìm nhân viên có lịch dùng ca này
        $employeeIds = EmployeeWorkSchedule::where('shift_id', $shift->id)
            ->distinct()
            ->pluck('employee_id')
            ->toArray();

        if (empty($employeeIds)) return;

        // Tìm bảng lương chưa chốt có chứa nhân viên dùng ca này
        $paysheets = Paysheet::whereIn('status', ['draft', 'calculating', 'calculated'])
            ->whereHas('payslips', fn($q) => $q->whereIn('employee_id', $employeeIds))
            ->with('payslips')
            ->get();

        if ($paysheets->isEmpty()) return;

        $timekeepingService = app(TimekeepingService::class);

        foreach ($paysheets as $paysheet) {
            $periodStart = Carbon::parse($paysheet->period_start);
            $periodEnd = Carbon::parse($paysheet->period_end);

            foreach ($paysheet->payslips as $slip) {
                if (!in_array($slip->employee_id, $employeeIds)) continue;

                // Tính lại chấm công
                $timekeepingService->recalculateForRange($periodStart, $periodEnd, $slip->employee_id);

                // Tính lại lương
                $employee = Employee::with('salarySetting')->find($slip->employee_id);
                if (!$employee) continue;

                $calc = $employee->calculateSalaryForRange($periodStart, $periodEnd);

                $adjs = $slip->adjustments()->get();
                $autoOt = ($calc['ot_pay'] ?? 0) + ($calc['holiday_pay'] ?? 0);
                $autoLatePenalty = $calc['late_penalty'] ?? 0;

                $totalBonus = $adjs->where('type', 'bonus')->count() > 0
                    ? $adjs->where('type', 'bonus')->sum('amount')
                    : ($calc['bonus'] ?? 0);
                $totalAllowance = $adjs->where('type', 'allowance')->count() > 0
                    ? $adjs->where('type', 'allowance')->sum('amount')
                    : ($calc['allowances'] ?? 0);
                $totalDeduction = $adjs->where('type', 'deduction')->count() > 0
                    ? ($adjs->where('type', 'deduction')->sum('amount') + $autoLatePenalty)
                    : ($calc['deductions'] ?? 0);
                $totalOt = $autoOt + $adjs->where('type', 'ot')->sum('amount');
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
            }

            $paysheet->status = 'calculated';
            $paysheet->save();
            $paysheet->recalculateTotals();
        }
    }
}
