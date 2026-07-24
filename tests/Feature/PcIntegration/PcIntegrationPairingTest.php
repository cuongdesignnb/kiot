<?php

namespace Tests\Feature\PcIntegration;

use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationPairingToken;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class PcIntegrationPairingTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('integrations.pc_website.management_ui_enabled', true);
        config()->set('integrations.pc_website.pairing_ttl_seconds', 600);
        config()->set('integrations.pc_website.pairing_max_attempts', 5);
        $this->admin = User::factory()->create();
        $this->branch = Branch::create(['name' => 'Pairing '.Str::uuid()]);
    }

    public function test_pairing_code_is_hashed_single_use_and_credentials_are_returned_once(): void
    {
        $client = $this->createClient();
        $issued = $this->actingAs($this->admin)
            ->postJson("/settings/integrations/website-pc/clients/{$client->id}/pairing-token")
            ->assertCreated()
            ->assertJsonStructure(['reference', 'pairing_code', 'expires_at']);

        $reference = $issued->json('reference');
        $code = $issued->json('pairing_code');
        $stored = IntegrationPairingToken::query()->where('reference', $reference)->firstOrFail();
        $this->assertNotSame($code, $stored->token_hash);
        $this->assertSame(hash('sha256', $code), $stored->token_hash);

        $paired = $this->postJson('/api/integrations/v1/pc/pair', [
            'reference' => $reference,
            'pairing_code' => $code,
            'website_url' => 'https://admin.laptopplus.vn',
        ])->assertOk()->assertJsonStructure(['client_id', 'secret', 'provider_url', 'api_version']);

        $this->assertSame($client->client_id, $paired->json('client_id'));
        $this->assertSame($client->secret_encrypted, $paired->json('secret'));
        $stored->refresh();
        $this->assertNotNull($stored->used_at);
        $this->assertNotNull($stored->used_by_ip);

        $this->postJson('/api/integrations/v1/pc/pair', [
            'reference' => $reference,
            'pairing_code' => $code,
            'website_url' => 'https://admin.laptopplus.vn',
        ])->assertStatus(409)->assertJsonPath('error.code', 'PAIRING_TOKEN_USED');
    }

    public function test_expired_token_wrong_origin_and_attempt_limit_fail_closed(): void
    {
        $client = $this->createClient();
        $issued = $this->actingAs($this->admin)
            ->postJson("/settings/integrations/website-pc/clients/{$client->id}/pairing-token")
            ->assertCreated();

        $payload = [
            'reference' => $issued->json('reference'),
            'pairing_code' => $issued->json('pairing_code'),
            'website_url' => 'https://evil.example.test',
        ];
        $this->postJson('/api/integrations/v1/pc/pair', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PAIRING_ORIGIN_MISMATCH');

        $token = IntegrationPairingToken::query()->where('reference', $payload['reference'])->firstOrFail();
        $token->update(['expires_at' => now()->subSecond()]);
        $payload['website_url'] = 'https://admin.laptopplus.vn';
        $this->postJson('/api/integrations/v1/pc/pair', $payload)
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'PAIRING_TOKEN_EXPIRED');

        $issued = $this->actingAs($this->admin)
            ->postJson("/settings/integrations/website-pc/clients/{$client->id}/pairing-token")
            ->assertCreated();
        $wrong = [
            'reference' => $issued->json('reference'),
            'pairing_code' => 'incorrect-pairing-code',
            'website_url' => 'https://admin.laptopplus.vn',
        ];
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/integrations/v1/pc/pair', $wrong)
                ->assertUnauthorized()
                ->assertJsonPath('error.code', 'INVALID_PAIRING_TOKEN');
        }
        $this->postJson('/api/integrations/v1/pc/pair', $wrong)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'PAIRING_ATTEMPTS_EXCEEDED');
    }

    public function test_plain_http_provider_is_rejected_in_production_mode(): void
    {
        $client = $this->createClient();
        $issued = $this->actingAs($this->admin)
            ->postJson("/settings/integrations/website-pc/clients/{$client->id}/pairing-token")
            ->assertCreated();
        config()->set('app.env', 'production');

        $this->postJson('/api/integrations/v1/pc/pair', [
            'reference' => $issued->json('reference'),
            'pairing_code' => $issued->json('pairing_code'),
            'website_url' => 'http://admin.laptopplus.vn',
        ])->assertStatus(422)->assertJsonPath('error.code', 'HTTPS_REQUIRED');
    }

    private function createClient(): IntegrationClient
    {
        $response = $this->actingAs($this->admin)->postJson('/settings/integrations/website-pc/clients', [
            'name' => 'LaptopPlus Website',
            'website_url' => 'https://admin.laptopplus.vn',
            'default_branch_id' => $this->branch->id,
            'sales_channel' => 'Website PC',
            'timestamp_tolerance_seconds' => 300,
            'nonce_ttl_seconds' => 600,
            'rate_limit_per_minute' => 60,
            'reservation_ttl_minutes' => 1440,
        ])->assertCreated();

        return IntegrationClient::query()->findOrFail($response->json('client.id'));
    }
}
