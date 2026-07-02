<?php

namespace Tests\Feature\Customers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Invoice;
use App\Services\PartnerDebtLedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DebtAdjustmentTimelineDisplayTest extends TestCase
{
    use DatabaseTransactions;

    public function test_anh_bay_debt_adjustment_cashflow_is_displayed_without_writing_db(): void
    {
        [$customer, $invoice, $cashFlow] = $this->anhBayFixtures();
        $before = $this->dbCounts();

        $ledger = app(PartnerDebtLedgerService::class)->buildCustomerNetLedger($customer);
        $entries = collect($ledger['entries']);

        $invoiceEntry = $entries->firstWhere('code', $invoice->code);
        $cashFlowEntry = $entries->firstWhere('code', $cashFlow->code);

        $this->assertNotNull($invoiceEntry);
        $this->assertSame(15000000.0, (float) $invoiceEntry['display_effect']);

        $this->assertNotNull($cashFlowEntry);
        $this->assertSame('debt_adjustment', $cashFlowEntry['type']);
        $this->assertSame(-15000000.0, (float) $cashFlowEntry['display_effect']);
        $this->assertSame(-15000000.0, (float) $cashFlowEntry['customer_display_effect']);
        $this->assertSame(-15000000.0, (float) $cashFlowEntry['customer_display_balance_effect']);
        $this->assertSame(0.0, (float) $cashFlowEntry['customer_balance_effect']);
        $this->assertFalse((bool) $cashFlowEntry['affects_debt_balance']);
        $this->assertTrue((bool) $cashFlowEntry['is_virtual_display_adjustment']);
        $this->assertTrue((bool) $cashFlowEntry['is_debt_adjustment_cashflow']);

        $this->assertSame(0.0, (float) $ledger['summary']['display_balance_final']);
        $this->assertSame(0.0, (float) $customer->debt_amount);
        $this->assertSame($before, $this->dbCounts());
    }

    public function test_debt_adjustment_cashflow_is_not_duplicated_when_customer_debt_represents_it(): void
    {
        [$customer, , $cashFlow] = $this->anhBayFixtures();

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => $cashFlow->code,
            'amount' => -15000000,
            'debt_total' => 0,
            'type' => 'payment',
            'note' => 'Existing ledger for ' . $cashFlow->code,
            'recorded_at' => Carbon::parse('2026-04-22 15:16:00'),
        ]);

        $ledger = app(PartnerDebtLedgerService::class)->buildCustomerNetLedger($customer);
        $virtualEntries = collect($ledger['entries'])
            ->filter(fn (array $entry) => (bool) ($entry['is_virtual_display_adjustment'] ?? false));

        $this->assertCount(0, $virtualEntries);
    }

    public function test_regular_receipt_is_not_marked_as_debt_adjustment(): void
    {
        $customer = $this->customer('REGULAR');

        CashFlow::create([
            'code' => 'PT-REGULAR-' . uniqid(),
            'type' => 'receipt',
            'target_type' => 'Khach hang',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'amount' => 1000000,
            'time' => Carbon::parse('2026-04-22 15:16:00'),
            'created_at' => Carbon::parse('2026-04-22 08:16:00'),
            'reference_type' => 'ManualPayment',
            'reference_code' => null,
            'status' => 'active',
            'payment_method' => 'cash',
            'description' => 'Regular customer receipt',
        ]);

        $ledger = app(PartnerDebtLedgerService::class)->buildCustomerNetLedger($customer);

        $this->assertFalse(collect($ledger['entries'])->contains(
            fn (array $entry) => (string) ($entry['type'] ?? '') === 'debt_adjustment'
        ));
    }

    public function test_cancelled_debt_adjustment_cashflow_is_not_displayed_as_virtual_adjustment(): void
    {
        [$customer, , $cashFlow] = $this->anhBayFixtures('CANCELLED');
        $cashFlow->status = 'cancelled';
        $cashFlow->save();

        $ledger = app(PartnerDebtLedgerService::class)->buildCustomerNetLedger($customer);

        $this->assertFalse(collect($ledger['entries'])->contains(
            fn (array $entry) => (bool) ($entry['is_virtual_display_adjustment'] ?? false)
        ));
    }

    public function test_other_customer_debt_adjustment_cashflow_is_not_displayed(): void
    {
        [$customer, , $cashFlow] = $this->anhBayFixtures('OTHER');
        $otherCustomer = $this->customer('OTHER-TARGET');
        $cashFlow->target_id = $otherCustomer->id;
        $cashFlow->save();

        $ledger = app(PartnerDebtLedgerService::class)->buildCustomerNetLedger($customer);

        $this->assertFalse(collect($ledger['entries'])->contains(
            fn (array $entry) => (string) ($entry['code'] ?? '') === (string) $cashFlow->code
        ));
    }

    private function anhBayFixtures(string $suffix = 'ANH-BAY'): array
    {
        $customer = $this->customer($suffix);

        $invoice = Invoice::create([
            'code' => 'HD177598589311-' . uniqid(),
            'customer_id' => $customer->id,
            'status' => 'Hoan thanh',
            'created_at' => Carbon::parse('2026-03-27 15:09:00'),
            'transaction_date' => null,
            'total' => 15000000,
            'customer_paid' => 0,
            'debt_amount' => 15000000,
            'payment_status' => 'unpaid',
            'note' => 'DebtAdjustment display test invoice',
        ]);

        $cashFlow = CashFlow::create([
            'code' => 'PT26042215161822-' . uniqid(),
            'type' => 'receipt',
            'target_type' => 'Khach hang',
            'target_id' => $customer->id,
            'target_name' => $customer->name,
            'amount' => 15000000,
            'time' => Carbon::parse('2026-04-22 15:16:00'),
            'created_at' => Carbon::parse('2026-04-22 08:16:00'),
            'reference_type' => 'DebtAdjustment',
            'reference_code' => null,
            'status' => 'active',
            'payment_method' => 'cash',
            'description' => 'Dieu chinh cong no | 15,000,000 -> 0',
        ]);

        return [$customer, $invoice, $cashFlow];
    }

    private function customer(string $suffix): Customer
    {
        return Customer::create([
            'code' => 'KH177460073148-' . strtoupper($suffix) . '-' . uniqid(),
            'name' => 'Anh Bay',
            'phone' => '09' . random_int(10000000, 99999999),
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ]);
    }

    private function dbCounts(): array
    {
        return [
            'customer_debts' => CustomerDebt::count(),
            'cash_flows' => CashFlow::count(),
            'invoices' => Invoice::count(),
        ];
    }
}
