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

    public function test_inactive_and_deleted_products_return_as_tombstones_but_service_is_always_excluded(): void
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
        $this->assertFalse($mapped->has($service->sku));

        $updated = $this->getJson(
            $basePath.'?updated_since='.urlencode(now()->subMinute()->toIso8601String()),
            $this->signedHeaders('GET', $basePath),
        );
        $updated->assertOk();
        $this->assertFalse(collect($updated->json('data'))->pluck('sku')->contains($service->sku));

        $detailPath = $basePath.'/'.$service->sku;
        $this->getJson($detailPath, $this->signedHeaders('GET', $detailPath))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'UNKNOWN_SKU');
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

    public function test_cursor_is_stable_for_equal_timestamps_and_updated_since_is_inclusive(): void
    {
        $timestamp = now()->subMinute()->startOfSecond();
        \App\Models\Product::withTrashed()->update(['updated_at' => $timestamp->copy()->subDays(2)]);
        $beforeBoundary = $this->makeProduct(['sku' => 'PC-CURSOR-BEFORE-BOUNDARY']);
        $products = collect(range(1, 3))->map(fn (int $number) => $this->makeProduct([
            'sku' => 'PC-CURSOR-EQUAL-'.$number,
        ]));
        \App\Models\Product::withoutTimestamps(function () use ($beforeBoundary, $products, $timestamp): void {
            $beforeBoundary->forceFill(['updated_at' => $timestamp->copy()->subSecond()])->save();
            $products->each(fn ($product) => $product->forceFill(['updated_at' => $timestamp])->save());
        });
        $basePath = '/api/integrations/v1/pc/products';
        $query = '?limit=2&updated_since='.urlencode($timestamp->toIso8601String());

        $first = $this->getJson($basePath.$query, $this->signedHeaders('GET', $basePath));
        $first->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.has_more', true);
        $second = $this->getJson(
            $basePath.$query.'&cursor='.urlencode((string) $first->json('meta.next_cursor')),
            $this->signedHeaders('GET', $basePath),
        );
        $second->assertOk()->assertJsonPath('meta.has_more', false);
        $this->assertCount(1, $second->json('data'), json_encode([
            'first' => $first->json('data'),
            'second' => $second->json('data'),
        ], JSON_THROW_ON_ERROR));

        $actualIds = collect($first->json('data'))->concat($second->json('data'))->pluck('id')->all();
        $this->assertSame($products->pluck('id')->sort()->values()->all(), $actualIds);
        $this->assertCount(3, array_unique($actualIds));
    }

    public function test_detail_accepts_url_encoded_exact_case_sku_and_rejects_wrong_case(): void
    {
        $product = $this->makeProduct(['sku' => 'PC+Encoded SKU']);
        $path = '/api/integrations/v1/pc/products/'.rawurlencode($product->sku);

        $this->getJson($path, $this->signedHeaders('GET', $path))
            ->assertOk()
            ->assertJsonPath('data.sku', $product->sku);

        $wrongCasePath = '/api/integrations/v1/pc/products/'.rawurlencode(strtolower($product->sku));
        $this->getJson($wrongCasePath, $this->signedHeaders('GET', $wrongCasePath))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'UNKNOWN_SKU');
    }
}
