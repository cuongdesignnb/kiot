<?php

namespace Tests\Feature\Products;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class Step247AdvancedProductSearchTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Step 247',
            'email' => 'admin-step-247-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);
    }

    private function product(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'sku' => 'SP247' . strtoupper(substr(uniqid(), -8)),
            'barcode' => null,
            'name' => 'Màn 13.3 FHD-HD cũ SPA đốm',
            'cost_price' => 1_000_000,
            'retail_price' => 1_500_000,
            'stock_quantity' => 5,
            'inventory_total_cost' => 5_000_000,
            'has_serial' => false,
            'is_active' => true,
        ], $overrides));
    }

    private function serial(Product $product, string $serialNumber, string $status = 'in_stock'): SerialImei
    {
        return SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => $serialNumber,
            'status' => $status,
            'repair_status' => 'ready',
            'cost_price' => 1_000_000,
        ]);
    }

    private function productIdsFromJson($response): array
    {
        return collect($response->json())->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_product_index_search_matches_non_contiguous_tokens(): void
    {
        $admin = $this->admin();
        $product = $this->product();

        $response = $this->actingAs($admin)->get('/products?search=' . urlencode('màn 13.3 đốm'));
        $response->assertOk();

        $ids = collect($response->viewData('page')['props']['products']['data'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains($product->id, $ids);
    }

    public function test_product_index_search_requires_all_tokens(): void
    {
        $admin = $this->admin();
        $matching = $this->product(['name' => 'Màn 13.3 FHD đốm']);
        $nonMatching = $this->product(['name' => 'Màn 14.0 FHD']);

        $response = $this->actingAs($admin)->get('/products?search=' . urlencode('màn 13.3 đốm'));
        $response->assertOk();

        $ids = collect($response->viewData('page')['props']['products']['data'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains($matching->id, $ids);
        $this->assertNotContains($nonMatching->id, $ids);
    }

    public function test_pos_product_search_matches_non_contiguous_tokens(): void
    {
        $admin = $this->admin();
        $product = $this->product();

        $response = $this->actingAs($admin)->getJson('/api/pos/products?search=' . urlencode('màn 13.3 đốm'));
        $response->assertOk();

        $this->assertContains($product->id, $this->productIdsFromJson($response));
    }

    public function test_api_product_search_matches_non_contiguous_tokens(): void
    {
        $admin = $this->admin();
        $product = $this->product();

        $response = $this->actingAs($admin)->getJson('/api/products/search?search=' . urlencode('màn 13.3 đốm'));
        $response->assertOk();

        $this->assertContains($product->id, $this->productIdsFromJson($response));
    }

    public function test_product_search_matches_sku_token(): void
    {
        $admin = $this->admin();
        $product = $this->product([
            'sku' => 'SP26041599330',
            'name' => 'Màn ThinkPad X1 Carbon đốm',
        ]);
        $other = $this->product([
            'sku' => 'SP26041234567',
            'name' => 'ThinkPad X1 Carbon sạch đẹp',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/products/search?search=' . urlencode('SP2604 màn'));
        $response->assertOk();

        $ids = $this->productIdsFromJson($response);
        $this->assertContains($product->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_product_search_matches_barcode(): void
    {
        $admin = $this->admin();
        $product = $this->product(['barcode' => 'BC-STEP-247-XYZ']);

        $response = $this->actingAs($admin)->getJson('/api/products/search?search=STEP-247');
        $response->assertOk();

        $this->assertContains($product->id, $this->productIdsFromJson($response));
    }

    public function test_product_search_matches_serial_and_pos_returns_matched_serials(): void
    {
        $admin = $this->admin();
        $product = $this->product([
            'has_serial' => true,
            'stock_quantity' => 1,
            'inventory_total_cost' => 1_000_000,
        ]);
        $serial = $this->serial($product, 'PF1RMTWJ');

        $response = $this->actingAs($admin)->getJson('/api/pos/products?search=PF1RMTWJ');
        $response->assertOk();

        $row = collect($response->json())->firstWhere('id', $product->id);
        $this->assertNotNull($row);
        $this->assertSame($serial->serial_number, $row['matched_serials'][0]['serial_number'] ?? null);
    }

    public function test_product_search_handles_hyphen_split(): void
    {
        $admin = $this->admin();
        $product = $this->product(['name' => 'Màn 13.3 FHD-HD cũ']);

        $response = $this->actingAs($admin)->getJson('/api/products/search?search=' . urlencode('fhd hd'));
        $response->assertOk();

        $this->assertContains($product->id, $this->productIdsFromJson($response));
    }

    public function test_product_search_escapes_like_wildcards(): void
    {
        $admin = $this->admin();
        $product = $this->product(['name' => 'Plain Step 247 Product']);

        $response = $this->actingAs($admin)->getJson('/api/products/search?search=' . urlencode('% _'));
        $response->assertOk();

        $this->assertNotContains($product->id, $this->productIdsFromJson($response));
    }

    public function test_product_search_does_not_mutate_stock_or_serials(): void
    {
        $admin = $this->admin();
        $product = $this->product([
            'has_serial' => true,
            'stock_quantity' => 1,
            'inventory_total_cost' => 1_000_000,
        ]);
        $serial = $this->serial($product, 'NO-MUTATE-247');

        $this->actingAs($admin)->getJson('/api/pos/products?search=NO-MUTATE-247')->assertOk();

        $this->assertSame(1, (int) $product->fresh()->stock_quantity);
        $this->assertSame('in_stock', $serial->fresh()->status);
    }

    public function test_returnable_invoice_product_search_matches_non_contiguous_tokens(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $invoice = Invoice::create([
            'code' => 'HD247' . uniqid(),
            'status' => 'Hoàn thành',
            'total' => 1_500_000,
            'subtotal' => 1_500_000,
            'discount' => 0,
            'customer_paid' => 1_500_000,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 1_500_000,
            'cost_price' => 1_000_000,
            'discount' => 0,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/pos/returnable-invoices?search=' . urlencode('màn 13.3 đốm'));
        $response->assertOk();

        $ids = collect($response->json())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($invoice->id, $ids);
    }

    public function test_repair_product_search_endpoints_match_non_contiguous_tokens(): void
    {
        $admin = $this->admin();
        $product = $this->product();

        $deviceRepairResponse = $this->actingAs($admin)
            ->getJson('/api/device-repairs/search-products?q=' . urlencode('màn 13.3 đốm'));
        $deviceRepairResponse->assertOk();
        $this->assertContains($product->id, $this->productIdsFromJson($deviceRepairResponse));

        $taskResponse = $this->actingAs($admin)
            ->getJson('/api/tasks/search-products?q=' . urlencode('màn 13.3 đốm'));
        $taskResponse->assertOk();
        $this->assertContains($product->id, $this->productIdsFromJson($taskResponse));
    }
}
