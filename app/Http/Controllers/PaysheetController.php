<?php

namespace App\Http\Controllers;

use App\Models\CashFlow;
use App\Models\Employee;
use App\Models\EmployeeSalarySetting;
use App\Models\Paysheet;
use App\Models\PaysheetPayment;
use App\Models\Payslip;
use App\Models\PayslipAdjustment;
use App\Services\PayrollDateGuard;
use App\Services\PayrollPostingService;
use App\Services\SalaryPaymentService;
use App\Services\TimekeepingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaysheetController extends Controller
{
    /**
     * GET /api/paysheets — Danh sách bảng lương
     */
    public function index(Request $request)
    {
        $query = Paysheet::with('branch:id,name')
            ->withCount(['payments as active_payments_count' => fn ($payment) => $payment->where('status', 'active')])
            ->withCount('payslips')
            ->orderByDesc('id');

        $this->applyPaysheetIndexFilters($query, $request);

        $summaryQuery = (clone $query)->reorder();
        $paysheets = $query->paginate(50);
        $items = collect($paysheets->items())
            ->map(fn (Paysheet $paysheet) => $this->decoratePaysheetForActions($paysheet))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'total' => $paysheets->total(),
                'current_page' => $paysheets->currentPage(),
                'last_page' => $paysheets->lastPage(),
            ],
            'summary' => [
                'total_salary' => (int) (clone $summaryQuery)->sum('total_salary'),
                'total_paid' => (int) (clone $summaryQuery)->sum('total_paid'),
                'total_remaining' => (int) (clone $summaryQuery)->sum('total_remaining'),
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
            'payslips.advanceApplications.advance:id,code,advance_date,amount,status',
            'payments.employee:id,code,name',
            'payments.payslip:id,code',
        ])->findOrFail($id);

        $autoRecalculated = false;

        // Auto-recalc nếu có dữ liệu thay đổi và chưa chốt
        if ($paysheet->needs_recalc && ! in_array($paysheet->status, ['locked', 'cancelled'])) {
            $this->performRecalculation($paysheet);
            $paysheet->refresh();
            $paysheet->load([
                'branch:id,name',
                'payslips.employee:id,code,name',
                'payslips.adjustments',
                'payslips.advanceApplications.advance:id,code,advance_date,amount,status',
                'payments',
            ]);
            $autoRecalculated = true;
        }

        return response()->json([
            'success' => true,
            'data' => $this->decoratePaysheetForActions($this->withEffectiveStandard($paysheet)),
            'auto_recalculated' => $autoRecalculated,
        ]);
    }

    private function applyPaysheetIndexFilters($query, Request $request): void
    {
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->integer('branch_id'));
        }

        if ($request->filled('status')) {
            $statuses = array_values(array_filter(is_array($request->status) ? $request->status : explode(',', $request->status)));
            $query->whereIn('status', $statuses);
        } else {
            $query->where('status', '!=', 'cancelled');
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('code', 'like', "%$s%")
                    ->orWhere('name', 'like', "%$s%");
            });
        }

        if ($request->filled('pay_period')) {
            $query->where('pay_period', $request->pay_period);
        }
    }

    private function decoratePaysheetForActions(Paysheet $paysheet): Paysheet
    {
        $activePayments = $paysheet->active_payments_count
            ?? ($paysheet->relationLoaded('payments')
                ? $paysheet->payments->where('status', 'active')->count()
                : $paysheet->payments()->where('status', 'active')->count());

        $paysheet->setAttribute('can_pay', $paysheet->status === 'locked');
        $paysheet->setAttribute('can_cancel', $paysheet->status === 'locked' && $activePayments === 0);
        $paysheet->setAttribute('can_recalculate', ! in_array($paysheet->status, ['locked', 'cancelled'], true));
        $paysheet->setAttribute('status_label', $this->paysheetStatusLabel($paysheet->status));

        return $paysheet;
    }

    private function paysheetStatusLabel(?string $status): string
    {
        return [
            'draft' => 'Dang tao',
            'calculating' => 'Dang tinh',
            'calculated' => 'Tam tinh',
            'locked' => 'Da chot luong',
            'cancelled' => 'Da huy',
        ][$status] ?? (string) $status;
    }

    /**
     * Step 24.12-FIX — Annotate a paysheet payload with the *effective* standard
     * working days for the UI. When the column is null (legacy paysheets created
     * before STEP 24.12), fall back to the calendar lookup so the right side
     * panel never has to invent a number — and never silently persists 26.
     */
    private function withEffectiveStandard(Paysheet $paysheet): Paysheet
    {
        $effective = $paysheet->standard_working_days
            ? (float) $paysheet->standard_working_days
            : (float) app(\App\Services\SalaryCalculationService::class)
                ->standardWorkingDaysForBranch(
                    $paysheet->branch_id,
                    Carbon::parse($paysheet->period_start),
                    Carbon::parse($paysheet->period_end),
                );
        $paysheet->setAttribute('effective_standard_working_days', $effective > 0 ? $effective : null);

        return $paysheet;
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

        // Step 24.12 — seed standard_working_days from the calendar so the
        // right side panel has a sensible default; the user can override later.
        $calendarStandard = app(\App\Services\SalaryCalculationService::class)
            ->standardWorkingDaysForBranch($data['branch_id'] ?? null, $periodStart, $periodEnd);

        $paysheet = Paysheet::create([
            'code' => Paysheet::nextCode(),
            'name' => $name,
            'pay_period' => $data['pay_period'],
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'standard_working_days' => $calendarStandard > 0 ? round($calendarStandard, 2) : null,
            'branch_id' => $data['branch_id'] ?? null,
            'scope' => $data['scope'] ?? 'all',
            'status' => 'calculating',
        ]);

        // Get employees
        $empQuery = Employee::where('is_active', true)->with(['salarySetting']);
        if (! empty($data['branch_id'])) {
            $empQuery->where('branch_id', $data['branch_id']);
        }
        if (($data['scope'] ?? 'all') === 'custom' && ! empty($data['employee_ids'])) {
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
                'code' => 'PL'.str_pad($slipNumber, 6, '0', STR_PAD_LEFT),
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

        if (in_array($paysheet->status, ['locked', 'cancelled'], true)) {
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
        // Step 24.12 — honour paysheet.standard_working_days as the denominator
        // when set; null falls back to calendar via SalaryCalculationService.
        $standardOverride = $paysheet->standard_working_days
            ? (float) $paysheet->standard_working_days
            : null;
        $salaryService = app(\App\Services\SalaryCalculationService::class);

        foreach ($paysheet->payslips as $slip) {
            $employee = Employee::with(['salarySetting'])->find($slip->employee_id);
            if (! $employee) {
                continue;
            }

            $calc = $salaryService->calculateForEmployee($employee, $periodStart, $periodEnd, $standardOverride);

            // HOTFIX 24.12B — Preserve manual_overrides set by bulkSaveAdjustments
            // across performRecalculation (otherwise $calc overwrites details and
            // the user's "phụ cấp = 0" intent silently reverts to auto).
            $oldDetails = is_array($slip->details) ? $slip->details : [];
            if (isset($oldDetails['manual_overrides']) && is_array($oldDetails['manual_overrides'])) {
                $calc['manual_overrides'] = $oldDetails['manual_overrides'];
            }
            $manualOverrides = $calc['manual_overrides'] ?? [];
            $commissionOverride = (bool) ($manualOverrides['commission'] ?? false);
            $allowanceOverride = (bool) ($manualOverrides['allowance'] ?? false);
            $bonusOverride = (bool) ($manualOverrides['bonus'] ?? false);
            $deductionOverride = (bool) ($manualOverrides['deduction'] ?? false);

            // Merge manual adjustments (giữ qua recalculate)
            $adjs = $slip->adjustments()->get();
            $adjCommission = $adjs->where('type', 'commission')->sum('amount');
            $adjBonus = $adjs->where('type', 'bonus')->sum('amount');
            $adjAllowance = $adjs->where('type', 'allowance')->sum('amount');
            $adjDeduction = $adjs->where('type', 'deduction')->sum('amount');
            $adjOt = $adjs->where('type', 'ot')->sum('amount');

            $autoOt = ($calc['ot_pay'] ?? 0) + ($calc['holiday_pay'] ?? 0);
            $autoLatePenalty = $calc['late_penalty'] ?? 0;

            // 24.12C — commission/bonus/allowance/deduction: adjustments OR
            // manual override REPLACE auto. OT: adjustments ADD to auto.
            // late_penalty is included only in the no-override deduction path
            // (a row-based addition); when the user explicitly overrides
            // deduction, their total is the final number.
            $totalCommission = ($commissionOverride || $adjs->where('type', 'commission')->count() > 0)
                ? $adjCommission
                : ($calc['commission'] ?? 0);
            $totalBonus = ($bonusOverride || $adjs->where('type', 'bonus')->count() > 0)
                ? $adjBonus
                : ($calc['bonus'] ?? 0);
            $totalAllowance = ($allowanceOverride || $adjs->where('type', 'allowance')->count() > 0)
                ? $adjAllowance
                : ($calc['allowances'] ?? 0);
            if ($deductionOverride) {
                $totalDeduction = $adjDeduction;
            } elseif ($adjs->where('type', 'deduction')->count() > 0) {
                $totalDeduction = $adjDeduction + $autoLatePenalty;
            } else {
                $totalDeduction = $calc['deductions'] ?? 0;
            }
            $totalOt = $autoOt + $adjOt;
            $totalSalary = max(0, $calc['base'] + $totalBonus + $totalCommission + $totalAllowance + $totalOt - $totalDeduction);

            $slip->update([
                'base_salary' => $calc['base'],
                'bonus' => $totalBonus,
                'commission' => $totalCommission,
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
        if (in_array($paysheet->status, ['locked', 'cancelled'], true)) {
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
    public function lock($id, PayrollPostingService $service)
    {
        $paysheet = Paysheet::findOrFail($id);

        return response()->json(['success' => true, 'data' => $service->lock($paysheet)]);
    }

    /**
     * PUT /api/paysheets/{id}/cancel — Hủy bỏ
     */
    public function cancel(Request $request, $id, PayrollPostingService $service, PayrollDateGuard $dateGuard)
    {
        $data = $request->validate([
            'reason' => 'required|string|min:10|max:1000',
            'cancel_date' => 'nullable|date',
            'override_reason' => 'nullable|string|min:10|max:1000',
        ]);
        $paysheet = Paysheet::findOrFail($id);
        $eventAt = $dateGuard->assertAllowed($data['cancel_date'] ?? now(), $data['override_reason'] ?? null, 'paysheet_cancel');
        $result = $service->cancel($paysheet, $data['reason'], $eventAt);

        return response()->json([
            'success' => true,
            'message' => 'Da huy bang luong',
            'data' => $result['paysheet'],
            'paysheet' => $result['paysheet'],
            'reversed_entries_count' => $result['reversed_entries_count'],
            'mode' => $result['mode'] ?? 'standard',
        ]);
    }

    /**
     * POST /api/paysheets/{id}/pay — Thanh toán
     */
    public function pay(Request $request, $id, SalaryPaymentService $service, PayrollDateGuard $dateGuard)
    {
        $payload = $request->validate([
            'payment_date' => 'required|date',
            'payment_method' => 'required|in:cash,bank,bank_transfer,ewallet',
            'note' => 'nullable|string|max:1000',
            'payments' => 'required|array|min:1',
            'payments.*.payslip_id' => 'required|integer|exists:payslips,id',
            'payments.*.amount' => 'required|integer|min:1',
            'override_reason' => 'nullable|string|min:10|max:1000',
        ]);
        $paysheet = Paysheet::findOrFail($id);
        $payload['payment_date'] = $dateGuard->assertAllowed(
            $payload['payment_date'],
            $payload['override_reason'] ?? null,
            'salary_payment_create'
        );
        $key = $request->header('Idempotency-Key') ?: 'payment:'.sha1(json_encode([
            $paysheet->id, (string) $payload['payment_date'], $payload['payments'],
        ]));

        return response()->json([
            'success' => true,
            'data' => $service->pay($paysheet, $payload['payments'], $payload, $key),
        ]);
    }

    /**
     * PUT /api/paysheets/{id}/notes — Cập nhật ghi chú
     */
    public function cancelPayment(Request $request, PaysheetPayment $payment, SalaryPaymentService $service, PayrollDateGuard $dateGuard)
    {
        $data = $request->validate([
            'reason' => 'required|string|min:10|max:1000',
            'cancel_date' => 'required|date',
            'override_reason' => 'nullable|string|min:10|max:1000',
        ]);
        $eventAt = $dateGuard->assertAllowed(
            $data['cancel_date'],
            $data['override_reason'] ?? null,
            'salary_payment_cancel'
        );

        return response()->json(['success' => true, 'data' => $service->cancel($payment, $data['reason'], $eventAt)]);
    }

    public function updateNotes(Request $request, $id)
    {
        $paysheet = Paysheet::findOrFail($id);
        $paysheet->update(['notes' => $request->input('notes', '')]);

        return response()->json(['success' => true, 'data' => $paysheet]);
    }

    /**
     * Step 24.12 — PUT /api/paysheets/{id}/standard-working-days
     *
     * Update the per-paysheet "ngày công chuẩn" and recompute every payslip
     * in the sheet. Backend recomputes — frontend's net_pay / totals are
     * never trusted. Locked or cancelled sheets are refused outright.
     */
    public function updateStandardWorkingDays(Request $request, $id)
    {
        $data = $request->validate([
            'standard_working_days' => 'required|numeric|min:1|max:31',
            'name' => 'sometimes|string|max:255',
            'notes' => 'sometimes|nullable|string|max:2000',
        ]);

        $paysheet = Paysheet::with('payslips')->findOrFail($id);

        if (in_array($paysheet->status, ['locked', 'cancelled'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Bảng lương đã chốt hoặc hủy, không thể chỉnh sửa ngày công chuẩn.',
            ], 422);
        }

        $paysheet->standard_working_days = (float) $data['standard_working_days'];
        if (array_key_exists('name', $data)) {
            $paysheet->name = $data['name'];
        }
        if (array_key_exists('notes', $data)) {
            $paysheet->notes = $data['notes'];
        }
        $paysheet->save();

        // Recompute every payslip with the new denominator. Backend never
        // trusts the FE-sent net_pay — recalculation is the source of truth.
        $this->performRecalculation($paysheet);

        return response()->json([
            'success' => true,
            'data' => $paysheet->fresh()->load(['payslips.employee:id,code,name', 'branch:id,name']),
        ]);
    }

    /**
     * DELETE /api/paysheets/{id}
     */
    public function destroy($id)
    {
        $paysheet = Paysheet::findOrFail($id);
        if ($paysheet->payments()->exists()) {
            return response()->json(['success' => false, 'message' => 'Không thể xóa bảng lương đã có thanh toán.'], 422);
        }
        if (in_array($paysheet->status, ['locked', 'cancelled'], true)) {
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
            ->when($request->search, fn ($q, $s) => $q->where('code', 'LIKE', "%{$s}%")->orWhere('name', 'LIKE', "%{$s}%"))
            ->orderBy('id', 'desc')->get();

        return \App\Services\CsvService::export(
            ['Mã bảng lương', 'Tên', 'Kỳ lương', 'Từ ngày', 'Đến ngày', 'Số NV', 'Tổng lương', 'Đã trả', 'Còn lại', 'Trạng thái', 'Ghi chú'],
            $paysheets->map(fn ($p) => [$p->code, $p->name, $p->pay_period, $p->period_start, $p->period_end, $p->employee_count, $p->total_salary, $p->total_paid, $p->total_remaining, $p->status, $p->notes]),
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
            'paysheet' => $this->withEffectiveStandard($paysheet),
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
        if (in_array($paysheet->status, ['locked', 'cancelled'], true)) {
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
        if (in_array($paysheet->status, ['locked', 'cancelled'], true)) {
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
        if (in_array($paysheet->status, ['locked', 'cancelled'], true)) {
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
        $adjCommission = $adjs->where('type', 'commission')->sum('amount');
        $adjBonus = $adjs->where('type', 'bonus')->sum('amount');
        $adjAllowance = $adjs->where('type', 'allowance')->sum('amount');
        $adjDeduction = $adjs->where('type', 'deduction')->sum('amount');
        $adjOt = $adjs->where('type', 'ot')->sum('amount');

        // Lấy giá trị auto từ details (tính bởi SalaryCalculationService)
        $details = $slip->details ?? [];
        $autoCommission = $details['commission'] ?? 0;
        $autoBonus = $details['bonus'] ?? 0;
        $autoAllowance = $details['allowances'] ?? 0;
        $autoDeduction = $details['deductions'] ?? 0;
        $autoLatePenalty = $details['late_penalty'] ?? 0;
        $autoOt = ($details['ot_pay'] ?? 0) + ($details['holiday_pay'] ?? 0);

        // HOTFIX 24.12B/24.12C — Honour `details.manual_overrides` so deleting
        // every row in the popup (sum=0) does NOT fall back to the auto value.
        // Set by bulkSaveAdjustments(), cleared by resetDefaultAdjustments().
        // 24.12C extends to commission and changes the deduction override path
        // to ignore auto late_penalty (override = user's exact total).
        $manualOverrides = is_array($details['manual_overrides'] ?? null)
            ? $details['manual_overrides']
            : [];
        $commissionOverride = (bool) ($manualOverrides['commission'] ?? false);
        $allowanceOverride = (bool) ($manualOverrides['allowance'] ?? false);
        $bonusOverride = (bool) ($manualOverrides['bonus'] ?? false);
        $deductionOverride = (bool) ($manualOverrides['deduction'] ?? false);

        $slip->commission = ($commissionOverride || $adjs->where('type', 'commission')->count() > 0)
            ? $adjCommission
            : $autoCommission;
        $slip->bonus = ($bonusOverride || $adjs->where('type', 'bonus')->count() > 0)
            ? $adjBonus
            : $autoBonus;
        $slip->allowances = ($allowanceOverride || $adjs->where('type', 'allowance')->count() > 0)
            ? $adjAllowance
            : $autoAllowance;
        // 24.12C — when deduction is overridden, late_penalty is NOT added.
        // Override mode means "the user's number is the final total"; if they
        // want late_penalty back they reset to default.
        if ($deductionOverride) {
            $slip->deductions = $adjDeduction;
        } elseif ($adjs->where('type', 'deduction')->count() > 0) {
            $slip->deductions = $adjDeduction + $autoLatePenalty;
        } else {
            $slip->deductions = $autoDeduction;
        }
        // OT stays additive — autoOt + any manual OT items.
        $slip->ot_pay = $autoOt + $adjOt;

        $slip->total_salary = max(
            0,
            $slip->base_salary + $slip->bonus + $slip->commission + $slip->allowances + $slip->ot_pay - $slip->deductions
        );
        $slip->remaining = max(0, $slip->total_salary - $slip->paid_amount);
        $slip->save();

        $slip->paysheet->recalculateTotals();
    }

    /**
     * HOTFIX 24.12B — Bulk-replace every adjustment of a given type in one shot.
     *
     * Why a separate endpoint: the popup is a list editor — the user adds rows,
     * removes rows, edits inline, then clicks "Xong". The legacy per-row
     * POST/PUT/DELETE endpoints can't express "remove the last allowance row
     * and keep allowance = 0" because once the count drops to 0 the old recalc
     * fell back to the auto value. This endpoint atomically (a) deletes the
     * existing rows of that type, (b) re-inserts the user's list, (c) sets the
     * manual_overrides[type] flag for allowance/bonus/deduction so the empty
     * list is honoured, then (d) recomputes the slip.
     */
    public function bulkSaveAdjustments(Request $request, $id, $slipId, string $type)
    {
        // HOTFIX 24.12C — commission is now editable like the other types.
        if (! in_array($type, ['commission', 'allowance', 'bonus', 'deduction', 'ot'], true)) {
            return response()->json(['success' => false, 'message' => 'Loại không hợp lệ.'], 422);
        }

        $paysheet = Paysheet::findOrFail($id);
        if (in_array($paysheet->status, ['locked', 'cancelled'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Bảng lương đã chốt hoặc hủy, không thể chỉnh '.$type.'.',
            ], 422);
        }

        $slip = Payslip::where('paysheet_id', $id)->findOrFail($slipId);

        $data = $request->validate([
            'items' => 'nullable|array',
            'items.*.id' => 'nullable|integer',
            'items.*.name' => 'required_with:items|string|max:255',
            'items.*.amount' => 'required_with:items|integer|min:0',
            'items.*.notes' => 'nullable|string|max:500',
            'items.*.meta' => 'nullable|array',
        ]);

        DB::transaction(function () use ($slip, $type, $data) {
            // 1. Wipe existing rows of this type.
            PayslipAdjustment::where('payslip_id', $slip->id)
                ->where('type', $type)
                ->delete();

            // 2. Re-insert. Skip rows with no name and no amount (UI may leave a
            //    blank row when the user is mid-edit).
            $rows = $data['items'] ?? [];
            foreach ($rows as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                $amount = (int) ($row['amount'] ?? 0);
                if ($name === '' && $amount <= 0) {
                    continue;
                }
                PayslipAdjustment::create([
                    'payslip_id' => $slip->id,
                    'type' => $type,
                    'name' => $name !== '' ? $name : ucfirst($type),
                    'amount' => $amount,
                    'notes' => $row['notes'] ?? null,
                    'meta' => $row['meta'] ?? null,
                ]);
            }

            // 3. Set manual_overrides flag for replace-style types so that
            //    sum=0 is honoured. OT stays additive — no flag.
            if ($type !== 'ot') {
                $details = is_array($slip->details) ? $slip->details : [];
                $overrides = is_array($details['manual_overrides'] ?? null) ? $details['manual_overrides'] : [];
                $overrides[$type] = true;
                $details['manual_overrides'] = $overrides;
                $slip->details = $details;
                $slip->save();
            }
        });

        // 4. Recompute outside the transaction so any service calls run on
        //    the committed state.
        $this->recalcSlipWithAdjustments($slip);

        $slip->refresh()->load('employee:id,code,name', 'adjustments');

        return response()->json([
            'success' => true,
            'slip' => $slip,
            'adjustments' => $slip->adjustments,
        ]);
    }

    /**
     * HOTFIX 24.12B — Restore the auto-computed value for a given type by
     * dropping its adjustments + clearing the manual_overrides flag.
     */
    public function resetDefaultAdjustments($id, $slipId, string $type)
    {
        // HOTFIX 24.12C — commission is now editable like the other types.
        if (! in_array($type, ['commission', 'allowance', 'bonus', 'deduction', 'ot'], true)) {
            return response()->json(['success' => false, 'message' => 'Loại không hợp lệ.'], 422);
        }

        $paysheet = Paysheet::findOrFail($id);
        if (in_array($paysheet->status, ['locked', 'cancelled'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Bảng lương đã chốt hoặc hủy.',
            ], 422);
        }

        $slip = Payslip::where('paysheet_id', $id)->findOrFail($slipId);

        DB::transaction(function () use ($slip, $type) {
            PayslipAdjustment::where('payslip_id', $slip->id)
                ->where('type', $type)
                ->delete();

            if ($type !== 'ot') {
                $details = is_array($slip->details) ? $slip->details : [];
                if (isset($details['manual_overrides']) && is_array($details['manual_overrides'])) {
                    unset($details['manual_overrides'][$type]);
                    if (empty($details['manual_overrides'])) {
                        unset($details['manual_overrides']);
                    }
                }
                $slip->details = $details;
                $slip->save();
            }
        });

        $this->recalcSlipWithAdjustments($slip);

        $slip->refresh()->load('employee:id,code,name', 'adjustments');

        return response()->json([
            'success' => true,
            'slip' => $slip,
            'adjustments' => $slip->adjustments,
        ]);
    }
}
