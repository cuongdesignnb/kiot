<?php

namespace Tests\Feature\InventoryApi;

use App\Models\Category;
use App\Models\InventoryApiAccessLog;
use App\Models\InventoryApiToken;
use App\Models\Product;
use App\Models\Role;
use App\Models\SerialImei;
use App\Models\User;
use App\Services\Integrations\InventoryApi\InventoryApiTokenService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use DatabaseTransactions;

    private const PRODUCTS = '/api/public/v1/inventory/products';

    private const SERIALS = '/api/public/v1/inventory/serials';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('integrations.inventory_api.enabled', true);
        config()->set('integrations.inventory_api.rate_limit_per_minute', 60);
        config()->set('integrations.inventory_api.failed_auth_per_minute', 100);
        $this->admin = User::factory()->create();
    }

    public function test_admin_creates_token_secret_is_shown_once_and_only_hash_is_stored(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/settings/integrations/inventory-api/tokens', [
            'name' => 'Laptop Plus - Google Sheet',
            'account_label' => 'laptopplus.vn bot',
        ]);

        $response->assertCreated()
            ->assertJsonPath('token.name', 'Laptop Plus - Google Sheet')
            ->assertJsonPath('token.abilities', [InventoryApiToken::ABILITY_INVENTORY_READ])
            ->assertJsonMissingPath('token.token_hash');
        $plain = (string) $response->json('plain_text_token');
        $this->assertStringStartsWith(InventoryApiToken::TOKEN_PREFIX, $plain);

        $token = InventoryApiToken::findOrFail($response->json('token.id'));
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertNotSame($plain, $token->token_hash);
        $this->assertDatabaseMissing('inventory_api_tokens', ['token_hash' => $plain]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'inventory_api.token_created', 'subject_id' => $token->id]);

        $page = $this->actingAs($this->admin)->get('/settings/integrations/inventory-api');
        $page->assertOk()->assertInertia(fn (Assert $inertia) => $inertia
            ->component('Settings/Integrations/InventoryApi')
            ->where('tokens.0.id', $token->id)
            ->missing('tokens.0.token_hash')
            ->missing('tokens.0.plain_text_token'));
        $this->assertStringNotContainsString($plain, $page->getContent());

        $this->getJson(self::PRODUCTS.'?limit=1', $this->bearer($plain))->assertOk();
        $token->refresh();
        $this->assertNotNull($token->last_used_at);
        $this->assertSame(1, $token->request_count);
    }

    public function test_only_managers_can_create_or_revoke_tokens(): void
    {
        $viewerRole = Role::create([
            'name' => 'inventory-api-viewer-'.Str::random(8),
            'display_name' => 'Inventory API Viewer',
            'permissions' => ['integrations.view'],
            'is_system' => false,
        ]);
        $viewer = User::factory()->create(['role_id' => $viewerRole->id]);
        [$token] = app(InventoryApiTokenService::class)->create('Existing', null, $this->admin->id);

        $this->actingAs($viewer)->get('/settings/integrations/inventory-api')->assertOk();
        $this->actingAs($viewer)->postJson('/settings/integrations/inventory-api/tokens', ['name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->postJson("/settings/integrations/inventory-api/tokens/{$token->id}/revoke")->assertForbidden();
        $this->assertNull($token->fresh()->revoked_at);
    }

    public function test_missing_wrong_and_revoked_tokens_are_rejected_with_401_and_audited(): void
    {
        [$token, $plain] = $this->makeToken();

        $this->getJson(self::PRODUCTS)->assertUnauthorized()->assertJsonPath('error.code', 'MISSING_TOKEN');
        $this->getJson(self::PRODUCTS, $this->bearer('kinv_wrong-token'))->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_TOKEN');
        $this->getJson(self::SERIALS, $this->bearer('not-even-a-prefix'))->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_TOKEN');
        $this->getJson(self::PRODUCTS, ['X-Integration-Key' => $plain])->assertUnauthorized();

        $this->getJson(self::PRODUCTS, $this->bearer($plain))->assertOk();

        $this->actingAs($this->admin)->postJson("/settings/integrations/inventory-api/tokens/{$token->id}/revoke")
            ->assertOk()->assertJsonPath('token.id', $token->id);
        $this->assertNotNull($token->fresh()->revoked_at);
        $this->assertDatabaseHas('activity_logs', ['action' => 'inventory_api.token_revoked', 'subject_id' => $token->id]);

        $this->app['auth']->forgetGuards();
        $this->getJson(self::PRODUCTS, $this->bearer($plain))->assertUnauthorized()->assertJsonPath('error.code', 'TOKEN_REVOKED');
        $this->getJson(self::SERIALS, $this->bearer($plain))->assertUnauthorized()->assertJsonPath('error.code', 'TOKEN_REVOKED');

        $outcomes = InventoryApiAccessLog::query()->latest('id')->limit(7)->pluck('outcome')->all();
        $this->assertContains('missing_token', $outcomes);
        $this->assertContains('invalid_token', $outcomes);
        $this->assertContains('revoked', $outcomes);
        $this->assertContains('ok', $outcomes);
        $this->assertSame(2, InventoryApiAccessLog::where('inventory_api_token_id', $token->id)->where('outcome', 'revoked')->count());
    }

    public function test_token_is_read_only_and_cannot_reach_other_apis(): void
    {
        [, $plain] = $this->makeToken();
        $product = $this->makeProduct();
        $before = $product->fresh()->toArray();

        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->{$method}(self::PRODUCTS, ['stock_quantity' => 999], $this->bearer($plain))->assertStatus(405);
            $this->{$method}(self::SERIALS, [], $this->bearer($plain))->assertStatus(405);
        }

        // Not a Sanctum token and not Website PC HMAC credentials.
        $this->getJson('/api/user', $this->bearer($plain))->assertUnauthorized();
        $this->getJson('/api/media', $this->bearer($plain))->assertUnauthorized();
        $orders = $this->postJson('/api/integrations/v1/pc/orders', [], $this->bearer($plain));
        $this->assertGreaterThanOrEqual(400, $orders->status());
        $pcProducts = $this->getJson('/api/integrations/v1/pc/products', $this->bearer($plain));
        $this->assertGreaterThanOrEqual(400, $pcProducts->status());

        $this->assertSame($before['stock_quantity'], $product->fresh()->stock_quantity);
        $this->assertSame($before['updated_at'], $product->fresh()->toArray()['updated_at']);
    }

    public function test_products_report_ready_and_repairing_counts_without_cost_or_internal_fields(): void
    {
        [, $plain] = $this->makeToken();
        $category = Category::create(['name' => 'Laptop Dell '.Str::random(5)]);
        $product = $this->makeProduct(['category_id' => $category->id, 'has_serial' => true, 'stock_quantity' => 3, 'retail_price' => 15990000]);
        $ready = $this->makeSerial($product, ['repair_status' => 'ready']);
        $this->makeSerial($product, ['repair_status' => 'repairing']);
        $this->makeSerial($product, ['repair_status' => null]);
        $this->makeSerial($product, ['status' => 'sold', 'repair_status' => 'ready']);

        $response = $this->getJson(self::PRODUCTS.'?sku='.$product->sku, $this->bearer($plain));
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sku', $product->sku)
            ->assertJsonPath('data.0.category.id', $category->id)
            ->assertJsonPath('data.0.category.name', $category->name)
            ->assertJsonPath('data.0.retail_price', 15990000)
            ->assertJsonPath('data.0.stock_quantity', 3)
            ->assertJsonPath('data.0.ready_serial_quantity', 2)
            ->assertJsonPath('data.0.repairing_serial_quantity', 1)
            ->assertJsonPath('data.0.available_quantity', 2)
            ->assertJsonPath('data.0.inventory_status', 'available')
            ->assertJsonStructure(['data' => [['id', 'sku', 'name', 'updated_at']], 'meta' => ['next_cursor', 'has_more', 'sync_watermark']]);

        $item = $response->json('data.0');
        foreach (['cost_price', 'inventory_total_cost', 'last_purchase_price', 'supplier_id', 'description', 'images'] as $field) {
            $this->assertArrayNotHasKey($field, $item, "Leaked product field {$field}");
        }

        $serials = $this->getJson(self::SERIALS.'?sku='.$product->sku, $this->bearer($plain));
        $serials->assertOk()->assertJsonCount(3, 'data');
        $row = collect($serials->json('data'))->firstWhere('id', $ready->id);
        $this->assertSame($ready->serial_number, $row['serial']);
        $this->assertSame('in_stock', $row['status']);
        $this->assertSame('ready', $row['repair_status']);
        $this->assertSame('ready', $row['readiness']);
        $this->assertSame($product->sku, $row['product']['sku']);
        foreach (['cost_price', 'original_cost', 'sold_cost_price', 'purchase_id', 'purchase_return_id', 'invoice_id'] as $field) {
            $this->assertArrayNotHasKey($field, $row, "Leaked serial field {$field}");
        }
        $this->assertStringNotContainsString('cost', strtolower(json_encode(array_keys($row))));

        $this->getJson(self::SERIALS.'?sku='.$product->sku.'&readiness=repairing', $this->bearer($plain))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.readiness', 'repairing');
        $this->getJson(self::SERIALS.'?sku='.$product->sku.'&status=all', $this->bearer($plain))
            ->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_cursor_pagination_returns_every_row_exactly_once(): void
    {
        [, $plain] = $this->makeToken();
        $since = now()->subSeconds(5)->startOfSecond();
        $skus = collect(range(1, 5))->map(fn () => $this->makeProduct()->sku)->all();
        $product = $this->makeProduct(['has_serial' => true]);
        $serials = collect(range(1, 5))->map(fn () => $this->makeSerial($product)->serial_number)->all();

        $collected = $this->collectPages(self::PRODUCTS, $since->toIso8601String(), $plain, 'sku');
        foreach ($skus as $sku) {
            $this->assertSame(1, collect($collected)->filter(fn ($value) => $value === $sku)->count(), "SKU {$sku} not returned exactly once");
        }

        $collectedSerials = $this->collectPages(self::SERIALS, $since->toIso8601String(), $plain, 'serial');
        foreach ($serials as $serial) {
            $this->assertSame(1, collect($collectedSerials)->filter(fn ($value) => $value === $serial)->count());
        }

        $this->getJson(self::PRODUCTS.'?limit=101', $this->bearer($plain))->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->getJson(self::PRODUCTS.'?cursor=not-a-cursor', $this->bearer($plain))->assertStatus(422)->assertJsonPath('error.code', 'INVALID_CURSOR');
    }

    public function test_updated_since_catches_repair_status_change_even_without_product_touch(): void
    {
        [, $plain] = $this->makeToken();
        $product = $this->makeProduct(['has_serial' => true, 'stock_quantity' => 1]);
        $serial = $this->makeSerial($product, ['repair_status' => 'ready']);
        $old = now()->subDays(2);
        DB::table('products')->where('id', $product->id)->update(['updated_at' => $old]);
        DB::table('serial_imeis')->where('id', $serial->id)->update(['updated_at' => $old]);

        $since = now()->subHour()->toIso8601String();
        $query = '?updated_since='.urlencode($since).'&sku='.$product->sku;
        $this->getJson(self::PRODUCTS.$query, $this->bearer($plain))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::SERIALS.$query, $this->bearer($plain))->assertOk()->assertJsonCount(0, 'data');

        // Bulk update bypasses model events, so the product row is NOT touched.
        SerialImei::query()->whereKey($serial->id)->update(['repair_status' => 'repairing']);
        $this->assertTrue($product->fresh()->updated_at->lt(now()->subDay()));

        $this->getJson(self::PRODUCTS.$query, $this->bearer($plain))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ready_serial_quantity', 0)
            ->assertJsonPath('data.0.repairing_serial_quantity', 1)
            ->assertJsonPath('data.0.available_quantity', 0)
            ->assertJsonPath('data.0.inventory_status', 'repairing');
        $this->getJson(self::SERIALS.$query, $this->bearer($plain))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.repair_status', 'repairing')
            ->assertJsonPath('data.0.readiness', 'repairing');

        // A sale (status change) appears in the serial feed with updated_since,
        // so the consumer can drop it; the default feed only lists in-stock serials.
        $serial->refresh()->update(['status' => 'sold']);
        $this->getJson(self::SERIALS.$query, $this->bearer($plain))->assertOk()
            ->assertJsonPath('data.0.status', 'sold')
            ->assertJsonPath('data.0.readiness', 'not_in_stock');
        $this->getJson(self::SERIALS.'?sku='.$product->sku, $this->bearer($plain))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_deleted_products_are_reported_in_incremental_sync(): void
    {
        [, $plain] = $this->makeToken();
        $product = $this->makeProduct();
        $since = now()->subMinute()->toIso8601String();
        $product->delete();

        $this->getJson(self::PRODUCTS.'?sku='.$product->sku, $this->bearer($plain))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::PRODUCTS.'?sku='.$product->sku.'&updated_since='.urlencode($since), $this->bearer($plain))
            ->assertOk()->assertJsonPath('data.0.deleted', true);
    }

    public function test_requests_are_rate_limited_per_token(): void
    {
        config()->set('integrations.inventory_api.rate_limit_per_minute', 2);
        [, $plain] = $this->makeToken();
        [, $other] = $this->makeToken();

        $this->getJson(self::PRODUCTS.'?limit=1', $this->bearer($plain))->assertOk()->assertHeader('X-RateLimit-Limit', '2');
        $this->getJson(self::PRODUCTS.'?limit=1', $this->bearer($plain))->assertOk();
        $this->getJson(self::PRODUCTS.'?limit=1', $this->bearer($plain))->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeader('Retry-After');
        $this->getJson(self::PRODUCTS.'?limit=1', $this->bearer($other))->assertOk();
    }

    public function test_failed_authentication_is_throttled_per_ip(): void
    {
        config()->set('integrations.inventory_api.failed_auth_per_minute', 2);

        $this->getJson(self::PRODUCTS, $this->bearer('kinv_guess-1'))->assertUnauthorized();
        $this->getJson(self::PRODUCTS, $this->bearer('kinv_guess-2'))->assertUnauthorized();
        $this->getJson(self::PRODUCTS, $this->bearer('kinv_guess-3'))->assertStatus(429)
            ->assertJsonPath('error.code', 'TOO_MANY_FAILED_ATTEMPTS');
    }

    private function collectPages(string $path, string $since, string $plain, string $field): array
    {
        $values = [];
        $cursor = null;
        for ($page = 0; $page < 50; $page++) {
            $url = $path.'?limit=2&updated_since='.urlencode($since).($cursor ? '&cursor='.urlencode($cursor) : '');
            $response = $this->getJson($url, $this->bearer($plain))->assertOk();
            $this->assertLessThanOrEqual(2, count($response->json('data')));
            foreach ($response->json('data') as $row) {
                $values[] = $row[$field];
            }
            if (! $response->json('meta.has_more')) {
                return $values;
            }
            $cursor = $response->json('meta.next_cursor');
        }

        $this->fail('Pagination did not terminate.');
    }

    private function makeToken(): array
    {
        return app(InventoryApiTokenService::class)->create('Test token '.Str::random(6), 'Laptop Plus', $this->admin->id);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'sku' => 'INV-'.strtoupper(Str::random(12)),
            'name' => 'Inventory API Product',
            'type' => 'standard',
            'cost_price' => 500000,
            'retail_price' => 800000,
            'stock_quantity' => 10,
            'inventory_total_cost' => 5000000,
            'has_serial' => false,
            'is_active' => true,
            'sell_directly' => true,
        ], $attributes));
    }

    private function makeSerial(Product $product, array $attributes = []): SerialImei
    {
        return SerialImei::create(array_merge([
            'product_id' => $product->id,
            'serial_number' => 'SN-'.strtoupper(Str::random(14)),
            'status' => 'in_stock',
            'repair_status' => 'ready',
            'cost_price' => 4000000,
        ], $attributes));
    }
}
