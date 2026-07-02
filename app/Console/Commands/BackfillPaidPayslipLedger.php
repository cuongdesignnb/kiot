<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\PaysheetPayment;
use App\Models\Payslip;
use App\Services\PayrollLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BackfillPaidPayslipLedger extends Command
{
    protected $signature = 'payroll:backfill-paid-payslip-ledger
        {--dry-run : Preview missing salary_payment ledger entries without writing}
        {--apply : Create missing salary_payment ledger entries}
        {--employee-code= : Limit backfill to one employee code}
        {--paysheet-code= : Limit backfill to one paysheet code}';

    protected $description = 'Backfill missing salary_payment ledger entries for paid payslips';

    public function handle(PayrollLedgerService $ledger): int
    {
        $apply = (bool) $this->option('apply');
        if (! $apply && ! $this->option('dry-run')) {
            $this->warn('No write option provided. Running as --dry-run.');
        }
        if ($apply && $this->option('dry-run')) {
            $this->error('Use either --dry-run or --apply, not both.');

            return self::INVALID;
        }

        $rows = [];
        $created = 0;
        $skipped = 0;
        $missingSlips = $this->missingPayslips();

        foreach ($missingSlips as $slip) {
            $missingAmount = $this->missingAmount($slip);
            $result = $apply ? 'CREATED' : 'WOULD_CREATE';
            $createdForSlip = 0;
            $idempotencyKeys = [];

            if ($missingAmount <= 0) {
                $skipped++;
                $result = 'SKIPPED';
            } elseif ($apply) {
                DB::transaction(function () use ($slip, $ledger, &$created, &$createdForSlip, &$idempotencyKeys, &$result) {
                    $lockedSlip = Payslip::query()
                        ->with(['payments' => fn ($query) => $query->where('status', 'active')->orderBy('id')])
                        ->lockForUpdate()
                        ->findOrFail($slip->id);
                    $employee = Employee::query()->lockForUpdate()->findOrFail($lockedSlip->employee_id);
                    $remainingMissing = $this->missingAmount($lockedSlip);

                    foreach ($lockedSlip->payments as $payment) {
                        if ($remainingMissing <= 0) {
                            break;
                        }
                        if ($this->hasSalaryPaymentLedger($payment)) {
                            continue;
                        }

                        $amount = min((int) $payment->amount, $remainingMissing);
                        $idempotencyKey = "legacy:salary_payment:{$payment->id}";
                        $ledger->appendEntry([
                            'employee_id' => $employee->id,
                            'paysheet_id' => $payment->paysheet_id,
                            'payslip_id' => $payment->payslip_id,
                            'code' => $payment->code ?: "TTPL-LEGACY-{$payment->id}",
                            'type' => EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT,
                            'reference_type' => 'paysheet_payment',
                            'reference_id' => $payment->id,
                            'amount' => -$amount,
                            'event_at' => $payment->paid_at ?? $payment->created_at ?? now(),
                            'payment_method' => $payment->method,
                            'note' => 'Backfill thanh toan luong da ton tai truoc payroll ledger',
                            'idempotency_key' => $idempotencyKey,
                        ]);

                        $remainingMissing -= $amount;
                        $created++;
                        $createdForSlip++;
                        $idempotencyKeys[] = $idempotencyKey;
                    }

                    if ($remainingMissing <= 0) {
                        return;
                    }

                    if ($this->hasPayslipFallbackLedger($lockedSlip)) {
                        $result = $createdForSlip > 0 ? 'PARTIAL_CREATED' : 'SKIPPED';

                        return;
                    }

                    $idempotencyKey = "legacy:salary_payment:payslip:{$lockedSlip->id}";
                    $ledger->appendEntry([
                        'employee_id' => $employee->id,
                        'paysheet_id' => $lockedSlip->paysheet_id,
                        'payslip_id' => $lockedSlip->id,
                        'code' => "TTPL-LEGACY-PL-{$lockedSlip->id}",
                        'type' => EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT,
                        'reference_type' => 'payslip',
                        'reference_id' => $lockedSlip->id,
                        'amount' => -$remainingMissing,
                        'event_at' => $lockedSlip->updated_at ?? $lockedSlip->created_at ?? now(),
                        'payment_method' => null,
                        'note' => 'Backfill thanh toan luong legacy theo paid_amount cua phieu luong',
                        'idempotency_key' => $idempotencyKey,
                    ]);

                    $created++;
                    $createdForSlip++;
                    $idempotencyKeys[] = $idempotencyKey;
                });
            } else {
                $idempotencyKeys = $this->plannedIdempotencyKeys($slip, $missingAmount);
            }

            $rows[] = [
                $slip->paysheet?->code,
                $slip->code,
                $slip->employee?->code,
                $slip->employee?->name,
                $missingAmount,
                implode('|', $idempotencyKeys),
                $result,
            ];
        }

        $this->table(
            ['Paysheet', 'Payslip', 'Employee code', 'Employee', 'Missing amount', 'Idempotency key', 'Result'],
            $rows
        );
        $this->line(json_encode([
            'mode' => $apply ? 'APPLY' : 'DRY-RUN',
            'candidate_count' => count($rows),
            'created_count' => $created,
            'skipped_count' => $skipped,
            'missing_total' => collect($rows)->sum(fn ($row) => (int) $row[4]),
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function missingPayslips(): Collection
    {
        return Payslip::query()
            ->where('paid_amount', '>', 0)
            ->with(['paysheet', 'employee', 'payments' => fn ($query) => $query->where('status', 'active')->orderBy('id')])
            ->when($this->option('employee-code'), fn ($query, $code) => $query->whereHas('employee', fn ($employee) => $employee->where('code', $code)))
            ->when($this->option('paysheet-code'), fn ($query, $code) => $query->whereHas('paysheet', fn ($paysheet) => $paysheet->where('code', $code)))
            ->orderBy('paysheet_id')
            ->orderBy('id')
            ->get()
            ->filter(fn (Payslip $slip) => $this->missingAmount($slip) > 0)
            ->values();
    }

    private function hasSalaryPaymentLedger(PaysheetPayment $payment): bool
    {
        return EmployeeSalaryLedgerEntry::query()
            ->where('type', EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT)
            ->where('reference_type', 'paysheet_payment')
            ->where('reference_id', $payment->id)
            ->exists();
    }

    private function hasPayslipFallbackLedger(Payslip $slip): bool
    {
        return EmployeeSalaryLedgerEntry::query()
            ->where('type', EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT)
            ->where('reference_type', 'payslip')
            ->where('reference_id', $slip->id)
            ->exists();
    }

    private function salaryPaymentLedgerSum(Payslip $slip): int
    {
        return abs((int) EmployeeSalaryLedgerEntry::query()
            ->where('type', EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT)
            ->where('is_effective', true)
            ->where('payslip_id', $slip->id)
            ->sum('amount'));
    }

    private function missingAmount(Payslip $slip): int
    {
        return max((int) $slip->paid_amount - $this->salaryPaymentLedgerSum($slip), 0);
    }

    private function plannedIdempotencyKeys(Payslip $slip, int $missingAmount): array
    {
        $keys = [];
        foreach ($slip->payments as $payment) {
            if ($missingAmount <= 0) {
                break;
            }
            if ($this->hasSalaryPaymentLedger($payment)) {
                continue;
            }

            $keys[] = "legacy:salary_payment:{$payment->id}";
            $missingAmount -= (int) $payment->amount;
        }

        if ($missingAmount > 0 && ! $this->hasPayslipFallbackLedger($slip)) {
            $keys[] = "legacy:salary_payment:payslip:{$slip->id}";
        }

        return $keys;
    }
}
