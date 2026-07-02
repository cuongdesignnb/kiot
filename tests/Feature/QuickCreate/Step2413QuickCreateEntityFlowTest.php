<?php

namespace Tests\Feature\QuickCreate;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use App\Models\Customer;
use App\Models\Product;

/**
 * STEP 24.13 — Unified Quick Create Flow.
 *
 * Pins the JSON contract so the in-context modals on POS / Purchases / Edit
 * pages keep working:
 *   - /products/quick-store creates a product, returns JSON, does NOT mutate stock.
 *   - /api/suppliers/quick-store creates a supplier (Customer is_supplier=true) + JSON.
 *   - /suppliers (full store) returns JSON when the caller wants it; legacy web
 *     redirect still works for HTML callers.
 *   - /customers (existing) returns JSON when the caller wants it.
 */
class Step2413QuickCreateEntityFlowTest extends TestCase
{
    use DatabaseTransactions;

    private function actor(): User
    {
        return User::create([
            'name'     => 'QA 2413',
            'email'    => 'qa-2413-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    public function test_product_quick_store_returns_json_without_stock_mutation(): void
    {
        $user = $this->actor();
        $before = Schema::hasTable('stock_movements') ? DB::table('stock_movements')->count() : 0;

        $res = $this->actingAs($user)->postJson('/products/quick-store', [
            'name'         => 'Sản phẩm test 2413',
            'cost_price'   => 800000,
            'retail_price' => 1200000,
        ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('product.name', 'Sản phẩm test 2413');

        $productId = $res->json('product.id');
        $product = Product::find($productId);
        $this->assertNotNull($product);
        $this->assertSame(0.0, (float) ($product->stock_quantity ?? 0), 'quick-store must not seed stock');
        $this->assertSame(800000, (int) $product->cost_price);
        $this->assertSame(1200000, (int) $product->retail_price);

        if (Schema::hasTable('stock_movements')) {
            $after = DB::table('stock_movements')->count();
            $this->assertSame($before, $after, 'quick-store must not insert a stock_movement row');
        }
    }

    public function test_product_quick_store_money_payload_must_be_numeric(): void
    {
        $user = $this->actor();

        // Backend validates numeric — formatted "1.000.000đ" must be rejected.
        $this->actingAs($user)
            ->postJson('/products/quick-store', [
                'name'         => 'SP money 2413',
                'cost_price'   => '1.000.000đ',
                'retail_price' => 1500000,
            ])
            ->assertStatus(422);
    }

    public function test_supplier_quick_store_returns_json_with_supplier_flag_true(): void
    {
        $user = $this->actor();

        $res = $this->actingAs($user)->postJson('/api/suppliers/quick-store', [
            'name'    => 'NCC Test 2413',
            'phone'   => '0900' . random_int(100000, 999999),
            'email'   => 'ncc-2413-' . uniqid() . '@test.local',
            'address' => 'Số 1 Đường Test',
        ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('supplier.name', 'NCC Test 2413')
            ->assertJsonPath('supplier.is_supplier', true);

        $supplier = Customer::find($res->json('supplier.id'));
        $this->assertTrue((bool) $supplier->is_supplier);
        $this->assertFalse((bool) $supplier->is_customer);
    }

    public function test_supplier_full_store_returns_json_when_caller_wants_json(): void
    {
        $user = $this->actor();

        $res = $this->actingAs($user)->postJson('/suppliers', [
            'name'    => 'NCC Full Store 2413',
            'phone'   => '0911' . random_int(100000, 999999),
            'email'   => 'full-2413-' . uniqid() . '@test.local',
        ]);

        // Must NOT be an HTML redirect — the in-context quick add depends on
        // the JSON path. 200 or 201, body has supplier.id.
        $this->assertContains($res->status(), [200, 201], 'expected JSON 200/201, got ' . $res->status());
        $this->assertTrue((bool) $res->json('success'));
        $supplierId = $res->json('supplier.id');
        $this->assertNotNull($supplierId);

        $supplier = Customer::find($supplierId);
        $this->assertTrue((bool) $supplier->is_supplier);
    }

    public function test_customer_store_returns_json_when_caller_wants_json(): void
    {
        $user = $this->actor();

        $res = $this->actingAs($user)->postJson('/customers', [
            'name'  => 'KH Test 2413',
            'phone' => '0922' . random_int(100000, 999999),
        ]);

        $this->assertContains($res->status(), [200, 201], 'expected JSON 200/201, got ' . $res->status());
        $customer = $res->json('customer');
        $this->assertNotNull($customer);
        $this->assertSame('KH Test 2413', $customer['name']);

        $row = Customer::find($customer['id']);
        $this->assertTrue((bool) $row->is_customer);
    }

    public function test_product_quick_store_validates_required_name(): void
    {
        $user = $this->actor();
        $this->actingAs($user)
            ->postJson('/products/quick-store', ['name' => ''])
            ->assertStatus(422);
    }

    public function test_supplier_quick_store_validates_required_name(): void
    {
        $user = $this->actor();
        $this->actingAs($user)
            ->postJson('/api/suppliers/quick-store', ['name' => ''])
            ->assertStatus(422);
    }
}
