<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\User;
use App\Models\Invoice;
use App\Models\Purchase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class HOTFIXProductActiveStatusTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Active Status Test',
            'email' => 'admin-active-status-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
    }

    private function product(string $sku, ?bool $isActive = null, bool $omitActive = false): Product
    {
        $payload = [
            'sku' => $sku,
            'name' => 'Product ' . $sku,
            'cost_price' => 100000,
            'retail_price' => 150000,
            'stock_quantity' => 10,
        ];
        if (!$omitActive) {
            $payload['is_active'] = $isActive ?? true;
        }
        return Product::create($payload);
    }

    /**
     * Test index defaults to only show active products.
     */
    public function test_index_defaults_to_show_active_only(): void
    {
        $admin = $this->admin();
        $activeProduct = $this->product('ACTIVE-01', true);
        $nullProduct = $this->product('ACTIVE-02', null, true);
        $inactiveProduct = $this->product('INACTIVE-01', false);

        $response = $this->actingAs($admin)->get('/products');
        $response->assertOk();

        $viewData = $response->viewData('page')['props']['products']['data'];
        $skus = collect($viewData)->pluck('sku')->all();

        $this->assertContains($activeProduct->sku, $skus);
        $this->assertContains($nullProduct->sku, $skus);
        $this->assertNotContains($inactiveProduct->sku, $skus);
    }

    /**
     * Test index status=inactive only shows is_active = false.
     */
    public function test_index_with_status_inactive_shows_inactive_only(): void
    {
        $admin = $this->admin();
        $activeProduct = $this->product('ACTIVE-03', true);
        $inactiveProduct = $this->product('INACTIVE-02', false);

        $response = $this->actingAs($admin)->get('/products?status=inactive');
        $response->assertOk();

        $viewData = $response->viewData('page')['props']['products']['data'];
        $skus = collect($viewData)->pluck('sku')->all();

        $this->assertNotContains($activeProduct->sku, $skus);
        $this->assertContains($inactiveProduct->sku, $skus);
    }

    /**
     * Test index status=all shows both active and inactive.
     */
    public function test_index_with_status_all_shows_all_products(): void
    {
        $admin = $this->admin();
        $activeProduct = $this->product('ACTIVE-04', true);
        $inactiveProduct = $this->product('INACTIVE-03', false);

        $response = $this->actingAs($admin)->get('/products?status=all');
        $response->assertOk();

        $viewData = $response->viewData('page')['props']['products']['data'];
        $skus = collect($viewData)->pluck('sku')->all();

        $this->assertContains($activeProduct->sku, $skus);
        $this->assertContains($inactiveProduct->sku, $skus);
    }

    /**
     * Test deactivate route sets is_active = false.
     */
    public function test_deactivate_route_updates_status(): void
    {
        $admin = $this->admin();
        $product = $this->product('ACTIVE-05', true);

        $response = $this->actingAs($admin)->post("/products/{$product->id}/deactivate");
        $response->assertRedirect();

        $this->assertFalse((bool) $product->fresh()->is_active);
    }

    /**
     * Test activate route sets is_active = true.
     */
    public function test_activate_route_updates_status(): void
    {
        $admin = $this->admin();
        $product = $this->product('INACTIVE-04', false);

        $response = $this->actingAs($admin)->post("/products/{$product->id}/activate");
        $response->assertRedirect();

        $this->assertTrue((bool) $product->fresh()->is_active);
    }

    /**
     * Test deactivate does not delete the product from database.
     */
    public function test_deactivate_does_not_delete_product(): void
    {
        $admin = $this->admin();
        $product = $this->product('ACTIVE-06', true);

        $response = $this->actingAs($admin)->post("/products/{$product->id}/deactivate");
        $response->assertRedirect();

        $this->assertNotNull(Product::find($product->id));
        $this->assertFalse((bool) $product->fresh()->is_active);
    }

    /**
     * Test deactivate product associated with invoice/purchase is not deleted and keeps connections.
     */
    public function test_deactivated_product_preserves_invoice_and_purchase_links(): void
    {
        $admin = $this->admin();
        $product = $this->product('ACTIVE-07', true);

        // Create virtual invoice item and purchase item
        $invoice = Invoice::create([
            'code' => 'HD-TEST-' . uniqid(),
            'subtotal' => 150000,
            'discount' => 0,
            'total' => 150000,
            'customer_paid' => 150000,
            'status' => 'Hoàn thành',
        ]);
        $invoice->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 150000,
        ]);

        $purchase = Purchase::create([
            'code' => 'PN-TEST-' . uniqid(),
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'status' => 'completed',
        ]);
        $purchase->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->sku,
            'quantity' => 1,
            'price' => 100000,
            'subtotal' => 100000,
        ]);

        $response = $this->actingAs($admin)->post("/products/{$product->id}/deactivate");
        $response->assertRedirect();

        // Check product is inactive but still in DB, and items links are intact
        $this->assertNotNull(Product::find($product->id));
        $this->assertFalse((bool) $product->fresh()->is_active);

        $this->assertDatabaseHas('invoice_items', [
            'product_id' => $product->id,
            'invoice_id' => $invoice->id,
        ]);

        $this->assertDatabaseHas('purchase_items', [
            'product_id' => $product->id,
            'purchase_id' => $purchase->id,
        ]);
    }
}
