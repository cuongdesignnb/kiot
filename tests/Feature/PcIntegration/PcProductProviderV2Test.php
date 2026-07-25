<?php

namespace Tests\Feature\PcIntegration;

use App\Models\Category;
use App\Models\PriceBook;
use App\Models\PriceBookProduct;
use App\Models\ProductImage;
use App\Models\SerialImei;
use Illuminate\Support\Facades\Storage;

class PcProductProviderV2Test extends PcIntegrationTestCase
{
    public function test_category_api_supports_stable_cursor_rename_inactive_and_deleted_tombstones(): void
    {
        Category::query()->update(['updated_at' => now()->subDays(2)]);
        $timestamp = now()->subMinute()->startOfSecond();
        $first = Category::create(['name' => 'Laptop Dell', 'is_active' => true, 'show_on_pc_website' => true]);
        $second = Category::create(['name' => 'Laptop HP', 'is_active' => false]);
        Category::withoutTimestamps(function () use ($first, $second, $timestamp) {
            $first->forceFill(['updated_at' => $timestamp])->save();
            $second->forceFill(['updated_at' => $timestamp])->save();
        });

        $path = '/api/integrations/v1/pc/categories';
        $pageOne = $this->getJson($path.'?limit=1&updated_since='.urlencode($timestamp->toIso8601String()), $this->signedHeaders('GET', $path));
        $pageOne->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', true);
        $pageTwo = $this->getJson($path.'?limit=1&updated_since='.urlencode($timestamp->toIso8601String()).'&cursor='.urlencode($pageOne->json('meta.next_cursor')), $this->signedHeaders('GET', $path));
        $pageTwo->assertOk()->assertJsonCount(1, 'data');
        $this->assertCount(2, array_unique([$pageOne->json('data.0.id'), $pageTwo->json('data.0.id')]));

        $stableId = $first->id;
        $stableCode = $first->code;
        $first->update(['name' => 'Dell Business']);
        $this->assertSame($stableId, $first->fresh()->id);
        $this->assertSame($stableCode, $first->fresh()->code);

        $first->delete();
        $all = $this->getJson($path.'?include_inactive=1', $this->signedHeaders('GET', $path));
        $mapped = collect($all->json('data'))->keyBy('id');
        $this->assertSame('deleted', $mapped[$first->id]['sync_status']);
        $this->assertSame('inactive', $mapped[$second->id]['sync_status']);
        $this->assertFalse($mapped[$second->id]['show_on_pc_website']);

        $this->getJson($path.'?cursor=invalid', $this->signedHeaders('GET', $path))
            ->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_CURSOR');
    }

    public function test_price_book_api_and_product_contract_use_selected_price_with_explicit_fallback(): void
    {
        $category = Category::create(['name' => 'Laptop Gaming', 'show_on_pc_website' => true]);
        $book = PriceBook::create(['code' => 'WEBSITE', 'name' => 'Giá Website', 'is_active' => true, 'status' => 'active']);
        $inactiveBook = PriceBook::create(['code' => 'OLD', 'name' => 'Giá cũ', 'is_active' => false, 'status' => 'inactive']);
        config()->set('integrations.pc_website.product_price_book_id', $book->id);
        $product = $this->makeProduct([
            'sku' => 'PC-V2-FULL', 'category_id' => $category->id, 'retail_price' => 10500000,
            'stock_quantity' => 2, 'has_serial' => true, 'description' => 'Public description',
        ]);
        PriceBookProduct::create(['price_book_id' => $book->id, 'product_id' => $product->id, 'price' => 9900000]);
        SerialImei::create(['product_id' => $product->id, 'serial_number' => 'READY-V2', 'status' => 'in_stock', 'repair_status' => 'ready']);
        SerialImei::create(['product_id' => $product->id, 'serial_number' => 'REPAIR-V2', 'status' => 'in_stock', 'repair_status' => 'repairing']);

        $pricePath = '/api/integrations/v1/pc/price-books';
        $priceResponse = $this->getJson($pricePath, $this->signedHeaders('GET', $pricePath));
        $priceResponse->assertOk()->assertJsonFragment(['id' => $book->id, 'code' => 'WEBSITE', 'is_active' => true]);
        $this->assertFalse(collect($priceResponse->json('data'))->pluck('id')->contains($inactiveBook->id));

        $productPath = '/api/integrations/v1/pc/products';
        $response = $this->getJson($productPath.'?sku=PC-V2-FULL', $this->signedHeaders('GET', $productPath));
        $response->assertOk()
            ->assertJsonPath('data.0.category.id', $category->id)
            ->assertJsonPath('data.0.category.show_on_pc_website', true)
            ->assertJsonPath('data.0.publishing.show_on_pc_website', true)
            ->assertJsonPath('data.0.publishing.blocked_reason', null)
            ->assertJsonPath('data.0.pricing.selected_price', 9900000)
            ->assertJsonPath('data.0.pricing.selected_price_book_id', $book->id)
            ->assertJsonPath('data.0.pricing.fallback_used', false)
            ->assertJsonPath('data.0.inventory.stock_quantity', 2)
            ->assertJsonPath('data.0.inventory.available_quantity', 1)
            ->assertJsonPath('data.0.inventory.status', 'available')
            ->assertJsonPath('data.0.availability.is_under_repair', true)
            ->assertJsonMissingPath('data.0.cost_price')
            ->assertJsonMissingPath('data.0.inventory_total_cost');

        PriceBookProduct::where('price_book_id', $book->id)->where('product_id', $product->id)->delete();
        $fallback = $this->getJson($productPath.'?id='.$product->id, $this->signedHeaders('GET', $productPath));
        $fallback->assertOk()
            ->assertJsonPath('data.0.pricing.selected_price', 10500000)
            ->assertJsonPath('data.0.pricing.fallback_used', true);
    }

