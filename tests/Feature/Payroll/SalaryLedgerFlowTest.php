<?php

namespace Tests\Feature\Payroll;

use App\Models\Branch;
use App\Models\CashFlow;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\Paysheet;
use App\Models\PaysheetPayment;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeSalaryLedgerService;
use App\Services\PayrollDateGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalaryLedgerFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Branch $branch;

    private Employee $employee;

    private Paysheet $sheet;

    private Payslip $slip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role_id' => null]);
        $this->branch = Branch::create(['name' => 'Payroll Branch']);
        $this->employee = Employee::create([
            'code' => 'NV-LEDGER',
            'name' => 'Ledger Employee',
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->sheet = Paysheet::create([
            'code' => 'BL-LEDGER',
            'name' => 'Payroll ledger test',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'branch_id' => $this->branch->id,
            'status' => 'calculated',
            'total_salary' => 5_000_000,
            'total_remaining' => 5_000_000,
            'employee_count' => 1,
        ]);
        $this->slip = Payslip::create([
            'code' => 'PL-LEDGER',
            'paysheet_id' => $this->sheet->id,
            'employee_id' => $this->employee->id,
            'total_salary' => 5_000_000,
            'remaining' => 5_000_000,
        ]);
        $this->actingAs($this->admin);
    }

    public function test_advance_accrual_and_payment_do_not_double_count(): void
    {
        $this->postJson("/api/employees/{$this->employee->id}/salary-advances", [
            'amount' => 1_000_000,
            'advance_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'branch_id' => $this->branch->id,
            'note' => 'Tam ung dau thang',
        ], ['Idempotency-Key' => 'advance-1'])->assertCreated();

        $this->putJson("/api/paysheets/{$this->sheet->id}/lock")->assertOk();

        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'payslip_id' => $this->slip->id,
            'type' => 'payroll_accrual',
            'amount' => 5_000_000,
            'is_effective' => 1,
        ]);
        $this->assertDatabaseCount('salary_advance_applications', 1);
        $this->assertDatabaseMissing('employee_salary_ledger_entries', ['type' => 'advance_offset']);

        $this->postJson("/api/paysheets/{$this->sheet->id}/pay", [
            'payment_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'payments' => [['payslip_id' => $this->slip->id, 'amount' => 4_000_000]],
        ], ['Idempotency-Key' => 'payment-1'])->assertOk();

        $this->assertSame(0, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee->id));
        $this->assertSame(0, (int) $this->employee->fresh()->salary_balance_cache);
    }

    public function test_reversed_original_and_cancel_reverse_are_both_effective(): void
    {
        $this->putJson("/api/paysheets/{$this->sheet->id}/lock")->assertOk();
        $this->putJson("/api/paysheets/{$this->sheet->id}/cancel", [
            'reason' => 'Huy bang luong nhap sai',
            'cancel_date' => now()->toDateTimeString(),
        ])->assertOk();

        $original = EmployeeSalaryLedgerEntry::where('type', 'payroll_accrual')->firstOrFail();
        $reverse = EmployeeSalaryLedgerEntry::where('type', 'cancel_reverse')->firstOrFail();
        $this->assertSame('reversed', $original->status);
        $this->assertTrue($original->is_effective);
        $this->assertTrue($reverse->is_effective);
        $this->assertSame(0, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee->id));

        $summary = app(EmployeeSalaryLedgerService::class)->timeline($this->employee, [
            'from_date' => now()->startOfDay()->toDateTimeString(),
        ])['summary'];
        $this->assertSame(5_000_000, $summary['total_increase']);
        $this->assertSame(5_000_000, $summary['total_decrease']);
        $this->assertSame(0, $summary['net_change']);
    }

    public function test_timeline_returns_opening_balance_and_effective_summary(): void
    {
        $ledger = app(EmployeeSalaryLedgerService::class);
        $ledger->append($this->employee, [
            'code' => 'SDDK-1',
            'type' => 'opening_balance',
            'amount' => 3_000_000,
            'event_at' => now()->subMonth()->endOfMonth(),
            'idempotency_key' => 'opening-1',
        ]);
        $ledger->append($this->employee, [
            'code' => 'DCL-1',
            'type' => 'adjustment_decrease',
            'amount' => -1_000_000,
            'event_at' => now(),
            'idempotency_key' => 'adjust-1',
        ]);

        $this->getJson("/api/employees/{$this->employee->id}/salary-ledger?from_date=".now()->startOfMonth()->toDateTimeString())
            ->assertOk()
            ->assertJsonPath('summary.opening_balance', 3_000_000)
            ->assertJsonPath('summary.total_decrease', 1_000_000)
            ->assertJsonPath('summary.current_balance', 2_000_000);
    }

    public function test_payment_is_idempotent_and_creates_one_cashflow(): void
    {
        $this->putJson("/api/paysheets/{$this->sheet->id}/lock")->assertOk();
        $payload = [
            'payment_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'payments' => [['payslip_id' => $this->slip->id, 'amount' => 5_000_000]],
        ];
        $headers = ['Idempotency-Key' => 'same-payment'];
        $this->postJson("/api/paysheets/{$this->sheet->id}/pay", $payload, $headers)->assertOk();
        $this->postJson("/api/paysheets/{$this->sheet->id}/pay", $payload, $headers)->assertOk();

        $this->assertSame(1, PaysheetPayment::count());
        $this->assertSame(1, CashFlow::where('reference_type', 'PaysheetPayment')->count());
        $this->assertSame(1, EmployeeSalaryLedgerEntry::where('type', 'salary_payment')->count());
    }

    public function test_cannot_cancel_paysheet_until_payment_is_cancelled(): void
    {
        $this->putJson("/api/paysheets/{$this->sheet->id}/lock")->assertOk();
        $this->postJson("/api/paysheets/{$this->sheet->id}/pay", [
            'payment_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'payments' => [['payslip_id' => $this->slip->id, 'amount' => 2_000_000]],
        ], ['Idempotency-Key' => 'partial-payment'])->assertOk();

        $cancelPayload = ['reason' => 'Huy bang luong nhap sai', 'cancel_date' => now()->toDateTimeString()];
        $this->putJson("/api/paysheets/{$this->sheet->id}/cancel", $cancelPayload)->assertStatus(422);

        $payment = PaysheetPayment::firstOrFail();
        $this->postJson("/api/paysheet-payments/{$payment->id}/cancel", [
            'reason' => 'Huy thanh toan nhap sai',
            'cancel_date' => now()->toDateTimeString(),
        ])->assertOk();
        $this->assertDatabaseHas('cash_flows', [
            'id' => $payment->cash_flow_id,
            'status' => 'cancelled',
        ]);
        $this->putJson("/api/paysheets/{$this->sheet->id}/cancel", $cancelPayload)->assertOk();
        $this->assertSame(0, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee->id));
    }

    public function test_balance_uses_is_effective_and_not_status(): void
    {
        EmployeeSalaryLedgerEntry::create([
            'employee_id' => $this->employee->id,
            'code' => 'IGNORED',
            'type' => 'adjustment_increase',
            'amount' => 9_000_000,
            'is_effective' => false,
            'status' => 'valid',
            'event_at' => now(),
        ]);
        EmployeeSalaryLedgerEntry::create([
            'employee_id' => $this->employee->id,
            'code' => 'COUNTED',
            'type' => 'adjustment_increase',
            'amount' => 1_000_000,
            'is_effective' => true,
            'status' => 'reversed',
            'event_at' => now(),
        ]);

        $this->assertSame(1_000_000, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employee->id));
    }

    public function test_opening_balance_is_idempotent(): void
    {
        $ledger = app(EmployeeSalaryLedgerService::class);
        $payload = [
            'code' => 'SDDK-2026',
            'type' => 'opening_balance',
            'amount' => -500_000,
            'event_at' => '2026-01-01 00:00:00',
            'idempotency_key' => "opening_balance:{$this->employee->id}:2026-01-01",
        ];
        $ledger->append($this->employee, $payload);
        $ledger->append($this->employee, $payload);

        $this->assertSame(1, EmployeeSalaryLedgerEntry::where('type', 'opening_balance')->count());
        $this->assertSame(-500_000, $ledger->currentBalance($this->employee->id));
    }

    public function test_backdate_over_30_days_requires_override_permission(): void
    {
        $role = Role::create([
            'name' => 'payroll-user',
            'display_name' => 'Payroll user',
            'permissions' => ['payroll.advance.create'],
        ]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $this->actingAs($user);

        $this->expectException(ValidationException::class);
        app(PayrollDateGuard::class)->assertAllowed(now()->subDays(31), null, 'test');
    }

    public function test_inactive_employee_cannot_receive_advance_and_employee_with_salary_data_cannot_be_deleted(): void
    {
        $this->employee->update(['is_active' => false]);
        $this->postJson("/api/employees/{$this->employee->id}/salary-advances", [
            'amount' => 1_000_000,
            'advance_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'branch_id' => $this->branch->id,
            'note' => 'Tam ung khong hop le',
        ])->assertStatus(422);

        $this->delete("/employees/{$this->employee->id}")->assertRedirect();
        $this->assertDatabaseHas('employees', ['id' => $this->employee->id]);
    }
}
