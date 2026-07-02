<?php

namespace Tests\Feature\Payroll;

use App\Models\Branch;
use App\Models\CashFlow;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\Paysheet;
use App\Models\PaysheetPayment;
use App\Models\Payslip;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Services\PayrollPaymentCashFlowService;
use App\Services\PayrollReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PayrollPaymentCashFlowTest extends TestCase
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
        $this->branch = Branch::create(['name' => 'P0 Payment CashFlow Branch']);
        $this->employee = Employee::create([
            'code' => 'NV-P0-CF-001',
            'name' => 'P0 Payment CashFlow Employee',
            'branch_id' => $this->branch->id,
            'salary_balance_cache' => 0,
            'is_active' => true,
        ]);
        $this->paysheet = Paysheet::create([
            'code' => 'BL-P0-CF-001',
            'name' => 'P0 payment cashflow',
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
            'code' => 'PL-P0-CF-001',
            'paysheet_id' => $this->paysheet->id,
            'employee_id' => $this->employee->id,
            'total_salary' => 1_000_000,
            'paid_amount' => 0,
            'remaining' => 1_000_000,
            'payment_status' => 'unpaid',
        ]);
    }

    public function test_salary_payment_creates_cash_flow_voucher(): void
    {
        $this->lockPaysheet();
        $payment = $this->paySalary(400_000);
        $cashFlow = $payment->fresh('cashFlow')->cashFlow;

        $this->assertNotNull($cashFlow);
        $this->assertSame('payment', $cashFlow->type);
        $this->assertSame(400_000, (int) $cashFlow->amount);
        $this->assertSame('active', $cashFlow->status);
        $this->assertSame($this->branch->id, $cashFlow->branch_id);
        $this->assertSame(PayrollPaymentCashFlowService::REFERENCE_TYPE, $cashFlow->reference_type);
        $this->assertSame($payment->code, $cashFlow->reference_code);
        $this->assertSame("payroll_payment_cashflow:{$payment->id}", $cashFlow->idempotency_key);

        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'reference_type' => 'paysheet_payment',
            'reference_id' => $payment->id,
            'type' => EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT,
            'amount' => -400_000,
            'is_effective' => true,
        ]);
        $this->assertSame(1, CashFlow::active()->where('branch_id', $this->branch->id)->where('reference_code', $payment->code)->count());
    }

    public function test_salary_payment_retry_does_not_duplicate_cash_flow(): void
    {
        $this->lockPaysheet();
        $payment = $this->paySalary(400_000);

        app(PayrollPaymentCashFlowService::class)->ensureForPayment($payment);
        app(PayrollPaymentCashFlowService::class)->ensureForPayment($payment->fresh());

        $this->assertSame(1, CashFlow::where('idempotency_key', "payroll_payment_cashflow:{$payment->id}")->count());
    }

    public function test_existing_salary_payment_without_cash_flow_is_detected_by_audit(): void
    {
        $payment = $this->legacyPaymentWithoutCashFlow(500_000, true);

        Artisan::call('payroll:audit-payment-cashflow', [
            '--paysheet' => $this->paysheet->code,
            '--format' => 'json',
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $payload['summary']['issue_count']);
        $this->assertSame(500_000, $payload['summary']['missing_cash_flow_total']);
        $this->assertSame($payment->id, $payload['issues'][0]['payment_id']);
        $this->assertSame('missing_cash_flow', $payload['issues'][0]['reason']);
    }

    public function test_backfill_salary_payment_cash_flow_is_idempotent(): void
    {
        $this->legacyPaymentWithoutCashFlow(500_000, true);

        Artisan::call('payroll:backfill-payment-cashflow', [
            '--dry-run' => true,
            '--paysheet' => $this->paysheet->code,
        ]);
        $this->assertSame(0, CashFlow::count());

        Artisan::call('payroll:backfill-payment-cashflow', [
            '--apply' => true,
            '--paysheet' => $this->paysheet->code,
        ]);
        Artisan::call('payroll:backfill-payment-cashflow', [
            '--apply' => true,
            '--paysheet' => $this->paysheet->code,
        ]);

        $this->assertSame(1, CashFlow::count());
        Artisan::call('payroll:audit-payment-cashflow', [
            '--paysheet' => $this->paysheet->code,
            '--format' => 'json',
        ]);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(0, $payload['summary']['issue_count']);
    }

    public function test_salary_payment_cash_flow_does_not_double_count_pnl_expense(): void
    {
        $this->lockPaysheet();
        $this->paySalary(1_000_000);

        $this->assertSame(1_000_000, (int) Paysheet::where('status', 'locked')->sum('total_salary'));
        $this->assertSame(0, (int) CashFlow::active()->nonPayrollForExpense()->where('type', 'payment')->sum('amount'));
        $this->assertSame(1_000_000, (int) CashFlow::active()->payrollRelated()->where('type', 'payment')->sum('amount'));
    }

    public function test_cancel_salary_payment_cancels_cash_flow(): void
    {
        $this->lockPaysheet();
        $payment = $this->paySalary(400_000);
        $cashFlowId = $payment->fresh()->cash_flow_id;

        $this->postJson("/api/paysheet-payments/{$payment->id}/cancel", [
            'reason' => 'Huy thanh toan tao nham',
            'cancel_date' => now()->toDateTimeString(),
        ])->assertOk();

        $this->assertSame(0, CashFlow::active()->whereKey($cashFlowId)->count());
        $this->assertSame('cancelled', CashFlow::withTrashed()->findOrFail($cashFlowId)->status);
    }

    public function test_reconciliation_accepts_cancelled_payment_with_cancelled_soft_deleted_cash_flow(): void
    {
        $this->lockPaysheet();
        $payment = $this->paySalary(400_000);
        $cashFlowId = $payment->fresh()->cash_flow_id;

        $this->postJson("/api/paysheet-payments/{$payment->id}/cancel", [
            'reason' => 'Huy thanh toan tao nham',
            'cancel_date' => now()->toDateTimeString(),
        ])->assertOk();

        $payment->refresh();
        $cashFlow = CashFlow::withTrashed()->findOrFail($cashFlowId);
        $this->assertSame('cancelled', $payment->status);
        $this->assertSame('cancelled', $cashFlow->status);
        $this->assertNotNull($cashFlow->deleted_at);

        $report = app(PayrollReconciliationService::class)->audit(['employee' => $this->employee->id]);

        $this->assertFalse($this->documentIssues($report)->contains(fn (array $issue) => $issue['group'] === 'payment'
            && $issue['issue'] === 'PAYMENT_WITHOUT_CASHFLOW'
            && (int) $issue['document_id'] === (int) $payment->id));
    }

    public function test_reconciliation_still_reports_active_payment_without_cash_flow(): void
    {
        $payment = $this->legacyPaymentWithoutCashFlow(500_000, true);

        $report = app(PayrollReconciliationService::class)->audit(['employee' => $this->employee->id]);

        $this->assertTrue($this->documentIssues($report)->contains(fn (array $issue) => $issue['group'] === 'payment'
            && $issue['issue'] === 'PAYMENT_WITHOUT_CASHFLOW'
            && (int) $issue['document_id'] === (int) $payment->id));
    }

    public function test_reconciliation_still_reports_active_payment_cash_flow_amount_mismatch(): void
    {
        $this->lockPaysheet();
        $payment = $this->paySalary(400_000);
        CashFlow::whereKey($payment->cash_flow_id)->update(['amount' => 399_999]);

        $report = app(PayrollReconciliationService::class)->audit(['employee' => $this->employee->id]);

        $this->assertTrue($this->documentIssues($report)->contains(fn (array $issue) => $issue['group'] === 'payment'
            && $issue['issue'] === 'CASHFLOW_AMOUNT_MISMATCH'
            && (int) $issue['document_id'] === (int) $payment->id));
    }

    public function test_reconciliation_accepts_cancelled_advance_with_cancelled_soft_deleted_cash_flow(): void
    {
        $this->postJson("/api/employees/{$this->employee->id}/salary-advances", [
            'amount' => 300_000,
            'advance_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'branch_id' => $this->branch->id,
            'note' => 'Tam ung test huy',
        ], ['Idempotency-Key' => 'advance-reconciliation-cancel'])->assertCreated();

        $advance = SalaryAdvance::firstOrFail();
        $cashFlowId = $advance->cash_flow_id;

        $this->postJson("/api/salary-advances/{$advance->id}/cancel", [
            'reason' => 'Huy tam ung tao nham',
            'cancel_date' => now()->toDateTimeString(),
        ])->assertOk();

        $advance->refresh();
        $cashFlow = CashFlow::withTrashed()->findOrFail($cashFlowId);
        $this->assertSame('cancelled', $advance->status);
        $this->assertSame('cancelled', $cashFlow->status);
        $this->assertNotNull($cashFlow->deleted_at);

        $report = app(PayrollReconciliationService::class)->audit(['employee' => $this->employee->id]);

        $this->assertFalse($this->documentIssues($report)->contains(fn (array $issue) => $issue['group'] === 'advance'
            && $issue['issue'] === 'ADVANCE_WITHOUT_CASHFLOW'
            && (int) $issue['document_id'] === (int) $advance->id));
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
        ], ['Idempotency-Key' => 'p0-payment-cashflow-'.$amount])->assertOk();

        return PaysheetPayment::firstOrFail();
    }

    private function legacyPaymentWithoutCashFlow(int $amount, bool $withLedger): PaysheetPayment
    {
        $this->lockPaysheet();
        $payment = PaysheetPayment::create([
            'code' => 'TTPL-LEGACY-CF',
            'paysheet_id' => $this->paysheet->id,
            'payslip_id' => $this->payslip->id,
            'employee_id' => $this->employee->id,
            'amount' => $amount,
            'status' => 'active',
            'method' => 'cash',
            'paid_at' => now(),
            'created_by' => auth()->id(),
            'idempotency_key' => 'legacy-payment-cashflow',
        ]);
        if ($withLedger) {
            EmployeeSalaryLedgerEntry::create([
                'employee_id' => $this->employee->id,
                'branch_id' => $this->branch->id,
                'paysheet_id' => $this->paysheet->id,
                'payslip_id' => $this->payslip->id,
                'code' => $payment->code,
                'type' => EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT,
                'reference_type' => 'paysheet_payment',
                'reference_id' => $payment->id,
                'amount' => -$amount,
                'balance_after' => 1_000_000 - $amount,
                'is_effective' => true,
                'status' => 'valid',
                'event_at' => now(),
                'idempotency_key' => "legacy:salary_payment:cashflow:{$payment->id}",
            ]);
        }
        $this->payslip->update([
            'paid_amount' => $amount,
            'remaining' => max(1_000_000 - $amount, 0),
            'payment_status' => $amount >= 1_000_000 ? 'paid' : 'partial',
        ]);
        $this->paysheet->recalculateTotals();

        return $payment;
    }

    private function documentIssues(array $report)
    {
        return collect($report['document_issues'] ?? []);
    }
}
