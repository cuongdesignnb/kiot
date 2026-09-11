<?php

namespace Tests\Feature\Payroll;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Employee;
use App\Models\EmployeeSalarySetting;
use App\Models\Paysheet;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeSalaryLedgerService;
use App\Services\PayrollCancellationService;
use App\Services\SalaryPaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PayrollCancelRecreateTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => str_repeat('k', 32)]);
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    private function sheet(string $status = 'calculated'): array
    {
        $employee = Employee::create(['code' => 'QA-C-'.uniqid(), 'name' => 'Synthetic cancellation employee', 'is_active' => true]);
        $sheet = Paysheet::create(['code' => Paysheet::nextCode(), 'name' => 'Synthetic cancellation', 'status' => $status, 'period_start' => '2031-06-01', 'period_end' => '2031-06-30']);
        $slip = Payslip::create(['code' => Payslip::nextCode(), 'paysheet_id' => $sheet->id, 'employee_id' => $employee->id, 'total_salary' => 1000, 'remaining' => 1000]);
        if ($status === 'locked') {
            app(EmployeeSalaryLedgerService::class)->append($employee, ['paysheet_id' => $sheet->id, 'payslip_id' => $slip->id, 'code' => $slip->code, 'type' => 'payroll_accrual', 'amount' => 1000, 'event_at' => now(), 'idempotency_key' => 'qa-accrual:'.$slip->id]);
        }

        return [$sheet, $slip, $employee];
    }

    private function cancel(Paysheet $sheet)
    {
        $preview = $this->getJson('/api/paysheets/'.$sheet->id.'/cancel-preview')->assertOk()->json();

        return $this->putJson('/api/paysheets/'.$sheet->id.'/cancel', ['reason' => 'Synthetic cancellation review', 'confirmation_hash' => $preview['confirmation_hash']]);
    }

    private function pay(Paysheet $sheet, Payslip $slip, int $amount = 400)
    {
        return app(SalaryPaymentService::class)->pay($sheet, [['payslip_id' => $slip->id, 'amount' => $amount]], ['payment_method' => 'cash', 'payment_date' => now()], uniqid('qa-pay'))[0];
    }

    public function test_cancel_draft_retire_and_create_again_preserves_old_history(): void
    {
        foreach (['draft', 'calculated'] as $status) {
            [$sheet, $slip, $employee] = $this->sheet($status);
            $this->cancel($sheet)->assertOk()->assertJsonPath('mode', 'unposted');
            $this->cancel($sheet)->assertOk()->assertJsonPath('mode', 'replay');
            $this->assertSame(1000, $slip->fresh()->total_salary);
            $this->assertSame(0, $slip->fresh()->remaining);
            $this->delete('/employees/'.$employee->id)->assertRedirect();
            $active = Employee::create(['code' => uniqid('QA-A'), 'name' => 'Synthetic active', 'is_active' => true]);
            EmployeeSalarySetting::create(['employee_id' => $active->id, 'salary_type' => 'fixed', 'base_salary' => 1000]);
            $new = $this->postJson('/api/paysheets', ['pay_period' => 'monthly', 'period_start' => '2031-06-01', 'period_end' => '2031-06-30', 'scope' => 'custom', 'employee_ids' => [$employee->id, $active->id]])->assertSuccessful()->json('data.id');
            $this->assertDatabaseMissing('payslips', ['paysheet_id' => $new, 'employee_id' => $employee->id]);
            $this->assertDatabaseHas('payslips', ['id' => $slip->id, 'paysheet_id' => $sheet->id]);
            $this->assertDatabaseHas('payslips', ['paysheet_id' => $new, 'employee_id' => $active->id]);
        }
    }

    public function test_paid_sheet_cancels_linked_payments_only_and_replay_is_safe(): void
    {
        [$sheet, $slip, $employee] = $this->sheet('locked');
        $payment = $this->pay($sheet, $slip);
        $other = CashFlow::create(['code' => uniqid('QA-PC'), 'type' => 'payment', 'amount' => 30, 'time' => now(), 'category' => 'Synthetic independent', 'status' => 'active']);
        $this->cancel($sheet)->assertOk();
        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame('cancelled', CashFlow::withTrashed()->findOrFail($payment->cash_flow_id)->status);
        $this->assertSame('active', $other->fresh()->status);
        $this->assertSame(0, app(EmployeeSalaryLedgerService::class)->currentBalance($employee->id));
        $this->cancel($sheet)->assertOk();
        $this->assertSame(1, ActivityLog::where('action', 'paysheet_cancel_reviewed')->where('subject_id', $sheet->id)->count());
    }

    public function test_changed_preview_blocks_without_writes(): void
    {
        [$sheet, $slip] = $this->sheet();
        $preview = app(PayrollCancellationService::class)->preview($sheet);
        $slip->update(['total_salary' => 1100]);
        $this->putJson('/api/paysheets/'.$sheet->id.'/cancel', ['reason' => 'Synthetic cancellation review', 'confirmation_hash' => $preview['confirmation_hash']])->assertUnprocessable();
        $this->assertSame('calculated', $sheet->fresh()->status);
    }

    public function test_fully_paid_sheet_and_multiple_payments_are_reversed_once(): void
    {
        [$sheet, $slip, $employee] = $this->sheet('locked');
        $one = $this->pay($sheet, $slip, 400);
        $two = $this->pay($sheet, $slip, 600);
        $this->cancel($sheet)->assertOk();
        $this->assertSame('cancelled', $one->fresh()->status);
        $this->assertSame('cancelled', $two->fresh()->status);
        $this->assertSame(0, app(EmployeeSalaryLedgerService::class)->currentBalance($employee->id));
    }

    public function test_invalid_second_payment_rolls_back_first_payment_cancellation(): void
    {
        [$sheet, $slip, $employee] = $this->sheet('locked');
        $one = $this->pay($sheet, $slip, 400);
        $two = $this->pay($sheet, $slip, 300);
        CashFlow::findOrFail($two->cash_flow_id)->update(['reference_type' => 'SyntheticIndependent']);
        $this->cancel($sheet)->assertUnprocessable();
        $this->assertSame('active', $one->fresh()->status);
        $this->assertSame('active', CashFlow::findOrFail($one->cash_flow_id)->status);
        $this->assertSame('locked', $sheet->fresh()->status);
        $this->assertSame(300, app(EmployeeSalaryLedgerService::class)->currentBalance($employee->id));
    }

    public function test_advance_application_is_released_without_cancelling_advance(): void
    {
        [$sheet, $slip, $employee] = $this->sheet('locked');
        $advance = \App\Models\SalaryAdvance::create(['code' => uniqid('QA-ADV'), 'employee_id' => $employee->id, 'amount' => 100, 'applied_amount' => 100, 'remaining_amount' => 0, 'status' => 'applied', 'advance_date' => now(), 'payment_method' => 'cash', 'note' => 'Synthetic advance']);
        $application = \App\Models\SalaryAdvanceApplication::create(['salary_advance_id' => $advance->id, 'employee_id' => $employee->id, 'paysheet_id' => $sheet->id, 'payslip_id' => $slip->id, 'amount' => 100, 'status' => 'active']);
        $slip->update(['applied_advance' => 100, 'remaining' => 900]);
        $this->cancel($sheet)->assertOk();
        $this->cancel($sheet)->assertOk();
        $this->assertSame('active', $advance->fresh()->status);
        $this->assertSame(100, $advance->fresh()->remaining_amount);
        $this->assertSame('cancelled', $application->fresh()->status);
    }

    public function test_payment_permission_required_and_draft_with_payment_totals_is_blocked(): void
    {
        [$sheet, $slip] = $this->sheet();
        $slip->update(['paid_amount' => 1]);
        $this->cancel($sheet)->assertUnprocessable();
        [$locked, $paidSlip] = $this->sheet('locked');
        $payment = $this->pay($locked, $paidSlip);
        $role = Role::create(['name' => uniqid('qa-role'), 'display_name' => 'Synthetic cancel only', 'permissions' => ['payroll.cancel']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));
        $this->cancel($locked)->assertForbidden();
        $this->assertSame('active', $payment->fresh()->status);
    }

    public function test_audit_failure_rolls_back_sheet_payment_cashflow_and_balance(): void
    {
        [$sheet, $slip, $employee] = $this->sheet('locked');
        $payment = $this->pay($sheet, $slip);
        $event = 'eloquent.creating: '.ActivityLog::class;
        Event::listen($event, function ($log) {
            if ($log->action === 'paysheet_cancel_reviewed') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            $service = app(PayrollCancellationService::class);
            $service->cancel($sheet, 'Synthetic review reason', now(), $service->preview($sheet)['confirmation_hash']);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame('locked', $sheet->fresh()->status);
        $this->assertSame('active', $payment->fresh()->status);
        $this->assertSame('active', CashFlow::findOrFail($payment->cash_flow_id)->status);
        $this->assertSame(600, app(EmployeeSalaryLedgerService::class)->currentBalance($employee->id));
    }
}
