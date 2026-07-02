<?php

namespace Tests\Feature\Purchase;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\SerialImei;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class HOTFIX246KPurchaseReturnQuickAndReturnerTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Customer $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Trần Văn Tiến',
            'email' => 'hotfix-246k-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);

        $this->supplier = Customer::create([
            'code' => 'NCC-246K-' . uniqid(),
            'name' => 'NCC HOTFIX 24.6K',
            'phone' => '0985' . rand(100000, 999999),
            'is_supplier' => true,
            'supplier_debt_amount' => 1000000,
            'total_bought' => 1000000,
        ]);
    }

    private function product(bool $hasSerial = false, int $stock = 5, float $cost = 100000): Product
    {
        $category = Category::firstOrCreate(['name' => 'HOTFIX 24.6K']);

        return Product::create([
            'sku' => 'SP246K-' . uniqid(),
            'name' => $hasSerial ? 'Serial Product 24.6K' : 'Normal Product 24.6K',
            'category_id' => $category->id,
            'cost_price' => $cost,
            'retail_price' => $cost * 2,
            'stock_quantity' => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active' => true,
            'has_serial' => $hasSerial,
        ]);
    }

    private function purchaseWithItem(Product $product): Purchase
    {
        $purchase = Purchase::create([
            'code' => 'PN246K' . time(),
            'supplier_id' => $this->supplier->id,
            'user_id' => $this->admin->id,
            'total_amount' => 500000,
            'paid_amount' => 0,
            'debt_amount' => 500000,
            'status' => 'completed',
            'purchase_date' => now(),
        ]);

        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->sku,
            'quantity' => 5,
            'price' => 100000,
            'subtotal' => 500000,
            'unit_cost_allocated' => 100000,
        ]);

        return $purchase;
    }

    public function test_purchase_return_create_page_includes_current_user_returner_when_user_has_no_employee(): void
    {
        $purchase = $this->purchaseWithItem($this->product());

        $this->actingAs($this->admin)
            ->get(route('purchase-returns.create', ['purchase_id' => $purchase->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PurchaseReturns/Create')
                ->where('currentReturner.name', 'Trần Văn Tiến')
                ->where('currentReturner.value', 'current_user')
            );
    }

    public function test_purchase_return_quick_page_route_exists_and_includes_current_user_returner(): void
    {
        $this->actingAs($this->admin)
            ->get(route('purchase-returns.create-quick'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PurchaseReturns/CreateQuick')
                ->where('currentReturner.name', 'Trần Văn Tiến')
                ->where('currentReturner.value', 'current_user')
            );
    }

    public function test_purchase_return_quick_normal_product_creates_return_and_reduces_stock(): void
    {
        $product = $this->product(false, 5, 100000);

        $this->actingAs($this->admin)->post(route('purchase-returns.quick-store'), [
            'code' => 'PTN246K' . time(),
            'supplier_id' => $this->supplier->id,
            'refund_amount' => 0,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'price' => 100000,
            ]],
        ])->assertRedirect(route('purchase-returns.index'));

        $return = PurchaseReturn::where('supplier_id', $this->supplier->id)->latest('id')->first();

        $this->assertNotNull($return);
        $this->assertNull($return->purchase_id);
        $this->assertSame(3, (int) $product->fresh()->stock_quantity);
        $this->assertSame(800000.0, (float) $this->supplier->fresh()->supplier_debt_amount);
    }

    public function test_purchase_return_quick_rejects_serial_product_without_serial_picker(): void
    {
        $product = $this->product(true, 1, 100000);
        SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SN246K-' . uniqid(),
            'status' => 'in_stock',
            'cost_price' => 100000,
            'original_cost' => 100000,
        ]);

        $this->actingAs($this->admin)->post(route('purchase-returns.quick-store'), [
            'code' => 'PTN246K-SERIAL' . time(),
            'supplier_id' => $this->supplier->id,
            'refund_amount' => 0,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 100000,
            ]],
        ])->assertSessionHasErrors('items.0.product_id');

        $this->assertSame(0, PurchaseReturn::where('supplier_id', $this->supplier->id)->count());
        $this->assertSame(1, (int) $product->fresh()->stock_quantity);
    }
}
