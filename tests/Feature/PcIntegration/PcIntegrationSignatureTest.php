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

    public function test_feature_flag_and_incomplete_configuration_fail_closed(): void
    {
        config()->set('integrations.pc_website.enabled', false);
        $this->getJson('/api/integrations/v1/pc/products')->assertStatus(503)->assertJsonPath('error.code', 'INTEGRATION_DISABLED');

        config()->set('integrations.pc_website.enabled', true);
        config()->set('integrations.pc_website.secret', '');
        $this->getJson('/api/integrations/v1/pc/products')->assertStatus(503)->assertJsonPath('error.code', 'INTEGRATION_NOT_CONFIGURED');

        config()->set('integrations.pc_website.secret', $this->secret);
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
    }
}
