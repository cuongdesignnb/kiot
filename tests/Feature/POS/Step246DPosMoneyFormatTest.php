<?php

namespace Tests\Feature\POS;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use App\Models\Role;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;

/**
 * HOTFIX 24.6D — POS VND money format guard.
 *
 * Frontend now uses <MoneyInput> + formatVND helpers, but the network
 * contract MUST keep numeric values: subtotal/discount/total/customer_paid
 * /price/paid_to_customer all stay as numbers, never "505.000đ" strings.
 * These tests guard the backend contract.
 */
class Step246DPosMoneyFormatTest extends TestCase
{
    use DatabaseTransactions;

    private function adminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin246d'], [
            'display_name' => 'Admin',
            'permissions'  => ['*'],
            'is_system'    => true,
        ]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    private function makeProduct(int $stock = 10, float $cost = 100000): Product
    {
        $cat = Category::firstOrCreate(['name' => 'Cat 246D']);
        return Product::create([
            'sku'                  => 'SP-246D-' . uniqid(),
            'name'                 => 'Sản phẩm 246D',
            'cost_price'           => $cost,
            'retail_price'         => $cost * 2,
            'stock_quantity'       => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active'            => true,
            'has_serial'           => false,
            'category_id'          => $cat->id,
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'code'        => 'KH-246D-' . uniqid(),
            'name'        => 'KH 246D',
            'phone'       => '090' . rand(1000000, 9999999),
            'is_customer' => true,
        ]);
    }

    public function test_pos_checkout_still_accepts_numeric_money_payload(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(10, 100000);

        $res = $this->actingAs($admin)->postJson('/api/pos/checkout', [
            'customer_id'    => $customer->id,
            'subtotal'       => 210000,    // pure number
            'discount'       => 0,
            'total'          => 210000,
            'customer_paid'  => 210000,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 210000,
                'discount'   => 0,
            ]],
        ]);

        $res->assertOk();
        $res->assertJsonPath('success', true);

        $invoice = Invoice::where('customer_id', $customer->id)->latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(210000.0, (float) $invoice->total);
        $this->assertEquals(210000.0, (float) $invoice->subtotal);
    }

    public function test_pos_checkout_rejects_formatted_money_strings(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(10, 100000);

        // Backend validation requires numeric — string "210.000đ" must fail.
        $res = $this->actingAs($admin)->postJson('/api/pos/checkout', [
            'customer_id'    => $customer->id,
            'subtotal'       => '210.000đ',
            'discount'       => 0,
            'total'          => '210.000đ',
            'customer_paid'  => '210.000đ',
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => '210.000đ',
                'discount'   => 0,
            ]],
        ]);

        $res->assertStatus(422);
    }

    public function test_pos_quick_order_still_accepts_numeric_money_payload(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(10, 100000);

        $res = $this->actingAs($admin)->postJson('/api/pos/quick-order', [
            'customer_id' => $customer->id,
            'subtotal'    => 505000,
            'discount'    => 0,
            'total'       => 505000,
            'items'       => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 505000,
                'discount'   => 0,
            ]],
        ]);

        $res->assertOk();
    }

    public function test_pos_return_payload_still_numeric(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(10, 100000);

        // Sell first so we have something to return.
        $this->actingAs($admin)->postJson('/api/pos/checkout', [
            'customer_id'    => $customer->id,
            'subtotal'       => 200000,
            'discount'       => 0,
            'total'          => 200000,
            'customer_paid'  => 200000,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 200000,
                'discount'   => 0,
            ]],
        ])->assertOk();

        $invoice = Invoice::where('customer_id', $customer->id)->latest('id')->first();
        $invoiceItem = $invoice->items()->first();

        $res = $this->actingAs($admin)->post(route('returns.store'), [
            'invoice_id'       => $invoice->id,
            'customer_id'      => $customer->id,
            'subtotal'         => 200000,        // numeric
            'discount'         => 0,
            'fee'              => 5000,          // numeric
            'total'            => 195000,
            'paid_to_customer' => 195000,        // numeric
            'note'             => null,
            'items'            => [[
                'product_id'      => $product->id,
                'invoice_item_id' => $invoiceItem->id,
                'qty'             => 1,
                'price'           => 200000,    // numeric
                'discount'        => 0,
                'serial_ids'      => [],
            ]],
        ]);

        $res->assertRedirect();
        $return = OrderReturn::where('invoice_id', $invoice->id)->latest('id')->first();
        $this->assertNotNull($return);
        $this->assertEquals(195000.0, (float) $return->total);
        $this->assertEquals(195000.0, (float) $return->paid_to_customer);
    }
}
