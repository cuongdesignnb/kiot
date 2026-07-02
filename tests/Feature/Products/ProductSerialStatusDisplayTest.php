<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\SerialImei;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductSerialStatusDisplayTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Serial Display',
            'email' => 'admin-serial-display-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'sku' => 'SP-SERIAL-DISPLAY-' . uniqid(),
            'name' => 'Serial Display Product',
            'cost_price' => 1_000_000,
            'retail_price' => 1_500_000,
            'stock_quantity' => 2,
            'inventory_total_cost' => 2_000_000,
            'has_serial' => true,
            'is_active' => true,
        ]);
    }

    private function serial(Product $product, string $number, string $status, ?string $repairStatus): SerialImei
    {
        return SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => $number,
            'status' => $status,
            'repair_status' => $repairStatus,
            'cost_price' => 1_000_000,
            'original_cost' => 1_000_000,
        ]);
    }

    public function test_ready_filter_only_returns_in_stock_serials_not_in_repair_flow(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $soldReady = $this->serial($product, 'SR-SOLD-READY', 'sold', 'ready');
        $inStockReady = $this->serial($product, 'SR-STOCK-READY', 'in_stock', 'ready');
        $inStockRepairing = $this->serial($product, 'SR-STOCK-REPAIRING', 'in_stock', 'repairing');
        $inStockNull = $this->serial($product, 'SR-STOCK-NULL', 'in_stock', null);

        $response = $this->actingAs($admin)->getJson("/products/{$product->id}/serials?status=ready");
        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();

        $this->assertContains($inStockReady->id, $ids);
        $this->assertContains($inStockNull->id, $ids);
        $this->assertNotContains($soldReady->id, $ids);
        $this->assertNotContains($inStockRepairing->id, $ids);
    }

    public function test_serials_endpoint_returns_status_and_repair_status_for_ui(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $serial = $this->serial($product, 'SR-UI-FIELDS', 'sold', 'ready');

        $response = $this->actingAs($admin)->getJson("/products/{$product->id}/serials?status=all");
        $response->assertOk();

        $row = collect($response->json())->firstWhere('id', $serial->id);
        $this->assertNotNull($row);
        $this->assertSame('SR-UI-FIELDS', $row['serial_number']);
        $this->assertSame('sold', $row['status']);
        $this->assertSame('ready', $row['repair_status']);
    }

    public function test_product_ready_count_excludes_sold_repair_ready_serials(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $this->serial($product, 'SR-SOLD-READY-COUNT', 'sold', 'ready');
        $this->serial($product, 'SR-STOCK-READY-COUNT', 'in_stock', 'ready');
        $this->serial($product, 'SR-STOCK-NULL-COUNT', 'in_stock', null);
        $this->serial($product, 'SR-STOCK-REPAIRING-COUNT', 'in_stock', 'repairing');

        $response = $this->actingAs($admin)->get('/products?search=' . urlencode($product->sku));
        $response->assertOk();

        $row = collect($response->viewData('page')['props']['products']['data'])
            ->firstWhere('id', $product->id);

        $this->assertNotNull($row);
        $this->assertEquals(3, (int) $row['in_stock_count']);
        $this->assertEquals(2, (int) $row['ready_count']);
        $this->assertEquals(1, (int) $row['repairing_count']);
    }
}
