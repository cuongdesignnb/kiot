<?php

namespace Tests\Feature\Payroll;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\Paysheet;
use App\Models\PaysheetPayment;
use App\Models\Payslip;
use App\Models\User;
use App\Services\EmployeeSalaryLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaidPaysheetShouldNotRemainEmployeeDebtTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Employee $employee;

    private Paysheet $paysheet;

    private Payslip $payslip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role_id' => null]));
        $this->branch = Branch::create(['name' => 'P0 Paid Salary Branch']);
        $this->employee = Employee::create([
            'code' => 'NV-P0-PAID-001',
            'name' => 'P0 Paid Salary Employee',
            'branch_id' => $this->branch->id,
            'salary_balance_cache' => 0,
            'is_active' => true,
        ]);
        $this->paysheet = Paysheet::create([
            'code' => 'BL-P0-PAID-001',
            'name' => 'P0 paid salary debt',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'branch_id' => $this->branch->id,
            'status' => 'calculated',
            'payment_status' => 'unpaid',
            'total_salary' => 1_000_000,
            'total_paid' => 0,
            'total_remaining' => 1_000_000,
            'employee_count' => 1,
        ]);
        $this->payslip = Payslip::create([
            'code' => 'PL-P0-PAID-001',
            'paysheet_id' => $this->paysheet->id,
            'employee_id' => $this->employee->id,
            'total_salary' => 1_000_000,
            'paid_amount' => 0,
            'remaining' => 1_000_000,
            'payment_status' => 'unpaid',
        ]);
    }

    public function test_fully_paid_payslip_does_not_remain_in_employee_salary_debt(): void
    {
        $this->putJson("/api/paysheets/{$this->paysheet->id}/lock")->assertOk();
        $this->assertSame(1_000_000, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee->id));
        $this->assertSame(1_000_000, (int) $this->employee->fresh()->salary_balance_cache);

        $this->postJson("/api/paysheets/{$this->paysheet->id}/pay", [
            'payment_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'payments' => [['payslip_id' => $this->payslip->id, 'amount' => 1_000_000]],
        ], ['Idempotency-Key' => 'p0-full-payment'])->assertOk();

        $this->payslip->refresh();
        $this->assertSame(1_000_000, (int) $this->payslip->paid_amount);
        $this->assertSame(0, (int) $this->payslip->remaining);
        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'payslip_id' => $this->payslip->id,
            'type' => 'salary_payment',
            'amount' => -1_000_000,
            'is_effective' => true,
        ]);
        $this->assertSame(0, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee->id));
        $this->assertSame(0, (int) $this->employee->fresh()->salary_balance_cache);

        $this->get('/employees?search=NV-P0-PAID-001')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Index')
                ->where('employees.data.0.salary_balance_cache', 0)
                ->where('employees.data.0.salary_balance', 0)
                ->where('employees.data.0.salary_debt_amount', 0));
    }

    public function test_partially_paid_payslip_only_remaining_amount_counts_as_employee_salary_debt(): void
    {
        $this->putJson("/api/paysheets/{$this->paysheet->id}/lock")->assertOk();
        $this->postJson("/api/paysheets/{$this->paysheet->id}/pay", [
            'payment_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'payments' => [['payslip_id' => $this->payslip->id, 'amount' => 400_000]],
        ], ['Idempotency-Key' => 'p0-partial-payment'])->assertOk();

        $this->payslip->refresh();
        $this->assertSame(400_000, (int) $this->payslip->paid_amount);
        $this->assertSame(600_000, (int) $this->payslip->remaining);
        $this->assertSame(600_000, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee->id));
        $this->assertSame(600_000, (int) $this->employee->fresh()->salary_balance_cache);
    }

    public function test_existing_paid_payslip_without_salary_payment_ledger_is_detected_by_audit(): void
    {
        $this->createLegacyPaidPayslipWithoutPaymentLedger();

        $exitCode = Artisan::call('payroll:audit-paid-payslip-ledger', [
            '--paysheet-code' => $this->paysheet->code,
            '--format' => 'json',
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $payload['summary']['issue_count']);
        $this->assertSame(1_000_000, $payload['summary']['missing_total']);
        $this->assertSame(1_000_000, $payload['data'][0]['missing_salary_payment_ledger']);
    }

    public function test_backfill_paid_payslip_ledger_creates_missing_salary_payment_once(): void
    {
        $this->createLegacyPaidPayslipWithoutPaymentLedger();

        Artisan::call('payroll:backfill-paid-payslip-ledger', [
            '--dry-run' => true,
            '--paysheet-code' => $this->paysheet->code,
        ]);
        $this->assertSame(0, EmployeeSalaryLedgerEntry::where('type', 'salary_payment')->count());
        $this->assertSame(1_000_000, (int) $this->employee->fresh()->salary_balance_cache);

        Artisan::call('payroll:backfill-paid-payslip-ledger', [
            '--apply' => true,
            '--paysheet-code' => $this->paysheet->code,
        ]);
        Artisan::call('payroll:backfill-paid-payslip-ledger', [
            '--apply' => true,
            '--paysheet-code' => $this->paysheet->code,
        ]);

        $this->assertSame(1, EmployeeSalaryLedgerEntry::where('type', 'salary_payment')->count());
        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'payslip_id' => $this->payslip->id,
            'type' => 'salary_payment',
            'reference_type' => 'paysheet_payment',
            'amount' => -1_000_000,
            'is_effective' => true,
        ]);
        $this->assertSame(0, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee->id));
        $this->assertSame(0, (int) $this->employee->fresh()->salary_balance_cache);
    }

    public function test_backfill_can_use_payslip_when_paid_amount_has_no_payment_document(): void
    {
        $this->putJson("/api/paysheets/{$this->paysheet->id}/lock")->assertOk();
        $this->payslip->update([
            'paid_amount' => 1_000_000,
            'remaining' => 0,
            'payment_status' => 'paid',
        ]);
        $this->paysheet->recalculateTotals();

        Artisan::call('payroll:audit-paid-payslip-ledger', [
            '--paysheet-code' => $this->paysheet->code,
            '--format' => 'json',
        ]);
        $auditPayload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $auditPayload['summary']['issue_count']);
        $this->assertSame(1_000_000, $auditPayload['data'][0]['missing_salary_payment_ledger']);
        $this->assertSame('no_payment', $auditPayload['data'][0]['cash_flow_status']);

        Artisan::call('payroll:backfill-paid-payslip-ledger', [
            '--apply' => true,
            '--paysheet-code' => $this->paysheet->code,
        ]);
        Artisan::call('payroll:backfill-paid-payslip-ledger', [
            '--apply' => true,
            '--paysheet-code' => $this->paysheet->code,
        ]);

        $this->assertSame(1, EmployeeSalaryLedgerEntry::where('type', 'salary_payment')->count());
        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'payslip_id' => $this->payslip->id,
            'type' => 'salary_payment',
            'reference_type' => 'payslip',
            'reference_id' => $this->payslip->id,
            'amount' => -1_000_000,
            'is_effective' => true,
        ]);
        $this->assertSame(0, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee->id));
        $this->assertSame(0, (int) $this->employee->fresh()->salary_balance_cache);
    }

    public function test_employee_salary_ledger_timeline_shows_accrual_and_payment_for_paid_payslip(): void
    {
        $this->putJson("/api/paysheets/{$this->paysheet->id}/lock")->assertOk();
        $this->postJson("/api/paysheets/{$this->paysheet->id}/pay", [
            'payment_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'payments' => [['payslip_id' => $this->payslip->id, 'amount' => 1_000_000]],
        ], ['Idempotency-Key' => 'p0-timeline-payment'])->assertOk();

        $this->getJson("/api/employees/{$this->employee->id}/salary-ledger")
            ->assertOk()
            ->assertJsonPath('summary.current_balance', 0)
            ->assertJsonPath('summary.total_increase', 1_000_000)
            ->assertJsonPath('summary.total_decrease', 1_000_000)
            ->assertJsonCount(2, 'data.data');
    }

    public function test_financial_report_does_not_count_salary_payment_as_salary_expense_again(): void
    {
        $this->putJson("/api/paysheets/{$this->paysheet->id}/lock")->assertOk();
        $this->postJson("/api/paysheets/{$this->paysheet->id}/pay", [
            'payment_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'payments' => [['payslip_id' => $this->payslip->id, 'amount' => 1_000_000]],
        ], ['Idempotency-Key' => 'p0-financial-payment'])->assertOk();

        $this->get('/reports/financial-report?'.http_build_query([
            'time_mode' => 'custom',
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->endOfMonth()->toDateString(),
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/FinancialReport')
                ->where('report.totalExpenses', 1_000_000)
                ->where('report.expensesByCategory.0.amount', 1_000_000));
    }

    private function createLegacyPaidPayslipWithoutPaymentLedger(): PaysheetPayment
    {
        $this->putJson("/api/paysheets/{$this->paysheet->id}/lock")->assertOk();

        $payment = PaysheetPayment::create([
            'code' => 'TTPL-LEGACY-P0',
            'paysheet_id' => $this->paysheet->id,
            'payslip_id' => $this->payslip->id,
            'employee_id' => $this->employee->id,
            'amount' => 1_000_000,
            'status' => 'active',
            'method' => 'cash',
            'paid_at' => now(),
            'created_by' => auth()->id(),
            'idempotency_key' => 'legacy-payment-without-ledger',
        ]);
        $this->payslip->update([
            'paid_amount' => 1_000_000,
            'remaining' => 0,
            'payment_status' => 'paid',
        ]);
        $this->paysheet->recalculateTotals();

        return $payment;
    }
}
