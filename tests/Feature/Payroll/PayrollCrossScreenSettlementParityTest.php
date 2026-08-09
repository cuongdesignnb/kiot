<?php

namespace Tests\Feature\Payroll;

use App\Models\Branch;
use App\Models\CashFlow;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\Paysheet;
use App\Models\PaysheetPayment;
use App\Models\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollCrossScreenSettlementParityTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role_id' => null]));
        $this->branch = Branch::create(['name' => 'Payroll parity branch']);
        $this->employee = Employee::create([
            'code' => 'NV-PARITY',
            'name' => 'Nhân viên đối soát thanh toán',
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
    }

    public function test_employee_payment_allocates_fifo_and_updates_the_same_ledger_contract(): void
    {
        [$firstSheet, $firstSlip] = $this->lockedPaysheet('BL-FIFO-01', 'PL-FIFO-01', 1_000_000, '2026-06-01');
        [$secondSheet, $secondSlip] = $this->lockedPaysheet('BL-FIFO-02', 'PL-FIFO-02', 2_000_000, '2026-07-01');

        $response = $this->postJson("/api/employees/{$this->employee->id}/salary-payments", [
            'amount' => 1_500_000,
            'payment_date' => '2026-07-15 10:00:00',
            'payment_method' => 'cash',
            'note' => 'Thanh toán FIFO từ màn hình nhân viên',
            'payments' => [
                ['payslip_id' => $firstSlip->id, 'amount' => 1_000_000],
                ['payslip_id' => $secondSlip->id, 'amount' => 500_000],
            ],
        ], ['Idempotency-Key' => 'parity-employee-fifo']);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(2, PaysheetPayment::count());
        $this->assertDatabaseHas('paysheet_payments', [
            'paysheet_id' => $firstSheet->id,
            'payslip_id' => $firstSlip->id,
            'amount' => 1_000_000,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('paysheet_payments', [
            'paysheet_id' => $secondSheet->id,
            'payslip_id' => $secondSlip->id,
            'amount' => 500_000,
            'status' => 'active',
        ]);
        $this->assertSame(1_500_000, (int) EmployeeSalaryLedgerEntry::where('employee_id', $this->employee->id)
            ->where('type', EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT)->sum('amount') * -1);
        $this->assertSame(1_500_000, (int) $this->employee->fresh()->salary_balance_cache);
        $this->assertSame(0, (int) $firstSlip->fresh()->remaining);
        $this->assertSame(1_500_000, (int) $secondSlip->fresh()->remaining);
    }

    public function test_paysheet_endpoint_persists_payment_cashflow_ledger_and_document_totals_atomically(): void
    {
        [$sheet, $slip] = $this->lockedPaysheet('BL-DIRECT-01', 'PL-DIRECT-01', 1_000_000, '2026-08-01');

        $this->postJson("/api/paysheets/{$sheet->id}/pay", [
            'amount' => 400_000,
            'payment_date' => '2026-08-03 09:00:00',
            'payment_method' => 'bank_transfer',
            'payments' => [['payslip_id' => $slip->id, 'amount' => 400_000]],
        ], ['Idempotency-Key' => 'parity-paysheet-direct'])->assertOk();

        $payment = PaysheetPayment::firstOrFail();
        $this->assertSame(400_000, (int) $payment->amount);
        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'reference_type' => 'paysheet_payment',
            'reference_id' => $payment->id,
            'amount' => -400_000,
            'type' => EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT,
            'is_effective' => true,
        ]);
        $this->assertNotNull($payment->fresh()->cash_flow_id);
        $this->assertSame(400_000, (int) $slip->fresh()->paid_amount);
        $this->assertSame(600_000, (int) $slip->fresh()->remaining);
        $this->assertSame(400_000, (int) $sheet->fresh()->total_paid);
        $this->assertSame(600_000, (int) $sheet->fresh()->total_remaining);
        $this->assertSame(1, CashFlow::where('reference_type', 'PaysheetPayment')->count());
    }

    public function test_multi_paysheet_employee_payment_is_atomic_when_a_later_allocation_fails(): void
    {
        [$firstSheet, $firstSlip] = $this->lockedPaysheet('BL-ATOMIC-01', 'PL-ATOMIC-01', 1_000_000, '2026-10-01');
        [$secondSheet, $secondSlip] = $this->lockedPaysheet('BL-ATOMIC-02', 'PL-ATOMIC-02', 2_000_000, '2026-11-01');
        $before = [
            'payments' => PaysheetPayment::count(),
            'ledger' => EmployeeSalaryLedgerEntry::count(),
            'cashflows' => CashFlow::count(),
            'first_slip' => $firstSlip->fresh()->only(['paid_amount', 'remaining', 'payment_status']),
            'second_slip' => $secondSlip->fresh()->only(['paid_amount', 'remaining', 'payment_status']),
            'first_sheet' => $firstSheet->fresh()->only(['total_paid', 'total_remaining', 'payment_status']),
            'second_sheet' => $secondSheet->fresh()->only(['total_paid', 'total_remaining', 'payment_status']),
        ];

        $this->postJson("/api/employees/{$this->employee->id}/salary-payments", [
            'amount' => 3_100_000,
            'payment_date' => '2026-11-15 10:00:00',
            'payment_method' => 'cash',
            'payments' => [
                ['payslip_id' => $firstSlip->id, 'amount' => 1_000_000],
                ['payslip_id' => $secondSlip->id, 'amount' => 2_100_000],
            ],
        ], ['Idempotency-Key' => 'parity-multi-sheet-atomic'])->assertStatus(422);

        $this->assertSame($before['payments'], PaysheetPayment::count());
        $this->assertSame($before['ledger'], EmployeeSalaryLedgerEntry::count());
        $this->assertSame($before['cashflows'], CashFlow::count());
        $this->assertSame($before['first_slip'], $firstSlip->fresh()->only(['paid_amount', 'remaining', 'payment_status']));
        $this->assertSame($before['second_slip'], $secondSlip->fresh()->only(['paid_amount', 'remaining', 'payment_status']));
        $this->assertSame($before['first_sheet'], $firstSheet->fresh()->only(['total_paid', 'total_remaining', 'payment_status']));
        $this->assertSame($before['second_sheet'], $secondSheet->fresh()->only(['total_paid', 'total_remaining', 'payment_status']));
    }

    public function test_employee_and_paysheet_entry_points_use_the_same_lock_order(): void
    {
        [$directSheet, $directSlip] = $this->lockedPaysheet('BL-LOCK-01', 'PL-LOCK-01', 1_000_000, '2026-12-01');
        [$employeeSheet, $employeeSlip] = $this->lockedPaysheet('BL-LOCK-02', 'PL-LOCK-02', 1_000_000, '2027-01-01');
        $orders = [];
        DB::listen(function ($query) use (&$orders): void {
            if (! str_contains(strtolower($query->sql), 'for update')) {
                return;
            }
            if (preg_match('/from [`"]?(paysheets|payslips|employees)[`"]?/i', $query->sql, $matches)) {
                $orders[] = strtolower($matches[1]);
            }
        });

        $this->postJson("/api/paysheets/{$directSheet->id}/pay", [
            'amount' => 100_000,
            'payment_date' => '2026-12-05 09:00:00',
            'payment_method' => 'cash',
            'payments' => [['payslip_id' => $directSlip->id, 'amount' => 100_000]],
        ], ['Idempotency-Key' => 'parity-lock-direct'])->assertOk();
        $directOrder = array_values(array_unique($orders));

        $orders = [];
        $this->postJson("/api/employees/{$this->employee->id}/salary-payments", [
            'amount' => 100_000,
            'payment_date' => '2027-01-05 09:00:00',
            'payment_method' => 'cash',
            'payments' => [['payslip_id' => $employeeSlip->id, 'amount' => 100_000]],
        ], ['Idempotency-Key' => 'parity-lock-employee'])->assertOk();
        $employeeOrder = array_values(array_unique($orders));

        $this->assertSame(['paysheets', 'payslips', 'employees'], $directOrder);
        $this->assertSame($directOrder, $employeeOrder);
        $this->assertSame(1_800_000, (int) $this->employee->fresh()->salary_balance_cache);
        $this->assertSame(900_000, (int) $directSlip->fresh()->remaining);
        $this->assertSame(900_000, (int) $employeeSlip->fresh()->remaining);
    }

    public function test_employee_allocation_total_mismatch_and_overpayment_do_not_mutate_documents(): void
    {
        [$sheet, $slip] = $this->lockedPaysheet('BL-ROLLBACK-01', 'PL-ROLLBACK-01', 1_000_000, '2026-09-01');
        $before = [
            'payments' => PaysheetPayment::count(),
            'ledger' => EmployeeSalaryLedgerEntry::count(),
            'cashflows' => CashFlow::count(),
            'slip' => $slip->fresh()->only(['paid_amount', 'remaining', 'payment_status']),
            'sheet' => $sheet->fresh()->only(['total_paid', 'total_remaining', 'payment_status']),
        ];

        $this->postJson("/api/employees/{$this->employee->id}/salary-payments", [
            'amount' => 900_000,
            'payment_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'payments' => [['payslip_id' => $slip->id, 'amount' => 1_000_000]],
        ], ['Idempotency-Key' => 'parity-bad-total'])->assertStatus(422);

        $this->postJson("/api/paysheets/{$sheet->id}/pay", [
            'amount' => 1_100_000,
            'payment_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'payments' => [['payslip_id' => $slip->id, 'amount' => 1_100_000]],
        ], ['Idempotency-Key' => 'parity-overpayment'])->assertStatus(422);

        $this->assertSame($before['payments'], PaysheetPayment::count());
        $this->assertSame($before['ledger'], EmployeeSalaryLedgerEntry::count());
        $this->assertSame($before['cashflows'], CashFlow::count());
        $this->assertSame($before['slip'], $slip->fresh()->only(['paid_amount', 'remaining', 'payment_status']));
        $this->assertSame($before['sheet'], $sheet->fresh()->only(['total_paid', 'total_remaining', 'payment_status']));
    }

    private function lockedPaysheet(string $sheetCode, string $slipCode, int $amount, string $periodStart): array
    {
        $sheet = Paysheet::create([
            'code' => $sheetCode,
            'name' => $sheetCode,
            'pay_period' => 'monthly',
            'period_start' => $periodStart,
            'period_end' => date('Y-m-t', strtotime($periodStart)),
            'branch_id' => $this->branch->id,
            'status' => 'calculated',
            'payment_status' => 'unpaid',
            'total_salary' => $amount,
            'total_paid' => 0,
            'total_remaining' => $amount,
            'employee_count' => 1,
        ]);
        $slip = Payslip::create([
            'code' => $slipCode,
            'paysheet_id' => $sheet->id,
            'employee_id' => $this->employee->id,
            'total_salary' => $amount,
            'paid_amount' => 0,
            'applied_advance' => 0,
            'remaining' => $amount,
            'payment_status' => 'unpaid',
        ]);
        $this->putJson("/api/paysheets/{$sheet->id}/lock")->assertOk();

        return [$sheet->fresh(), $slip->fresh()];
    }
}
