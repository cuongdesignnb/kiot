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

class PayrollLedgerKiotVietFlowTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    /** @var array<string, Employee> */
    private array $employees;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role_id' => null]));
        $this->branch = Branch::create(['name' => 'KiotViet Payroll E2E']);
        $this->employees = collect([
            'NV-E2E-001' => 'Test chot luong va tra luong',
            'NV-E2E-002' => 'Test tam ung truoc luong',
            'NV-E2E-DOUBLE' => 'Test chong double tru tam ung',
            'NV-E2E-CANCEL' => 'Test huy va dao giao dich',
        ])->mapWithKeys(fn (string $name, string $code) => [
            $code => Employee::create([
                'code' => $code,
                'name' => $name,
                'branch_id' => $this->branch->id,
                'is_active' => true,
            ]),
        ])->all();
    }

    public function test_lock_paysheet_posts_payroll_accrual_to_employee_ledger(): void
    {
        [$sheet, $slip] = $this->makePaysheet($this->employee('NV-E2E-001'), 1_000_000, 'LOCK');

        $this->putJson("/api/paysheets/{$sheet->id}/lock")->assertOk();

        $entry = EmployeeSalaryLedgerEntry::where('payslip_id', $slip->id)->sole();
        $this->assertSame('payroll_accrual', $entry->type);
        $this->assertSame(1_000_000, $entry->amount);
        $this->assertSame(1_000_000, $entry->balance_after);
        $this->assertTrue($entry->is_effective);
        $this->assertSame(1_000_000, $this->balance('NV-E2E-001'));
        $this->assertSame(1_000_000, (int) $this->employee('NV-E2E-001')->fresh()->salary_balance_cache);
        $this->assertDatabaseCount('cash_flows', 0);
        $this->assertDatabaseCount('paysheet_payments', 0);
        $this->assertDatabaseCount('salary_advances', 0);
        $this->assertDatabaseCount('salary_advance_applications', 0);
    }

    public function test_partial_salary_payment_decreases_employee_balance(): void
    {
        [$sheet, $slip] = $this->lockedPaysheet('NV-E2E-001', 1_000_000, 'PARTIAL');

        $this->pay($sheet, $slip, 400_000, 'partial-payment')->assertOk();

        $entry = EmployeeSalaryLedgerEntry::where('type', 'salary_payment')->sole();
        $this->assertSame(-400_000, $entry->amount);
        $this->assertSame(600_000, $entry->balance_after);
        $this->assertSame(600_000, $this->balance('NV-E2E-001'));
        $this->assertSame(600_000, (int) $this->employee('NV-E2E-001')->fresh()->salary_balance_cache);
        $this->assertSame(1, PaysheetPayment::count());
        $this->assertSame(1, CashFlow::where('reference_type', 'PaysheetPayment')->count());
        $this->assertDatabaseCount('salary_advances', 0);
        $this->assertDatabaseCount('salary_advance_applications', 0);
    }

    public function test_full_salary_payment_brings_employee_balance_to_zero(): void
    {
        [$sheet, $slip] = $this->lockedPaysheet('NV-E2E-001', 1_000_000, 'FULL');
        $this->pay($sheet, $slip, 400_000, 'full-payment-1')->assertOk();
        $this->pay($sheet, $slip, 600_000, 'full-payment-2')->assertOk();

        $this->assertSame(
            [1_000_000, -400_000, -600_000],
            EmployeeSalaryLedgerEntry::orderBy('event_at')->orderBy('id')->pluck('amount')->all()
        );
        $this->assertSame(0, $this->balance('NV-E2E-001'));
        $this->assertSame(0, (int) $this->employee('NV-E2E-001')->fresh()->salary_balance_cache);
        $this->assertSame(2, PaysheetPayment::count());
        $this->assertSame(2, CashFlow::where('reference_type', 'PaysheetPayment')->count());
        $this->assertDatabaseCount('salary_advances', 0);
        $this->assertDatabaseCount('salary_advance_applications', 0);
    }

    public function test_salary_advance_before_payroll_makes_balance_negative(): void
    {
        $this->advance('NV-E2E-002', 500_000, 'advance-negative')->assertCreated();

        $entry = EmployeeSalaryLedgerEntry::where('type', 'salary_advance')->sole();
        $this->assertSame(-500_000, $entry->amount);
        $this->assertSame(-500_000, $entry->balance_after);
        $this->assertSame(-500_000, $this->balance('NV-E2E-002'));
        $this->assertSame(-500_000, (int) $this->employee('NV-E2E-002')->fresh()->salary_balance_cache);
        $this->assertSame(1, SalaryAdvance::count());
        $this->assertSame(1, CashFlow::where('reference_type', 'SalaryAdvance')->count());
        $this->assertDatabaseCount('paysheet_payments', 0);
        $this->assertDatabaseCount('salary_advance_applications', 0);
    }

    public function test_payroll_after_advance_offsets_to_positive_remaining_balance(): void
    {
        $this->advance('NV-E2E-002', 500_000, 'advance-before-payroll')->assertCreated();
        $this->lockedPaysheet('NV-E2E-002', 2_000_000, 'AFTER-ADVANCE');

        $this->assertSame(
            [-500_000, 2_000_000],
            EmployeeSalaryLedgerEntry::orderBy('event_at')->orderBy('id')->pluck('amount')->all()
        );
        $this->assertSame(1_500_000, $this->balance('NV-E2E-002'));
        $this->assertSame(1_500_000, (int) $this->employee('NV-E2E-002')->fresh()->salary_balance_cache);
        $this->assertSame(1, SalaryAdvance::count());
        $this->assertSame(1, SalaryAdvanceApplication::count());
        $this->assertDatabaseCount('paysheet_payments', 0);
        $this->assertSame(1, CashFlow::where('reference_type', 'SalaryAdvance')->count());
    }

    public function test_advance_is_not_double_deducted_when_paysheet_is_locked(): void
    {
        $this->advance('NV-E2E-DOUBLE', 300_000, 'advance-no-double')->assertCreated();
        [, $slip] = $this->lockedPaysheet('NV-E2E-DOUBLE', 1_000_000, 'NO-DOUBLE');

        $this->assertSame(700_000, $this->balance('NV-E2E-DOUBLE'));
        $this->assertSame(700_000, (int) $this->employee('NV-E2E-DOUBLE')->fresh()->salary_balance_cache);
        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'payslip_id' => $slip->id,
            'type' => 'payroll_accrual',
            'amount' => 1_000_000,
        ]);
        $this->assertDatabaseMissing('employee_salary_ledger_entries', ['type' => 'advance_offset']);
        $this->assertSame(2, EmployeeSalaryLedgerEntry::count());
        $this->assertSame(1, SalaryAdvance::count());
        $this->assertSame(1, SalaryAdvanceApplication::count());
        $this->assertSame(1, CashFlow::count());
        $this->assertDatabaseCount('paysheet_payments', 0);
    }

    public function test_cancel_salary_payment_reverses_ledger_without_deleting_original_entry(): void
    {
        [$sheet, $slip] = $this->lockedPaysheet('NV-E2E-CANCEL', 1_000_000, 'CANCEL-PAYMENT');
        $this->pay($sheet, $slip, 400_000, 'cancel-payment-source')->assertOk();
        $payment = PaysheetPayment::sole();
        $original = EmployeeSalaryLedgerEntry::where('type', 'salary_payment')->sole();

        $this->postJson("/api/paysheet-payments/{$payment->id}/cancel", [
            'reason' => 'Huy thanh toan de kiem tra dong dao',
            'cancel_date' => now()->toDateTimeString(),
        ])->assertOk();

        $original->refresh();
        $reverse = EmployeeSalaryLedgerEntry::where('type', 'cancel_reverse')->sole();
        $this->assertSame('reversed', $original->status);
        $this->assertTrue($original->is_effective);
        $this->assertSame(400_000, $reverse->amount);
        $this->assertSame($original->id, $reverse->original_entry_id);
        $this->assertSame(1_000_000, $reverse->balance_after);
        $this->assertSame(3, EmployeeSalaryLedgerEntry::count());
        $this->assertSame(1_000_000, $this->balance('NV-E2E-CANCEL'));
        $this->assertSame(1_000_000, (int) $this->employee('NV-E2E-CANCEL')->fresh()->salary_balance_cache);
        $this->assertSame(1, PaysheetPayment::count());
        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame(1, CashFlow::withTrashed()->count());
        $this->assertSame('cancelled', CashFlow::withTrashed()->sole()->status);
        $this->assertDatabaseCount('salary_advances', 0);
        $this->assertDatabaseCount('salary_advance_applications', 0);
    }

    public function test_cancel_locked_paysheet_reverses_payroll_accrual_without_deleting_history(): void
    {
        [$sheet] = $this->lockedPaysheet('NV-E2E-CANCEL', 1_000_000, 'CANCEL-SHEET');
        $original = EmployeeSalaryLedgerEntry::where('type', 'payroll_accrual')->sole();

        $this->putJson("/api/paysheets/{$sheet->id}/cancel", [
            'reason' => 'Huy bang luong de kiem tra dong dao',
            'cancel_date' => now()->toDateTimeString(),
        ])->assertOk();

        $original->refresh();
        $reverse = EmployeeSalaryLedgerEntry::where('type', 'cancel_reverse')->sole();
        $this->assertSame('reversed', $original->status);
        $this->assertTrue($original->is_effective);
        $this->assertSame(-1_000_000, $reverse->amount);
        $this->assertSame($original->id, $reverse->original_entry_id);
        $this->assertSame(0, $reverse->balance_after);
        $this->assertSame(2, EmployeeSalaryLedgerEntry::count());
        $this->assertSame(0, $this->balance('NV-E2E-CANCEL'));
        $this->assertSame(0, (int) $this->employee('NV-E2E-CANCEL')->fresh()->salary_balance_cache);
        $this->assertDatabaseCount('cash_flows', 0);
        $this->assertDatabaseCount('paysheet_payments', 0);
        $this->assertDatabaseCount('salary_advances', 0);
        $this->assertDatabaseCount('salary_advance_applications', 0);
    }

    public function test_salary_ledger_api_returns_summary_and_entries(): void
    {
        [$sheet, $slip] = $this->lockedPaysheet('NV-E2E-001', 1_000_000, 'API');
        $this->pay($sheet, $slip, 400_000, 'api-payment')->assertOk();

        $response = $this->getJson("/api/employees/{$this->employee('NV-E2E-001')->id}/salary-ledger")
            ->assertOk()
            ->assertJsonPath('employee.code', 'NV-E2E-001')
            ->assertJsonPath('summary.current_balance', 600_000)
            ->assertJsonPath('summary.total_increase', 1_000_000)
            ->assertJsonPath('summary.total_decrease', 400_000)
            ->assertJsonPath('summary.entry_count', 2)
            ->assertJsonCount(2, 'entries')
            ->assertJsonStructure([
                'entries' => [[
                    'code',
                    'type',
                    'type_label',
                    'amount',
                    'increase_amount',
                    'decrease_amount',
                    'balance_after',
                    'is_effective',
                    'status',
                    'status_label',
                    'event_at',
                    'note',
                    'created_at',
                ]],
            ]);

        $this->assertSame($response->json('entries'), $response->json('data.data'));
        $this->assertSame(600_000, (int) $this->employee('NV-E2E-001')->fresh()->salary_balance_cache);
        $this->assertSame(2, EmployeeSalaryLedgerEntry::count());
        $this->assertSame(1, PaysheetPayment::count());
        $this->assertSame(1, CashFlow::where('reference_type', 'PaysheetPayment')->count());
        $this->assertDatabaseCount('salary_advances', 0);
        $this->assertDatabaseCount('salary_advance_applications', 0);
    }

    private function employee(string $code): Employee
    {
        return $this->employees[$code];
    }

    private function makePaysheet(Employee $employee, int $amount, string $suffix): array
    {
        $sheet = Paysheet::create([
            'code' => "BL-E2E-{$suffix}",
            'name' => "KiotViet flow {$suffix}",
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'branch_id' => $this->branch->id,
            'status' => 'calculated',
            'total_salary' => $amount,
            'total_remaining' => $amount,
            'employee_count' => 1,
        ]);
        $slip = Payslip::create([
            'code' => "PL-E2E-{$suffix}",
            'paysheet_id' => $sheet->id,
            'employee_id' => $employee->id,
            'total_salary' => $amount,
            'remaining' => $amount,
        ]);

        return [$sheet, $slip];
    }

    private function lockedPaysheet(string $employeeCode, int $amount, string $suffix): array
    {
        [$sheet, $slip] = $this->makePaysheet($this->employee($employeeCode), $amount, $suffix);
        $this->putJson("/api/paysheets/{$sheet->id}/lock")->assertOk();

        return [$sheet, $slip];
    }

    private function pay(Paysheet $sheet, Payslip $slip, int $amount, string $key)
    {
        return $this->postJson("/api/paysheets/{$sheet->id}/pay", [
            'payment_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'note' => 'Thanh toan luong E2E',
            'payments' => [['payslip_id' => $slip->id, 'amount' => $amount]],
        ], ['Idempotency-Key' => $key]);
    }

    private function advance(string $employeeCode, int $amount, string $key)
    {
        $employee = $this->employee($employeeCode);

        return $this->postJson("/api/employees/{$employee->id}/salary-advances", [
            'amount' => $amount,
            'advance_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'branch_id' => $this->branch->id,
            'note' => 'Tam ung luong E2E',
        ], ['Idempotency-Key' => $key]);
    }

    private function balance(string $employeeCode): int
    {
        return app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee($employeeCode)->id);
    }
}