    public function test_all_stock_under_repair_maps_to_zero_website_availability(): void
    {
        $product = $this->makeProduct(['sku' => 'PC-ALL-REPAIR', 'stock_quantity' => 1, 'has_serial' => true]);
        SerialImei::create(['product_id' => $product->id, 'serial_number' => 'ONLY-REPAIR', 'status' => 'in_stock', 'repair_status' => 'not_started']);

        $path = '/api/integrations/v1/pc/products?sku=PC-ALL-REPAIR';
        $response = $this->getJson($path, $this->signedHeaders('GET', '/api/integrations/v1/pc/products'));
        $response->assertOk()
            ->assertJsonPath('data.0.inventory.status', 'repairing')
            ->assertJsonPath('data.0.inventory.available_quantity', 0)
            ->assertJsonPath('data.0.availability.is_available', false);
    }

    public function test_category_visibility_is_name_agnostic_and_touches_products_for_incremental_sync(): void
    {
        $category = Category::create(['name' => 'Repair-looking label', 'show_on_pc_website' => false]);
        $product = $this->makeProduct(['sku' => 'PC-CATEGORY-VISIBILITY', 'category_id' => $category->id]);
        $originalProductTimestamp = now()->subHour()->startOfSecond();
        $product->forceFill(['updated_at' => $originalProductTimestamp])->saveQuietly();

        $path = '/api/integrations/v1/pc/products';
        $hidden = $this->getJson($path.'?sku=PC-CATEGORY-VISIBILITY', $this->signedHeaders('GET', $path));
        $hidden->assertOk()
            ->assertJsonPath('data.0.publishing.show_on_pc_website', false)
            ->assertJsonPath('data.0.publishing.blocked_reason', 'CATEGORY_NOT_PUBLISHED');

        $category->update(['show_on_pc_website' => true]);
        $this->assertTrue($product->fresh()->updated_at->greaterThan($originalProductTimestamp));

        $visible = $this->getJson(
            $path.'?updated_since='.urlencode($originalProductTimestamp->toIso8601String()),
            $this->signedHeaders('GET', $path),
        );
        $record = collect($visible->json('data'))->firstWhere('id', $product->id);
        $this->assertTrue($record['publishing']['show_on_pc_website']);
        $this->assertNull($record['publishing']['blocked_reason']);
    }

    public function test_category_parent_cycle_is_rejected_without_changing_stable_identity(): void
    {
        $parent = Category::create(['name' => 'Parent']);
        $child = Category::create(['name' => 'Child', 'parent_id' => $parent->id]);
        $stableId = $parent->id;

        try {
            $parent->update(['parent_id' => $child->id]);
            $this->fail('A cyclic category parent should be rejected.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('parent_id', $exception->errors());
        }

        $this->assertSame($stableId, $parent->fresh()->id);
        $this->assertNull($parent->fresh()->parent_id);
    }

    public function test_product_contract_exposes_https_image_metadata_without_storage_path(): void
    {
        Storage::fake('public');
        config()->set('app.url', 'https://kiot.example.test');
        $product = $this->makeProduct(['sku' => 'PC-V2-IMAGE']);
        $image = ProductImage::create([
            'product_id' => $product->id,
            'storage_disk' => 'public',
            'storage_path' => 'products/'.$product->id.'/provider.webp',
            'original_filename' => 'provider.jpg',
            'mime_type' => 'image/webp',
            'width' => 640,
            'height' => 480,
            'file_size' => 1234,
            'checksum' => str_repeat('a', 64),
            'sort_order' => 0,
            'is_primary' => true,
            'primary_product_id' => $product->id,
        ]);
        Storage::disk('public')->put($image->storage_path, 'webp');

        $path = '/api/integrations/v1/pc/products';
        $response = $this->getJson($path.'?sku=PC-V2-IMAGE', $this->signedHeaders('GET', $path));

        $response->assertOk()
            ->assertJsonPath('data.0.primary_image.checksum', str_repeat('a', 64))
            ->assertJsonPath('data.0.primary_image.is_primary', true)
            ->assertJsonPath('data.0.images.0.width', 640)
            ->assertJsonMissingPath('data.0.primary_image.storage_path')
            ->assertJsonMissingPath('data.0.images.0.storage_disk');
        $this->assertStringStartsWith('https://', $response->json('data.0.primary_image.url'));
    }
}
