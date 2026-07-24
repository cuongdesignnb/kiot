<?php

namespace Tests\Feature\PcIntegration;

use App\Models\IntegrationClient;

class PcIntegrationRuntimeConfigurationTest extends PcIntegrationTestCase
{
    public function test_database_credentials_take_precedence_over_environment_as_one_complete_source(): void
    {
        $databaseSecret = 'database-runtime-secret-with-sufficient-entropy';
        $client = $this->databaseClient([
            'client_id' => 'database-pc-client',
            'secret_encrypted' => $databaseSecret,
            'sales_channel' => 'Database Channel',
        ]);

        $this->secret = $databaseSecret;
        $this->clientId = $client->client_id;

        $this->getJson('/api/integrations/v1/pc/connection', $this->signedHeaders('GET', '/api/integrations/v1/pc/connection'))
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('configuration_source', 'database')
            ->assertJsonPath('client_id', 'database-pc-client')
            ->assertJsonMissing(['secret' => $databaseSecret]);

        $this->secret = (string) config('integrations.pc_website.secret');
        $this->clientId = (string) config('integrations.pc_website.client_id');
        $this->getJson('/api/integrations/v1/pc/connection', $this->signedHeaders('GET', '/api/integrations/v1/pc/connection'))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_INTEGRATION_CLIENT');
    }

    public function test_environment_is_used_only_when_no_database_connection_exists(): void
    {
        $this->getJson('/api/integrations/v1/pc/connection', $this->signedHeaders('GET', '/api/integrations/v1/pc/connection'))
            ->assertOk()
            ->assertJsonPath('configuration_source', 'environment');

        $this->databaseClient([
            'is_enabled' => false,
            'client_id' => 'disabled-database-client',
        ]);

        $this->getJson('/api/integrations/v1/pc/connection', $this->signedHeaders('GET', '/api/integrations/v1/pc/connection'))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_INTEGRATION_CLIENT');
    }

    public function test_current_and_unexpired_previous_secrets_are_accepted_but_expired_previous_secret_is_rejected(): void
    {
        $currentSecret = 'current-database-secret-with-sufficient-entropy';
        $previousSecret = 'previous-database-secret-with-sufficient-entropy';
        $client = $this->databaseClient([
            'secret_encrypted' => $currentSecret,
            'previous_secret_encrypted' => $previousSecret,
            'previous_secret_expires_at' => now()->addMinutes(15),
        ]);
        $this->clientId = $client->client_id;

        $this->secret = $currentSecret;
        $this->getJson('/api/integrations/v1/pc/connection', $this->signedHeaders('GET', '/api/integrations/v1/pc/connection'))->assertOk();

        $this->secret = $previousSecret;
        $this->getJson('/api/integrations/v1/pc/connection', $this->signedHeaders('GET', '/api/integrations/v1/pc/connection'))->assertOk();

        $client->update(['previous_secret_expires_at' => now()->subSecond()]);
        $this->getJson('/api/integrations/v1/pc/connection', $this->signedHeaders('GET', '/api/integrations/v1/pc/connection'))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_SIGNATURE');
    }

    public function test_connection_endpoint_reports_safe_capabilities_and_records_handshake_metadata(): void
    {
        $client = $this->databaseClient();
        $this->clientId = $client->client_id;
        $this->secret = $client->secret_encrypted;

        $response = $this->getJson('/api/integrations/v1/pc/connection', $this->signedHeaders('GET', '/api/integrations/v1/pc/connection'));

        $response->assertOk()
            ->assertJsonPath('provider', 'kiot')
            ->assertJsonPath('api_version', 'v1')
            ->assertJsonPath('capabilities.products', true)
            ->assertJsonPath('capabilities.orders', true)
            ->assertJsonPath('capabilities.price_books', false)
            ->assertJsonPath('capabilities.google_sheets', false)
            ->assertJsonMissingPath('environment')
            ->assertJsonMissingPath('database_id')
            ->assertJsonMissingPath('secret');

        $client->refresh();
        $this->assertNotNull($client->last_request_at);
        $this->assertNotNull($client->last_connected_at);
        $this->assertNotNull($client->last_request_ip);
    }

    private function databaseClient(array $overrides = []): IntegrationClient
    {
        return IntegrationClient::create(array_merge([
            'name' => 'Runtime Website PC',
            'provider' => IntegrationClient::PROVIDER_PC_WEBSITE,
            'client_id' => 'runtime-database-client',
            'secret_encrypted' => 'runtime-database-secret-with-sufficient-entropy',
            'secret_fingerprint' => '12345678',
            'website_url' => 'https://pc.example.test',
            'default_branch_id' => $this->integrationBranch->id,
            'sales_channel' => 'Website PC',
            'is_enabled' => true,
            'timestamp_tolerance_seconds' => 300,
            'nonce_ttl_seconds' => 600,
            'rate_limit_per_minute' => 60,
            'reservation_ttl_minutes' => 1440,
            'secret_created_at' => now(),
        ], $overrides));
    }
}
