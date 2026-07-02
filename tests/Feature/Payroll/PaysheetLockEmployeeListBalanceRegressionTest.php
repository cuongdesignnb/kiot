<?php

namespace Tests\Feature\Payroll;

use App\Models\Branch;
use App\Models\CashFlow;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\Paysheet;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaysheetLockEmployeeListBalanceRegressionTest extends TestCase
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
        $this->branch = Branch::create(['name' => 'P0 Payroll Lock Branch']);
        $this->employee = Employee::create([
            'code' => 'NV-P0-LOCK-001',
            'name' => 'P0 Lock Employee Balance',
            'branch_id' => $this->branch->id,
            'balance' => 777_777,
            'salary_balance_cache' => 0,
            'is_active' => true,
        ]);
        $this->paysheet = Paysheet::create([
            'code' => 'BL-P0-LOCK-001',
            'name' => 'P0 paysheet lock balance',
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
            'code' => 'PL-P0-LOCK-001',
            'paysheet_id' => $this->paysheet->id,
            'employee_id' => $this->employee->id,
            'total_salary' => 1_000_000,
            'paid_amount' => 0,
            'remaining' => 1_000_000,
            'payment_status' => 'unpaid',
        ]);
    }

    public function test_locking_paysheet_from_real_endpoint_updates_employee_list_salary_balance(): void
    {
        $this->assertSame(0, EmployeeSalaryLedgerEntry::where('employee_id', $this->employee->id)->count());
        $this->assertSame(0, (int) $this->employee->fresh()->salary_balance_cache);

        $this->putJson("/api/paysheets/{$this->paysheet->id}/lock")
            ->assertOk()
            ->assertJsonPath('data.status', 'locked');

        $this->assertSame('locked', $this->paysheet->fresh()->status);
        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'employee_id' => $this->employee->id,
            'paysheet_id' => $this->paysheet->id,
            'payslip_id' => $this->payslip->id,
            'type' => 'payroll_accrual',
            'amount' => 1_000_000,
            'balance_after' => 1_000_000,
            'is_effective' => true,
            'status' => 'valid',
            'reference_type' => 'payslip',
            'reference_id' => $this->payslip->id,
        ]);
        $this->assertSame(1_000_000, (int) $this->employee->fresh()->salary_balance_cache);

        $this->get('/employees?search=NV-P0-LOCK-001')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Employees/Index')
                ->where('employees.total', 1)
                ->where('employees.data.0.id', $this->employee->id)
                ->where('employees.data.0.code', 'NV-P0-LOCK-001')
                ->where('employees.data.0.salary_balance_cache', 1_000_000)
                ->where('employees.data.0.salary_balance', 1_000_000)
                ->where('employees.data.0.salary_debt_amount', 1_000_000)
                ->where('employees.data.0.balance', '777777.00'));
    }

    public function test_employee_list_uses_salary_balance_cache_not_legacy_balance(): void
    {
        $this->putJson("/api/paysheets/{$this->paysheet->id}/lock")->assertOk();

        $this->employee->refresh();
        $this->assertSame(777_777, (int) $this->employee->balance);
        $this->assertSame(1_000_000, (int) $this->employee->salary_balance_cache);

        $this->get('/employees?search=NV-P0-LOCK-001')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('employees.total', 1)
                ->where('employees.data.0.salary_balance_cache', 1_000_000)
                ->where('employees.data.0.salary_balance', 1_000_000)
                ->where('employees.data.0.salary_debt_amount', 1_000_000)
                ->where('employees.data.0.balance', '777777.00'));
    }

    public function test_locking_paysheet_twice_does_not_duplicate_payroll_accrual(): void
    {
        $this->putJson("/api/paysheets/{$this->paysheet->id}/lock")->assertOk();
        $this->putJson("/api/paysheets/{$this->paysheet->id}/lock")->assertOk();

        $this->assertSame(1, EmployeeSalaryLedgerEntry::where('employee_id', $this->employee->id)
            ->where('payslip_id', $this->payslip->id)
            ->where('type', 'payroll_accrual')
            ->count());
        $this->assertSame(1_000_000, (int) $this->employee->fresh()->salary_balance_cache);
        $this->assertSame(0, CashFlow::count());
    }

    public function test_employee_list_frontend_binds_debt_column_to_salary_balance_cache(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Employees/Index.vue'));

        $this->assertStringContainsString('employee.salary_balance_cache || 0', $source);
        $this->assertStringNotContainsString('formatCurrency(employee.balance', $source);
    }
}
