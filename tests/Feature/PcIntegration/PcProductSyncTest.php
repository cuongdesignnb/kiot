<?php

namespace Tests\Feature\PcIntegration;

use App\Models\Customer;
use App\Models\ExternalInventoryReservation;
use App\Models\Order;

class PcProductSyncTest extends PcIntegrationTestCase
{
    public function test_product_response_exposes_only_operational_fields_and_available_stock(): void
    {
        $product = $this->makeProduct(['sku' => 'PC-SYNC-STOCK', 'stock_quantity' => 10]);
        $customer = Customer::create(['code' => 'KH-PC-SYNC', 'name' => 'Sync Customer', 'is_customer' => true]);
        $order = Order::create([
            'code' => 'DH-PC-SYNC', 'customer_id' => $customer->id, 'branch_id' => $this->integrationBranch->id,
            'status' => 'confirmed', 'total_price' => 800000, 'total_payment' => 800000,
        ]);
        $item = $order->items()->create(['product_id' => $product->id, 'qty' => 2, 'price' => 800000, 'subtotal' => 1600000]);
        ExternalInventoryReservation::create([
            'source' => 'pc_website', 'order_id' => $order->id, 'order_item_id' => $item->id,
            'product_id' => $product->id, 'branch_id' => $this->integrationBranch->id,
            'quantity' => 2, 'status' => 'active', 'expires_at' => now()->addHour(),
        ]);
        foreach (['released', 'consumed', 'expired'] as $status) {
            ExternalInventoryReservation::create([
                'source' => 'pc_website', 'order_id' => $order->id, 'order_item_id' => $item->id,
                'product_id' => $product->id, 'branch_id' => $this->integrationBranch->id,
                'quantity' => 5, 'status' => $status, 'expires_at' => now()->addHour(),
            ]);
        }

        $path = '/api/integrations/v1/pc/products?sku=PC-SYNC-STOCK';
        $response = $this->getJson($path, $this->signedHeaders('GET', '/api/integrations/v1/pc/products'));

        $response->assertOk()
            ->assertJsonPath('data.0.stock_quantity', 10)
            ->assertJsonPath('data.0.reserved_quantity', 2)
            ->assertJsonPath('data.0.available_quantity', 8)
            ->assertJsonMissingPath('data.0.cost_price')
            ->assertJsonMissingPath('data.0.inventory_total_cost')
            ->assertJsonMissingPath('data.0.supplier');

        $this->getJson(
            '/api/integrations/v1/pc/products?sku=pc-sync-stock',
            $this->signedHeaders('GET', '/api/integrations/v1/pc/products'),
        )->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_inactive_deleted_and_service_products_are_hidden_by_default_but_return_as_tombstones(): void
    {
        $inactive = $this->makeProduct(['sku' => 'PC-INACTIVE', 'is_active' => false]);
        $deleted = $this->makeProduct(['sku' => 'PC-DELETED']);
        $deleted->delete();
        $service = $this->makeProduct(['sku' => 'PC-SERVICE', 'type' => 'service']);

        $basePath = '/api/integrations/v1/pc/products';
        $default = $this->getJson($basePath, $this->signedHeaders('GET', $basePath));
        $default->assertOk();
        $skus = collect($default->json('data'))->pluck('sku');
        $this->assertFalse($skus->contains($inactive->sku));
        $this->assertFalse($skus->contains($deleted->sku));
        $this->assertFalse($skus->contains($service->sku));

        $all = $this->getJson($basePath.'?include_inactive=1', $this->signedHeaders('GET', $basePath));
        $all->assertOk();
        $mapped = collect($all->json('data'))->keyBy('sku');
        $this->assertSame('inactive', $mapped[$inactive->sku]['sync_status']);
        $this->assertSame('deleted', $mapped[$deleted->sku]['sync_status']);
        $this->assertFalse($mapped[$service->sku]['sell_directly']);
    }

    public function test_cursor_pagination_and_product_detail_by_sku(): void
    {
        foreach (range(1, 3) as $number) {
            $this->makeProduct(['sku' => 'PC-CURSOR-'.$number, 'updated_at' => now()->addSeconds($number)]);
        }

        $basePath = '/api/integrations/v1/pc/products';
        $first = $this->getJson($basePath.'?limit=2', $this->signedHeaders('GET', $basePath));
        $first->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.has_more', true);
        $cursor = urlencode((string) $first->json('meta.next_cursor'));
        $second = $this->getJson($basePath.'?limit=2&cursor='.$cursor, $this->signedHeaders('GET', $basePath));
        $second->assertOk();

        $detailPath = '/api/integrations/v1/pc/products/PC-CURSOR-1';
        $this->getJson($detailPath, $this->signedHeaders('GET', $detailPath))
            ->assertOk()->assertJsonPath('data.sku', 'PC-CURSOR-1');
    }
}
