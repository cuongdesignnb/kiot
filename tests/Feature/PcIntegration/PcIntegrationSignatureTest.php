<?php

namespace Tests\Feature\PcIntegration;

use Illuminate\Support\Str;

class PcIntegrationSignatureTest extends PcIntegrationTestCase
{
    public function test_valid_signature_allows_request(): void
    {
        $response = $this->getJson('/api/integrations/v1/pc/products', $this->signedHeaders('GET', '/api/integrations/v1/pc/products'));

        $response->assertOk()->assertJsonPath('success', true);
    }

    public function test_real_empty_get_body_and_query_exclusion_match_the_wire_contract(): void
    {
        $routePath = '/api/integrations/v1/pc/products';
        $requestPath = $routePath.'?limit=1';

        $response = $this->call(
            'GET',
            $requestPath,
            [],
            [],
            [],
            $this->transformHeadersToServerVars($this->signedHeadersForRawBody('GET', $routePath, '')),
            '',
        );

        $response->assertOk()->assertJsonPath('success', true);
    }

    public function test_feature_flag_and_incomplete_configuration_fail_closed(): void
    {
        config()->set('integrations.pc_website.enabled', false);
        $this->getJson('/api/integrations/v1/pc/products')->assertStatus(503)->assertJsonPath('error.code', 'INTEGRATION_DISABLED');

        config()->set('integrations.pc_website.enabled', true);
        config()->set('integrations.pc_website.client_id', '');
        $this->getJson('/api/integrations/v1/pc/products')->assertStatus(503)->assertJsonPath('error.code', 'INTEGRATION_NOT_CONFIGURED');

        config()->set('integrations.pc_website.client_id', $this->clientId);
        config()->set('integrations.pc_website.secret', '');
        $this->getJson('/api/integrations/v1/pc/products')->assertStatus(503)->assertJsonPath('error.code', 'INTEGRATION_NOT_CONFIGURED');

        config()->set('integrations.pc_website.secret', $this->secret);
        config()->set('integrations.pc_website.default_branch_id', PHP_INT_MAX);
        $this->getJson('/api/integrations/v1/pc/products')->assertStatus(503)->assertJsonPath('error.code', 'INTEGRATION_NOT_CONFIGURED');

        config()->set('integrations.pc_website.default_branch_id', $this->integrationBranch->id);
        $this->integrationBranch->delete();
        $this->getJson('/api/integrations/v1/pc/products')->assertStatus(503)->assertJsonPath('error.code', 'INTEGRATION_NOT_CONFIGURED');
    }

    public function test_invalid_client_signature_and_missing_headers_are_rejected(): void
    {
        $path = '/api/integrations/v1/pc/products';
        $headers = $this->signedHeaders('GET', $path);
        $headers['X-Integration-Key'] = 'wrong-client';
        $this->getJson($path, $headers)->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_INTEGRATION_CLIENT');

        $headers = $this->signedHeaders('GET', $path);
        $headers['X-Signature'] = str_repeat('0', 64);
        $this->getJson($path, $headers)->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_SIGNATURE');

        $this->getJson($path)->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_INTEGRATION_CLIENT');
    }

    public function test_each_required_authentication_header_and_overlong_nonce_fail_closed(): void
    {
        $path = '/api/integrations/v1/pc/products';

        foreach (['X-Integration-Key', 'X-Timestamp', 'X-Nonce', 'X-Signature'] as $missingHeader) {
            $headers = $this->signedHeaders('GET', $path);
            unset($headers[$missingHeader]);

            $this->getJson($path, $headers)
                ->assertUnauthorized()
                ->assertJsonPath('error.code', 'INVALID_INTEGRATION_CLIENT');
        }

        $this->getJson($path, $this->signedHeaders('GET', $path, '', str_repeat('n', 129)))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_SIGNATURE');
    }

    public function test_method_path_and_raw_body_tampering_are_rejected(): void
    {
        $path = '/api/integrations/v1/pc/orders';
        $product = $this->makeProduct();
        $raw = json_encode(
            $this->orderPayload($product),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );

        $methodHeaders = $this->signedHeaders('GET', $path, $raw, null, null, 'tamper-method');
        $this->call('POST', $path, [], [], [], $this->transformHeadersToServerVars($methodHeaders), $raw)
            ->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_SIGNATURE');

        $pathHeaders = $this->signedHeaders('POST', $path.'/wrong', $raw, null, null, 'tamper-path');
        $this->call('POST', $path, [], [], [], $this->transformHeadersToServerVars($pathHeaders), $raw)
            ->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_SIGNATURE');

        $bodyHeaders = $this->signedHeaders('POST', $path, $raw, null, null, 'tamper-body');
        $this->call('POST', $path, [], [], [], $this->transformHeadersToServerVars($bodyHeaders), $raw.' ')
            ->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_SIGNATURE');
    }

    public function test_past_and_future_timestamps_are_rejected(): void
    {
        $path = '/api/integrations/v1/pc/products';
        $this->getJson($path, $this->signedHeaders('GET', $path, '', null, time() - 301))
            ->assertUnauthorized()->assertJsonPath('error.code', 'EXPIRED_TIMESTAMP');
        $this->getJson($path, $this->signedHeaders('GET', $path, '', null, time() + 301))
            ->assertUnauthorized()->assertJsonPath('error.code', 'EXPIRED_TIMESTAMP');
    }

    public function test_nonce_replay_is_rejected(): void
    {
        $path = '/api/integrations/v1/pc/products';
        $nonce = (string) Str::uuid();
        $headers = $this->signedHeaders('GET', $path, '', $nonce);

        $this->getJson($path, $headers)->assertOk();
        $this->getJson($path, $headers)->assertStatus(409)->assertJsonPath('error.code', 'REPLAYED_NONCE');
    }

    public function test_rate_limit_is_enforced_per_client(): void
    {
        config()->set('integrations.pc_website.rate_limit_per_minute', 1);
        $path = '/api/integrations/v1/pc/products';

        $this->getJson($path, $this->signedHeaders('GET', $path))->assertOk();
        $this->getJson($path, $this->signedHeaders('GET', $path))->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');

        $this->travel(61)->seconds();
        $this->getJson($path, $this->signedHeaders('GET', $path))->assertOk();
    }

    private function signedHeadersForRawBody(string $method, string $path, string $rawBody): array
    {
        $nonce = (string) Str::uuid();
        $timestamp = time();
        $signature = hash_hmac(
            'sha256',
            implode("\n", [strtoupper($method), $path, (string) $timestamp, $nonce, hash('sha256', $rawBody)]),
            $this->secret,
        );

        return [
            'X-Integration-Key' => $this->clientId,
            'X-Timestamp' => (string) $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => $signature,
            'Accept' => 'application/json',
        ];
    }
}
