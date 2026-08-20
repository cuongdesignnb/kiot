<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductImageManagementTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config()->set('integrations.pc_website.product_images', [
            'disk' => 'public', 'max_count' => 4, 'max_size_kb' => 1024,
            'max_pixels' => 1000000, 'webp_quality' => 80,
        ]);
        config()->set('app.url', 'https://kiot.example.test');
        $this->admin = User::factory()->create(['role_id' => null]);
        $this->product = Product::create([
            'sku' => 'IMAGE-PRODUCT', 'name' => 'Image Product', 'type' => 'standard',
            'retail_price' => 100000, 'stock_quantity' => 1, 'is_active' => true, 'sell_directly' => true,
        ]);
    }

    public function test_upload_converts_to_webp_and_manages_primary_reorder_and_delete(): void
    {
        $response = $this->actingAs($this->admin)->post('/products/'.$this->product->id.'/images', [
            'images' => [
                UploadedFile::fake()->image('front.jpg', 120, 100),
                UploadedFile::fake()->image('back.png', 100, 120),
            ],
            'primary_index' => 1,
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonCount(2, 'images');
        $images = ProductImage::where('product_id', $this->product->id)->orderBy('sort_order')->get();
        $this->assertCount(2, $images);
        $this->assertSame(1, $images->where('is_primary', true)->count());
        $this->assertTrue($images[1]->is_primary);
        $this->assertSame('image/webp', $images[0]->mime_type);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $images[0]->checksum);
        Storage::disk('public')->assertExists($images[0]->storage_path);
        $response->assertJsonMissingPath('images.0.storage_path');
        $this->assertStringStartsWith('https://', $response->json('images.0.url'));

        $this->actingAs($this->admin)->putJson('/products/'.$this->product->id.'/images/reorder', [
            'image_ids' => [$images[1]->id, $images[0]->id],
        ])->assertOk()->assertJsonPath('images.0.id', $images[1]->id);

        $this->actingAs($this->admin)
            ->putJson('/products/'.$this->product->id.'/images/'.$images[0]->id.'/primary')
            ->assertOk()->assertJsonPath('image.is_primary', true);
        $this->assertSame(1, ProductImage::where('product_id', $this->product->id)->where('is_primary', true)->count());

        $path = $images[0]->storage_path;
        $this->actingAs($this->admin)
            ->deleteJson('/products/'.$this->product->id.'/images/'.$images[0]->id)
            ->assertOk();
        Storage::disk('public')->assertMissing($path);
        $this->assertSoftDeleted('product_images', ['id' => $images[0]->id]);
        $this->assertSame(1, ProductImage::where('product_id', $this->product->id)->where('is_primary', true)->count());
    }

    public function test_mime_spoofing_is_rejected_without_creating_record_or_file(): void
    {
        $spoofed = UploadedFile::fake()->createWithContent('payload.jpg', '<?php echo "owned";');

        $this->actingAs($this->admin)->post('/products/'.$this->product->id.'/images', [
            'images' => [$spoofed],
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_repeated_image_checksum_is_idempotent(): void
    {
        $first = UploadedFile::fake()->image('same.jpg', 80, 80);
        $second = UploadedFile::fake()->image('same-again.jpg', 80, 80);

        $this->actingAs($this->admin)->post('/products/'.$this->product->id.'/images', [
            'images' => [$first],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(1, 'images');

        $this->actingAs($this->admin)->post('/products/'.$this->product->id.'/images', [
            'images' => [$second],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(1, 'images');

        $this->assertSame(1, ProductImage::where('product_id', $this->product->id)->count());
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_configured_count_and_size_limits_are_enforced(): void
    {
        config()->set('integrations.pc_website.product_images.max_count', 1);
        $this->actingAs($this->admin)->post('/products/'.$this->product->id.'/images', [
            'images' => [
                UploadedFile::fake()->image('one.jpg', 20, 20),
                UploadedFile::fake()->image('two.jpg', 20, 20),
            ],
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        config()->set('integrations.pc_website.product_images.max_size_kb', 1);
        $this->actingAs($this->admin)->post('/products/'.$this->product->id.'/images', [
            'images' => [UploadedFile::fake()->image('large.jpg', 20, 20)->size(2)],
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertDatabaseCount('product_images', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_client_filename_cannot_control_storage_path(): void
    {
        $this->actingAs($this->admin)->post('/products/'.$this->product->id.'/images', [
            'images' => [UploadedFile::fake()->image('../escape.jpg', 20, 20)],
        ], ['Accept' => 'application/json'])->assertCreated();

        $image = ProductImage::where('product_id', $this->product->id)->sole();
        $this->assertMatchesRegularExpression(
            '#^products/'.$this->product->id.'/[0-9a-f-]{36}\.webp$#',
            $image->storage_path,
        );
        $this->assertStringNotContainsString('..', $image->storage_path);
    }

    public function test_upload_persists_only_webp_and_exposes_primary_image_to_product_list(): void
    {
        $this->actingAs($this->admin)->post('/products/'.$this->product->id.'/images', [
            'images' => [UploadedFile::fake()->image('original-photo.jpg', 120, 100)],
        ], ['Accept' => 'application/json'])->assertCreated();

        $image = ProductImage::where('product_id', $this->product->id)->sole();
        $this->assertStringEndsWith('.webp', $image->storage_path);
        $this->assertSame('image/webp', $image->mime_type);
        $this->assertSame([$image->storage_path], Storage::disk('public')->allFiles());
        $this->assertFalse(collect(Storage::disk('public')->allFiles())->contains(
            fn (string $path) => preg_match('/\.(?:jpe?g|png)$/i', $path) === 1
        ));

        $expectedUrl = $image->publicUrl();
        $this->assertSame($expectedUrl, $this->product->fresh()->image);

        $this->actingAs($this->admin)
            ->get('/products?search=IMAGE-PRODUCT')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Welcome')
                ->where('products.data.0.id', $this->product->id)
                ->where('products.data.0.image', $expectedUrl));
    }

    public function test_product_list_template_renders_image_and_keeps_broken_url_fallback(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/resources/js/Pages/Welcome.vue');

        $this->assertStringContainsString('v-if="canDisplayProductImage(product)"', $source);
        $this->assertStringContainsString(':src="product.image"', $source);
        $this->assertStringContainsString('@error="markProductImageFailed(product.image)"', $source);
        $this->assertStringContainsString('v-else', $source);
    }

    public function test_product_edit_cannot_navigate_away_while_image_upload_is_pending_or_failed(): void
    {
        $resourceRoot = dirname(__DIR__, 3).'/resources/js';
        $manager = file_get_contents($resourceRoot.'/Components/ProductImageManager.vue');
        $edit = file_get_contents($resourceRoot.'/Pages/Products/Edit.vue');

        $this->assertStringContainsString("'update:busy'", $manager);
        $this->assertStringContainsString("'update:error'", $manager);
        $this->assertStringContainsString('@update:busy="imageUploadBusy = $event"', $edit);
        $this->assertStringContainsString('@update:error="imageUploadError = $event"', $edit);
        $this->assertStringContainsString('form.processing || imageUploadBusy || Boolean(imageUploadError)', $edit);
        $this->assertStringContainsString('if (imageUploadBusy.value || imageUploadError.value) return;', $edit);
    }
}
