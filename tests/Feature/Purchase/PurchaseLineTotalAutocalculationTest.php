<?php

namespace Tests\Feature\Purchase;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseLineTotalAutocalculationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_purchase_persists_exact_line_total_derived_from_quantity_price_and_rounding_discount(): void
    {
        $admin = $this->admin();
        $supplier = $this->supplier();
        $product = $this->product();

        $response = $this->actingAs($admin)->post('/purchases', [
            'code' => 'PN-LINE-TOTAL-'.uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'purchase_date' => now()->toDateTimeString(),
            'discount' => 0,
            'paid_amount' => 0,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 3,
                'price' => 33_334,
                'discount' => 2,
                'line_total' => 1, // UI-only value must never be trusted.
            ]],
        ]);

        $response->assertRedirect();

        $purchase = Purchase::query()->where('supplier_id', $supplier->id)->latest('id')->firstOrFail();
        $item = PurchaseItem::query()->where('purchase_id', $purchase->id)->firstOrFail();

        $this->assertSame(100_000.0, (float) $purchase->total_amount);
        $this->assertSame(100_000.0, (float) $purchase->debt_amount);
        $this->assertSame(33_334.0, (float) $item->price);
        $this->assertSame(2.0, (float) $item->discount);
        $this->assertSame(100_000.0, (float) $item->subtotal);
        $this->assertSame(100_000.0, (float) $supplier->fresh()->supplier_debt_amount);
        $this->assertSame(3, (int) $product->fresh()->stock_quantity);
    }

    public function test_purchase_rejects_discount_greater_than_gross_line_amount_without_mutation(): void
    {
        $admin = $this->admin();
        $supplier = $this->supplier();
        $product = $this->product();

        $purchaseCount = Purchase::query()->count();
        $response = $this->from('/purchases/create')->actingAs($admin)->post('/purchases', [
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'purchase_date' => now()->toDateTimeString(),
            'discount' => 0,
            'paid_amount' => 0,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 100_000,
                'discount' => 100_001,
            ]],
        ]);

        $response->assertRedirect('/purchases/create');
        $response->assertSessionHasErrors('items.0.discount');
        $this->assertSame($purchaseCount, Purchase::query()->count());
        $this->assertSame(0.0, (float) $supplier->fresh()->supplier_debt_amount);
        $this->assertSame(0, (int) $product->fresh()->stock_quantity);
    }

    public function test_purchase_update_rejects_discount_greater_than_gross_without_partial_mutation(): void
    {
        $admin = $this->admin();
        $supplier = $this->supplier();
        $product = $this->product();

        $this->actingAs($admin)->post('/purchases', [
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'purchase_date' => now()->toDateTimeString(),
            'discount' => 0,
            'paid_amount' => 0,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'price' => 50_000,
                'discount' => 0,
            ]],
        ])->assertRedirect();

        $purchase = Purchase::query()->where('supplier_id', $supplier->id)->latest('id')->firstOrFail();
        $item = PurchaseItem::query()->where('purchase_id', $purchase->id)->firstOrFail();

        $response = $this->from("/purchases/{$purchase->id}/edit")
            ->actingAs($admin)
            ->put("/purchases/{$purchase->id}", [
                'supplier_id' => $supplier->id,
                'status' => 'completed',
                'purchase_date' => now()->toDateTimeString(),
                'discount' => 0,
                'paid_amount' => 0,
                'payment_method' => 'cash',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'price' => 50_000,
                    'discount' => 100_001,
                    'serials' => [],
                    'warranty_months' => 0,
                ]],
            ]);

        $response->assertRedirect("/purchases/{$purchase->id}/edit");
        $response->assertSessionHas('error');
        $this->assertSame(100_000.0, (float) $purchase->fresh()->total_amount);
        $this->assertSame(100_000.0, (float) $purchase->fresh()->debt_amount);
        $this->assertSame(2, (int) $item->fresh()->quantity);
        $this->assertSame(50_000.0, (float) $item->fresh()->price);
        $this->assertSame(0.0, (float) $item->fresh()->discount);
        $this->assertSame(100_000.0, (float) $item->fresh()->subtotal);
        $this->assertSame(2, (int) $product->fresh()->stock_quantity);
        $this->assertSame(100_000.0, (float) $product->fresh()->inventory_total_cost);
        $this->assertSame(100_000.0, (float) $supplier->fresh()->supplier_debt_amount);
    }

    public function test_purchase_order_recomputes_total_value_and_ignores_client_total(): void
    {
        $admin = $this->admin();
        $supplier = $this->supplier();
        $product = $this->product();
        $branch = Branch::create(['name' => 'Purchase line total branch '.uniqid()]);

        $response = $this->actingAs($admin)->post('/purchase-orders', [
            'code' => 'DDH-LINE-TOTAL-'.uniqid(),
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'order_date' => now()->toDateTimeString(),
            'discount' => 0,
            'import_fee' => 0,
            'other_import_fee' => 0,
            'items' => [[
                'product_id' => $product->id,
                'qty' => 3,
                'price' => 33_334,
                'discount' => 2,
                'total_value' => 1,
            ]],
        ]);

        $response->assertRedirect();

        $purchaseOrder = PurchaseOrder::query()->where('supplier_id', $supplier->id)->latest('id')->firstOrFail();
        $item = PurchaseOrderItem::query()->where('purchase_order_id', $purchaseOrder->id)->firstOrFail();

        $this->assertSame(100_000.0, (float) $purchaseOrder->total_amount);
        $this->assertSame(100_000.0, (float) $purchaseOrder->total_payment);
        $this->assertSame(100_000.0, (float) $item->total_value);
        $this->assertSame(0, (int) $product->fresh()->stock_quantity, 'Purchase order must not mutate stock.');
        $this->assertSame(0.0, (float) $supplier->fresh()->supplier_debt_amount, 'Purchase order must not mutate debt.');
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Purchase Line Total',
            'email' => 'admin-purchase-line-total-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function supplier(): Customer
    {
        return Customer::create([
            'code' => 'NCC-LINE-TOTAL-'.uniqid(),
            'name' => 'Supplier Line Total',
            'phone' => '09'.random_int(10000000, 99999999),
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'total_bought' => 0,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'sku' => 'SKU-LINE-TOTAL-'.uniqid(),
            'name' => 'Product Line Total',
            'cost_price' => 0,
            'retail_price' => 0,
            'stock_quantity' => 0,
            'inventory_total_cost' => 0,
            'has_serial' => false,
            'is_active' => true,
        ]);
    }
}
