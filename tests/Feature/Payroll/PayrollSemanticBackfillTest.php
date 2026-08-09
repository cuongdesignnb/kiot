<?php

namespace Tests\Feature\Payroll;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\Paysheet;
use App\Models\PaysheetPayment;
use App\Models\Payslip;
use App\Models\User;
use App\Services\PayrollDocumentParityService;
use App\Services\PayrollReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PayrollSemanticBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role_id' => null]));
        $this->branch = Branch::create(['name' => 'Semantic backfill branch']);
        $this->employee = Employee::create([
            'code' => 'NV-SEMANTIC',
            'name' => 'Nhân viên semantic ledger',
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
    }

    public function test_dry_run_and_apply_repair_only_missing_accruals_and_are_idempotent(): void
    {
        $missingAmounts = [12_200_538, 13_132_580, 8_738_077, 8_981_800];
        $missing = [];
        for ($i = 1; $i <= 15; $i++) {
            $amount = $missingAmounts[$i - 1] ?? 1_000_000;
            [$sheet, $slip] = $this->lockedPaysheet($i, $amount);
            if ($i <= 4) {
                $missing[] = [$sheet, $slip];

                continue;
            }
            $this->accrual($sheet, $slip, $amount, "canonical-accrual-{$slip->id}");
        }

        $before = [
            'ledger' => EmployeeSalaryLedgerEntry::count(),
            'payments' => PaysheetPayment::count(),
            'employee' => $this->employee->fresh()->only(['salary_balance_cache', 'salary_balance_calculated_at']),
            'slips' => Payslip::query()->orderBy('id')->get()->map(fn (Payslip $slip) => $slip->only([
                'id', 'paid_amount', 'remaining', 'payment_status', 'updated_at',
            ]))->all(),
        ];

        $dryRun = Artisan::call('payroll:migrate-salary-ledger', ['--backfill-documents' => true]);
        $this->assertSame(0, $dryRun);
        $this->assertStringContainsString('Semantic backfill candidates: accruals=4/43052995', Artisan::output());
        $this->assertSame($before['ledger'], EmployeeSalaryLedgerEntry::count());
        $this->assertSame($before['payments'], PaysheetPayment::count());
        $this->assertSame($before['employee'], $this->employee->fresh()->only(['salary_balance_cache', 'salary_balance_calculated_at']));

        $apply = Artisan::call('payroll:migrate-salary-ledger', [
            '--backfill-documents' => true,
            '--apply' => true,
        ]);
        $this->assertSame(0, $apply);
        $this->assertSame($before['ledger'] + 4, EmployeeSalaryLedgerEntry::count());
        $this->assertSame(0, PaysheetPayment::count());
        foreach ($missing as [$sheet, $slip]) {
            $this->assertDatabaseHas('employee_salary_ledger_entries', [
                'employee_id' => $this->employee->id,
                'paysheet_id' => $sheet->id,
                'payslip_id' => $slip->id,
                'type' => EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL,
                'reference_type' => 'payslip',
                'reference_id' => $slip->id,
                'amount' => (int) $slip->total_salary,
                'idempotency_key' => "payroll_accrual:{$slip->id}",
            ]);
            $this->assertSame(
                $sheet->locked_at?->toDateTimeString(),
                EmployeeSalaryLedgerEntry::where('payslip_id', $slip->id)->firstOrFail()->event_at?->toDateTimeString()
            );
        }
        $this->assertEquals($before['slips'], Payslip::query()->orderBy('id')->get()->map(fn (Payslip $slip) => $slip->only([
            'id', 'paid_amount', 'remaining', 'payment_status', 'updated_at',
        ]))->all());

        $second = Artisan::call('payroll:migrate-salary-ledger', [
            '--backfill-documents' => true,
            '--apply' => true,
        ]);
        $this->assertSame(0, $second);
        $this->assertSame($before['ledger'] + 4, EmployeeSalaryLedgerEntry::count());
    }

    public function test_missing_salary_payment_ledger_is_backfilled_from_payment_identity_without_creating_payment_or_cashflow(): void
    {
        [$sheet, $slip] = $this->lockedPaysheet(1, 1_000_000);
        $payment = PaysheetPayment::create([
            'code' => 'TTPL-SEMANTIC-1',
            'paysheet_id' => $sheet->id,
            'payslip_id' => $slip->id,
            'employee_id' => $this->employee->id,
            'amount' => 250_000,
            'status' => 'active',
            'method' => 'cash',
            'paid_at' => '2026-06-30 08:00:00',
            'idempotency_key' => 'semantic-payment-document',
        ]);
        $paymentCount = PaysheetPayment::count();

        $dryRun = Artisan::call('payroll:migrate-salary-ledger', ['--backfill-documents' => true]);
        $this->assertSame(0, $dryRun);
        $this->assertStringContainsString('payments=1/250000', Artisan::output());
        $this->assertSame(0, EmployeeSalaryLedgerEntry::where('type', EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT)->count());

        Artisan::call('payroll:migrate-salary-ledger', [
            '--backfill-documents' => true,
            '--apply' => true,
        ]);

        $this->assertSame($paymentCount, PaysheetPayment::count());
        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'reference_type' => 'paysheet_payment',
            'reference_id' => $payment->id,
            'employee_id' => $this->employee->id,
            'paysheet_id' => $sheet->id,
            'payslip_id' => $slip->id,
            'type' => EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT,
            'amount' => -250_000,
            'idempotency_key' => "salary_payment:{$payment->id}",
        ]);
    }

    public function test_apply_fails_closed_on_semantic_duplicate_or_amount_mismatch(): void
    {
        [$sheet, $slip] = $this->lockedPaysheet(1, 1_000_000);
        $this->accrual($sheet, $slip, 600_000, 'ambiguous-one');
        $this->accrual($sheet, $slip, 400_000, 'ambiguous-two');
        $before = EmployeeSalaryLedgerEntry::count();

        $exitCode = Artisan::call('payroll:migrate-salary-ledger', [
            '--backfill-documents' => true,
            '--apply' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame($before, EmployeeSalaryLedgerEntry::count());
        $this->assertStringContainsString('mismatches or duplicate', Artisan::output());
    }

    public function test_zero_salary_with_nonzero_effective_accrual_is_not_hidden(): void
    {
        [$sheet, $slip] = $this->lockedPaysheet(1, 0);
        $this->accrual($sheet, $slip, 100_000, 'zero-salary-nonzero-accrual');

        $classification = app(PayrollDocumentParityService::class)->classifyAccrual($slip->fresh());

        $this->assertSame('AMOUNT_MISMATCH', $classification['classification']);
        $this->assertSame(1, $classification['entry_count']);
        $this->assertSame(100_000, $classification['actual_amount']);
    }

    public function test_cancelled_payment_missing_original_is_audit_only_and_blocks_apply(): void
    {
        [$sheet, $slip] = $this->lockedPaysheet(1, 1_000_000);
        $payment = $this->payment($sheet, $slip, 500_000, 'cancelled');
        $before = EmployeeSalaryLedgerEntry::count();

        $report = app(PayrollReconciliationService::class)->audit(['employee' => $this->employee->id]);
        $this->assertTrue(collect($report['document_issues'])->contains('issue', 'CANCELLED_PAYMENT_MISSING_ORIGINAL_LEDGER'));

        $exitCode = Artisan::call('payroll:migrate-salary-ledger', [
            '--backfill-documents' => true,
            '--apply' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame($before, EmployeeSalaryLedgerEntry::count());
        $this->assertDatabaseHas('paysheet_payments', ['id' => $payment->id, 'status' => 'cancelled']);
    }

    public function test_cancelled_payment_with_missing_reversal_is_audit_only_and_blocks_apply(): void
    {
        [$sheet, $slip] = $this->lockedPaysheet(1, 1_000_000);
        $payment = $this->payment($sheet, $slip, 500_000, 'cancelled');
        $this->accrual($sheet, $slip, 1_000_000, 'cancelled-accrual');
        $original = EmployeeSalaryLedgerEntry::create([
            'employee_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'paysheet_id' => $sheet->id,
            'payslip_id' => $slip->id,
            'code' => $payment->code,
            'type' => EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT,
            'reference_type' => 'paysheet_payment',
            'reference_id' => $payment->id,
            'amount' => -500_000,
            'is_effective' => true,
            'status' => 'reversed',
            'event_at' => $payment->paid_at,
            'idempotency_key' => 'cancelled-original-only',
        ]);
        $before = EmployeeSalaryLedgerEntry::count();

        $issues = collect(app(PayrollReconciliationService::class)
            ->audit(['employee' => $this->employee->id])['document_issues'])->pluck('issue');
        $this->assertTrue($issues->contains('CANCELLED_PAYMENT_MISSING_REVERSAL'));

        $exitCode = Artisan::call('payroll:migrate-salary-ledger', [
            '--backfill-documents' => true,
            '--apply' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame($before, EmployeeSalaryLedgerEntry::count());
        $this->assertDatabaseHas('employee_salary_ledger_entries', ['id' => $original->id, 'status' => 'reversed']);
    }

    public function test_cancelled_payment_exact_lifecycle_is_not_repaired(): void
    {
        [$sheet, $slip] = $this->lockedPaysheet(1, 1_000_000);
        $payment = $this->payment($sheet, $slip, 500_000, 'cancelled');
        $this->accrual($sheet, $slip, 1_000_000, 'cancelled-exact-accrual');
        $original = EmployeeSalaryLedgerEntry::create([
            'employee_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'paysheet_id' => $sheet->id,
            'payslip_id' => $slip->id,
            'code' => $payment->code,
            'type' => EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT,
            'reference_type' => 'paysheet_payment',
            'reference_id' => $payment->id,
            'amount' => -500_000,
            'is_effective' => true,
            'status' => 'reversed',
            'event_at' => $payment->paid_at,
            'idempotency_key' => 'cancelled-exact-original',
        ]);
        EmployeeSalaryLedgerEntry::create([
            'employee_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'paysheet_id' => $sheet->id,
            'payslip_id' => $slip->id,
            'original_entry_id' => $original->id,
            'code' => 'H'.$payment->code,
            'type' => EmployeeSalaryLedgerEntry::TYPE_CANCEL_REVERSE,
            'reference_type' => 'paysheet_payment',
            'reference_id' => $payment->id,
            'amount' => 500_000,
            'is_effective' => true,
            'status' => 'valid',
            'event_at' => $payment->paid_at,
            'idempotency_key' => 'cancelled-exact-reversal',
        ]);
        $before = EmployeeSalaryLedgerEntry::count();

        $issues = collect(app(PayrollReconciliationService::class)
            ->audit(['employee' => $this->employee->id])['document_issues'])->pluck('issue');
        $this->assertFalse($issues->contains('CANCELLED_PAYMENT_MISSING_REVERSAL'));
        $this->assertFalse($issues->contains('CANCELLED_PAYMENT_MISSING_ORIGINAL_LEDGER'));

        $exitCode = Artisan::call('payroll:migrate-salary-ledger', [
            '--backfill-documents' => true,
            '--apply' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($before, EmployeeSalaryLedgerEntry::count());
    }

    public function test_cancelled_payment_wrong_reversal_amount_blocks_apply(): void
    {
        [$sheet, $slip] = $this->lockedPaysheet(1, 1_000_000);
        $payment = $this->payment($sheet, $slip, 500_000, 'cancelled');
        $this->accrual($sheet, $slip, 1_000_000, 'cancelled-wrong-accrual');
        $original = EmployeeSalaryLedgerEntry::create([
            'employee_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'paysheet_id' => $sheet->id,
            'payslip_id' => $slip->id,
            'code' => $payment->code,
            'type' => EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT,
            'reference_type' => 'paysheet_payment',
            'reference_id' => $payment->id,
            'amount' => -500_000,
            'is_effective' => true,
            'status' => 'reversed',
            'event_at' => $payment->paid_at,
            'idempotency_key' => 'cancelled-wrong-original',
        ]);
        EmployeeSalaryLedgerEntry::create([
            'employee_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'paysheet_id' => $sheet->id,
            'payslip_id' => $slip->id,
            'original_entry_id' => $original->id,
            'code' => 'H'.$payment->code,
            'type' => EmployeeSalaryLedgerEntry::TYPE_CANCEL_REVERSE,
            'reference_type' => 'paysheet_payment',
            'reference_id' => $payment->id,
            'amount' => 400_000,
            'is_effective' => true,
            'status' => 'valid',
            'event_at' => $payment->paid_at,
            'idempotency_key' => 'cancelled-wrong-reversal',
        ]);
        $before = EmployeeSalaryLedgerEntry::count();

        $issues = collect(app(PayrollReconciliationService::class)
            ->audit(['employee' => $this->employee->id])['document_issues'])->pluck('issue');
        $this->assertTrue($issues->contains('CANCELLED_PAYMENT_REVERSAL_AMOUNT_MISMATCH'));

        $exitCode = Artisan::call('payroll:migrate-salary-ledger', [
            '--backfill-documents' => true,
            '--apply' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame($before, EmployeeSalaryLedgerEntry::count());
    }

    private function lockedPaysheet(int $number, int $amount): array
    {
        $start = now()->startOfMonth()->addMonths($number - 1);
        $sheet = Paysheet::create([
            'code' => 'BL-SEM-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            'name' => 'Semantic '.$number,
            'pay_period' => 'monthly',
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'branch_id' => $this->branch->id,
            'status' => 'locked',
            'payment_status' => 'unpaid',
            'locked_at' => $start->copy()->addDay()->setTime(9, 0),
            'locked_by' => 'QA',
            'total_salary' => $amount,
            'total_paid' => 0,
            'total_remaining' => $amount,
            'employee_count' => 1,
        ]);
        $slip = Payslip::create([
            'code' => 'PL-SEM-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            'paysheet_id' => $sheet->id,
            'employee_id' => $this->employee->id,
            'total_salary' => $amount,
            'paid_amount' => 0,
            'applied_advance' => 0,
            'remaining' => $amount,
            'payment_status' => 'unpaid',
        ]);

        return [$sheet->fresh(), $slip->fresh()];
    }

    private function accrual(Paysheet $sheet, Payslip $slip, int $amount, string $key): void
    {
        EmployeeSalaryLedgerEntry::create([
            'employee_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'paysheet_id' => $sheet->id,
            'payslip_id' => $slip->id,
            'code' => $slip->code,
            'type' => EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL,
            'reference_type' => 'payslip',
            'reference_id' => $slip->id,
            'amount' => $amount,
            'balance_after' => 0,
            'is_effective' => true,
            'status' => 'valid',
            'event_at' => $sheet->locked_at,
            'idempotency_key' => $key,
        ]);
    }

    private function payment(Paysheet $sheet, Payslip $slip, int $amount, string $status): PaysheetPayment
    {
        return PaysheetPayment::create([
            'code' => 'TTPL-SEMANTIC-'.$slip->id,
            'paysheet_id' => $sheet->id,
            'payslip_id' => $slip->id,
            'employee_id' => $this->employee->id,
            'amount' => $amount,
            'status' => $status,
            'method' => 'cash',
            'paid_at' => '2026-06-30 08:00:00',
            'idempotency_key' => 'semantic-payment-'.$slip->id,
        ]);
    }
}
