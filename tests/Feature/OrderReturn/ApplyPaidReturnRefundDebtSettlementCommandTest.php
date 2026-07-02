<?php

namespace Tests\Feature\OrderReturn;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\OrderReturn;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ApplyPaidReturnRefundDebtSettlementCommandTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(string $code = null, float $debt = -100000): Customer
    {
        return Customer::create([
            'code' => $code ?: 'KH-APPLY-' . uniqid(),
            'name' => 'Apply Paid Refund Customer',
            'phone' => '091' . rand(1000000, 9999999),
            'is_customer' => true,
            'debt_amount' => $debt,
            'total_spent' => 0,
        ]);
    }

    private function paidReturn(Customer $customer, string $code = null, float $total = 100000, float $paid = 100000): OrderReturn
    {
        return OrderReturn::create([
            'code' => $code ?: 'TH-APPLY-' . uniqid(),
            'customer_id' => $customer->id,
            'subtotal' => $total,
            'discount' => 0,
            'fee' => 0,
            'total' => $total,
            'paid_to_customer' => $paid,
            'status' => 'completed',
            'return_date' => now(),
        ]);
    }

    private function addReturnLedger(OrderReturn $return, Customer $customer, float $ledgerAmount = -100000, float $cashflowAmount = 100000): void
    {
        CustomerDebt::create([
            'customer_id' => $customer->id,
            'order_return_id' => $return->id,
            'ref_code' => $return->code,
            'amount' => $ledgerAmount,
            'debt_total' => $ledgerAmount,
            'type' => 'return',
            'note' => 'Return apply test',
            'recorded_at' => now(),
        ]);

        if ($cashflowAmount > 0) {
            CashFlow::create([
                'code' => 'PC-APPLY-' . uniqid(),
                'type' => 'payment',
                'amount' => $cashflowAmount,
                'time' => now(),
                'category' => 'Chi tien tra hang khach',
                'target_type' => 'Khach hang',
                'target_id' => $customer->id,
                'target_name' => $customer->name,
                'reference_type' => 'OrderReturn',
                'reference_code' => $return->code,
                'description' => 'Paid refund apply test',
                'status' => 'completed',
            ]);
        }
    }

    public function test_dry_run_lists_missing_settlement_without_updating_data(): void
    {
        $customer = $this->customer();
        $return = $this->paidReturn($customer, 'TH-APPLY-DRY-RUN');
        $this->addReturnLedger($return, $customer);

        $debtBefore = (float) $customer->fresh()->debt_amount;
        $rowsBefore = CustomerDebt::count();

        $this->artisan('returns:apply-paid-refund-debt-settlement --dry-run')
            ->expectsOutputToContain('TH-APPLY-DRY-RUN')
            ->expectsOutputToContain('100000')
            ->assertExitCode(0);

        $this->assertSame($debtBefore, (float) $customer->fresh()->debt_amount);
        $this->assertSame($rowsBefore, CustomerDebt::count());
    }

    public function test_apply_requires_confirm_phrase(): void
    {
        $customer = $this->customer();
        $return = $this->paidReturn($customer, 'TH-APPLY-NO-CONFIRM');
        $this->addReturnLedger($return, $customer);

        $debtBefore = (float) $customer->fresh()->debt_amount;
        $rowsBefore = CustomerDebt::count();

        $this->artisan('returns:apply-paid-refund-debt-settlement')
            ->expectsOutput('Apply requires --confirm=CONFIRM_UPDATE_RETURN_REFUND_DEBT')
            ->assertExitCode(1);

        $this->artisan('returns:apply-paid-refund-debt-settlement --confirm=WRONG_PHRASE')
            ->expectsOutput('Apply requires --confirm=CONFIRM_UPDATE_RETURN_REFUND_DEBT')
            ->assertExitCode(1);

        $this->assertSame($debtBefore, (float) $customer->fresh()->debt_amount);
        $this->assertSame($rowsBefore, CustomerDebt::count());
    }

    public function test_apply_creates_positive_adjustment_and_updates_customer_debt(): void
    {
        $customer = $this->customer(null, -19200000);
        $return = $this->paidReturn($customer, 'TH-APPLY-CORRECT', 19200000, 19200000);
        $this->addReturnLedger($return, $customer, -19200000, 19200000);

        $cashflowsBefore = CashFlow::count();

        $this->artisan('returns:apply-paid-refund-debt-settlement --confirm=CONFIRM_UPDATE_RETURN_REFUND_DEBT')
            ->assertExitCode(0);

        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);

        $adjustment = CustomerDebt::where('ref_code', $return->code)
            ->where('type', 'adjustment')
            ->firstOrFail();

        $this->assertSame(19200000.0, (float) $adjustment->amount);
        $this->assertSame($return->id, $adjustment->order_return_id);
        $this->assertSame($cashflowsBefore, CashFlow::count());
    }

    public function test_apply_is_idempotent(): void
    {
        $customer = $this->customer();
        $return = $this->paidReturn($customer, 'TH-APPLY-IDEMPOTENT');
        $this->addReturnLedger($return, $customer);

        // First apply
        $this->artisan('returns:apply-paid-refund-debt-settlement --confirm=CONFIRM_UPDATE_RETURN_REFUND_DEBT')
            ->assertExitCode(0);

        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
        $adjustmentCount = CustomerDebt::where('ref_code', $return->code)
            ->where('type', 'adjustment')
            ->count();
        $this->assertSame(1, $adjustmentCount);

        // Second apply
        $this->artisan('returns:apply-paid-refund-debt-settlement --confirm=CONFIRM_UPDATE_RETURN_REFUND_DEBT')
            ->expectsOutput('No corrections applied.')
            ->assertExitCode(0);

        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(1, CustomerDebt::where('ref_code', $return->code)->where('type', 'adjustment')->count());
    }

    public function test_apply_skips_when_cashflow_paid_is_less_than_paid_to_customer(): void
    {
        $customer = $this->customer();
        $return = $this->paidReturn($customer, 'TH-APPLY-INSUFFICIENT-CASHFLOW');
        $this->addReturnLedger($return, $customer, -100000, 50000);

        $this->artisan('returns:apply-paid-refund-debt-settlement --confirm=CONFIRM_UPDATE_RETURN_REFUND_DEBT')
            ->assertExitCode(0);

        $this->assertSame(-100000.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(0, CustomerDebt::where('ref_code', $return->code)->where('type', 'adjustment')->count());
    }

    public function test_apply_skips_cancelled_return(): void
    {
        $customer = $this->customer();
        $return = $this->paidReturn($customer, 'TH-APPLY-CANCELLED');
        $return->update(['status' => 'cancelled']);
        $this->addReturnLedger($return, $customer);

        $this->artisan('returns:apply-paid-refund-debt-settlement --confirm=CONFIRM_UPDATE_RETURN_REFUND_DEBT')
            ->assertExitCode(0);

        $this->assertSame(-100000.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(0, CustomerDebt::where('ref_code', $return->code)->where('type', 'adjustment')->count());
    }

    public function test_apply_with_code_only_updates_selected_return(): void
    {
        $customer1 = $this->customer();
        $return1 = $this->paidReturn($customer1, 'TH-APPLY-SELECTED-1');
        $this->addReturnLedger($return1, $customer1);

        $customer2 = $this->customer();
        $return2 = $this->paidReturn($customer2, 'TH-APPLY-SELECTED-2');
        $this->addReturnLedger($return2, $customer2);

        $this->artisan('returns:apply-paid-refund-debt-settlement --code=TH-APPLY-SELECTED-1 --confirm=CONFIRM_UPDATE_RETURN_REFUND_DEBT')
            ->assertExitCode(0);

        $this->assertSame(0.0, (float) $customer1->fresh()->debt_amount);
        $this->assertSame(-100000.0, (float) $customer2->fresh()->debt_amount);
    }

    public function test_apply_outputs_customer_debt_ids_and_rollback_helper(): void
    {
        $customer = $this->customer();
        $return = $this->paidReturn($customer, 'TH-APPLY-OUTPUTS');
        $this->addReturnLedger($return, $customer);

        $this->artisan('returns:apply-paid-refund-debt-settlement --confirm=CONFIRM_UPDATE_RETURN_REFUND_DEBT')
            ->expectsOutputToContain('APPLIED')
            ->expectsOutputToContain('Rollback helper')
            ->expectsOutputToContain('DELETE FROM customer_debts WHERE id IN')
            ->expectsOutputToContain('UPDATE customers SET debt_amount = debt_amount -')
            ->assertExitCode(0);
    }

    public function test_dry_run_after_apply_returns_no_rows(): void
    {
        $customer = $this->customer();
        $return = $this->paidReturn($customer, 'TH-APPLY-NO-ROWS');
        $this->addReturnLedger($return, $customer);

        // Dry-run should find 1 row
        $this->artisan('returns:audit-paid-refund-debt --dry-run')
            ->expectsOutputToContain('TH-APPLY-NO-ROWS')
            ->assertExitCode(0);

        // Apply
        $this->artisan('returns:apply-paid-refund-debt-settlement --confirm=CONFIRM_UPDATE_RETURN_REFUND_DEBT')
            ->assertExitCode(0);

        // Dry-run should now return 0 rows
        $this->artisan('returns:audit-paid-refund-debt --dry-run')
            ->expectsOutput('No paid returns with missing refund debt settlement were found.')
            ->assertExitCode(0);
    }
}
