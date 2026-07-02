<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\PaysheetPayment;
use App\Models\SalaryAdvance;
use App\Services\CsvService;
use App\Services\EmployeeSalaryLedgerService;
use App\Services\PayrollAccessService;
use App\Services\PayrollDateGuard;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeSalaryLedgerController extends Controller
{
    public function index(Request $request, Employee $employee, EmployeeSalaryLedgerService $ledger, PayrollAccessService $access)
    {
        $data = $this->validateFilters($request);
        $access->assertEmployee($employee);
        if (! empty($data['branch_id'])) {
            $access->assertBranch((int) $data['branch_id']);
        }

        return response()->json($ledger->timeline($employee, $data));
    }

    public function adjust(Request $request, Employee $employee, EmployeeSalaryLedgerService $ledger, PayrollDateGuard $dateGuard)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['adjustment_increase', 'adjustment_decrease'])],
            'amount' => 'required|integer|min:1',
            'event_at' => 'required|date',
            'reason' => 'required|string|min:10|max:1000',
            'note' => 'nullable|string|max:1000',
            'override_reason' => 'nullable|string|min:10|max:1000',
        ]);
        app(PayrollAccessService::class)->assertEmployee($employee);
        $eventAt = $dateGuard->assertAllowed($data['event_at'], $data['override_reason'] ?? null, 'salary_adjustment');
        $amount = $data['type'] === 'adjustment_decrease' ? -$data['amount'] : $data['amount'];
        $entry = $ledger->append($employee, [
            'code' => 'DCL'.now()->format('ymdHis').$employee->id,
            'type' => $data['type'],
            'reference_type' => 'employee',
            'reference_id' => $employee->id,
            'amount' => $amount,
            'event_at' => $eventAt,
            'reason' => $data['reason'],
            'note' => $data['note'] ?? $data['reason'],
            'idempotency_key' => $request->header('Idempotency-Key'),
        ]);
        ActivityLog::log('salary_balance_adjust', "Điều chỉnh số dư {$employee->code}", $entry, $data);

        return response()->json(['success' => true, 'data' => $entry], 201);
    }

    public function rebuild(Employee $employee, EmployeeSalaryLedgerService $ledger)
    {
        app(PayrollAccessService::class)->assertEmployee($employee);

        return response()->json(['success' => true, 'balance' => $ledger->rebuild($employee)]);
    }

    public function rebuildAll(EmployeeSalaryLedgerService $ledger)
    {
        $count = 0;
        Employee::query()->orderBy('id')->each(function (Employee $employee) use ($ledger, &$count) {
            $ledger->rebuild($employee, false);
            $count++;
        });
        ActivityLog::log('payroll_balance_rebuild', "Tính lại số dư lương {$count} nhân viên");

        return response()->json(['success' => true, 'employees_rebuilt' => $count]);
    }

    public function show(EmployeeSalaryLedgerEntry $entry, PayrollAccessService $access)
    {
        $entry->load([
            'employee:id,code,name,branch_id',
            'employee.branch:id,name',
            'creator:id,name',
            'canceller:id,name',
            'paysheet:id,code,name,status',
            'payslip:id,code,total_salary,paid_amount,applied_advance,remaining,payment_status',
            'originalEntry.creator:id,name',
            'reversalEntries.creator:id,name',
        ]);
        $access->assertEmployee($entry->employee);

        $payment = null;
        $advance = null;
        if ($entry->reference_type === 'paysheet_payment') {
            $payment = PaysheetPayment::with(['cashFlow', 'employee:id,code,name', 'payslip:id,code'])
                ->find($entry->reference_id);
        } elseif ($entry->reference_type === 'salary_advance') {
            $advance = SalaryAdvance::with(['cashFlow', 'applications.payslip:id,code', 'creator:id,name'])
                ->find($entry->reference_id);
        }

        $logs = ActivityLog::query()
            ->where(function ($q) use ($entry, $payment, $advance) {
                $q->where(fn ($entryLog) => $entryLog
                    ->where('subject_type', EmployeeSalaryLedgerEntry::class)
                    ->where('subject_id', $entry->id));
                if ($payment) {
                    $q->orWhere(fn ($paymentLog) => $paymentLog
                        ->where('subject_type', PaysheetPayment::class)
                        ->where('subject_id', $payment->id));
                }
                if ($advance) {
                    $q->orWhere(fn ($advanceLog) => $advanceLog
                        ->where('subject_type', SalaryAdvance::class)
                        ->where('subject_id', $advance->id));
                }
            })
            ->with('user:id,name')
            ->latest()
            ->get();

        return response()->json([
            'ledger_entry' => $entry,
            'employee' => $entry->employee,
            'branch' => $entry->employee?->branch,
            'reference' => $entry->payslip ?: $entry->paysheet,
            'original_entry' => $entry->originalEntry,
            'reversal_entries' => $entry->reversalEntries,
            'cash_flow' => $payment?->cashFlow ?: $advance?->cashFlow,
            'payment' => $payment,
            'advance' => $advance,
            'applications' => $advance?->applications ?? [],
            'activity_logs' => $logs,
        ]);
    }

    public function export(
        Request $request,
        Employee $employee,
        EmployeeSalaryLedgerService $ledger,
        PayrollAccessService $access
    ) {
        $filters = $this->validateFilters($request);
        $access->assertEmployee($employee);
        if (! empty($filters['branch_id'])) {
            $access->assertBranch((int) $filters['branch_id']);
        }

        $rows = $ledger->filteredEntries($employee, $filters)->map(fn ($entry) => [
            $entry->code,
            $entry->event_at?->format('Y-m-d H:i:s'),
            $entry->created_at?->format('Y-m-d H:i:s'),
            $entry->employee?->name,
            $entry->branch_id,
            $entry->type,
            (int) $entry->amount,
            (int) $entry->balance_after,
            $entry->type === 'cancel_reverse' ? 'Dòng đảo' : ($entry->status === 'reversed' ? 'Đã đảo' : 'Hợp lệ'),
            $entry->note,
            $entry->reason ?: $entry->cancel_reason,
            $entry->creator?->name,
            trim(($entry->reference_type ?? '').':'.($entry->reference_id ?? ''), ':'),
        ]);
        $from = $filters['from_date'] ?? 'all';
        $to = $filters['to_date'] ?? 'all';

        return CsvService::export([
            'Mã phiếu', 'Ngày nghiệp vụ', 'Ngày tạo', 'Nhân viên', 'Chi nhánh',
            'Loại phát sinh', 'Giá trị', 'Số dư sau phát sinh', 'Trạng thái',
            'Ghi chú', 'Lý do', 'Người tạo', 'Chứng từ tham chiếu',
        ], $rows, "salary-ledger-{$employee->code}-{$from}-{$to}.csv");
    }

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'type' => 'nullable|string',
            'status' => 'nullable|in:valid,reversed,cancelled',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'keyword' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
    }
}
