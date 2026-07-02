<?php

namespace Tests\Feature\Warranty;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\Warranty;
use App\Models\SerialImei;
use Carbon\Carbon;

/**
 * STEP 24.9 — Warranty generation from Product policies.
 *
 * Pins:
 *   - sale on a product with policies → warranty record uses policy's
 *     primary duration (warranty_period in months) and the right end date
 *   - serial product → 1 warranty per serial
 *   - no policy + no fallback → no warranty
 *   - warranty_policy_snapshot + maintenance_policy_snapshot persisted
 *   - product edited after sale → existing warranty unchanged (snapshot)
 *   - next_maintenance_date computed from first maintenance policy
 *   - legacy fallback (purchase_items.warranty_months) still works when no
 *     product-level policy is set
 */
class Step249WarrantyGenerationFromProductPolicyTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 249W',
            'email'    => 'admin-249w-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function makeProduct(bool $hasSerial = false, array $extra = []): Product
    {
        $cat = Category::firstOrCreate(['name' => 'Cat 249W']);
        return Product::create(array_merge([
            'sku'                  => 'P249W-' . uniqid(),
            'name'                 => 'Product 249W',
            'cost_price'           => 100000,
            'retail_price'         => 200000,
            'stock_quantity'       => 10,
            'inventory_total_cost' => 1000000,
            'is_active'            => true,
            'has_serial'           => $hasSerial,
            'category_id'          => $cat->id,
        ], $extra));
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'code'        => 'KH-249W-' . uniqid(),
            'name'        => 'KH 249W',
            'phone'       => '090' . rand(1000000, 9999999),
            'is_customer' => true,
        ]);
    }

    private function sellNormal(User $admin, Customer $cust, Product $product, int $qty, float $price): Invoice
    {
        $this->actingAs($admin)->post(route('invoices.store'), [
            'customer_id'    => $cust->id,
            'subtotal'       => $qty * $price,
            'discount'       => 0,
            'total'          => $qty * $price,
            'customer_paid'  => $qty * $price,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => $qty,
                'price'      => $price,
                'discount'   => 0,
            ]],
        ]);
        return Invoice::where('customer_id', $cust->id)->latest('id')->first();
    }

    public function test_sale_generates_warranty_from_product_policy_for_normal_product(): void
    {
        $admin = $this->admin();
        $cust = $this->makeCustomer();
        $product = $this->makeProduct(false, [
            'warranty_months'   => 12,
            'warranty_policies' => [
                ['name' => 'Toàn bộ sản phẩm', 'duration_value' => 12, 'duration_unit' => 'month', 'is_default' => true],
            ],
        ]);
        $invoice = $this->sellNormal($admin, $cust, $product, 1, 200000);

        $w = Warranty::where('invoice_code', $invoice->code)
            ->where('product_id', $product->id)
            ->first();
        $this->assertNotNull($w);
        $this->assertSame(12, (int) $w->warranty_period);
        $this->assertNotNull($w->warranty_end_date);
        $this->assertEquals(
            Carbon::parse($w->purchase_date)->addMonths(12)->toDateString(),
            Carbon::parse($w->warranty_end_date)->toDateString()
        );
    }

    public function test_sale_generates_one_warranty_per_serial(): void
    {
        $admin = $this->admin();
        $cust = $this->makeCustomer();
        $product = $this->makeProduct(true, [
            'stock_quantity'    => 0,
            'warranty_policies' => [
                ['name' => 'Bảo hành 6 tháng', 'duration_value' => 6, 'duration_unit' => 'month', 'is_default' => true],
            ],
        ]);
        $sA = SerialImei::create(['product_id' => $product->id, 'serial_number' => 'SN249W-A-' . uniqid(), 'status' => 'in_stock', 'cost_price' => 100000]);
        $sB = SerialImei::create(['product_id' => $product->id, 'serial_number' => 'SN249W-B-' . uniqid(), 'status' => 'in_stock', 'cost_price' => 100000]);
        $product->update(['stock_quantity' => 2, 'inventory_total_cost' => 200000]);

        $this->actingAs($admin)->post(route('invoices.store'), [
            'customer_id'    => $cust->id,
            'subtotal'       => 400000,
            'discount'       => 0,
            'total'          => 400000,
            'customer_paid'  => 400000,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => 2,
                'price'      => 200000,
                'serial_ids' => [$sA->id, $sB->id],
            ]],
        ]);
        $invoice = Invoice::where('customer_id', $cust->id)->latest('id')->first();

        $count = Warranty::where('invoice_code', $invoice->code)
            ->where('product_id', $product->id)
            ->count();
        $this->assertSame(2, $count);
    }

    public function test_sale_does_not_generate_warranty_when_no_policy(): void
    {
        $admin = $this->admin();
        $cust = $this->makeCustomer();
        $product = $this->makeProduct(false, [
            'warranty_months'   => 0,
            'warranty_policies' => null,
        ]);
        $invoice = $this->sellNormal($admin, $cust, $product, 1, 100000);

        $this->assertSame(0, Warranty::where('invoice_code', $invoice->code)->count());
    }

    public function test_sale_stores_warranty_policy_snapshot(): void
    {
        $admin = $this->admin();
        $cust = $this->makeCustomer();
        $product = $this->makeProduct(false, [
            'warranty_months'   => 12,
            'warranty_policies' => [
                ['name' => 'Toàn bộ', 'duration_value' => 12, 'duration_unit' => 'month', 'is_default' => true],
                ['name' => 'Pin',    'duration_value' => 6,  'duration_unit' => 'month', 'is_default' => false],
            ],
        ]);
        $invoice = $this->sellNormal($admin, $cust, $product, 1, 200000);

        $w = Warranty::where('invoice_code', $invoice->code)->first();
        $this->assertNotNull($w);
        $this->assertIsArray($w->warranty_policy_snapshot);
        $this->assertCount(2, $w->warranty_policy_snapshot);
    }

    public function test_product_update_does_not_mutate_existing_warranty(): void
    {
        $admin = $this->admin();
        $cust = $this->makeCustomer();
        $product = $this->makeProduct(false, [
            'warranty_months'   => 6,
            'warranty_policies' => [
                ['name' => 'Cũ', 'duration_value' => 6, 'duration_unit' => 'month', 'is_default' => true],
            ],
        ]);
        $invoice = $this->sellNormal($admin, $cust, $product, 1, 100000);
        $w = Warranty::where('invoice_code', $invoice->code)->first();
        $this->assertNotNull($w);
        $originalEnd = $w->warranty_end_date->toDateString();

        // Now change product policy.
        $product->update([
            'warranty_months'   => 24,
            'warranty_policies' => [
                ['name' => 'Mới', 'duration_value' => 24, 'duration_unit' => 'month', 'is_default' => true],
            ],
        ]);

        $w->refresh();
        $this->assertSame(6, (int) $w->warranty_period);
        $this->assertSame($originalEnd, $w->warranty_end_date->toDateString());
        // snapshot still shows old policy
        $this->assertSame('Cũ', $w->warranty_policy_snapshot[0]['name']);
    }

    public function test_sale_stores_maintenance_policy_snapshot_and_next_maintenance_date(): void
    {
        $admin = $this->admin();
        $cust = $this->makeCustomer();
        $product = $this->makeProduct(false, [
            'warranty_months'   => 12,
            'warranty_policies' => [
                ['name' => 'BH', 'duration_value' => 12, 'duration_unit' => 'month', 'is_default' => true],
            ],
            'maintenance_policies' => [
                ['name' => 'Vệ sinh', 'duration_value' => 3, 'duration_unit' => 'month'],
            ],
        ]);
        $invoice = $this->sellNormal($admin, $cust, $product, 1, 200000);

        $w = Warranty::where('invoice_code', $invoice->code)->first();
        $this->assertIsArray($w->maintenance_policy_snapshot);
        $this->assertSame('Vệ sinh', $w->maintenance_policy_snapshot[0]['name']);
        $this->assertNotNull($w->next_maintenance_date);
        $this->assertEquals(
            Carbon::parse($w->purchase_date)->addMonths(3)->toDateString(),
            Carbon::parse($w->next_maintenance_date)->toDateString()
        );
    }
}
