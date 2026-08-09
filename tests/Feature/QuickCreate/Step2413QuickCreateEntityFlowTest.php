<?php

namespace Tests\Feature\QuickCreate;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

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
            'name' => 'QA 2413',
            'email' => 'qa-2413-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function prepareImageUpload(): void
    {
        Storage::fake('public');
        config()->set('integrations.pc_website.product_images.disk', 'public');
        config()->set('app.url', 'https://kiot.example.test');
    }

    public function test_product_quick_store_returns_json_without_stock_mutation(): void
    {
        $user = $this->actor();
        $before = Schema::hasTable('stock_movements') ? DB::table('stock_movements')->count() : 0;

        $res = $this->actingAs($user)->postJson('/products/quick-store', [
            'name' => 'Sản phẩm test 2413',
            'cost_price' => 800000,
            'retail_price' => 1200000,
        ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('product.name', 'Sản phẩm test 2413')
            ->assertJsonPath('product.images', [])
            ->assertJsonPath('product.primary_image', null);

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

    public function test_product_quick_store_accepts_one_image_and_returns_public_primary_image(): void
    {
        $this->prepareImageUpload();
        $user = $this->actor();
        $name = 'Quick image 2413 single';

        $response = $this->actingAs($user)->post('/products/quick-store', [
            'name' => $name,
            'cost_price' => 800000,
            'retail_price' => 1200000,
            'images' => [UploadedFile::fake()->image('front.jpg', 120, 100)],
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('product.primary_image.is_primary', true)
            ->assertJsonCount(1, 'product.images')
            ->assertJsonMissingPath('product.images.0.storage_path');

        $product = Product::where('name', $name)->with('images')->sole();
        $image = $product->images->sole();
        $this->assertTrue($image->is_primary);
        $this->assertSame(1, ProductImage::where('product_id', $product->id)->where('is_primary', true)->count());
        Storage::disk('public')->assertExists($image->storage_path);
        $this->assertStringStartsWith('https://', $response->json('product.primary_image.url'));
    }

    public function test_product_quick_store_accepts_multiple_images_and_explicit_primary(): void
    {
        $this->prepareImageUpload();
        $user = $this->actor();
        $name = 'Quick image 2413 multiple';

        $response = $this->actingAs($user)->post('/products/quick-store', [
            'name' => $name,
            'cost_price' => 800000,
            'retail_price' => 1200000,
            'images' => [
                UploadedFile::fake()->image('front.jpg', 120, 100),
                UploadedFile::fake()->image('back.png', 100, 120),
            ],
            'primary_image_index' => 1,
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonCount(2, 'product.images');
        $product = Product::where('name', $name)->with('images')->sole();
        $images = $product->images->sortBy('sort_order')->values();
        $this->assertSame(1, $images->where('is_primary', true)->count());
        $this->assertTrue($images[1]->is_primary);
        $this->assertSame($images[1]->id, $response->json('product.primary_image.id'));
    }

    public function test_product_quick_store_rejects_invalid_image_without_product_or_file(): void
    {
        $this->prepareImageUpload();
        $user = $this->actor();
        $name = 'Quick image 2413 invalid';

        $this->actingAs($user)->post('/products/quick-store', [
            'name' => $name,
            'cost_price' => 800000,
            'retail_price' => 1200000,
            'images' => [UploadedFile::fake()->createWithContent('payload.jpg', '<?php echo "owned";')],
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertDatabaseMissing('products', ['name' => $name]);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_product_quick_store_rejects_oversized_image_without_product_or_file(): void
    {
        $this->prepareImageUpload();
        config()->set('integrations.pc_website.product_images.max_size_kb', 1);
        $user = $this->actor();
        $name = 'Quick image 2413 oversized';

        $this->actingAs($user)->post('/products/quick-store', [
            'name' => $name,
            'cost_price' => 800000,
            'retail_price' => 1200000,
            'images' => [UploadedFile::fake()->image('large.jpg', 20, 20)->size(2)],
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertDatabaseMissing('products', ['name' => $name]);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_product_quick_store_rejects_too_many_images_without_product_or_file(): void
    {
        $this->prepareImageUpload();
        config()->set('integrations.pc_website.product_images.max_count', 1);
        $user = $this->actor();
        $name = 'Quick image 2413 too many';

        $this->actingAs($user)->post('/products/quick-store', [
            'name' => $name,
            'cost_price' => 800000,
            'retail_price' => 1200000,
            'images' => [
                UploadedFile::fake()->image('front.jpg', 120, 100),
                UploadedFile::fake()->image('back.png', 100, 120),
            ],
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertDatabaseMissing('products', ['name' => $name]);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_product_quick_store_rolls_back_product_when_image_processing_fails(): void
    {
        $this->prepareImageUpload();
        $user = $this->actor();
        $name = 'Quick image 2413 corrupt';

        $this->actingAs($user)->post('/products/quick-store', [
            'name' => $name,
            'cost_price' => 800000,
            'retail_price' => 1200000,
            'images' => [UploadedFile::fake()->create('corrupt.png', 1, 'image/png')],
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertDatabaseMissing('products', ['name' => $name]);
        $this->assertSame([], ProductImage::whereHas('product', fn ($query) => $query->where('name', $name))->get()->all());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_product_quick_store_money_payload_must_be_numeric(): void
    {
        $user = $this->actor();

        // Backend validates numeric — formatted "1.000.000đ" must be rejected.
        $this->actingAs($user)
            ->postJson('/products/quick-store', [
                'name' => 'SP money 2413',
                'cost_price' => '1.000.000đ',
                'retail_price' => 1500000,
            ])
            ->assertStatus(422);
    }

    public function test_supplier_quick_store_returns_json_with_supplier_flag_true(): void
    {
        $user = $this->actor();

        $res = $this->actingAs($user)->postJson('/api/suppliers/quick-store', [
            'name' => 'NCC Test 2413',
            'phone' => '0900'.random_int(100000, 999999),
            'email' => 'ncc-2413-'.uniqid().'@test.local',
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
            'name' => 'NCC Full Store 2413',
            'phone' => '0911'.random_int(100000, 999999),
            'email' => 'full-2413-'.uniqid().'@test.local',
        ]);

        // Must NOT be an HTML redirect — the in-context quick add depends on
        // the JSON path. 200 or 201, body has supplier.id.
        $this->assertContains($res->status(), [200, 201], 'expected JSON 200/201, got '.$res->status());
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
            'name' => 'KH Test 2413',
            'phone' => '0922'.random_int(100000, 999999),
        ]);

        $this->assertContains($res->status(), [200, 201], 'expected JSON 200/201, got '.$res->status());
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

    public function test_product_validation_failure_creates_no_image_rows(): void
    {
        $this->prepareImageUpload();
        $user = $this->actor();

        $this->actingAs($user)->post('/products/quick-store', [
            'name' => '',
            'cost_price' => 800000,
            'retail_price' => 1200000,
            'images' => [UploadedFile::fake()->image('front.jpg', 120, 100)],
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertSame([], ProductImage::all()->all());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_supplier_quick_store_validates_required_name(): void
    {
        $user = $this->actor();
        $this->actingAs($user)
            ->postJson('/api/suppliers/quick-store', ['name' => ''])
            ->assertStatus(422);
    }
}
