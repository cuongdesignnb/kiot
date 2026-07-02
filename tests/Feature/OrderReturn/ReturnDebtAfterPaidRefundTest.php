<?php

namespace Tests\Feature\OrderReturn;

use App\Models\CashFlow;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReturnDebtAfterPaidRefundTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Paid Refund',
            'email' => 'admin-paid-refund-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'KH-PR-' . uniqid(),
            'name' => 'Customer Paid Refund',
            'phone' => '090' . rand(1000000, 9999999),
            'is_customer' => true,
            'debt_amount' => 0,
            'total_spent' => 0,
        ]);
    }

    private function product(int $stock = 5): Product
    {
        $category = Category::firstOrCreate(['name' => 'Paid Refund']);

        return Product::create([
            'sku' => 'PR-' . uniqid(),
            'name' => 'Paid Refund Product',
            'cost_price' => 1000000,
            'retail_price' => 19200000,
            'stock_quantity' => $stock,
            'inventory_total_cost' => $stock * 1000000,
            'is_active' => true,
            'has_serial' => false,
            'category_id' => $category->id,
        ]);
    }

    private function paidInvoice(User $admin, Customer $customer, Product $product, float $price = 19200000): Invoice
    {
        $this->actingAs($admin)->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'subtotal' => $price,
            'discount' => 0,
            'total' => $price,
            'customer_paid' => $price,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => $price,
                'discount' => 0,
            ]],
        ])->assertRedirect();

        return Invoice::where('customer_id', $customer->id)->latest('id')->firstOrFail();
    }

    private function createReturn(User $admin, Customer $customer, Invoice $invoice, float $total, float $paidToCustomer): OrderReturn
    {
        $invoiceItem = $invoice->items()->firstOrFail();

        $this->actingAs($admin)->post(route('returns.store'), [
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'subtotal' => $total,
            'discount' => 0,
            'fee_type' => 'amount',
            'fee_value' => 0,
            'total' => $total,
            'paid_to_customer' => $paidToCustomer,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $invoiceItem->product_id,
                'invoice_item_id' => $invoiceItem->id,
                'qty' => 1,
                'price' => $total,
                'discount' => 0,
            ]],
        ])->assertRedirect();

        return OrderReturn::where('invoice_id', $invoice->id)->latest('id')->firstOrFail();
    }

    public function test_return_fully_refunded_to_customer_does_not_leave_negative_debt(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $invoice = $this->paidInvoice($admin, $customer, $this->product());

        $return = $this->createReturn($admin, $customer, $invoice, 19200000, 19200000);

        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(-19200000.0, (float) CustomerDebt::where('ref_code', $return->code)->where('type', 'return')->sum('amount'));
        $this->assertSame(19200000.0, (float) CustomerDebt::where('ref_code', $return->code)->where('type', 'adjustment')->where('amount', '>', 0)->sum('amount'));
        $this->assertSame(1, CustomerDebt::where('ref_code', $return->code)->where('type', 'adjustment')->where('amount', '>', 0)->count());
        $this->assertSame(19200000.0, (float) CashFlow::where('reference_type', 'OrderReturn')->where('reference_code', $return->code)->where('type', 'payment')->sum('amount'));

        $history = $this->actingAs($admin)->getJson("/customers/{$customer->id}/debt-history");
        $history->assertOk();
        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
    }

    public function test_return_not_refunded_creates_customer_credit(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $invoice = $this->paidInvoice($admin, $customer, $this->product());

        $return = $this->createReturn($admin, $customer, $invoice, 19200000, 0);

        $this->assertSame(-19200000.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(-19200000.0, (float) CustomerDebt::where('ref_code', $return->code)->where('type', 'return')->sum('amount'));
        $this->assertSame(0.0, (float) CustomerDebt::where('ref_code', $return->code)->where('type', 'adjustment')->where('amount', '>', 0)->sum('amount'));
        $this->assertSame(0.0, (float) CashFlow::where('reference_type', 'OrderReturn')->where('reference_code', $return->code)->where('type', 'payment')->sum('amount'));
    }

    public function test_return_partially_refunded_leaves_credit_for_unpaid_refund(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $invoice = $this->paidInvoice($admin, $customer, $this->product());

        $return = $this->createReturn($admin, $customer, $invoice, 19200000, 5000000);

        $this->assertSame(-14200000.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(-19200000.0, (float) CustomerDebt::where('ref_code', $return->code)->where('type', 'return')->sum('amount'));
        $this->assertSame(5000000.0, (float) CustomerDebt::where('ref_code', $return->code)->where('type', 'adjustment')->where('amount', '>', 0)->sum('amount'));
        $this->assertSame(5000000.0, (float) CashFlow::where('reference_type', 'OrderReturn')->where('reference_code', $return->code)->where('type', 'payment')->sum('amount'));
    }

    public function test_return_rejects_paid_to_customer_over_total(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $invoice = $this->paidInvoice($admin, $customer, $this->product());
        $invoiceItem = $invoice->items()->firstOrFail();

        $this->actingAs($admin)
            ->from(route('returns.index'))
            ->post(route('returns.store'), [
                'invoice_id' => $invoice->id,
                'customer_id' => $customer->id,
                'subtotal' => 19200000,
                'total' => 19200000,
                'paid_to_customer' => 20000000,
                'items' => [[
                    'product_id' => $invoiceItem->product_id,
                    'invoice_item_id' => $invoiceItem->id,
                    'qty' => 1,
                    'price' => 19200000,
                ]],
            ])->assertSessionHasErrors('paid_to_customer');

        $this->assertSame(0, OrderReturn::where('invoice_id', $invoice->id)->count());
        $this->assertSame(0, CustomerDebt::where('order_return_id', '>', 0)->count());
        $this->assertSame(0, CashFlow::where('reference_type', 'OrderReturn')->count());
    }

    public function test_return_does_not_double_settle_paid_refund(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $invoice = $this->paidInvoice($admin, $customer, $this->product());

        $return = $this->createReturn($admin, $customer, $invoice, 19200000, 19200000);

        $this->assertSame(1, CustomerDebt::where('ref_code', $return->code)->where('type', 'adjustment')->where('amount', '>', 0)->count());
        $this->assertSame(19200000.0, (float) CustomerDebt::where('ref_code', $return->code)->where('type', 'adjustment')->where('amount', '>', 0)->sum('amount'));
    }

    public function test_cancel_paid_return_restores_original_customer_debt(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $invoice = $this->paidInvoice($admin, $customer, $this->product());
        $return = $this->createReturn($admin, $customer, $invoice, 19200000, 19200000);

        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);

        $this->actingAs($admin)->post(route('returns.cancel', $return->id), [
            'reason' => 'Cancel paid return test',
        ])->assertRedirect();

        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(-19200000.0, (float) CustomerDebt::where('ref_code', $return->code)->where('type', 'adjustment')->where('amount', '<', 0)->sum('amount'));
    }

    public function test_cancel_old_paid_return_without_settlement_does_not_leave_negative_debt(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $invoice = $this->paidInvoice($admin, $customer, $this->product());
        $return = $this->createReturn($admin, $customer, $invoice, 19200000, 19200000);

        CustomerDebt::where('ref_code', $return->code)
            ->where('type', 'adjustment')
            ->where('amount', '>', 0)
            ->delete();
        $customer->forceFill(['debt_amount' => -19200000])->save();

        $this->actingAs($admin)->post(route('returns.cancel', $return->id), [
            'reason' => 'Cancel legacy paid return',
        ])->assertRedirect();

        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(0.0, (float) CustomerDebt::where('ref_code', $return->code)
            ->where('type', 'adjustment')
            ->where('amount', '<', 0)
            ->sum('amount'));
    }

    public function test_cancel_new_paid_return_with_settlement_reverses_existing_settlement_once(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $invoice = $this->paidInvoice($admin, $customer, $this->product());
        $return = $this->createReturn($admin, $customer, $invoice, 19200000, 19200000);

        $this->actingAs($admin)->post(route('returns.cancel', $return->id), [
            'reason' => 'Cancel new paid return',
        ])->assertRedirect();

        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(-19200000.0, (float) CustomerDebt::where('ref_code', $return->code)
            ->where('type', 'adjustment')
            ->where('amount', '<', 0)
            ->sum('amount'));
        $this->assertSame(1, CustomerDebt::where('ref_code', $return->code)
            ->where('type', 'adjustment')
            ->where('amount', '<', 0)
            ->count());
    }

    public function test_cancel_partially_refunded_return_reverses_only_existing_settlement_amount(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $invoice = $this->paidInvoice($admin, $customer, $this->product());
        $return = $this->createReturn($admin, $customer, $invoice, 19200000, 5000000);

        $this->assertSame(-14200000.0, (float) $customer->fresh()->debt_amount);

        $this->actingAs($admin)->post(route('returns.cancel', $return->id), [
            'reason' => 'Cancel partial refund return',
        ])->assertRedirect();

        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(-5000000.0, (float) CustomerDebt::where('ref_code', $return->code)
            ->where('type', 'adjustment')
            ->where('amount', '<', 0)
            ->sum('amount'));
    }

    public function test_cancel_return_blocks_second_cancel_without_extra_ledger(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $invoice = $this->paidInvoice($admin, $customer, $this->product());
        $return = $this->createReturn($admin, $customer, $invoice, 19200000, 19200000);

        $this->actingAs($admin)->post(route('returns.cancel', $return->id), [
            'reason' => 'First cancel',
        ])->assertRedirect();

        $ledgerCountAfterFirstCancel = CustomerDebt::where('ref_code', $return->code)->count();
        $debtAfterFirstCancel = (float) $customer->fresh()->debt_amount;

        $this->actingAs($admin)->post(route('returns.cancel', $return->id), [
            'reason' => 'Second cancel',
        ])->assertRedirect();

        $this->assertSame($ledgerCountAfterFirstCancel, CustomerDebt::where('ref_code', $return->code)->count());
        $this->assertSame($debtAfterFirstCancel, (float) $customer->fresh()->debt_amount);
    }
}
