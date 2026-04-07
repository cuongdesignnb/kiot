<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Paysheet;
use App\Models\Payslip;
use App\Models\PaysheetPayment;
use App\Models\Employee;
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
     */
    public function show($id)
    {
        $paysheet = Paysheet::with([
            'branch:id,name',
            'payslips.employee:id,code,name',
            'payments',
        ])->findOrFail($id);

        return response()->json(['success' => true, 'data' => $paysheet]);
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

        $periodStart = Carbon::parse($paysheet->period_start);
        $periodEnd = Carbon::parse($paysheet->period_end);

        // Auto-recalculate timekeeping trước khi tính lại lương
        $timekeepingService = app(TimekeepingService::class);
        $employeeIds = $paysheet->payslips->pluck('employee_id')->unique()->toArray();
        foreach ($employeeIds as $empId) {
            $timekeepingService->recalculateForRange($periodStart, $periodEnd, $empId);
        }

        foreach ($paysheet->payslips as $slip) {
            $employee = Employee::with(['salarySetting'])->find($slip->employee_id);
            if (!$employee) continue;

            $calc = $employee->calculateSalaryForRange($periodStart, $periodEnd);
            $slip->update([
                'base_salary' => $calc['base'],
                'bonus' => $calc['bonus'] ?? 0,
                'commission' => $calc['commission'] ?? 0,
                'allowances' => $calc['allowances'],
                'deductions' => $calc['deductions'],
                'ot_pay' => ($calc['ot_pay'] ?? 0) + ($calc['holiday_pay'] ?? 0),
                'total_salary' => $calc['total'],
                'remaining' => max(0, $calc['total'] - $slip->paid_amount),
                'work_units' => $calc['work_units'],
                'paid_leave_units' => $calc['paid_leave_units'] ?? 0,
                'ot_minutes' => $calc['ot_minutes'] ?? 0,
                'details' => $calc,
            ]);
        }

        $paysheet->status = 'calculated';
        $paysheet->save();
        $paysheet->recalculateTotals();

        return response()->json([
            'success' => true,
            'data' => $paysheet->load(['payslips.employee:id,code,name', 'branch:id,name']),
        ]);
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

            PaysheetPayment::create([
                'paysheet_id' => $paysheet->id,
                'payslip_id' => $slip->id,
                'employee_id' => $slip->employee_id,
                'amount' => $slip->remaining,
                'method' => $method,
                'notes' => $data['notes'] ?? null,
                'paid_at' => now(),
            ]);

            $slip->paid_amount = $slip->total_salary;
            $slip->remaining = 0;
            $slip->save();
            $totalPaid += $slip->paid_amount;
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
}
