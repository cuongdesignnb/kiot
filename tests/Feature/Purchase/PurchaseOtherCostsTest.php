<?php

namespace Tests\Feature\Purchase;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Models\SerialImei;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseOtherCostsTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin Test Other Costs',
            'email'    => 'admin-other-costs-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function supplier(): Customer
    {
        return Customer::create([
            'code'                 => 'NCC-OC-' . uniqid(),
            'name'                 => 'Supplier Test OC',
            'phone'                => '09' . random_int(10000000, 99999999),
            'is_customer'          => false,
            'is_supplier'          => true,
            'status'               => 'active',
            'debt_amount'          => 0,
            'supplier_debt_amount' => 0,
            'total_bought'         => 0,
        ]);
    }

    private function product(string $name, int $cost, bool $hasSerial = false): Product
    {
        return Product::create([
            'sku'                  => 'SKU-OC-' . uniqid(),
            'name'                 => $name,
            'cost_price'           => $cost,
            'retail_price'         => $cost * 2,
            'stock_quantity'       => 0,
            'inventory_total_cost' => 0,
            'has_serial'           => $hasSerial,
            'is_active'            => true,
        ]);
    }

    // Test 1: Tạo phiếu nhập có chi phí nhập khác tính đúng phân bổ
    public function test_purchase_store_with_other_costs_allocates_correctly(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $prod = $this->product('Product Regular', 32000);

        $payload = [
            'supplier_id'   => $sup->id,
            'discount'      => 0,
            'paid_amount'   => 0,
            'note'          => 'Test OC allocation',
            'status'        => 'completed',
            'purchase_date' => now()->toDateTimeString(),
            'other_costs'   => [
                ['name' => 'Ship hàng', 'amount' => 40000]
            ],
            'items'         => [[
                'product_id' => $prod->id,
                'quantity'   => 100,
                'price'      => 32000,
                'discount'   => 0,
            ]],
            'payment_method' => 'cash',
        ];

        $res = $this->actingAs($admin)->postJson('/purchases', $payload);
        $res->assertRedirect(); // purchase creation redirects on success

        $purchase = Purchase::where('supplier_id', $sup->id)->orderByDesc('id')->first();
        $this->assertNotNull($purchase);
        $this->assertEquals(3200000, (int)$purchase->total_amount);
        $this->assertEquals(40000, (int)$purchase->other_costs_total);
        $this->assertEquals(3240000, (int)$purchase->debt_amount);

        $item = PurchaseItem::where('purchase_id', $purchase->id)->first();
        $this->assertNotNull($item);
        // Allocation: (3,200,000 + 40,000) / 100 = 32,400
        $this->assertEquals(32400, (int)$item->unit_cost_allocated);

        // Product stock and cost price check
        $prod->refresh();
        $this->assertEquals(100, $prod->stock_quantity);
        $this->assertEquals(32400, (int)$prod->cost_price);
    }

    // Test 2: Trả tiền NCC có cộng phí nhập
    public function test_purchase_store_paid_amount_with_other_costs(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $prod = $this->product('Product Paid OC', 32000);

        $payload = [
            'supplier_id'   => $sup->id,
            'discount'      => 0,
            'paid_amount'   => 1000000,
            'note'          => 'Test paid with OC',
            'status'        => 'completed',
            'purchase_date' => now()->toDateTimeString(),
            'other_costs'   => [
                ['name' => 'Ship hàng', 'amount' => 40000]
            ],
            'items'         => [[
                'product_id' => $prod->id,
                'quantity'   => 100,
                'price'      => 32000,
                'discount'   => 0,
            ]],
            'payment_method' => 'cash',
        ];

        $res = $this->actingAs($admin)->postJson('/purchases', $payload);
        $res->assertRedirect();

        $purchase = Purchase::where('supplier_id', $sup->id)->orderByDesc('id')->first();
        $this->assertNotNull($purchase);
        // debt = (3200000 - 0 + 40000) - 1000000 = 2240000
        $this->assertEquals(2240000, (int)$purchase->debt_amount);

        $sup->refresh();
        $this->assertEquals(2240000, (int)$sup->supplier_debt_amount);
    }

    // Test 3: Không gửi other_costs thì không đổi hành vi cũ
    public function test_purchase_store_no_other_costs(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $prod = $this->product('Product No OC', 32000);

        $payload = [
            'supplier_id'   => $sup->id,
            'discount'      => 0,
            'paid_amount'   => 0,
            'status'        => 'completed',
            'purchase_date' => now()->toDateTimeString(),
            'items'         => [[
                'product_id' => $prod->id,
                'quantity'   => 100,
                'price'      => 32000,
                'discount'   => 0,
            ]],
            'payment_method' => 'cash',
        ];

        $res = $this->actingAs($admin)->postJson('/purchases', $payload);
        $res->assertRedirect();

        $purchase = Purchase::where('supplier_id', $sup->id)->orderByDesc('id')->first();
        $this->assertEquals(0, (int)$purchase->other_costs_total);
        $this->assertEquals(3200000, (int)$purchase->debt_amount);

        $item = PurchaseItem::where('purchase_id', $purchase->id)->first();
        $this->assertEquals(32000, (int)$item->unit_cost_allocated);
    }

    // Test 4: Backend bỏ qua dòng other_costs rỗng tiền (0)
    public function test_purchase_store_filters_invalid_other_costs(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $prod = $this->product('Product Filter OC', 32000);

        $payload = [
            'supplier_id'   => $sup->id,
            'discount'      => 0,
            'paid_amount'   => 0,
            'status'        => 'completed',
            'purchase_date' => now()->toDateTimeString(),
            'other_costs'   => [
                ['name' => 'Ship hàng', 'amount' => 0],    // invalid: zero amount
                ['name' => 'Bốc vác', 'amount' => 10000]    // valid
            ],
            'items'         => [[
                'product_id' => $prod->id,
                'quantity'   => 10,
                'price'      => 32000,
                'discount'   => 0,
            ]],
            'payment_method' => 'cash',
        ];

        $res = $this->actingAs($admin)->postJson('/purchases', $payload);
        $res->assertRedirect();

        $purchase = Purchase::where('supplier_id', $sup->id)->orderByDesc('id')->first();
        $this->assertNotNull($purchase);
        $this->assertEquals(10000, (int)$purchase->other_costs_total);
        
        $otherCostsSaved = $purchase->other_costs;
        $this->assertCount(1, $otherCostsSaved);
        $this->assertEquals('Bốc vác', $otherCostsSaved[0]['name']);
        $this->assertEquals(10000, (int)$otherCostsSaved[0]['amount']);
    }

    // Test 5: Validation fails when name is empty
    public function test_purchase_store_validation_fails_with_empty_cost_name(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $prod = $this->product('Product Val Fail', 32000);

        $payload = [
            'supplier_id'   => $sup->id,
            'discount'      => 0,
            'paid_amount'   => 0,
            'status'        => 'completed',
            'purchase_date' => now()->toDateTimeString(),
            'other_costs'   => [
                ['name' => '', 'amount' => 40000],
            ],
            'items'         => [[
                'product_id' => $prod->id,
                'quantity'   => 10,
                'price'      => 32000,
                'discount'   => 0,
            ]],
            'payment_method' => 'cash',
        ];

        $res = $this->actingAs($admin)->postJson('/purchases', $payload);
        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['other_costs.0.name']);
    }

    // Test 6: Serial product cost được phân bổ đúng
    public function test_purchase_store_serial_product_cost_allocation(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $prod = $this->product('Serial Product', 1000000, true); // has serial

        $payload = [
            'supplier_id'   => $sup->id,
            'discount'      => 0,
            'paid_amount'   => 0,
            'status'        => 'completed',
            'purchase_date' => now()->toDateTimeString(),
            'other_costs'   => [
                ['name' => 'Vận chuyển', 'amount' => 100000]
            ],
            'items'         => [[
                'product_id' => $prod->id,
                'quantity'   => 2,
                'price'      => 1000000,
                'discount'   => 0,
                'serials'    => ['SN1', 'SN2']
            ]],
            'payment_method' => 'cash',
        ];

        $res = $this->actingAs($admin)->postJson('/purchases', $payload);
        $res->assertRedirect();

        $purchase = Purchase::where('supplier_id', $sup->id)->orderByDesc('id')->first();
        $item = PurchaseItem::where('purchase_id', $purchase->id)->first();
        // Allocated unit cost: (2,000,000 + 100,000) / 2 = 1,050,000
        $this->assertEquals(1050000, (int)$item->unit_cost_allocated);

        // Check SerialImei cost_price
        $serials = SerialImei::where('purchase_id', $purchase->id)->get();
        $this->assertCount(2, $serials);
        foreach ($serials as $serial) {
            $this->assertEquals(1050000, (int)$serial->cost_price);
            $this->assertEquals(1050000, (int)$serial->original_cost);
        }
    }
}
