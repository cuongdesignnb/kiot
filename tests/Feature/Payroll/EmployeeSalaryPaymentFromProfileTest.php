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

class EmployeeSalaryPaymentFromProfileTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role_id' => null]));
        $this->branch = Branch::create(['name' => 'Employee Profile Payroll']);
        $this->employee = Employee::create([
            'code' => 'NV-PROFILE-PAY',
            'name' => 'Profile Payment Employee',
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
    }

    public function test_employee_profile_payment_preview_returns_salary_payment_mode_when_payslip_has_remaining(): void
    {
        $this->lockedPaysheet(1_000_000, 'PREVIEW');

        $this->getJson("/api/employees/{$this->employee->id}/salary-payment-preview")
            ->assertOk()
            ->assertJsonPath('mode', 'salary_payment')
            ->assertJsonPath('current_balance', 1_000_000)
            ->assertJsonPath('total_remaining', 1_000_000)
            ->assertJsonPath('payslips.0.remaining_amount', 1_000_000);

        $this->assertDatabaseCount('paysheet_payments', 0);
        $this->assertDatabaseCount('cash_flows', 0);
        $this->assertDatabaseCount('salary_advances', 0);
        $this->assertSame(1_000_000, $this->ledgerBalance());
    }

    public function test_employee_profile_payment_uses_existing_payslip_remaining(): void
    {
        [$sheet, $slip] = $this->lockedPaysheet(1_000_000, 'USES-REMAINING');

        $this->postProfilePayment([['payslip_id' => $slip->id, 'amount' => 1_000_000]], 'profile-full-pay')
            ->assertOk()
            ->assertJsonPath('mode', 'salary_payment')
            ->assertJsonPath('current_balance', 0);

        $slip->refresh();
        $this->assertSame(0, (int) $slip->remaining);
        $this->assertSame(1_000_000, (int) $slip->paid_amount);
        $this->assertSame('paid', $slip->payment_status);
        $this->assertSame('paid', $sheet->fresh()->payment_status);
        $this->assertDatabaseCount('paysheet_payments', 1);
        $this->assertDatabaseCount('cash_flows', 1);
        $this->assertDatabaseCount('salary_advances', 0);
        $this->assertDatabaseCount('salary_advance_applications', 0);
        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'type' => 'salary_payment',
            'amount' => -1_000_000,
            'is_effective' => true,
        ]);
        $this->assertSame(0, $this->ledgerBalance());
        $this->assertSame(0, (int) $this->employee->fresh()->salary_balance_cache);
    }

    public function test_employee_profile_payment_after_full_paysheet_payment_is_blocked_for_salary_payment(): void
    {
        [$sheet, $slip] = $this->lockedPaysheet(1_000_000, 'BLOCKED');
        $this->payFromPaysheet($sheet, $slip, 1_000_000, 'sheet-full')->assertOk();

        $before = $this->counts();
        $this->postProfilePayment([['payslip_id' => $slip->id, 'amount' => 1]], 'profile-should-block')
            ->assertStatus(422);

        $this->assertSame($before, $this->counts());
        $this->getJson("/api/employees/{$this->employee->id}/salary-payment-preview")
            ->assertOk()
            ->assertJsonPath('mode', 'salary_advance')
            ->assertJsonPath('total_remaining', 0)
            ->assertJsonCount(0, 'payslips');
    }

    public function test_employee_profile_partial_payment_updates_paysheet_remaining(): void
    {
        [$sheet, $slip] = $this->lockedPaysheet(1_000_000, 'PARTIAL');
        $this->payFromPaysheet($sheet, $slip, 400_000, 'sheet-partial')->assertOk();

        $this->postProfilePayment([['payslip_id' => $slip->id, 'amount' => 600_000]], 'profile-pay-rest')
            ->assertOk();

        $slip->refresh();
        $this->assertSame(0, (int) $slip->remaining);
        $this->assertSame(1_000_000, (int) $slip->paid_amount);
        $this->assertSame(2, PaysheetPayment::count());
        $this->assertSame(2, CashFlow::where('reference_type', 'PaysheetPayment')->count());
        $this->assertSame(3, EmployeeSalaryLedgerEntry::count());
        $this->assertSame(0, $this->ledgerBalance());
        $this->assertSame(0, (int) $this->employee->fresh()->salary_balance_cache);
    }

    public function test_employee_profile_payment_with_no_remaining_creates_salary_advance(): void
    {
        $this->postProfileAdvance(500_000, 'profile-advance')
            ->assertCreated()
            ->assertJsonPath('mode', 'salary_advance')
            ->assertJsonPath('current_balance', -500_000);

        $advance = SalaryAdvance::sole();
        $this->assertSame(500_000, (int) $advance->amount);
        $this->assertSame(500_000, (int) $advance->remaining_amount);
        $this->assertDatabaseCount('paysheet_payments', 0);
        $this->assertDatabaseCount('cash_flows', 1);
        $this->assertDatabaseCount('salary_advances', 1);
        $this->assertDatabaseCount('salary_advance_applications', 0);
        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'type' => 'salary_advance',
            'amount' => -500_000,
            'balance_after' => -500_000,
            'is_effective' => true,
        ]);
        $this->assertSame(-500_000, $this->ledgerBalance());
        $this->assertSame(-500_000, (int) $this->employee->fresh()->salary_balance_cache);
    }

    public function test_employee_profile_payment_cannot_exceed_remaining(): void
    {
        [, $slip] = $this->lockedPaysheet(300_000, 'EXCEED');

        $before = $this->counts();
        $this->postProfilePayment([['payslip_id' => $slip->id, 'amount' => 500_000]], 'profile-exceed')
            ->assertStatus(422);

        $this->assertSame($before, $this->counts());
        $this->assertSame(300_000, (int) $slip->fresh()->remaining);
        $this->assertSame(300_000, $this->ledgerBalance());
        $this->assertSame(300_000, (int) $this->employee->fresh()->salary_balance_cache);
    }

    public function test_employee_profile_payment_is_idempotent_on_double_submit(): void
    {
        [, $slip] = $this->lockedPaysheet(700_000, 'IDEMPOTENT');
        $payload = [['payslip_id' => $slip->id, 'amount' => 700_000]];

        $this->postProfilePayment($payload, 'same-profile-payment')->assertOk();
        $this->postProfilePayment($payload, 'same-profile-payment')->assertOk();

        $this->assertDatabaseCount('paysheet_payments', 1);
        $this->assertDatabaseCount('cash_flows', 1);
        $this->assertSame(2, EmployeeSalaryLedgerEntry::count());
        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'type' => 'salary_payment',
            'amount' => -700_000,
        ]);
        $this->assertSame(0, (int) $slip->fresh()->remaining);
        $this->assertSame(0, $this->ledgerBalance());
    }

    public function test_employee_profile_payment_does_not_modify_employees_balance_directly(): void
    {
        $this->employee->update(['balance' => 123_456]);
        [, $slip] = $this->lockedPaysheet(900_000, 'LEGACY-BALANCE');

        $this->postProfilePayment([['payslip_id' => $slip->id, 'amount' => 400_000]], 'legacy-balance-check')
            ->assertOk();

        $this->assertSame(123_456, (int) $this->employee->fresh()->balance);
        $this->assertSame(500_000, (int) $slip->fresh()->remaining);
        $this->assertSame(500_000, $this->ledgerBalance());
        $this->assertSame(500_000, (int) $this->employee->fresh()->salary_balance_cache);
    }

    private function lockedPaysheet(int $amount, string $suffix): array
    {
        $sheet = Paysheet::create([
            'code' => "BL-PROFILE-{$suffix}",
            'name' => "Profile payment {$suffix}",
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'branch_id' => $this->branch->id,
            'status' => 'calculated',
            'total_salary' => $amount,
            'total_remaining' => $amount,
            'employee_count' => 1,
        ]);
        $slip = Payslip::create([
            'code' => "PL-PROFILE-{$suffix}",
            'paysheet_id' => $sheet->id,
            'employee_id' => $this->employee->id,
            'total_salary' => $amount,
            'remaining' => $amount,
        ]);

        $this->putJson("/api/paysheets/{$sheet->id}/lock")->assertOk();

        return [$sheet, $slip];
    }

    private function payFromPaysheet(Paysheet $sheet, Payslip $slip, int $amount, string $key)
    {
        return $this->postJson("/api/paysheets/{$sheet->id}/pay", [
            'payment_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'note' => 'Thanh toán từ bảng lương',
            'payments' => [['payslip_id' => $slip->id, 'amount' => $amount]],
        ], ['Idempotency-Key' => $key]);
    }

    private function postProfilePayment(array $items, string $key)
    {
        return $this->postJson("/api/employees/{$this->employee->id}/salary-payments", [
            'mode' => 'salary_payment',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateTimeString(),
            'note' => 'Thanh toán từ chi tiết nhân viên',
            'items' => $items,
        ], ['Idempotency-Key' => $key]);
    }

    private function postProfileAdvance(int $amount, string $key)
    {
        return $this->postJson("/api/employees/{$this->employee->id}/salary-payments", [
            'mode' => 'salary_advance',
            'payment_method' => 'cash',
            'advanced_at' => now()->toDateTimeString(),
            'note' => 'Tạm ứng từ chi tiết nhân viên',
            'amount' => $amount,
        ], ['Idempotency-Key' => $key]);
    }

    private function counts(): array
    {
        return [
            'payments' => PaysheetPayment::count(),
            'cash_flows' => CashFlow::count(),
            'advances' => SalaryAdvance::count(),
            'ledger' => EmployeeSalaryLedgerEntry::count(),
            'applications' => SalaryAdvanceApplication::count(),
        ];
    }

    private function ledgerBalance(): int
    {
        $balance = app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee->id);
        $this->assertSame(
            $balance,
            (int) EmployeeSalaryLedgerEntry::where('employee_id', $this->employee->id)
                ->where('is_effective', true)
                ->sum('amount')
        );

        return $balance;
    }
}
