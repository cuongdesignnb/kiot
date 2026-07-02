<?php

namespace App\Services;

use App\Models\CashFlow;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\PaysheetPayment;
use App\Models\Payslip;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceApplication;
use Illuminate\Database\Eloquent\Builder;

class PayrollReconciliationService
{
    public function audit(array $filters = []): array
    {
        $section = $filters['section'] ?? 'all';
        $employeeQuery = app(PayrollAccessService::class)->scopeEmployees(
            Employee::query()->with('branch:id,name')->orderBy('id')
        );
        $employeeQuery
            ->when($filters['branch'] ?? null, fn (Builder $q, $branch) => $q->where('branch_id', $branch))
            ->when($filters['employee'] ?? null, function (Builder $q, $employee) {
                $q->where(fn (Builder $inner) => $inner
                    ->where('id', $employee)
                    ->orWhere('code', $employee));
            });

        $rows = $employeeQuery->get()->map(fn (Employee $employee) => $this->auditEmployee($employee, $section))->values();
        $documentIssues = collect();
        if (in_array($section, ['all', 'payments'], true)) {
            $documentIssues = $documentIssues->concat($this->paymentIssues($filters));
        }
        if (in_array($section, ['all', 'advances'], true)) {
            $documentIssues = $documentIssues->concat($this->advanceIssues($filters));
        }

        return [
            'data' => $rows,
            'document_issues' => $documentIssues->values(),
            'summary' => [
                'total_employees' => $rows->count(),
                'ok_count' => $rows->where('primary_status', 'OK')->count(),
                'issue_count' => $rows->where('primary_status', '!=', 'OK')->count() + $documentIssues->count(),
                'cache_mismatch_count' => $rows->filter(fn ($row) => in_array('CACHE_MISMATCH', $row['issues'], true))->count(),
                'missing_cache_count' => $rows->filter(fn ($row) => in_array('MISSING_CACHE', $row['issues'], true))->count(),
                'legacy_balance_count' => $rows->filter(fn ($row) => in_array('LEGACY_BALANCE_EXISTS', $row['issues'], true))->count(),
                'payment_cashflow_issue_count' => $documentIssues->where('group', 'payment')->count(),
                'advance_issue_count' => $documentIssues->where('group', 'advance')->count(),
                'negative_balance_count' => $rows->filter(fn ($row) => in_array('NEGATIVE_BALANCE', $row['issues'], true))->count(),
                'outstanding_advance_count' => $rows->filter(fn ($row) => in_array('OUTSTANDING_ADVANCE', $row['issues'], true))->count(),
            ],
        ];
    }

    private function auditEmployee(Employee $employee, string $section): array
    {
        $issues = [];
        $ledgerBalance = (int) EmployeeSalaryLedgerEntry::where('employee_id', $employee->id)
            ->where('is_effective', true)
            ->sum('amount');
        $cache = $employee->salary_balance_cache === null ? null : (int) $employee->salary_balance_cache;

        if (in_array($section, ['all', 'cache'], true)) {
            if ($employee->salary_balance_calculated_at === null || $cache === null) {
                $issues[] = 'MISSING_CACHE';
            } elseif ($cache !== $ledgerBalance) {
                $issues[] = 'CACHE_MISMATCH';
            }
            if ($ledgerBalance < 0) {
                $issues[] = 'NEGATIVE_BALANCE';
            }
        }
        if (in_array($section, ['all', 'legacy'], true) && (int) $employee->balance !== 0) {
            $issues[] = 'LEGACY_BALANCE_EXISTS';
        }
        if (in_array($section, ['all', 'advances'], true)
            && SalaryAdvance::where('employee_id', $employee->id)->where('remaining_amount', '>', 0)
                ->whereIn('status', ['active', 'partially_applied'])->exists()) {
            $issues[] = 'OUTSTANDING_ADVANCE';
        }

        $priority = ['CACHE_MISMATCH', 'MISSING_CACHE', 'LEGACY_BALANCE_EXISTS', 'NEGATIVE_BALANCE', 'OUTSTANDING_ADVANCE'];
        $primary = collect($priority)->first(fn ($status) => in_array($status, $issues, true)) ?? 'OK';

        return [
            'employee_id' => $employee->id,
            'employee_code' => $employee->code,
            'employee_name' => $employee->name,
            'branch' => $employee->branch?->name,
            'salary_balance_cache' => $cache,
            'ledger_balance' => $ledgerBalance,
            'difference' => $cache === null ? null : $cache - $ledgerBalance,
            'legacy_balance' => (int) $employee->balance,
            'issues' => $issues,
            'primary_status' => $primary,
            'severity' => $this->issueSeverity($primary),
            'suggested_action' => $this->suggestedAction($employee, $ledgerBalance),
        ];
    }

    private function paymentIssues(array $filters)
    {
        $query = PaysheetPayment::query()->with([
            'cashFlow' => fn ($cashFlow) => $cashFlow->withTrashed(),
            'employee:id,code,name,branch_id',
        ]);
        $this->scopeDocumentQuery($query, $filters);

        return $query->get()->flatMap(function (PaysheetPayment $payment) {
            $issues = [];
            $ledger = EmployeeSalaryLedgerEntry::where('reference_type', 'paysheet_payment')
                ->where('reference_id', $payment->id)
                ->where('type', EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT)
                ->first();
            if (! $ledger) {
                $issues[] = 'PAYMENT_WITHOUT_LEDGER';
            }
            if (! $payment->cashFlow) {
                $issues[] = 'PAYMENT_WITHOUT_CASHFLOW';
            } elseif ((int) $payment->cashFlow->amount !== (int) $payment->amount) {
                $issues[] = 'CASHFLOW_AMOUNT_MISMATCH';
            } elseif ($this->paymentRequiresActiveCashFlow($payment) && ! $this->isActiveCashFlow($payment->cashFlow)) {
                $issues[] = 'PAYMENT_WITHOUT_CASHFLOW';
            } elseif ($this->paymentIsCancelled($payment) && ! $this->isCancelledCashFlow($payment->cashFlow)) {
                $issues[] = 'PAYMENT_WITHOUT_CASHFLOW';
            }

            return collect($issues)->map(fn ($issue) => $this->documentIssue(
                'payment',
                $issue,
                $payment->employee,
                $payment->code,
                $payment->id
            ));
        });
    }

