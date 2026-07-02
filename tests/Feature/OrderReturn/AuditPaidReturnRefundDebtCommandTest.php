<?php

namespace Tests\Feature\OrderReturn;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\OrderReturn;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuditPaidReturnRefundDebtCommandTest extends TestCase
{
    use DatabaseTransactions;

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'KH-AUDIT-' . uniqid(),
            'name' => 'Audit Paid Refund Customer',
            'phone' => '091' . rand(1000000, 9999999),
            'is_customer' => true,
            'debt_amount' => -100000,
            'total_spent' => 0,
        ]);
    }

    private function paidReturn(Customer $customer, string $code = null): OrderReturn
    {
        return OrderReturn::create([
            'code' => $code ?: 'TH-AUDIT-' . uniqid(),
            'customer_id' => $customer->id,
            'subtotal' => 100000,
            'discount' => 0,
            'fee' => 0,
            'total' => 100000,
            'paid_to_customer' => 100000,
            'status' => 'completed',
            'return_date' => now(),
        ]);
    }

    private function addReturnLedger(OrderReturn $return, Customer $customer): void
    {
        CustomerDebt::create([
            'customer_id' => $customer->id,
            'order_return_id' => $return->id,
            'ref_code' => $return->code,
            'amount' => -100000,
            'debt_total' => -100000,
            'type' => 'return',
            'note' => 'Return audit test',
            'recorded_at' => now(),
        ]);

        CashFlow::create([
            'code' => 'PC-AUDIT-' . uniqid(),
            'type' => 'payment',
            'amount' => 100000,
            'time' => now(),
            'category' => 'Chi tien tra hang khach',
            'target_type' => 'Khach hang',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'reference_type' => 'OrderReturn',
            'reference_code' => $return->code,
            'description' => 'Paid refund audit test',
            'status' => 'completed',
        ]);
    }

    public function test_dry_run_lists_paid_return_missing_debt_settlement(): void
    {
        $customer = $this->customer();
        $return = $this->paidReturn($customer, 'TH-MISSING-SETTLEMENT');
        $this->addReturnLedger($return, $customer);

        $this->artisan('returns:audit-paid-refund-debt --dry-run')
            ->expectsOutputToContain('TH-MISSING-SETTLEMENT')
            ->expectsOutputToContain('100000')
            ->assertExitCode(0);
    }

    public function test_dry_run_ignores_return_with_existing_settlement(): void
    {
        $customer = $this->customer();
        $return = $this->paidReturn($customer, 'TH-HAS-SETTLEMENT');
        $this->addReturnLedger($return, $customer);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'order_return_id' => $return->id,
            'ref_code' => $return->code,
            'amount' => 100000,
            'debt_total' => 0,
            'type' => 'adjustment',
            'note' => 'Existing paid refund settlement',
            'recorded_at' => now(),
        ]);

        $this->artisan('returns:audit-paid-refund-debt --dry-run')
            ->expectsOutput('No paid returns with missing refund debt settlement were found.')
            ->assertExitCode(0);
    }

    public function test_dry_run_does_not_update_data(): void
    {
        $customer = $this->customer();
        $return = $this->paidReturn($customer, 'TH-DRY-RUN-ONLY');
        $this->addReturnLedger($return, $customer);

        $debtBefore = (float) $customer->fresh()->debt_amount;
        $rowsBefore = CustomerDebt::count();

        $this->artisan('returns:audit-paid-refund-debt --dry-run --code=TH-DRY-RUN-ONLY')
            ->assertExitCode(0);

        $this->assertSame($debtBefore, (float) $customer->fresh()->debt_amount);
        $this->assertSame($rowsBefore, CustomerDebt::count());
    }

    public function test_dry_run_ignores_cancelled_paid_return(): void
    {
        $customer = $this->customer();
        $return = $this->paidReturn($customer, 'TH-CANCELLED-MISSING');
        $return->update(['status' => 'cancelled']);
        $this->addReturnLedger($return, $customer);

        $this->artisan('returns:audit-paid-refund-debt --dry-run')
            ->expectsOutput('No paid returns with missing refund debt settlement were found.')
            ->assertExitCode(0);
    }
}
