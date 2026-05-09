<?php

namespace Tests\Feature\OrderReturn;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\CashFlow;
use App\Models\Product;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\SerialImei;
use App\Services\ReturnTotalCalculator;

/**
 * STEP 24.6E — Return fee VND/percent.
 *
 * Backend is the single source of truth: total_refund is recomputed from
 * (subtotal − discount − fee_amount), where fee_amount is resolved from
 * (fee_type, fee_value). Frontend `total` is intentionally ignored.
 */
class Step246EReturnFeeTypeTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 246E',
            'email'    => 'admin-246e-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null, // null role_id => isAdmin() = true (passes all permission gates)
        ]);
    }

    private function makeProduct(bool $hasSerial = false, int $stock = 10, float $cost = 1000000): Product
    {
        $cat = Category::firstOrCreate(['name' => 'Cat 246E']);
        return Product::create([
            'sku'                  => 'P246E-' . uniqid(),
            'name'                 => 'Product 24.6E',
            'cost_price'           => $cost,
            'retail_price'         => $cost * 2,
            'stock_quantity'       => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active'            => true,
            'has_serial'           => $hasSerial,
            'category_id'          => $cat->id,
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'code'        => 'KH-246E-' . uniqid(),
            'name'        => 'KH 246E',
            'phone'       => '090' . rand(1000000, 9999999),
            'is_customer' => true,
            'debt_amount' => 0,
            'total_spent' => 0,
        ]);
    }

    /** Helper: sell normal product so we have an invoice + invoice_item to return against. */
    private function sellNormal(User $admin, Customer $customer, Product $product, int $qty, float $price, float $paid): Invoice
    {
        $this->actingAs($admin)->post(route('invoices.store'), [
            'customer_id'    => $customer->id,
            'subtotal'       => $qty * $price,
            'discount'       => 0,
            'total'          => $qty * $price,
            'customer_paid'  => $paid,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => $qty,
                'price'      => $price,
                'discount'   => 0,
            ]],
        ]);
        return Invoice::where('customer_id', $customer->id)->latest('id')->first();
    }

    // ───────── Calculator unit tests (no DB) ─────────

    public function test_calculator_amount_fee_produces_net_total(): void
    {
        $out = (new ReturnTotalCalculator())->calculate([
            'items'     => [['qty' => 1, 'price' => 7000000, 'discount' => 0]],
            'fee_type'  => 'amount',
            'fee_value' => 700000,
        ]);
        $this->assertEquals(7000000.0, $out['subtotal']);
        $this->assertEquals(700000.0,  $out['fee_amount']);
        $this->assertEquals(6300000.0, $out['total_refund']);
    }

    public function test_calculator_percent_fee_produces_net_total(): void
    {
        $out = (new ReturnTotalCalculator())->calculate([
            'items'     => [['qty' => 1, 'price' => 7000000, 'discount' => 0]],
            'fee_type'  => 'percent',
            'fee_value' => 10,
        ]);
        $this->assertEquals(700000.0,  $out['fee_amount']);
        $this->assertEquals(6300000.0, $out['total_refund']);
    }

    public function test_calculator_legacy_payload_without_fee_type_treats_as_amount(): void
    {
        $out = (new ReturnTotalCalculator())->calculate([
            'items'    => [['qty' => 1, 'price' => 7000000, 'discount' => 0]],
            'fee'      => 700000, // legacy callers
        ]);
        $this->assertEquals('amount', $out['fee_type']);
        $this->assertEquals(700000.0, $out['fee_amount']);
        $this->assertEquals(6300000.0, $out['total_refund']);
    }

    public function test_calculator_percent_over_100_fails(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        (new ReturnTotalCalculator())->calculate([
            'items'     => [['qty' => 1, 'price' => 7000000]],
            'fee_type'  => 'percent',
            'fee_value' => 120,
        ]);
    }

    public function test_calculator_paid_exceeding_total_fails(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        (new ReturnTotalCalculator())->calculate([
            'items'            => [['qty' => 1, 'price' => 7000000]],
            'fee_type'         => 'percent',
            'fee_value'        => 10,
            'paid_to_customer' => 7000000, // > 6_300_000
        ]);
    }

    // ───────── Integration tests via POST /returns ─────────

    public function test_return_fee_amount_calculates_net_total_and_persists(): void
    {
        $admin = $this->admin();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(false, 10, 3500000);
        $invoice = $this->sellNormal($admin, $customer, $product, 1, 7000000, 0);

        $invoiceItem = $invoice->items()->first();

        $this->actingAs($admin)->post(route('returns.store'), [
            'invoice_id'       => $invoice->id,
            'customer_id'      => $customer->id,
            'subtotal'         => 7000000,
            'discount'         => 0,
            'fee_type'         => 'amount',
            'fee_value'        => 700000,
            'total'            => 9999999, // intentionally wrong — backend must ignore
            'paid_to_customer' => 6300000,
            'items'            => [[
                'product_id'      => $product->id,
                'invoice_item_id' => $invoiceItem->id,
                'qty'             => 1,
                'price'           => 7000000,
            ]],
        ])->assertRedirect();

        $return = OrderReturn::where('invoice_id', $invoice->id)->latest('id')->first();
        $this->assertEquals(700000.0,  (float) $return->fee);
        $this->assertEquals(6300000.0, (float) $return->total);
        $this->assertEquals(6300000.0, (float) $return->paid_to_customer);
        $this->assertEquals('amount',  $return->fee_type);
        $this->assertEquals(700000.0,  (float) $return->fee_value);
    }

    public function test_return_fee_percent_calculates_fee_and_net_and_persists(): void
    {
        $admin = $this->admin();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(false, 10, 3500000);
        $invoice = $this->sellNormal($admin, $customer, $product, 1, 7000000, 0);
        $invoiceItem = $invoice->items()->first();

        $this->actingAs($admin)->post(route('returns.store'), [
            'invoice_id'       => $invoice->id,
            'customer_id'      => $customer->id,
            'subtotal'         => 7000000,
            'fee_type'         => 'percent',
            'fee_value'        => 10,
            'total'            => 0, // wrong on purpose
            'paid_to_customer' => 6300000,
            'items'            => [[
                'product_id'      => $product->id,
                'invoice_item_id' => $invoiceItem->id,
                'qty'             => 1,
                'price'           => 7000000,
            ]],
        ])->assertRedirect();

        $return = OrderReturn::where('invoice_id', $invoice->id)->latest('id')->first();
        $this->assertEquals('percent',  $return->fee_type);
        $this->assertEquals(10.0,       (float) $return->fee_value);
        $this->assertEquals(700000.0,   (float) $return->fee);
        $this->assertEquals(6300000.0,  (float) $return->total);
    }

    public function test_return_fee_percent_over_100_is_rejected(): void
    {
        $admin = $this->admin();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(false, 10, 3500000);
        $invoice = $this->sellNormal($admin, $customer, $product, 1, 7000000, 0);
        $invoiceItem = $invoice->items()->first();

        $before = (float) $product->fresh()->stock_quantity;

        $this->actingAs($admin)
            ->from(route('invoices.index'))
            ->post(route('returns.store'), [
                'invoice_id'  => $invoice->id,
                'customer_id' => $customer->id,
                'subtotal'    => 7000000,
                'fee_type'    => 'percent',
                'fee_value'   => 120,
                'total'       => 0,
                'items'       => [[
                    'product_id'      => $product->id,
                    'invoice_item_id' => $invoiceItem->id,
                    'qty'             => 1,
                    'price'           => 7000000,
                ]],
            ])->assertSessionHasErrors('fee_value');

        $this->assertNull(OrderReturn::where('invoice_id', $invoice->id)->first());
        $this->assertEquals($before, (float) $product->fresh()->stock_quantity);
    }

    public function test_paid_to_customer_cannot_exceed_net_total(): void
    {
        $admin = $this->admin();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(false, 10, 3500000);
        $invoice = $this->sellNormal($admin, $customer, $product, 1, 7000000, 0);
        $invoiceItem = $invoice->items()->first();

        $this->actingAs($admin)
            ->from(route('invoices.index'))
            ->post(route('returns.store'), [
                'invoice_id'       => $invoice->id,
                'customer_id'      => $customer->id,
                'subtotal'         => 7000000,
                'fee_type'         => 'percent',
                'fee_value'        => 10,
                'total'            => 0,
                'paid_to_customer' => 7000000, // > net 6_300_000
                'items'            => [[
                    'product_id'      => $product->id,
                    'invoice_item_id' => $invoiceItem->id,
                    'qty'             => 1,
                    'price'           => 7000000,
                ]],
            ])->assertSessionHasErrors('paid_to_customer');

        $this->assertNull(OrderReturn::where('invoice_id', $invoice->id)->first());
    }

    public function test_return_fee_percent_reduces_customer_debt_by_net_total(): void
    {
        $admin = $this->admin();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(false, 10, 3500000);
        // Sell on credit (paid 0) so debt = 7,000,000.
        $invoice = $this->sellNormal($admin, $customer, $product, 1, 7000000, 0);
        $this->assertEquals(7000000.0, (float) $customer->fresh()->debt_amount);

        $invoiceItem = $invoice->items()->first();
        $this->actingAs($admin)->post(route('returns.store'), [
            'invoice_id'       => $invoice->id,
            'customer_id'      => $customer->id,
            'subtotal'         => 7000000,
            'fee_type'         => 'percent',
            'fee_value'        => 10,
            'total'            => 0,
            'paid_to_customer' => 0, // debit-only return; no cash out
            'items'            => [[
                'product_id'      => $product->id,
                'invoice_item_id' => $invoiceItem->id,
                'qty'             => 1,
                'price'           => 7000000,
            ]],
        ])->assertRedirect();

        // Debt after must reflect the NET refund (6.3M), not the gross subtotal (7M).
        $this->assertEquals(700000.0, (float) $customer->fresh()->debt_amount);
        $this->assertTrue(CustomerDebt::where('customer_id', $customer->id)
            ->where('type', 'return')
            ->where('amount', -6300000)
            ->exists());
    }

    public function test_return_fee_percent_cashflow_uses_paid_to_customer(): void
    {
        $admin = $this->admin();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(false, 10, 3500000);
        $invoice = $this->sellNormal($admin, $customer, $product, 1, 7000000, 7000000); // fully paid
        $invoiceItem = $invoice->items()->first();

        $this->actingAs($admin)->post(route('returns.store'), [
            'invoice_id'       => $invoice->id,
            'customer_id'      => $customer->id,
            'subtotal'         => 7000000,
            'fee_type'         => 'percent',
            'fee_value'        => 10,
            'total'            => 0,
            'paid_to_customer' => 6300000,
            'items'            => [[
                'product_id'      => $product->id,
                'invoice_item_id' => $invoiceItem->id,
                'qty'             => 1,
                'price'           => 7000000,
            ]],
        ])->assertRedirect();

        $return = OrderReturn::where('invoice_id', $invoice->id)->latest('id')->first();
        $cf = CashFlow::where('reference_type', 'OrderReturn')
            ->where('reference_code', $return->code)
            ->first();
        $this->assertNotNull($cf);
        $this->assertEquals(6300000.0, (float) $cf->amount);
    }

    public function test_legacy_return_without_fee_type_treated_as_amount(): void
    {
        $admin = $this->admin();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(false, 10, 3500000);
        $invoice = $this->sellNormal($admin, $customer, $product, 1, 7000000, 0);
        $invoiceItem = $invoice->items()->first();

        // Old-style payload: just `fee` numeric, no fee_type/fee_value.
        $this->actingAs($admin)->post(route('returns.store'), [
            'invoice_id'  => $invoice->id,
            'customer_id' => $customer->id,
            'subtotal'    => 7000000,
            'fee'         => 700000,
            'total'       => 6300000,
            'items'       => [[
                'product_id'      => $product->id,
                'invoice_item_id' => $invoiceItem->id,
                'qty'             => 1,
                'price'           => 7000000,
            ]],
        ])->assertRedirect();

        $return = OrderReturn::where('invoice_id', $invoice->id)->latest('id')->first();
        $this->assertEquals('amount',   $return->fee_type);
        $this->assertEquals(700000.0,   (float) $return->fee_value);
        $this->assertEquals(700000.0,   (float) $return->fee);
        $this->assertEquals(6300000.0,  (float) $return->total);
    }

    /**
     * Step 24.6E-FIX: backend calculator deliberately ignores any `refund_other`
     * field the UI might send. As long as the formula stays
     *   total_refund = subtotal − discount − fee_amount,
     * the persisted total must NOT be inflated by an extra refund_other key.
     * If we ever support refund_other in 24.6F, replace this test.
     */
    public function test_calculator_ignores_unsupported_refund_other_field(): void
    {
        $out = (new ReturnTotalCalculator())->calculate([
            'items'        => [['qty' => 1, 'price' => 7000000, 'discount' => 0]],
            'fee_type'     => 'percent',
            'fee_value'    => 10,
            'refund_other' => 500000, // <- unsupported in scope; must be ignored
        ]);
        // 7M − 0 − 700k = 6.3M. NOT 6.8M.
        $this->assertEquals(6300000.0, $out['total_refund']);
    }

    public function test_cancel_return_with_percent_fee_restores_debt_by_net_total(): void
    {
        $admin = $this->admin();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(false, 10, 3500000);
        $invoice = $this->sellNormal($admin, $customer, $product, 1, 7000000, 0);
        $invoiceItem = $invoice->items()->first();

        $this->actingAs($admin)->post(route('returns.store'), [
            'invoice_id'       => $invoice->id,
            'customer_id'      => $customer->id,
            'subtotal'         => 7000000,
            'fee_type'         => 'percent',
            'fee_value'        => 10,
            'total'            => 0,
            'paid_to_customer' => 0,
            'items'            => [[
                'product_id'      => $product->id,
                'invoice_item_id' => $invoiceItem->id,
                'qty'             => 1,
                'price'           => 7000000,
            ]],
        ])->assertRedirect();

        $return = OrderReturn::where('invoice_id', $invoice->id)->latest('id')->first();
        $debtAfterReturn = (float) $customer->fresh()->debt_amount;
        $this->assertEquals(700000.0, $debtAfterReturn);

        // Cancel the return — debt must restore by net total (6.3M), not gross.
        $this->actingAs($admin)->post(route('returns.cancel', $return->id), [
            'reason' => 'Khách đổi ý',
        ])->assertRedirect();

        $this->assertEquals(7000000.0, (float) $customer->fresh()->debt_amount);
    }
}
