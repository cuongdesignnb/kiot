<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Department;
use App\Models\JobTitle;
use App\Models\Branch;
use App\Models\Shift;
use App\Models\EmployeeWorkSchedule;
use App\Models\AttendanceDevice;
use App\Models\EmployeeSalarySetting;
use App\Models\Holiday;
use App\Models\Paysheet;
use App\Models\PayrollSetting;
use App\Models\SalaryTemplate;
use App\Models\Setting;
use App\Models\TimekeepingSetting;
use App\Models\WorkdaySetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    private function defaultWorkdays(): array
    {
        return [
            'mon' => true,
            'tue' => true,
            'wed' => true,
            'thu' => true,
            'fri' => true,
            'sat' => true,
            'sun' => false,
        ];
    }

    private function summarizeWorkdays(array $weekDays): string
    {
        $labels = [
            'mon' => 'T2',
            'tue' => 'T3',
            'wed' => 'T4',
            'thu' => 'T5',
            'fri' => 'T6',
            'sat' => 'T7',
            'sun' => 'CN',
        ];

        $active = [];
        foreach ($labels as $key => $label) {
            if (!empty($weekDays[$key])) {
                $active[] = $label;
            }
        }

        return count($active) ? implode(', ', $active) : 'Chưa thiết lập';
    }

    public function settings()
    {
        $employees = Employee::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'phone', 'branch_id', 'department_id', 'job_title_id']);

        $shifts = Shift::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'start_time', 'end_time', 'status']);

        $branches = Branch::select('id', 'name')->orderBy('name')->get();
        $departments = Department::select('id', 'name')->orderBy('name')->get();
        $jobTitles = JobTitle::select('id', 'name')->orderBy('name')->get();

        $overview = [
            'employees_total' => Employee::count(),
            'shifts_total' => Shift::count(),
            'schedules_total' => EmployeeWorkSchedule::count(),
            'devices_total' => AttendanceDevice::count(),
            'salary_configs_total' => EmployeeSalarySetting::count(),
            'payroll_sheets_total' => Paysheet::count(),
            'employees_scheduled_distinct' => EmployeeWorkSchedule::distinct('employee_id')->count('employee_id'),
        ];

        return Inertia::render('Employees/Settings', [
            'overview' => $overview,
            'employees' => $employees,
            'shifts' => $shifts,
            'branches' => $branches,
            'departments' => $departments,
            'jobTitles' => $jobTitles,
        ]);
    }

    public function attendanceSettings()
    {
        $timekeeping = TimekeepingSetting::whereNull('branch_id')->first();

        return Inertia::render('Employees/AttendanceSettings', [
            'timekeeping' => [
                'standard_hours_per_day' => (float) ($timekeeping?->standard_hours_per_day ?? 8),
                'late_grace_minutes' => (int) ($timekeeping?->late_grace_minutes ?? 10),
                'early_grace_minutes' => (int) ($timekeeping?->early_grace_minutes ?? 0),
                'allow_multiple_shifts_one_inout' => (bool) ($timekeeping?->allow_multiple_shifts_one_inout ?? false),
                'ot_after_minutes' => (int) ($timekeeping?->ot_after_minutes ?? 1),
            ],
            'preferences' => [
                'half_work_enabled' => (bool) Setting::get('attendance_half_work_enabled', true),
                'half_work_max_minutes' => (int) Setting::get('attendance_half_work_max_minutes', 480),
                'half_work_min_minutes' => (int) Setting::get('attendance_half_work_min_minutes', 0),
                'late_enabled' => (bool) Setting::get('attendance_late_enabled', true),
                'early_enabled' => (bool) Setting::get('attendance_early_enabled', false),
                'overtime_before_enabled' => (bool) Setting::get('attendance_overtime_before_enabled', true),
                'overtime_after_enabled' => (bool) Setting::get('attendance_overtime_after_enabled', true),
                'overtime_before_minutes' => (int) Setting::get('attendance_overtime_before_minutes', 1),
                'auto_attendance' => (bool) Setting::get('attendance_auto_enabled', false),
                'mobile_attendance' => (bool) Setting::get('attendance_mobile_enabled', true),
                'mobile_gps_required' => (bool) Setting::get('attendance_mobile_gps_required', true),
                'mobile_qr_enabled' => (bool) Setting::get('attendance_mobile_qr_enabled', true),
                'device_attendance' => (bool) Setting::get('attendance_device_enabled', AttendanceDevice::count() > 0),
            ],
            'meta' => [
                'shift_count' => Shift::count(),
                'device_count' => AttendanceDevice::count(),
            ],
        ]);
    }

    public function attendanceShiftList()
    {
        $shifts = Shift::query()
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'start_time',
                'end_time',
                'allow_late_minutes',
                'allow_early_minutes',
                'status',
                'notes',
            ]);

        return Inertia::render('Employees/AttendanceShiftList', [
            'shifts' => $shifts,
        ]);
    }

    public function attendanceDevices()
    {
        $devices = AttendanceDevice::query()
            ->with('branch:id,name')
            ->orderByDesc('id')
            ->get();

        $branches = Branch::query()->select('id', 'name')->orderBy('name')->get();

        return Inertia::render('Employees/AttendanceDevices', [
            'devices' => $devices,
            'branches' => $branches,
        ]);
    }

    public function payrollSettings()
    {
        $setting = PayrollSetting::whereNull('branch_id')->first();

        return Inertia::render('Employees/PayrollSettings', [
            'payrollSetting' => [
                'pay_cycle' => (string) ($setting?->pay_cycle ?? 'monthly'),
                'start_day' => (int) ($setting?->start_day ?? 26),
                'end_day' => (int) ($setting?->end_day ?? 25),
                'start_in_prev_month' => (bool) ($setting?->start_in_prev_month ?? true),
                'pay_day' => (int) ($setting?->pay_day ?? 5),
                'default_recalculate_timekeeping' => (bool) ($setting?->default_recalculate_timekeeping ?? true),
                'auto_generate_enabled' => (bool) ($setting?->auto_generate_enabled ?? false),
            ],
            'salaryTemplates' => SalaryTemplate::query()
                ->withCount('employeeSettings as employee_count')
                ->with(['bonuses', 'commissions.commissionTable', 'allowances', 'deductions'])
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function workdaySettings()
    {
        $defaults = $this->defaultWorkdays();
        $settingsByBranch = WorkdaySetting::query()->get()->keyBy('branch_id');

        $branches = Branch::query()
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function (Branch $branch) use ($settingsByBranch, $defaults) {
                $setting = $settingsByBranch->get($branch->id);
                $weekDays = array_merge($defaults, $setting?->week_days ?? []);

                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'status' => $setting?->status ?? 'active',
                    'week_days' => $weekDays,
                    'summary' => $this->summarizeWorkdays($weekDays),
                ];
            })
            ->values();

        return Inertia::render('Employees/WorkdaySettings', [
            'branches' => $branches,
            'holidayCount' => Holiday::count(),
        ]);
    }

    public function holidayManagement()
    {
        return Inertia::render('Employees/HolidayManagement', [
            'holidays' => Holiday::query()
                ->orderBy('holiday_date')
                ->get([
                    'id',
                    'holiday_date',
                    'name',
                    'multiplier',
                    'paid_leave',
                    'status',
                    'notes',
                ]),
        ]);
    }

    public function saveAttendanceSettings(Request $request)
    {
        $validated = $request->validate([
            'standard_hours_per_day' => ['required', 'numeric', 'min:0', 'max:24'],
            'late_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:300'],
            'early_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:300'],
            'allow_multiple_shifts_one_inout' => ['nullable', 'boolean'],
            'ot_after_minutes' => ['nullable', 'integer', 'min:0', 'max:300'],
            'preferences' => ['required', 'array'],
            'preferences.half_work_enabled' => ['nullable', 'boolean'],
            'preferences.half_work_max_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'preferences.half_work_min_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'preferences.late_enabled' => ['nullable', 'boolean'],
            'preferences.early_enabled' => ['nullable', 'boolean'],
            'preferences.overtime_before_enabled' => ['nullable', 'boolean'],
            'preferences.overtime_after_enabled' => ['nullable', 'boolean'],
            'preferences.overtime_before_minutes' => ['nullable', 'integer', 'min:0', 'max:300'],
            'preferences.auto_attendance' => ['nullable', 'boolean'],
            'preferences.mobile_attendance' => ['nullable', 'boolean'],
            'preferences.mobile_gps_required' => ['nullable', 'boolean'],
            'preferences.mobile_qr_enabled' => ['nullable', 'boolean'],
            'preferences.device_attendance' => ['nullable', 'boolean'],
        ]);

        $preferences = $validated['preferences'];
        $userId = $request->user()?->id;

        DB::transaction(function () use ($validated, $preferences, $userId) {
            $setting = TimekeepingSetting::updateOrCreate(
                ['branch_id' => null],
                [
                    'branch_id' => null,
                    'standard_hours_per_day' => $validated['standard_hours_per_day'],
                    'use_shift_allowances' => false,
                    'late_grace_minutes' => ($preferences['late_enabled'] ?? false) ? ($validated['late_grace_minutes'] ?? 0) : 0,
                    'early_grace_minutes' => ($preferences['early_enabled'] ?? false) ? ($validated['early_grace_minutes'] ?? 0) : 0,
                    'allow_multiple_shifts_one_inout' => (bool) ($validated['allow_multiple_shifts_one_inout'] ?? false),
                    'enforce_shift_checkin_window' => false,
                    'ot_rounding_minutes' => 0,
                    'ot_after_minutes' => ($preferences['overtime_after_enabled'] ?? false) ? ($validated['ot_after_minutes'] ?? 0) : 0,
                    'status' => 'active',
                    'updated_by' => $userId,
                ]
            );

            if (!$setting->created_by) {
                $setting->created_by = $userId;
                $setting->save();
            }

            Setting::set('attendance_half_work_enabled', (bool) ($preferences['half_work_enabled'] ?? false), 'employee_attendance');
            Setting::set('attendance_half_work_max_minutes', (int) ($preferences['half_work_max_minutes'] ?? 0), 'employee_attendance');
            Setting::set('attendance_half_work_min_minutes', (int) ($preferences['half_work_min_minutes'] ?? 0), 'employee_attendance');
            Setting::set('attendance_late_enabled', (bool) ($preferences['late_enabled'] ?? false), 'employee_attendance');
            Setting::set('attendance_early_enabled', (bool) ($preferences['early_enabled'] ?? false), 'employee_attendance');
            Setting::set('attendance_overtime_before_enabled', (bool) ($preferences['overtime_before_enabled'] ?? false), 'employee_attendance');
            Setting::set('attendance_overtime_after_enabled', (bool) ($preferences['overtime_after_enabled'] ?? false), 'employee_attendance');
            Setting::set('attendance_overtime_before_minutes', (int) ($preferences['overtime_before_minutes'] ?? 0), 'employee_attendance');
            Setting::set('attendance_auto_enabled', (bool) ($preferences['auto_attendance'] ?? false), 'employee_attendance');
            Setting::set('attendance_mobile_enabled', (bool) ($preferences['mobile_attendance'] ?? false), 'employee_attendance');
            Setting::set('attendance_mobile_gps_required', (bool) ($preferences['mobile_gps_required'] ?? true), 'employee_attendance');
            Setting::set('attendance_mobile_qr_enabled', (bool) ($preferences['mobile_qr_enabled'] ?? true), 'employee_attendance');
            Setting::set('attendance_device_enabled', (bool) ($preferences['device_attendance'] ?? false), 'employee_attendance');
        });

        return response()->json([
            'success' => true,
            'message' => 'Đã lưu thiết lập chấm công.',
        ]);
    }

    public function index(Request $request)
    {
        $query = Employee::with(['branch', 'department', 'jobTitle']);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active') && $request->is_active !== null) {
            $query->where('is_active', $request->is_active === 'true' || $request->is_active == 1);
        }

        if ($request->has('branch_id') && $request->branch_id) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->has('department_id') && $request->department_id) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->has('job_title_id') && $request->job_title_id) {
            $query->where('job_title_id', $request->job_title_id);
        }

        $query->when($request->filled('sort_by'), function ($q) use ($request) {
            $allowed = ['code', 'attendance_code', 'name', 'phone', 'cccd', 'created_at'];
            $sortBy = in_array($request->sort_by, $allowed) ? $request->sort_by : 'created_at';
            $dir = $request->sort_direction === 'asc' ? 'asc' : 'desc';
            $q->orderBy($sortBy, $dir);
        }, function ($q) {
            $q->latest();
        });

        $employees = $query->paginate(20)->withQueryString();

        $branches = Branch::select('id', 'name')->get();
        $departments = Department::select('id', 'name')->get();
        $jobTitles = JobTitle::select('id', 'name')->get();

        return Inertia::render('Employees/Index', [
            'employees' => $employees,
            'branches' => $branches,
            'departments' => $departments,
            'jobTitles' => $jobTitles,
            'salaryTemplates' => SalaryTemplate::select('id', 'name')->get(),
            'filters' => $request->only('search', 'is_active', 'branch_id', 'department_id', 'job_title_id', 'sort_by', 'sort_direction')
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'nullable|string|unique:employees,code',
            'attendance_code' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'cccd' => 'nullable|string|max:20',
            'branch_id' => 'nullable|exists:branches,id',
            'department_id' => 'nullable|exists:departments,id',
            'job_title_id' => 'nullable|exists:job_titles,id',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // Nếu không truyền code, tự sinh mã
        if (empty($validated['code'])) {
            $nextId = (Employee::max('id') ?? 0) + 1;
            $validated['code'] = $this->generateEmployeeCode($nextId);
        }

        $employee = Employee::create($validated);

        return redirect()->back()->with([
            'success' => 'Thêm nhân viên thành công.',
            'new_employee_id' => $employee->id,
        ]);
    }

    public function update(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'code' => 'nullable|string|unique:employees,code,' . $employee->id,
            'attendance_code' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'cccd' => 'nullable|string|max:20',
            'branch_id' => 'nullable|exists:branches,id',
            'department_id' => 'nullable|exists:departments,id',
            'job_title_id' => 'nullable|exists:job_titles,id',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $employee->update($validated);

        return redirect()->back()->with('success', 'Cập nhật nhân viên thành công.');
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();
        return redirect()->back()->with('success', 'Xóa nhân viên thành công.');
    }

    public function bulkStore(Request $request)
    {
        $validated = $request->validate([
            'employees' => 'required|array|min:1',
            'employees.*.code' => 'nullable|string|max:50|distinct|unique:employees,code',
            'employees.*.attendance_code' => 'nullable|string|max:50',
            'employees.*.name' => 'required|string|max:255',
            'employees.*.phone' => 'nullable|string|max:20',
            'employees.*.email' => 'nullable|email|max:255',
            'employees.*.cccd' => 'nullable|string|max:20',
            'employees.*.branch_id' => 'nullable|exists:branches,id',
            'employees.*.department_id' => 'nullable|exists:departments,id',
            'employees.*.job_title_id' => 'nullable|exists:job_titles,id',
            'employees.*.notes' => 'nullable|string',
            'employees.*.is_active' => 'nullable|boolean',
        ]);

        $nextId = (Employee::max('id') ?? 0) + 1;
        $created = 0;

        DB::transaction(function () use (&$validated, &$nextId, &$created) {
            foreach ($validated['employees'] as $row) {
                if (empty($row['code'])) {
                    $row['code'] = $this->generateEmployeeCode($nextId);
                    $nextId++;
                }

                if (!array_key_exists('is_active', $row)) {
                    $row['is_active'] = true;
                }

                Employee::create($row);
                $created++;
            }
        });

        return redirect()->back()->with('success', "Đã thêm {$created} nhân viên thành công.");
    }

    private function generateEmployeeCode(int $id): string
    {
        return 'NV' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    public function export(Request $request)
    {
        $employees = \App\Models\Employee::with(['branch', 'department', 'jobTitle'])
            ->when($request->search, fn($q, $s) => $q->where('name', 'LIKE', "%{$s}%")->orWhere('code', 'LIKE', "%{$s}%")->orWhere('phone', 'LIKE', "%{$s}%"))
            ->orderBy('id', 'desc')->get();

        return \App\Services\CsvService::export(
            ['Mã NV', 'Mã chấm công', 'Tên nhân viên', 'Điện thoại', 'Email', 'CCCD', 'Chi nhánh', 'Phòng ban', 'Chức danh', 'Trạng thái', 'Ghi chú'],
            $employees->map(fn($e) => [$e->code, $e->attendance_code, $e->name, $e->phone, $e->email, $e->cccd, $e->branch?->name, $e->department?->name, $e->jobTitle?->name, $e->is_active ? 'Đang làm' : 'Nghỉ việc', $e->notes]),
            'nhan_vien.csv'
        );
    }

    public function import(Request $request)
    {
        [$headers, $rows] = \App\Services\CsvService::parse($request);
        $count = 0;
        foreach ($rows as $row) {
            if (count($row) < 3 || empty(trim($row[2] ?? ''))) continue;
            \App\Models\Employee::updateOrCreate(
                ['code' => trim($row[0])],
                ['attendance_code' => trim($row[1] ?? ''), 'name' => trim($row[2]), 'phone' => trim($row[3] ?? ''), 'email' => trim($row[4] ?? ''), 'cccd' => trim($row[5] ?? ''), 'notes' => trim($row[10] ?? '')]
            );
            $count++;
        }
        return back()->with('success', "Đã nhập {$count} nhân viên từ file.");
    }
}
