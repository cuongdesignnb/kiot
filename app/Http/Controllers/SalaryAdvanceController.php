<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\SalaryAdvance;
use App\Services\PayrollDateGuard;
use App\Services\PayrollAccessService;
use App\Services\SalaryAdvanceService;
use Illuminate\Http\Request;

class SalaryAdvanceController extends Controller
{
    public function index(Request $request, Employee $employee, PayrollAccessService $access)
    {
        $filters = $request->validate([
            'status' => 'nullable|string|max:50',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $access->assertEmployee($employee);

        $advances = SalaryAdvance::query()
            ->where('employee_id', $employee->id)
            ->with(['cashFlow', 'creator:id,name', 'applications.payslip:id,code'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from_date'] ?? null, fn ($q, $from) => $q->where('advance_date', '>=', $from))
            ->when($filters['to_date'] ?? null, fn ($q, $to) => $q->where('advance_date', '<=', $to.' 23:59:59'))
            ->orderByDesc('advance_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);

        return response()->json($advances);
    }

    public function store(Request $request, Employee $employee, SalaryAdvanceService $service, PayrollDateGuard $dateGuard)
    {
        $data = $request->validate([
            'amount' => 'required|integer|min:1',
            'advance_date' => 'required|date',
            'payment_method' => 'required|in:cash,bank,bank_transfer,ewallet',
            'branch_id' => 'required|integer|exists:branches,id',
            'note' => 'required|string|min:5|max:1000',
            'override_reason' => 'nullable|string|min:10|max:1000',
        ]);
        $this->assertBranchAccess($employee, (int) $data['branch_id']);
        $data['advance_date'] = $dateGuard->assertAllowed($data['advance_date'], $data['override_reason'] ?? null, 'salary_advance_create');
        $key = $request->header('Idempotency-Key') ?: 'advance:'.sha1(json_encode([
            $employee->id, $data['amount'], (string) $data['advance_date'], $data['note'],
        ]));

        return response()->json(['success' => true, 'data' => $service->create($employee, $data, $key)], 201);
    }

    public function cancel(Request $request, SalaryAdvance $advance, SalaryAdvanceService $service, PayrollDateGuard $dateGuard)
    {
        $data = $request->validate([
            'reason' => 'required|string|min:10|max:1000',
            'cancel_date' => 'required|date',
            'override_reason' => 'nullable|string|min:10|max:1000',
        ]);
        $this->assertBranchAccess($advance->employee, (int) $advance->branch_id);
        $eventAt = $dateGuard->assertAllowed($data['cancel_date'], $data['override_reason'] ?? null, 'salary_advance_cancel');

        return response()->json(['success' => true, 'data' => $service->cancel($advance, $data['reason'], $eventAt)]);
    }

    private function assertBranchAccess(Employee $employee, int $branchId): void
    {
        abort_if($employee->branch_id && $employee->branch_id !== $branchId, 422, 'Chi nhánh không khớp nhân viên.');
        $user = auth()->user();
        if (! $user || $user->isAdmin()) {
            return;
        }
        abort_unless(in_array($branchId, $user->getAccessibleBranchIds(), true), 403);
    }
}