    private function advanceIssues(array $filters)
    {
        $query = SalaryAdvance::query()->with([
            'cashFlow' => fn ($cashFlow) => $cashFlow->withTrashed(),
            'employee:id,code,name,branch_id',
        ]);
        $this->scopeDocumentQuery($query, $filters);

        return $query->get()->flatMap(function (SalaryAdvance $advance) {
            $issues = [];
            $ledger = EmployeeSalaryLedgerEntry::where('reference_type', 'salary_advance')
                ->where('reference_id', $advance->id)
                ->where('type', EmployeeSalaryLedgerEntry::TYPE_SALARY_ADVANCE)
                ->first();
            if (! $ledger) {
                $issues[] = 'ADVANCE_WITHOUT_LEDGER';
            }
            if (! $advance->cashFlow) {
                $issues[] = 'ADVANCE_WITHOUT_CASHFLOW';
            } elseif ($this->advanceRequiresActiveCashFlow($advance) && ! $this->isActiveCashFlow($advance->cashFlow)) {
                $issues[] = 'ADVANCE_WITHOUT_CASHFLOW';
            } elseif ($this->advanceIsCancelled($advance) && ! $this->isCancelledCashFlow($advance->cashFlow)) {
                $issues[] = 'ADVANCE_WITHOUT_CASHFLOW';
            }
            $applicationTotal = (int) SalaryAdvanceApplication::where('salary_advance_id', $advance->id)
                ->where('status', 'active')->sum('amount');
            if ($applicationTotal !== (int) $advance->applied_amount
                || (int) $advance->amount !== (int) $advance->applied_amount + (int) $advance->remaining_amount) {
                $issues[] = 'ADVANCE_APPLICATION_MISMATCH';
            }

            return collect($issues)->map(fn ($issue) => $this->documentIssue(
                'advance',
                $issue,
                $advance->employee,
                $advance->code,
                $advance->id
            ));
        });
    }

    private function paymentRequiresActiveCashFlow(PaysheetPayment $payment): bool
    {
        return ! $this->paymentIsCancelled($payment);
    }

    private function paymentIsCancelled(PaysheetPayment $payment): bool
    {
        return $payment->status === 'cancelled';
    }

    private function advanceRequiresActiveCashFlow(SalaryAdvance $advance): bool
    {
        return ! $this->advanceIsCancelled($advance);
    }

    private function advanceIsCancelled(SalaryAdvance $advance): bool
    {
        return $advance->status === 'cancelled';
    }

    private function isActiveCashFlow(CashFlow $cashFlow): bool
    {
        return $cashFlow->deleted_at === null && $cashFlow->status !== 'cancelled';
    }

    private function isCancelledCashFlow(CashFlow $cashFlow): bool
    {
        return $cashFlow->deleted_at !== null || $cashFlow->status === 'cancelled';
    }

    private function scopeDocumentQuery(Builder $query, array $filters): void
    {
        $user = auth()->user();
        if ($user && ! $user->isAdmin()) {
            $query->whereIn('branch_id', $user->getAccessibleBranchIds());
        }
        $query->when($filters['branch'] ?? null, fn (Builder $q, $branch) => $q->where('branch_id', $branch))
            ->when($filters['employee'] ?? null, function (Builder $q, $employee) {
                $q->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery
                    ->where('id', $employee)
                    ->orWhere('code', $employee));
            });
    }

    private function documentIssue(string $group, string $issue, ?Employee $employee, ?string $code, int $id): array
    {
        return [
            'group' => $group,
            'issue' => $issue,
            'severity' => $this->issueSeverity($issue),
            'employee_id' => $employee?->id,
            'employee_code' => $employee?->code,
            'employee_name' => $employee?->name,
            'document_code' => $code,
            'document_id' => $id,
        ];
    }

    private function suggestedAction(Employee $employee, int $ledgerBalance): ?string
    {
        if ((int) $employee->balance === 0) {
            return null;
        }
        if ($ledgerBalance === 0
            && ! PaysheetPayment::where('employee_id', $employee->id)->exists()
            && ! SalaryAdvance::where('employee_id', $employee->id)->exists()) {
            return 'OPENING_BALANCE';
        }

        return 'NEED_MANUAL_REVIEW';
    }

    private function issueSeverity(string $issue): string
    {
        return match ($issue) {
            'PAYMENT_WITHOUT_CASHFLOW',
            'PAYMENT_WITHOUT_LEDGER',
            'CASHFLOW_AMOUNT_MISMATCH',
            'ADVANCE_WITHOUT_LEDGER',
            'ADVANCE_WITHOUT_CASHFLOW',
            'ADVANCE_APPLICATION_MISMATCH' => 'CRITICAL',
            'CACHE_MISMATCH',
            'MISSING_CACHE',
            'PAYSLIP_REMAINING_MISMATCH' => 'HIGH',
            'LEGACY_BALANCE_EXISTS',
            'OUTSTANDING_ADVANCE',
            'NEGATIVE_BALANCE' => 'MEDIUM',
            'OK' => 'OK',
            default => 'LOW',
        };
    }
}
