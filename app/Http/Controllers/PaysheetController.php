<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Paysheet;
use App\Models\Payslip;
use App\Models\PayslipAdjustment;
use App\Models\PaysheetPayment;
use App\Models\CashFlow;
use App\Models\Employee;
use App\Models\EmployeeSalarySetting;
use App\Services\TimekeepingService;
use Carbon\Carbon;

class PaysheetController extends Controller
{
    /**
     * GET /api/paysheets — Danh sách bảng lương
     */
    public function index(Request $request)
    {
        $query = Paysheet::with('branch:id,name')
            ->withCount('payslips')
            ->orderByDesc('id');

        if ($request->filled('branch_id'))
            $query->where('branch_id', $request->integer('branch_id'));

        if ($request->filled('status')) {
            $statuses = is_array($request->status) ? $request->status : explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('code', 'like', "%$s%")
                  ->orWhere('name', 'like', "%$s%");
            });
        }

        if ($request->filled('pay_period'))
            $query->where('pay_period', $request->pay_period);

        $paysheets = $query->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $paysheets->items(),
            'meta' => [
                'total' => $paysheets->total(),
                'current_page' => $paysheets->currentPage(),
                'last_page' => $paysheets->lastPage(),
            ],
            'summary' => [
                'total_salary' => Paysheet::when($request->filled('branch_id'), fn($q) => $q->where('branch_id', $request->branch_id))->sum('total_salary'),
                'total_paid' => Paysheet::when($request->filled('branch_id'), fn($q) => $q->where('branch_id', $request->branch_id))->sum('total_paid'),
                'total_remaining' => Paysheet::when($request->filled('branch_id'), fn($q) => $q->where('branch_id', $request->branch_id))->sum('total_remaining'),
            ],
        ]);
    }

    /**
     * GET /api/paysheets/{id} — Chi tiết bảng lương
     * Nếu needs_recalc = true → tự động tính lại trước khi trả về
     */
    public function show($id)
    {
        $paysheet = Paysheet::with([
            'branch:id,name',
            'payslips.employee:id,code,name',
            'payslips.adjustments',
            'payments',
        ])->findOrFail($id);

        $autoRecalculated = false;

        // Auto-recalc nếu có dữ liệu thay đổi và chưa chốt
        if ($paysheet->needs_recalc && !in_array($paysheet->status, ['locked', 'cancelled'])) {
            $this->performRecalculation($paysheet);
            $paysheet->refresh();
            $paysheet->load([
                'branch:id,name',
                'payslips.employee:id,code,name',
                'payslips.adjustments',
                'payments',
            ]);
            $autoRecalculated = true;
        }

        return response()->json([
            'success' => true,
            'data' => $paysheet,
            'auto_recalculated' => $autoRecalculated,
        ]);
    }

    /**
     * POST /api/paysheets — Tạo bảng tính lương mới
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'pay_period' => 'required|in:monthly,biweekly',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'scope' => 'nullable|in:all,custom',
            'employee_ids' => 'nullable|array',
            'employee_ids.*' => 'integer|exists:employees,id',
        ]);

        $periodStart = Carbon::parse($data['period_start']);
        $periodEnd = Carbon::parse($data['period_end']);

        // Generate name
        $monthLabel = $periodStart->month;
        $yearLabel = $periodStart->year;
        $name = "Bảng lương tháng {$monthLabel}/{$yearLabel}";

        $paysheet = Paysheet::create([
            'code' => Paysheet::nextCode(),
            'name' => $name,
            'pay_period' => $data['pay_period'],
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'branch_id' => $data['branch_id'] ?? null,
            'scope' => $data['scope'] ?? 'all',
            'status' => 'calculating',
        ]);

        // Get employees
        $empQuery = Employee::where('is_active', true)->with(['salarySetting']);
        if (!empty($data['branch_id'])) {
            $empQuery->where('branch_id', $data['branch_id']);
        }
        if (($data['scope'] ?? 'all') === 'custom' && !empty($data['employee_ids'])) {
            $empQuery->whereIn('id', $data['employee_ids']);
        }
        $employees = $empQuery->get();

        // Auto-recalculate timekeeping trước khi tính lương
        $timekeepingService = app(TimekeepingService::class);
        foreach ($employees as $employee) {
            $timekeepingService->recalculateForRange($periodStart, $periodEnd, $employee->id);
        }

        // Calculate salary for each employee
        $slipNumber = (int) substr(Payslip::orderByDesc('id')->value('code') ?? 'PL000000', 2);
        foreach ($employees as $employee) {
            $slipNumber++;
            $calc = $employee->calculateSalaryForRange($periodStart, $periodEnd);

            Payslip::create([
                'code' => 'PL' . str_pad($slipNumber, 6, '0', STR_PAD_LEFT),
                'paysheet_id' => $paysheet->id,
                'employee_id' => $employee->id,
                'base_salary' => $calc['base'],
                'bonus' => $calc['bonus'] ?? 0,
                'commission' => $calc['commission'] ?? 0,
                'allowances' => $calc['allowances'],
                'deductions' => $calc['deductions'],
                'ot_pay' => ($calc['ot_pay'] ?? 0) + ($calc['holiday_pay'] ?? 0),
                'total_salary' => $calc['total'],
                'paid_amount' => 0,
                'remaining' => $calc['total'],
                'work_units' => $calc['work_units'],
                'paid_leave_units' => $calc['paid_leave_units'] ?? 0,
                'ot_minutes' => $calc['ot_minutes'] ?? 0,
                'details' => $calc,
            ]);
        }

        // Update paysheet totals
        $paysheet->status = 'calculated';
        $paysheet->save();
        $paysheet->recalculateTotals();

        return response()->json([
            'success' => true,
            'data' => $paysheet->load(['payslips.employee:id,code,name', 'branch:id,name']),
        ]);
    }

    /**
     * POST /api/paysheets/{id}/recalculate — Tải lại dữ liệu / tính lại
     */
    public function recalculate($id)
    {
        $paysheet = Paysheet::with('payslips')->findOrFail($id);

        if ($paysheet->status === 'locked') {
            return response()->json(['success' => false, 'message' => 'Bảng lương đã chốt, không thể tính lại.'], 422);
        }

        $this->performRecalculation($paysheet);

        return response()->json([
            'success' => true,
            'data' => $paysheet->load(['payslips.employee:id,code,name', 'payslips.adjustments', 'branch:id,name']),
        ]);
    }

    /**
     * Thực hiện tính lại bảng lương — dùng chung cho cả auto-recalc và manual recalc.
     * Tự động: recalculate timekeeping → salary calculation → merge adjustments → save.
     */
    private function performRecalculation(Paysheet $paysheet): void
    {
        $periodStart = Carbon::parse($paysheet->period_start);
        $periodEnd = Carbon::parse($paysheet->period_end);

        // Step 1: Recalculate timekeeping
        $timekeepingService = app(TimekeepingService::class);
        $employeeIds = $paysheet->payslips->pluck('employee_id')->unique()->toArray();
        foreach ($employeeIds as $empId) {
            $timekeepingService->recalculateForRange($periodStart, $periodEnd, $empId);
        }

        // Step 2: Recalculate salary for each payslip
        foreach ($paysheet->payslips as $slip) {
            $employee = Employee::with(['salarySetting'])->find($slip->employee_id);
            if (!$employee) continue;

            $calc = $employee->calculateSalaryForRange($periodStart, $periodEnd);

            // Merge manual adjustments (giữ qua recalculate)
            $adjs = $slip->adjustments()->get();
            $adjBonus = $adjs->where('type', 'bonus')->sum('amount');
            $adjAllowance = $adjs->where('type', 'allowance')->sum('amount');
            $adjDeduction = $adjs->where('type', 'deduction')->sum('amount');
            $adjOt = $adjs->where('type', 'ot')->sum('amount');

            $autoOt = ($calc['ot_pay'] ?? 0) + ($calc['holiday_pay'] ?? 0);
            $autoLatePenalty = $calc['late_penalty'] ?? 0;

            // Bonus/Allowance/Deduction: adjustments REPLACE auto (items from settings)
            // OT: adjustments ADD to auto. Late penalty always from auto.
            $totalBonus = $adjs->where('type', 'bonus')->count() > 0 ? $adjBonus : ($calc['bonus'] ?? 0);
            $totalAllowance = $adjs->where('type', 'allowance')->count() > 0 ? $adjAllowance : ($calc['allowances'] ?? 0);
            $totalDeduction = $adjs->where('type', 'deduction')->count() > 0
                ? ($adjDeduction + $autoLatePenalty)
                : ($calc['deductions'] ?? 0);
            $totalOt = $autoOt + $adjOt;
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

        // Step 3: Update paysheet status & totals, clear recalc flag
        $paysheet->status = 'calculated';
        $paysheet->needs_recalc = false;
        $paysheet->save();
        $paysheet->recalculateTotals();
    }

    /**
     * PUT /api/paysheets/{id}/payslips/{slipId} — Cập nhật phiếu lương inline
     */
    public function updatePayslip(Request $request, $id, $slipId)
    {
        $paysheet = Paysheet::findOrFail($id);
        if ($paysheet->status === 'locked') {
            return response()->json(['success' => false, 'message' => 'Bảng lương đã chốt.'], 422);
        }

        $slip = Payslip::where('paysheet_id', $id)->findOrFail($slipId);

        $fields = $request->only([
            'base_salary', 'bonus', 'commission', 'allowances', 'deductions', 'ot_pay',
        ]);

        // Cập nhật các field được gửi
        foreach ($fields as $key => $value) {
            $slip->$key = (int) $value;
        }

        // Tính lại total
        $slip->total_salary = $slip->base_salary + $slip->bonus + $slip->commission
            + $slip->allowances + $slip->ot_pay - $slip->deductions;
        $slip->total_salary = max(0, $slip->total_salary);
        $slip->remaining = max(0, $slip->total_salary - $slip->paid_amount);
        $slip->save();

        // Cập nhật tổng paysheet
        $paysheet->recalculateTotals();

        return response()->json([
            'success' => true,
            'data' => $slip->load('employee:id,code,name'),
            'paysheet' => $paysheet->fresh(),
        ]);
    }

    /**
     * PUT /api/paysheets/{id}/lock — Chốt lương
     */
    public function lock($id)
    {
        $paysheet = Paysheet::findOrFail($id);
        $paysheet->update([
            'status' => 'locked',
            'locked_at' => now(),
            'locked_by' => 'Admin',
        ]);

        return response()->json(['success' => true, 'data' => $paysheet]);
    }

    /**
     * PUT /api/paysheets/{id}/cancel — Hủy bỏ
     */
    public function cancel($id)
    {
        $paysheet = Paysheet::findOrFail($id);

        // Reverse CashFlow entries for this paysheet
        CashFlow::where('reference_type', 'paysheet')
            ->where('reference_code', $paysheet->code)
            ->delete();

        $paysheet->update(['status' => 'cancelled']);
        return response()->json(['success' => true, 'data' => $paysheet]);
    }

    /**
     * POST /api/paysheets/{id}/pay — Thanh toán
     */
    public function pay(Request $request, $id)
    {
        $data = $request->validate([
            'payslip_ids' => 'required|array|min:1',
            'payslip_ids.*' => 'integer|exists:payslips,id',
            'method' => 'nullable|in:cash,bank_transfer',
            'notes' => 'nullable|string',
        ]);

        $paysheet = Paysheet::findOrFail($id);
        $method = $data['method'] ?? 'cash';
        $totalPaid = 0;

        foreach ($data['payslip_ids'] as $slipId) {
            $slip = Payslip::where('paysheet_id', $paysheet->id)->findOrFail($slipId);
            if ($slip->remaining <= 0) continue;

            $paidAmount = $slip->remaining;

            PaysheetPayment::create([
                'paysheet_id' => $paysheet->id,
                'payslip_id' => $slip->id,
                'employee_id' => $slip->employee_id,
                'amount' => $paidAmount,
                'method' => $method,
                'notes' => $data['notes'] ?? null,
                'paid_at' => now(),
            ]);

            // Tạo CashFlow entry — kết nối với báo cáo tài chính
            $employee = Employee::find($slip->employee_id);
            CashFlow::create([
                'code' => 'PC' . now()->format('ymdHis') . $slip->id,
                'type' => 'payment',
                'amount' => $paidAmount,
                'time' => now(),
                'branch_id' => $paysheet->branch_id,
                'category' => 'Chi lương nhân viên',
                'target_type' => 'employee',
                'target_id' => $slip->employee_id,
                'target_name' => $employee?->name ?? '',
                'reference_type' => 'paysheet',
                'reference_code' => $paysheet->code,
                'payment_method' => $method,
                'description' => "Thanh toán lương {$slip->code} - {$employee?->name}",
            ]);

            $slip->paid_amount = $slip->total_salary;
            $slip->remaining = 0;
            $slip->save();
            $totalPaid += $paidAmount;
        }

        $paysheet->recalculateTotals();

        return response()->json([
            'success' => true,
            'data' => $paysheet->load(['payslips.employee:id,code,name', 'branch:id,name']),
        ]);
    }

    /**
     * PUT /api/paysheets/{id}/notes — Cập nhật ghi chú
     */
    public function updateNotes(Request $request, $id)
    {
        $paysheet = Paysheet::findOrFail($id);
        $paysheet->update(['notes' => $request->input('notes', '')]);
        return response()->json(['success' => true, 'data' => $paysheet]);
    }

    /**
     * DELETE /api/paysheets/{id}
     */
    public function destroy($id)
    {
        $paysheet = Paysheet::findOrFail($id);
        if ($paysheet->status === 'locked') {
            return response()->json(['success' => false, 'message' => 'Không thể xóa bảng lương đã chốt.'], 422);
        }

        // Reverse CashFlow entries
        CashFlow::where('reference_type', 'paysheet')
            ->where('reference_code', $paysheet->code)
            ->delete();

        $paysheet->delete();
        return response()->json(['success' => true]);
    }

    public function export(Request $request)
    {
        $paysheets = Paysheet::query()
            ->when($request->search, fn($q, $s) => $q->where('code', 'LIKE', "%{$s}%")->orWhere('name', 'LIKE', "%{$s}%"))
            ->orderBy('id', 'desc')->get();

        return \App\Services\CsvService::export(
            ['Mã bảng lương', 'Tên', 'Kỳ lương', 'Từ ngày', 'Đến ngày', 'Số NV', 'Tổng lương', 'Đã trả', 'Còn lại', 'Trạng thái', 'Ghi chú'],
            $paysheets->map(fn($p) => [$p->code, $p->name, $p->pay_period, $p->period_start, $p->period_end, $p->employee_count, $p->total_salary, $p->total_paid, $p->total_remaining, $p->status, $p->notes]),
            'bang_luong.csv'
        );
    }

    public function print(Paysheet $paysheet)
    {
        $paysheet->load(['branch', 'payslips.employee']);
        return view('prints.paysheet', compact('paysheet'));
    }

    /**
     * GET /employees/paysheets/{id}/edit — Trang sửa bảng lương (Inertia)
     */
    public function edit($id)
    {
        $paysheet = Paysheet::with([
            'branch:id,name',
            'payslips.employee:id,code,name',
            'payslips.adjustments',
        ])->findOrFail($id);

        // Lấy salary settings cho mỗi nhân viên trong bảng lương
        $employeeIds = $paysheet->payslips->pluck('employee_id')->unique();
        $salarySettings = EmployeeSalarySetting::whereIn('employee_id', $employeeIds)
            ->get()
            ->keyBy('employee_id');

        return inertia('Employees/PaysheetEdit', [
            'paysheet' => $paysheet,
            'salarySettings' => $salarySettings,
        ]);
    }

    // ===== Payslip Adjustments CRUD =====

    public function listAdjustments($id, $slipId)
    {
        $slip = Payslip::where('paysheet_id', $id)->findOrFail($slipId);
        return response()->json(['success' => true, 'data' => $slip->adjustments]);
    }

    public function storeAdjustment(Request $request, $id, $slipId)
    {
        $paysheet = Paysheet::findOrFail($id);
        if ($paysheet->status === 'locked') {
            return response()->json(['success' => false, 'message' => 'Bảng lương đã chốt.'], 422);
        }

        $slip = Payslip::where('paysheet_id', $id)->findOrFail($slipId);
        $data = $request->validate([
            'type' => 'required|in:bonus,allowance,deduction,ot',
            'name' => 'required|string|max:255',
            'amount' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:500',
            'meta' => 'nullable|array',
        ]);

        $adj = PayslipAdjustment::create([
            'payslip_id' => $slip->id,
            ...$data,
        ]);

        $this->recalcSlipWithAdjustments($slip);

        return response()->json(['success' => true, 'data' => $adj, 'slip' => $slip->fresh()->load('employee:id,code,name')]);
    }

    public function updateAdjustment(Request $request, $id, $slipId, $adjId)
    {
        $paysheet = Paysheet::findOrFail($id);
        if ($paysheet->status === 'locked') {
            return response()->json(['success' => false, 'message' => 'Bảng lương đã chốt.'], 422);
        }

        $slip = Payslip::where('paysheet_id', $id)->findOrFail($slipId);
        $adj = PayslipAdjustment::where('payslip_id', $slip->id)->findOrFail($adjId);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'amount' => 'sometimes|integer|min:0',
            'notes' => 'nullable|string|max:500',
            'meta' => 'nullable|array',
        ]);

        $adj->update($data);
        $this->recalcSlipWithAdjustments($slip);

        return response()->json(['success' => true, 'data' => $adj, 'slip' => $slip->fresh()->load('employee:id,code,name')]);
    }

    public function deleteAdjustment($id, $slipId, $adjId)
    {
        $paysheet = Paysheet::findOrFail($id);
        if ($paysheet->status === 'locked') {
            return response()->json(['success' => false, 'message' => 'Bảng lương đã chốt.'], 422);
        }

        $slip = Payslip::where('paysheet_id', $id)->findOrFail($slipId);
        PayslipAdjustment::where('payslip_id', $slip->id)->findOrFail($adjId)->delete();
        $this->recalcSlipWithAdjustments($slip);

        return response()->json(['success' => true, 'slip' => $slip->fresh()->load('employee:id,code,name')]);
    }

    /**
     * Tính lại tổng payslip bao gồm adjustments
     */
    private function recalcSlipWithAdjustments(Payslip $slip): void
    {
        $adjs = $slip->adjustments()->get();
        $adjBonus = $adjs->where('type', 'bonus')->sum('amount');
        $adjAllowance = $adjs->where('type', 'allowance')->sum('amount');
        $adjDeduction = $adjs->where('type', 'deduction')->sum('amount');
        $adjOt = $adjs->where('type', 'ot')->sum('amount');

        // Lấy giá trị auto từ details (tính bởi SalaryCalculationService)
        $details = $slip->details ?? [];
        $autoBonus = $details['bonus'] ?? 0;
        $autoCommission = $details['commission'] ?? 0;
        $autoAllowance = $details['allowances'] ?? 0;
        $autoDeduction = $details['deductions'] ?? 0;
        $autoLatePenalty = $details['late_penalty'] ?? 0;
        $autoOt = ($details['ot_pay'] ?? 0) + ($details['holiday_pay'] ?? 0);

        // Bonus/Allowance/Deduction: adjustments REPLACE auto values (items from settings)
        // OT: adjustments ADD to auto. Late penalty always from auto.
        $slip->bonus = $adjs->where('type', 'bonus')->count() > 0 ? $adjBonus : $autoBonus;
        $slip->allowances = $adjs->where('type', 'allowance')->count() > 0 ? $adjAllowance : $autoAllowance;
        $slip->deductions = $adjs->where('type', 'deduction')->count() > 0
            ? ($adjDeduction + $autoLatePenalty)
            : $autoDeduction;
        $slip->ot_pay = $autoOt + $adjOt;

        $slip->total_salary = max(0, $slip->base_salary + $slip->bonus + $autoCommission + $slip->allowances + $slip->ot_pay - $slip->deductions);
        $slip->remaining = max(0, $slip->total_salary - $slip->paid_amount);
        $slip->save();

        $slip->paysheet->recalculateTotals();
    }
}
