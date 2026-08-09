<?php

namespace Tests\Feature\Payroll;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\Paysheet;
use App\Models\PaysheetPayment;
use App\Models\Payslip;
use App\Models\User;
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
}
