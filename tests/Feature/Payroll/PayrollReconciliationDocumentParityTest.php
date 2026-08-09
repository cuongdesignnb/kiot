<?php

namespace Tests\Feature\Payroll;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\Paysheet;
use App\Models\PaysheetPayment;
use App\Models\Payslip;
use App\Models\User;
use App\Services\PayrollReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollReconciliationDocumentParityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Employee $employee;

    private Paysheet $sheet;

    private Payslip $slip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role_id' => null]));
        $this->branch = Branch::create(['name' => 'Payroll reconciliation branch']);
        $this->employee = Employee::create([
            'code' => 'NV-RECON-DOC',
            'name' => 'Nhân viên đối soát chứng từ',
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->sheet = Paysheet::create([
            'code' => 'BL-RECON-DOC',
            'name' => 'Đối soát chứng từ',
            'pay_period' => 'monthly',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'branch_id' => $this->branch->id,
            'status' => 'locked',
            'payment_status' => 'partial',
            'locked_at' => '2026-06-02 09:00:00',
            'locked_by' => 'QA',
            'total_salary' => 1_000_000,
            'total_paid' => 0,
            'total_remaining' => 1_000_000,
            'employee_count' => 1,
        ]);
        $this->slip = Payslip::create([
            'code' => 'PL-RECON-DOC',
            'paysheet_id' => $this->sheet->id,
            'employee_id' => $this->employee->id,
            'total_salary' => 1_000_000,
            'paid_amount' => 0,
            'applied_advance' => 0,
            'remaining' => 1_000_000,
            'payment_status' => 'unpaid',
        ]);
    }

    public function test_reconciliation_detects_all_required_document_identity_and_settlement_issues_read_only(): void
    {
        $payment = PaysheetPayment::create([
            'code' => 'TTPL-RECON-DOC',
            'paysheet_id' => $this->sheet->id,
            'payslip_id' => $this->slip->id,
            'employee_id' => $this->employee->id,
            'amount' => 250_000,
            'status' => 'active',
            'method' => 'cash',
            'paid_at' => '2026-06-05 09:00:00',
            'idempotency_key' => 'recon-doc-payment',
        ]);
        $this->slip->update(['paid_amount' => 0, 'remaining' => 1_000_000]);
        $this->sheet->update(['total_paid' => 250_000, 'total_remaining' => 750_000]);

        $before = [
            'ledger' => EmployeeSalaryLedgerEntry::count(),
            'payments' => PaysheetPayment::count(),
            'slip' => $this->slip->fresh()->only(['paid_amount', 'remaining']),
            'sheet' => $this->sheet->fresh()->only(['total_paid', 'total_remaining']),
        ];

        $report = app(PayrollReconciliationService::class)->audit(['employee' => $this->employee->id]);
        $codes = collect($report['document_issues'])->pluck('issue');

        $this->assertTrue($codes->contains('MISSING_PAYROLL_ACCRUAL'));
        $this->assertTrue($codes->contains('MISSING_SALARY_PAYMENT_LEDGER'));
        $this->assertTrue($codes->contains('PAYSLIP_PAID_AMOUNT_MISMATCH'));
        $this->assertTrue($codes->contains('PAYSLIP_REMAINING_MISMATCH'));
        $this->assertTrue($codes->contains('PAYSHEET_TOTAL_PAID_MISMATCH'));
        $this->assertTrue($codes->contains('PAYSHEET_TOTAL_REMAINING_MISMATCH'));
        $this->assertSame($before['ledger'], EmployeeSalaryLedgerEntry::count());
        $this->assertSame($before['payments'], PaysheetPayment::count());
        $this->assertSame($before['slip'], $this->slip->fresh()->only(['paid_amount', 'remaining']));
        $this->assertSame($before['sheet'], $this->sheet->fresh()->only(['total_paid', 'total_remaining']));
        $this->assertSame($payment->id, collect($report['document_issues'])
            ->firstWhere('issue', 'MISSING_SALARY_PAYMENT_LEDGER')['document_id']);
    }

    public function test_reconciliation_classifies_accrual_mismatch_and_duplicate_and_payment_mismatch(): void
    {
        foreach ([900_000, 200_000] as $index => $amount) {
            EmployeeSalaryLedgerEntry::create([
                'employee_id' => $this->employee->id,
                'branch_id' => $this->branch->id,
                'paysheet_id' => $this->sheet->id,
                'payslip_id' => $this->slip->id,
                'code' => 'ACCRUAL-'.$index,
                'type' => EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL,
                'reference_type' => 'payslip',
                'reference_id' => $this->slip->id,
                'amount' => $amount,
                'is_effective' => true,
                'status' => 'valid',
                'event_at' => $this->sheet->locked_at,
                'idempotency_key' => 'recon-accrual-'.$index,
            ]);
        }
        $payment = PaysheetPayment::create([
            'code' => 'TTPL-RECON-MISMATCH',
            'paysheet_id' => $this->sheet->id,
            'payslip_id' => $this->slip->id,
            'employee_id' => $this->employee->id,
            'amount' => 250_000,
            'status' => 'active',
            'method' => 'cash',
            'paid_at' => '2026-06-05 09:00:00',
            'idempotency_key' => 'recon-payment-mismatch',
        ]);
        EmployeeSalaryLedgerEntry::create([
            'employee_id' => $this->employee->id,
            'branch_id' => $this->branch->id,
            'paysheet_id' => $this->sheet->id,
            'payslip_id' => $this->slip->id,
            'code' => $payment->code,
            'type' => EmployeeSalaryLedgerEntry::TYPE_SALARY_PAYMENT,
            'reference_type' => 'paysheet_payment',
            'reference_id' => $payment->id,
            'amount' => -200_000,
            'is_effective' => true,
            'status' => 'valid',
            'event_at' => $payment->paid_at,
            'idempotency_key' => 'recon-payment-ledger-mismatch',
        ]);

        $codes = collect(app(PayrollReconciliationService::class)
            ->audit(['employee' => $this->employee->id])['document_issues'])->pluck('issue');

        $this->assertTrue($codes->contains('DUPLICATE_PAYROLL_ACCRUAL'));
        $this->assertTrue($codes->contains('SALARY_PAYMENT_LEDGER_MISMATCH'));
    }
}
