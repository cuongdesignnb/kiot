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

class PaysheetCancelReversalTest extends TestCase
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
        $this->branch = Branch::create(['name' => 'P0 Paysheet Cancel Branch']);
        $this->employee = Employee::create([
            'code' => 'NV-P0-CANCEL-001',
            'name' => 'P0 Paysheet Cancel Employee',
            'branch_id' => $this->branch->id,
            'salary_balance_cache' => 0,
            'is_active' => true,
        ]);
        $this->paysheet = Paysheet::create([
            'code' => 'BL-P0-CANCEL-001',
            'name' => 'P0 cancel safe reversal',
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
            'code' => 'PL-P0-CANCEL-001',
            'paysheet_id' => $this->paysheet->id,
            'employee_id' => $this->employee->id,
            'total_salary' => 1_000_000,
            'paid_amount' => 0,
            'remaining' => 1_000_000,
            'payment_status' => 'unpaid',
        ]);
    }

    public function test_cancel_locked_unpaid_paysheet_reverses_payroll_accrual_and_clears_employee_debt(): void
    {
        $this->lockPaysheet();

        $this->assertSame(1_000_000, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee->id));

        $this->postJson("/api/paysheets/{$this->paysheet->id}/cancel", [
            'reason' => 'Huy bang luong tao nham UAT',
        ])
            ->assertOk()
            ->assertJsonPath('reversed_entries_count', 1);

        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'payslip_id' => $this->payslip->id,
            'type' => EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL,
            'amount' => 1_000_000,
            'is_effective' => true,
        ]);
        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'payslip_id' => $this->payslip->id,
            'type' => EmployeeSalaryLedgerEntry::TYPE_CANCEL_REVERSE,
            'amount' => -1_000_000,
            'is_effective' => true,
        ]);
        $this->assertSame(0, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee->id));
        $this->assertSame(0, (int) $this->employee->fresh()->salary_balance_cache);
        $this->assertSame('cancelled', $this->paysheet->fresh()->status);
        $this->assertSame(0, (int) $this->payslip->fresh()->remaining);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'paysheet_cancel',
            'subject_type' => Paysheet::class,
            'subject_id' => $this->paysheet->id,
        ]);
    }

    public function test_cannot_cancel_paysheet_with_active_salary_payments(): void
    {
        $this->lockPaysheet();
        $this->paySalary(400_000);

        $this->postJson("/api/paysheets/{$this->paysheet->id}/cancel", [
            'reason' => 'Huy bang luong tao nham UAT',
        ])->assertStatus(422);

        $this->assertSame(0, EmployeeSalaryLedgerEntry::where('type', EmployeeSalaryLedgerEntry::TYPE_CANCEL_REVERSE)->count());
        $this->assertSame('locked', $this->paysheet->fresh()->status);
        $this->assertSame(600_000, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee->id));
    }

    public function test_can_cancel_payment_then_cancel_paysheet_safely(): void
    {
        $this->lockPaysheet();
        $payment = $this->paySalary(400_000);

        $this->postJson("/api/paysheet-payments/{$payment->id}/cancel", [
            'reason' => 'Huy thanh toan tao nham',
            'cancel_date' => now()->toDateTimeString(),
        ])->assertOk();
        $this->assertSame(1_000_000, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee->id));

        $this->postJson("/api/paysheets/{$this->paysheet->id}/cancel", [
            'reason' => 'Huy bang luong tao nham UAT',
        ])->assertOk();

        $this->assertSame(0, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee->id));
        $this->assertDatabaseHas('employee_salary_ledger_entries', ['type' => EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL, 'amount' => 1_000_000]);
        $this->assertDatabaseHas('employee_salary_ledger_entries', ['type' => EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT, 'amount' => -400_000]);
        $this->assertDatabaseHas('employee_salary_ledger_entries', ['type' => EmployeeSalaryLedgerEntry::TYPE_CANCEL_REVERSE, 'amount' => 400_000]);
        $this->assertDatabaseHas('employee_salary_ledger_entries', ['type' => EmployeeSalaryLedgerEntry::TYPE_CANCEL_REVERSE, 'amount' => -1_000_000]);
    }

    public function test_cancel_paysheet_is_idempotent_and_does_not_duplicate_reversal(): void
    {
        $this->lockPaysheet();

        $payload = ['reason' => 'Huy bang luong tao nham UAT'];
        $this->postJson("/api/paysheets/{$this->paysheet->id}/cancel", $payload)->assertOk();
        $this->postJson("/api/paysheets/{$this->paysheet->id}/cancel", $payload)->assertOk();

        $this->assertSame(1, EmployeeSalaryLedgerEntry::where('type', EmployeeSalaryLedgerEntry::TYPE_CANCEL_REVERSE)->count());
        $this->assertSame(0, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee->id));
        $this->assertSame(0, (int) $this->employee->fresh()->salary_balance_cache);
    }

    public function test_cancelled_paysheet_is_excluded_from_payroll_expense_report(): void
    {
        $this->lockPaysheet();

        $this->get($this->financialReportUrl())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/FinancialReport')
                ->where('report.totalExpenses', 1_000_000));

        $this->postJson("/api/paysheets/{$this->paysheet->id}/cancel", [
            'reason' => 'Huy bang luong tao nham UAT',
        ])->assertOk();

        $this->get($this->financialReportUrl())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/FinancialReport')
                ->where('report.totalExpenses', 0));
    }

    public function test_audit_paysheet_cancel_command_reports_can_cancel_for_unpaid_locked_paysheet(): void
    {
        $this->lockPaysheet();

        $exitCode = Artisan::call('payroll:audit-paysheet-cancel', [
            'paysheet_code' => $this->paysheet->code,
            '--format' => 'json',
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('yes', $payload['can_cancel']);
        $this->assertSame('ok', $payload['reason']);
        $this->assertSame(1, $payload['payroll_accrual_count']);
    }

    public function test_audit_paysheet_cancel_command_blocks_paysheet_with_active_payment(): void
    {
        $this->lockPaysheet();
        $this->paySalary(400_000);

        $exitCode = Artisan::call('payroll:audit-paysheet-cancel', [
            'paysheet_code' => $this->paysheet->code,
            '--format' => 'json',
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('no', $payload['can_cancel']);
        $this->assertSame('has_active_payment', $payload['reason']);
        $this->assertSame(1, $payload['active_payment_count']);
    }

    private function lockPaysheet(): void
    {
        $this->putJson("/api/paysheets/{$this->paysheet->id}/lock")->assertOk();
    }

    private function paySalary(int $amount): PaysheetPayment
    {
        $this->postJson("/api/paysheets/{$this->paysheet->id}/pay", [
            'payment_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'payments' => [['payslip_id' => $this->payslip->id, 'amount' => $amount]],
        ], ['Idempotency-Key' => 'cancel-flow-payment-'.$amount])->assertOk();

        return PaysheetPayment::firstOrFail();
    }

    private function financialReportUrl(): string
    {
        return '/reports/financial-report?'.http_build_query([
            'time_mode' => 'custom',
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->endOfMonth()->toDateString(),
        ]);
    }
}
