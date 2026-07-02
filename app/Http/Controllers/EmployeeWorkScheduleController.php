<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\EmployeeWorkSchedule;

class EmployeeWorkScheduleController extends Controller
{
    public function index(Request $request)
    {
        $query = EmployeeWorkSchedule::query()->with([
            'employee:id,code,name',
            'branch:id,name',
            'shift:id,name,start_time,end_time',
            'timekeepingRecord:id,employee_work_schedule_id,attendance_type,manual_override,scheduled_start_at,scheduled_end_at,check_in_at,check_out_at,source,late_minutes,early_minutes,ot_minutes,worked_minutes,notes',
        ]);

        if ($request->filled('employee_id'))
            $query->where('employee_id', $request->integer('employee_id'));
        if ($request->filled('from'))
            $query->whereDate('work_date', '>=', $request->date('from'));
        if ($request->filled('to'))
            $query->whereDate('work_date', '<=', $request->date('to'));

        return response()->json([
            'success' => true,
            'data' => $query->orderByDesc('work_date')->paginate(5000)->items(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'work_date' => 'required|date',
            'slot' => 'nullable|integer|min:1|max:20',
            'shift_id' => 'nullable|integer|exists:shifts,id',
            'start_time' => 'nullable',
            'end_time' => 'nullable',
            'shift_name' => 'nullable|string|max:255',
            'status' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $slot = (int) ($data['slot'] ?? 1);

        $schedule = EmployeeWorkSchedule::updateOrCreate(
            ['employee_id' => $data['employee_id'], 'work_date' => $data['work_date'], 'slot' => $slot],
            [
                'branch_id' => $request->branch_id ?? null,
                'shift_id' => $data['shift_id'] ?? null,
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'shift_name' => $data['shift_name'] ?? null,
                'status' => $data['status'] ?? 'planned',
                'notes' => $data['notes'] ?? null,
            ]
        );

        return response()->json(['success' => true, 'data' => $schedule->load('shift', 'employee', 'timekeepingRecord')]);
    }

    public function destroy($id)
    {
        $schedule = EmployeeWorkSchedule::findOrFail($id);
        $schedule->delete();
        return response()->json(['success' => true]);
    }

    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|integer',
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $deleted = EmployeeWorkSchedule::where('employee_id', $data['employee_id'])
            ->whereDate('work_date', '>=', $data['from'])
            ->whereDate('work_date', '<=', $data['to'])
            ->delete();

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }
}
