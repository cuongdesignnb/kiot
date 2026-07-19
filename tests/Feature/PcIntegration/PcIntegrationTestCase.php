<?php

namespace Tests\Feature\PcIntegration;

use App\Models\Branch;
use App\Models\Product;
use App\Services\Integrations\PcWebsite\PcIntegrationSignatureService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class PcIntegrationTestCase extends TestCase
{
    use DatabaseTransactions;

    protected Branch $integrationBranch;

    protected string $clientId = 'pc-website-test';

    protected string $secret = 'pc-integration-test-secret-with-sufficient-entropy';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        RateLimiter::clear('pc-integration:rate:'.hash('sha256', $this->clientId));

        $this->integrationBranch = Branch::create(['name' => 'PC Integration '.Str::uuid()]);
        config()->set('integrations.pc_website', [
            'enabled' => true,
            'client_id' => $this->clientId,
            'secret' => $this->secret,
            'default_branch_id' => $this->integrationBranch->id,
            'sales_channel' => 'Website PC',
            'timestamp_tolerance_seconds' => 300,
            'nonce_ttl_seconds' => 600,
            'rate_limit_per_minute' => 60,
            'reservation_ttl_minutes' => 1440,
        ]);
    }

    protected function signedHeaders(
        string $method,
        string $path,
        string $rawBody = '',
        ?string $nonce = null,
        ?int $timestamp = null,
        ?string $idempotencyKey = null,
    ): array {
        // Laravel's getJson() serializes its empty data argument as an empty JSON array.
        if (strtoupper($method) === 'GET' && $rawBody === '') {
            $rawBody = '[]';
        }
        $nonce ??= (string) Str::uuid();
        $timestamp ??= time();
        $signature = app(PcIntegrationSignatureService::class)->sign(
            $method,
            $path,
            (string) $timestamp,
            $nonce,
            $rawBody,
            $this->secret,
        );

        return array_filter([
            'X-Integration-Key' => $this->clientId,
            'X-Timestamp' => (string) $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => $signature,
            'Idempotency-Key' => $idempotencyKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], fn ($value) => $value !== null);
    }

    protected function postSignedJson(string $path, array $payload, string $idempotencyKey, ?string $nonce = null)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return $this->call(
            'POST',
            $path,
            [],
            [],
            [],
            $this->transformHeadersToServerVars($this->signedHeaders('POST', $path, $raw, $nonce, null, $idempotencyKey)),
            $raw,
        );
    }

    protected function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'sku' => 'PC-'.strtoupper(Str::random(12)),
            'name' => 'PC Integration Product',
            'type' => 'standard',
            'cost_price' => 500000,
            'retail_price' => 800000,
            'stock_quantity' => 10,
            'inventory_total_cost' => 5000000,
            'has_serial' => false,
            'is_active' => true,
            'sell_directly' => true,
            'weight' => '500',
            'warranty_months' => 24,
        ], $attributes));
    }

    protected function orderPayload(Product $product, array $overrides = []): array
    {
        $payload = [
            'event_id' => (string) Str::uuid(),
            'external_order_id' => 'EXT-'.Str::random(12),
            'external_order_code' => 'WEB-'.strtoupper(Str::random(10)),
            'ordered_at' => now()->toIso8601String(),
            'customer' => [
                'name' => 'Nguyễn Văn Test',
                'phone' => '098'.random_int(1000000, 9999999),
                'email' => 'pc-'.Str::random(8).'@example.test',
            ],
            'delivery' => [
                'is_delivery' => false,
                'receiver_name' => null,
                'receiver_phone' => null,
                'receiver_address' => null,
                'receiver_ward' => null,
                'receiver_district' => null,
                'receiver_city' => null,
                'weight' => 500,
                'shipping_fee' => 30000,
            ],
            'payment' => ['method' => 'sepay', 'status' => 'paid'],
            'totals' => [
                'subtotal' => 800000,
                'discount' => 0,
                'shipping_fee' => 30000,
                'total' => 830000,
            ],
            'items' => [[
                'sku' => $product->sku,
                'product_name' => $product->name,
                'quantity' => 1,
                'unit_price' => 800000,
                'discount' => 0,
                'line_total' => 800000,
                'bundle_ref' => null,
            ]],
            'note' => 'Đơn kiểm thử tích hợp',
        ];

        return array_replace_recursive($payload, $overrides);
    }
}
