<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Paysheet;
use App\Models\Payslip;
use App\Services\PayrollAccessService;
use App\Services\PayrollDateGuard;
use App\Services\SalaryPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeSalaryPaymentController extends Controller
{
    public function preview(Employee $employee, PayrollAccessService $access)
    {
        $access->assertEmployee($employee);

        return response()->json([
            'employee' => $this->employeePayload($employee),
            'salary_balance' => (int) $employee->salary_balance_cache,
            'open_payslips' => $this->openPayslips($employee),
            'payment_methods' => ['cash', 'bank_transfer', 'other'],
        ]);
    }

    public function store(
        Request $request,
        Employee $employee,
        PayrollAccessService $access,
        PayrollDateGuard $dateGuard,
        SalaryPaymentService $service
    ) {
        $access->assertEmployee($employee);

        $payload = $request->validate([
            'payment_date' => 'required|date',
            'payment_method' => 'required|in:cash,bank,bank_transfer,ewallet,other',
            'note' => 'nullable|string|max:1000',
            'payments' => 'required|array|min:1',
            'payments.*.payslip_id' => 'required|integer|exists:payslips,id',
            'payments.*.amount' => 'required|integer|min:1',
            'override_reason' => 'nullable|string|min:10|max:1000',
        ]);

        $eventAt = $dateGuard->assertAllowed(
            $payload['payment_date'],
            $payload['override_reason'] ?? null,
            'employee_salary_payment_create'
        );
        $payload['payment_date'] = $eventAt;

        $slipIds = collect($payload['payments'])->pluck('payslip_id')->all();
        $slips = Payslip::query()
            ->with('paysheet:id,status,code')
            ->whereIn('id', $slipIds)
            ->where('employee_id', $employee->id)
            ->get()
            ->keyBy('id');

        if ($slips->count() !== count(array_unique($slipIds))) {
            throw ValidationException::withMessages([
                'payments' => 'Phieu luong khong thuoc nhan vien dang thanh toan.',
            ]);
        }

        foreach ($payload['payments'] as $index => $item) {
            $slip = $slips[(int) $item['payslip_id']];
            if ($slip->paysheet?->status !== 'locked') {
                throw ValidationException::withMessages([
                    "payments.{$index}.payslip_id" => 'Chi thanh toan phieu luong thuoc bang luong da chot.',
                ]);
            }
            if ((int) $item['amount'] > (int) $slip->remaining) {
                throw ValidationException::withMessages([
                    "payments.{$index}.amount" => 'So tien tra vuot so con can tra cua phieu luong.',
                ]);
            }
        }

        $key = $request->header('Idempotency-Key') ?: 'employee-payment:'.sha1(json_encode([
            $employee->id,
            (string) $eventAt,
            $payload['payments'],
        ]));

        $created = DB::transaction(function () use ($payload, $service, $slips, $key) {
            return collect($payload['payments'])
                ->groupBy(fn ($item) => $slips[(int) $item['payslip_id']]->paysheet_id)
                ->flatMap(function (Collection $items, int $paysheetId) use ($payload, $service, $key) {
                    $paysheet = Paysheet::query()->findOrFail($paysheetId);

                    return $service->pay(
                        $paysheet,
                        $items->values()->all(),
                        $payload,
                        "{$key}:paysheet:{$paysheetId}"
                    );
                })
                ->values();
        });

        return response()->json([
            'success' => true,
            'data' => $created,
            'employee' => $employee->fresh(),
        ]);
    }

    private function openPayslips(Employee $employee)
    {
        return Payslip::query()
            ->with('paysheet:id,code,name,period_start,period_end,status')
            ->where('employee_id', $employee->id)
            ->where('remaining', '>', 0)
            ->whereHas('paysheet', fn ($query) => $query->where('status', 'locked'))
            ->orderBy(Paysheet::select('period_start')->whereColumn('paysheets.id', 'payslips.paysheet_id'))
            ->orderBy('id')
            ->get()
            ->map(fn (Payslip $slip) => [
                'id' => $slip->id,
                'code' => $slip->code,
                'paysheet_id' => $slip->paysheet_id,
                'paysheet_code' => $slip->paysheet?->code,
                'period_start' => $slip->paysheet?->period_start?->format('Y-m-d'),
                'period_end' => $slip->paysheet?->period_end?->format('Y-m-d'),
                'total_salary' => (int) $slip->total_salary,
                'paid_amount' => (int) $slip->paid_amount,
                'applied_advance' => (int) $slip->applied_advance,
                'remaining' => (int) $slip->remaining,
                'payment_status' => $slip->payment_status,
            ])
            ->values();
    }

    private function employeePayload(Employee $employee): array
    {
        $employee->loadMissing(['department:id,name', 'jobTitle:id,name']);

        return [
            'id' => $employee->id,
            'code' => $employee->code,
            'name' => $employee->name,
            'department' => $employee->department?->name,
            'position' => $employee->jobTitle?->name,
        ];
    }
}
