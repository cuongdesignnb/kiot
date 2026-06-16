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
use App\Models\SalaryAdvanceApplication;
use App\Models\User;
use App\Services\EmployeeSalaryLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeSalaryPaymentRealScenarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_kiotviet_employee_payment_flow_can_be_read_as_business_scenario(): void
    {
        $this->actingAs(User::factory()->create(['role_id' => null]));

        $branch = Branch::create(['name' => 'Real Scenario Branch']);
        $employee = Employee::create([
            'code' => 'NV-REAL-SCENARIO-001',
            'name' => 'Test thuc te No Tam Ung',
            'branch_id' => $branch->id,
            'balance' => 777_777,
            'salary_balance_cache' => 0,
            'is_active' => true,
        ]);
        $paysheet = Paysheet::create([
            'code' => 'BL-REAL-001',
            'name' => 'Bang luong real scenario',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'branch_id' => $branch->id,
            'status' => 'calculated',
            'payment_status' => 'unpaid',
            'total_salary' => 1_000_000,
            'total_paid' => 0,
            'total_remaining' => 1_000_000,
            'employee_count' => 1,
        ]);
        $payslip = Payslip::create([
            'code' => 'PL-REAL-001',
            'paysheet_id' => $paysheet->id,
            'employee_id' => $employee->id,
            'total_salary' => 1_000_000,
            'paid_amount' => 0,
            'remaining' => 1_000_000,
            'payment_status' => 'unpaid',
        ]);

        $this->assertScenarioState(
            $employee,
            $payslip,
            ledgerRows: 0,
            payments: 0,
            advances: 0,
            cashFlows: 0,
            expectedBalance: 0,
            expectedPaid: 0,
            expectedRemaining: 1_000_000,
        );
        $this->assertSame(777_777, (int) $employee->fresh()->balance);

        // Step 1: chot bang luong, phat sinh khoan cong ty phai tra nhan vien.
        $this->putJson("/api/paysheets/{$paysheet->id}/lock")
            ->assertOk()
            ->assertJsonPath('data.status', 'locked');

        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'employee_id' => $employee->id,
            'payslip_id' => $payslip->id,
            'type' => 'payroll_accrual',
            'amount' => 1_000_000,
            'is_effective' => true,
        ]);
        $this->assertScenarioState($employee, $payslip, 1, 0, 0, 0, 1_000_000, 0, 1_000_000);

        // Step 2: thanh toan mot phan tu bang luong, giam no phai tra.
        $this->postJson("/api/paysheets/{$paysheet->id}/pay", [
            'payment_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'note' => 'Tra 400k tu bang luong',
            'payments' => [['payslip_id' => $payslip->id, 'amount' => 400_000]],
        ], ['Idempotency-Key' => 'real-scenario-sheet-400k'])
            ->assertOk();

        $this->assertLedgerAmount($employee, 'salary_payment', -400_000, 1);
        $this->assertScenarioState($employee, $payslip, 2, 1, 0, 1, 600_000, 400_000, 600_000);

        // Step 3: preview tu chi tiet nhan vien thay dung cung payslip con can tra.
        $this->getJson("/api/employees/{$employee->id}/salary-payment-preview")
            ->assertOk()
            ->assertJsonPath('mode', 'salary_payment')
            ->assertJsonPath('total_remaining', 600_000)
            ->assertJsonPath('current_balance', 600_000)
            ->assertJsonCount(1, 'payslips')
            ->assertJsonPath('payslips.0.id', $payslip->id)
            ->assertJsonPath('payslips.0.code', 'PL-REAL-001')
            ->assertJsonPath('payslips.0.remaining_amount', 600_000);

        $this->assertScenarioState($employee, $payslip, 2, 1, 0, 1, 600_000, 400_000, 600_000);

        // Step 4: thanh toan tiep tu chi tiet nhan vien, van tru dung payslip con lai.
        $this->postJson("/api/employees/{$employee->id}/salary-payments", [
            'mode' => 'salary_payment',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateTimeString(),
            'note' => 'Thanh toan het tu chi tiet nhan vien',
            'items' => [['payslip_id' => $payslip->id, 'amount' => 600_000]],
        ], ['Idempotency-Key' => 'real-scenario-profile-600k'])
            ->assertOk()
            ->assertJsonPath('mode', 'salary_payment')
            ->assertJsonPath('current_balance', 0);

        $this->assertLedgerAmount($employee, 'salary_payment', -600_000, 1);
        $this->assertScenarioState($employee, $payslip, 3, 2, 0, 2, 0, 1_000_000, 0);
        $this->assertSame('paid', $payslip->fresh()->payment_status);
        $this->assertSame('paid', $paysheet->fresh()->payment_status);

        // Step 5: da tra du thi preview khong con payslip nao de tra lai.
        $this->getJson("/api/employees/{$employee->id}/salary-payment-preview")
            ->assertOk()
            ->assertJsonPath('mode', 'salary_advance')
            ->assertJsonPath('total_remaining', 0)
            ->assertJsonPath('current_balance', 0)
            ->assertJsonCount(0, 'payslips');

        $this->assertScenarioState($employee, $payslip, 3, 2, 0, 2, 0, 1_000_000, 0);

        // Step 6: chi tiep khi khong con no luong thi thanh tam ung, tao so du am.
        $this->postJson("/api/employees/{$employee->id}/salary-payments", [
            'mode' => 'salary_advance',
            'payment_method' => 'cash',
            'advanced_at' => now()->toDateTimeString(),
            'note' => 'Tao tam ung khi khong con no luong',
            'amount' => 500_000,
        ], ['Idempotency-Key' => 'real-scenario-profile-advance-500k'])
            ->assertCreated()
            ->assertJsonPath('mode', 'salary_advance')
            ->assertJsonPath('current_balance', -500_000);

        $this->assertLedgerAmount($employee, 'salary_advance', -500_000, 1);
        $this->assertScenarioState($employee, $payslip, 4, 2, 1, 3, -500_000, 1_000_000, 0);
        $this->assertDatabaseCount('salary_advance_applications', 0);
        $this->assertDatabaseHas('salary_advances', [
            'employee_id' => $employee->id,
            'amount' => 500_000,
            'remaining_amount' => 500_000,
            'status' => 'active',
        ]);

        $this->assertSame(777_777, (int) $employee->fresh()->balance);
        $this->assertNoDuplicateFinancialDocuments($employee);
    }

    private function assertScenarioState(
        Employee $employee,
        Payslip $payslip,
        int $ledgerRows,
        int $payments,
        int $advances,
        int $cashFlows,
        int $expectedBalance,
        int $expectedPaid,
        int $expectedRemaining,
    ): void {
        $this->assertSame($ledgerRows, EmployeeSalaryLedgerEntry::where('employee_id', $employee->id)->count());
        $this->assertSame($payments, PaysheetPayment::where('employee_id', $employee->id)->count());
        $this->assertSame($advances, SalaryAdvance::where('employee_id', $employee->id)->count());
        $this->assertSame($cashFlows, CashFlow::count());
        $this->assertSame($expectedBalance, $this->effectiveBalance($employee));
        $this->assertSame($expectedBalance, (int) $employee->fresh()->salary_balance_cache);
        $this->assertSame($expectedPaid, (int) $payslip->fresh()->paid_amount);
        $this->assertSame($expectedRemaining, (int) $payslip->fresh()->remaining);
    }

    private function assertLedgerAmount(Employee $employee, string $type, int $amount, int $count): void
    {
        $this->assertSame($count, EmployeeSalaryLedgerEntry::where('employee_id', $employee->id)
            ->where('type', $type)
            ->where('amount', $amount)
            ->where('is_effective', true)
            ->count());
    }

    private function effectiveBalance(Employee $employee): int
    {
        $sum = (int) EmployeeSalaryLedgerEntry::where('employee_id', $employee->id)
            ->where('is_effective', true)
            ->sum('amount');

        $this->assertSame($sum, app(EmployeeSalaryLedgerService::class)->currentBalance($employee->id));

        return $sum;
    }

    private function assertNoDuplicateFinancialDocuments(Employee $employee): void
    {
        $this->assertSame(2, PaysheetPayment::where('employee_id', $employee->id)->count());
        $this->assertSame(2, PaysheetPayment::where('employee_id', $employee->id)->distinct('idempotency_key')->count('idempotency_key'));
        $this->assertSame(1, SalaryAdvance::where('employee_id', $employee->id)->count());
        $this->assertSame(1, SalaryAdvance::where('employee_id', $employee->id)->distinct('idempotency_key')->count('idempotency_key'));
        $this->assertSame(4, EmployeeSalaryLedgerEntry::where('employee_id', $employee->id)->count());
        $this->assertSame(4, EmployeeSalaryLedgerEntry::where('employee_id', $employee->id)->distinct('idempotency_key')->count('idempotency_key'));
        $this->assertSame(3, CashFlow::count());
        $this->assertSame(0, SalaryAdvanceApplication::where('employee_id', $employee->id)->count());
    }
}
