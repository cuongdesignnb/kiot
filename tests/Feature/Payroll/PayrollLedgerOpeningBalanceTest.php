<?php

namespace Tests\Feature\Payroll;

use App\Models\Branch;
use App\Models\CashFlow;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\PaysheetPayment;
use App\Services\PayrollLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PayrollLedgerOpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::create(['name' => 'Payroll opening balance']);
        $this->employee = Employee::create([
            'code' => 'NV000012',
            'name' => 'Vũ Thị Thu Thủy',
            'branch_id' => $branch->id,
            'is_active' => true,
            'balance' => 50_000_000,
        ]);
    }

    public function test_dry_run_does_not_write_database(): void
    {
        $exitCode = $this->runMigration();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Mode: DRY-RUN', Artisan::output());
        $this->assertDatabaseCount('employee_salary_ledger_entries', 0);
        $this->assertSame(0, (int) $this->employee->fresh()->salary_balance_cache);
    }

    public function test_apply_creates_one_opening_balance_for_legacy_fifty_million(): void
    {
        $this->runMigration(true);

        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'employee_id' => $this->employee->id,
            'type' => EmployeeSalaryLedgerEntry::TYPE_OPENING_BALANCE,
            'amount' => 50_000_000,
            'balance_after' => 50_000_000,
            'is_effective' => true,
            'note' => 'Số dư lương chuyển đổi từ hệ thống KiotViet',
        ]);
        $this->assertDatabaseCount('employee_salary_ledger_entries', 1);
    }

    public function test_apply_does_not_modify_legacy_employee_balance(): void
    {
        $this->runMigration(true);

        $this->assertSame(50_000_000, (int) $this->employee->fresh()->balance);
    }

    public function test_apply_rebuilds_salary_balance_cache(): void
    {
        $this->runMigration(true);

        $this->assertSame(50_000_000, (int) $this->employee->fresh()->salary_balance_cache);
    }

    public function test_apply_does_not_create_cash_flow(): void
    {
        $before = CashFlow::count();

        $this->runMigration(true);

        $this->assertSame($before, CashFlow::count());
    }

    public function test_apply_does_not_create_paysheet_payment(): void
    {
        $before = PaysheetPayment::count();

        $this->runMigration(true);

        $this->assertSame($before, PaysheetPayment::count());
    }

    public function test_second_apply_is_idempotent(): void
    {
        $this->runMigration(true);
        $this->runMigration(true);

        $key = "payroll-opening-balance:employee:{$this->employee->id}:legacy-balance:50000000:go-live:2026-06-15";
        $this->assertSame(1, EmployeeSalaryLedgerEntry::query()->where('idempotency_key', $key)->count());
        $this->assertStringContainsString('SKIPPED', Artisan::output());
    }

    public function test_rebuild_uses_only_effective_entries(): void
    {
        EmployeeSalaryLedgerEntry::create([
            'employee_id' => $this->employee->id,
            'code' => 'EFFECTIVE-REVERSED',
            'type' => EmployeeSalaryLedgerEntry::TYPE_ADJUSTMENT_INCREASE,
            'amount' => 2_000_000,
            'is_effective' => true,
            'status' => 'reversed',
            'event_at' => now(),
        ]);
        EmployeeSalaryLedgerEntry::create([
            'employee_id' => $this->employee->id,
            'code' => 'VALID-INEFFECTIVE',
            'type' => EmployeeSalaryLedgerEntry::TYPE_ADJUSTMENT_INCREASE,
            'amount' => 9_000_000,
            'is_effective' => false,
            'status' => 'valid',
            'event_at' => now(),
        ]);

        app(PayrollLedgerService::class)->rebuildEmployeeBalance($this->employee->id);

        $this->assertSame(2_000_000, (int) $this->employee->fresh()->salary_balance_cache);
    }

    public function test_zero_legacy_balance_does_not_create_opening_balance(): void
    {
        $this->employee->update(['balance' => 0]);

        $this->runMigration(true);

        $this->assertDatabaseCount('employee_salary_ledger_entries', 0);
    }

    public function test_unknown_employee_code_returns_clear_error(): void
    {
        $exitCode = Artisan::call('payroll:migrate-salary-ledger', [
            '--legacy-balance' => 'opening',
            '--go-live-date' => '2026-06-15',
            '--employee-code' => 'NOT-FOUND',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Employee code NOT-FOUND was not found.', Artisan::output());
    }

    private function runMigration(bool $apply = false): int
    {
        $parameters = [
            '--legacy-balance' => 'opening',
            '--go-live-date' => '2026-06-15',
            '--employee-code' => $this->employee->code,
        ];
        if ($apply) {
            $parameters['--apply'] = true;
        }

        return Artisan::call('payroll:migrate-salary-ledger', $parameters);
    }
}
