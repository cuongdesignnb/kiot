<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Paysheet;
use App\Models\PaysheetPayment;
use App\Models\Payslip;
use App\Services\EmployeeSalaryLedgerService;
use App\Services\PayrollAccessService;
use App\Services\PayrollDateGuard;
use App\Services\SalaryAdvanceService;
use App\Services\SalaryPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeSalaryPaymentController extends Controller
{
    public function preview(Employee $employee, PayrollAccessService $access, EmployeeSalaryLedgerService $ledger)
    {
        $access->assertEmployee($employee);
        $payslips = $this->remainingPayslips($employee);
        $totalRemaining = (int) $payslips->sum('remaining');

        return response()->json([
            'employee' => $employee->only(['id', 'code', 'name', 'branch_id']),
            'mode' => $totalRemaining > 0 ? 'salary_payment' : 'salary_advance',
            'current_balance' => $ledger->currentBalance($employee->id),
            'total_remaining' => $totalRemaining,
            'payslips' => $payslips->map(fn (Payslip $slip) => $this->presentPayslip($slip))->values(),
        ]);
    }

    public function store(
        Request $request,
        Employee $employee,
        PayrollAccessService $access,
        PayrollDateGuard $dateGuard,
        SalaryPaymentService $payments,
        SalaryAdvanceService $advances,
        EmployeeSalaryLedgerService $ledger
    ) {
        $access->assertEmployee($employee);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['salary_payment', 'salary_advance'])],
            'payment_method' => 'required|in:cash,bank,bank_transfer,ewallet',
            'paid_at' => 'required_if:mode,salary_payment|date',
            'advanced_at' => 'required_if:mode,salary_advance|date',
            'note' => 'nullable|string|max:1000',
            'amount' => 'required_if:mode,salary_advance|integer|min:1',
            'items' => 'required_if:mode,salary_payment|array|min:1',
            'items.*.payslip_id' => 'required_if:mode,salary_payment|integer|exists:payslips,id',
            'items.*.amount' => 'required_if:mode,salary_payment|integer|min:1',
            'override_reason' => 'nullable|string|min:10|max:1000',
        ]);

        if ($data['mode'] === 'salary_advance') {
            $remaining = (int) $this->remainingPayslips($employee)->sum('remaining');
            if ($remaining > 0) {
                throw ValidationException::withMessages([
                    'mode' => 'Nhân viên còn phiếu lương cần thanh toán, không thể ghi nhận là tạm ứng.',
                ]);
            }

            $advancedAt = $dateGuard->assertAllowed($data['advanced_at'], $data['override_reason'] ?? null, 'salary_advance_create');
            $advance = $advances->create($employee, [
                'amount' => (int) $data['amount'],
                'advance_date' => $advancedAt,
                'payment_method' => $data['payment_method'],
                'branch_id' => $employee->branch_id,
                'note' => $data['note'] ?? 'Tạm ứng từ chi tiết nhân viên',
            ], $request->header('Idempotency-Key') ?: 'employee-advance:'.sha1(json_encode([
                $employee->id,
                (int) $data['amount'],
                (string) $advancedAt,
                $data['note'] ?? '',
            ])));

            return response()->json([
                'success' => true,
                'mode' => 'salary_advance',
                'data' => $advance,
                'current_balance' => $ledger->currentBalance($employee->id),
            ], 201);
        }

        $paidAt = $dateGuard->assertAllowed($data['paid_at'], $data['override_reason'] ?? null, 'salary_payment_create');
        $items = collect($data['items'])->map(fn ($item) => [
            'payslip_id' => (int) $item['payslip_id'],
            'amount' => (int) $item['amount'],
        ]);
        $key = $request->header('Idempotency-Key') ?: 'employee-payment:'.sha1(json_encode([
            $employee->id,
            (string) $paidAt,
            $items->all(),
        ]));
        $slips = Payslip::with('paysheet:id,code,name,status')
            ->where('employee_id', $employee->id)
            ->whereIn('id', $items->pluck('payslip_id'))
            ->get()
            ->keyBy('id');

        if ($slips->count() !== $items->pluck('payslip_id')->unique()->count()) {
            throw ValidationException::withMessages(['items' => 'Phiếu lương không thuộc nhân viên đang thanh toán.']);
        }

        foreach ($items->groupBy('payslip_id') as $payslipId => $payslipItems) {
            $slip = $slips->get($payslipId);
            if ($slip->paysheet?->status !== 'locked') {
                throw ValidationException::withMessages(['items' => 'Chỉ thanh toán phiếu lương thuộc bảng lương đã chốt.']);
            }
            $paymentKey = "{$key}:paysheet:{$slip->paysheet_id}:{$slip->id}";
            if (PaysheetPayment::where('idempotency_key', $paymentKey)->exists()) {
                continue;
            }
            if ((int) $payslipItems->sum('amount') > (int) $slip->remaining) {
                throw ValidationException::withMessages(['items' => 'Số tiền trả vượt số còn cần trả.']);
            }
        }

        $created = [];
        foreach ($items->groupBy(fn ($item) => $slips->get($item['payslip_id'])->paysheet_id) as $paysheetId => $sheetItems) {
            $sheet = Paysheet::findOrFail($paysheetId);
            $created = [
                ...$created,
                ...$payments->pay($sheet, $sheetItems->values()->all(), [
                    'payment_date' => $paidAt,
                    'payment_method' => $data['payment_method'],
                    'note' => $data['note'] ?? 'Thanh toán từ chi tiết nhân viên',
                ], "{$key}:paysheet:{$paysheetId}"),
            ];
        }

        return response()->json([
            'success' => true,
            'mode' => 'salary_payment',
            'data' => $created,
            'current_balance' => $ledger->currentBalance($employee->id),
        ]);
    }

    private function remainingPayslips(Employee $employee): Collection
    {
        return Payslip::query()
            ->with('paysheet:id,code,name,period_start,period_end,status')
            ->where('employee_id', $employee->id)
            ->where('remaining', '>', 0)
            ->whereHas('paysheet', fn ($query) => $query->where('status', 'locked'))
            ->orderBy(Paysheet::select('period_start')->whereColumn('paysheets.id', 'payslips.paysheet_id'))
            ->orderBy('id')
            ->get();
    }

    private function presentPayslip(Payslip $slip): array
    {
        return [
            'id' => $slip->id,
            'code' => $slip->code,
            'paysheet_id' => $slip->paysheet_id,
            'paysheet_code' => $slip->paysheet?->code,
            'paysheet_name' => $slip->paysheet?->name,
            'period_label' => trim(($slip->paysheet?->period_start?->format('d/m/Y') ?? '').' - '.($slip->paysheet?->period_end?->format('d/m/Y') ?? ''), ' -'),
            'total_salary' => (int) $slip->total_salary,
            'paid_amount' => (int) $slip->paid_amount,
            'remaining_amount' => (int) $slip->remaining,
        ];
    }
}
