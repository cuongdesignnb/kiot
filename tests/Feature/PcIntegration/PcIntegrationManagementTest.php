<?php

namespace Tests\Feature\PcIntegration;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\PriceBook;
use App\Models\Role;
use App\Models\User;
use App\Services\Integrations\PcWebsite\PcIntegrationCredentialResolver;
use App\Services\Integrations\PcWebsite\PcIntegrationSignatureService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PcIntegrationManagementTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config()->set('integrations.pc_website.management_ui_enabled', true);
        config()->set('integrations.pc_website.enabled', false);
        config()->set('integrations.pc_website.client_id', null);
        config()->set('integrations.pc_website.secret', null);

        $this->admin = User::factory()->create();
        $this->branch = Branch::create(['name' => 'Chi nhánh Phase 2A '.Str::uuid()]);
    }

    public function test_admin_can_create_connection_and_plaintext_secret_is_returned_once_only(): void
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
        ]);

        $response->assertCreated()
            ->assertJsonPath('client.name', 'LaptopPlus Website')
            ->assertJsonPath('client.is_enabled', false)
            ->assertJsonStructure(['client' => ['id', 'client_id', 'secret_fingerprint'], 'secret']);

        $secret = $response->json('secret');
        $clientId = $response->json('client.client_id');
        $this->assertIsString($secret);
        $this->assertGreaterThanOrEqual(32, strlen($secret));

        $ciphertext = DB::table('integration_clients')->where('client_id', $clientId)->value('secret_encrypted');
        $this->assertNotSame($secret, $ciphertext);
        $this->assertStringNotContainsString($secret, (string) $ciphertext);

        $page = $this->actingAs($this->admin)->get('/settings/integrations/website-pc');
        $page->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Integrations/PcWebsite')
            ->where('clients.0.client_id', $clientId)
            ->where('clients.0.secret_fingerprint', substr(hash('sha256', $secret), 0, 8))
            ->missing('clients.0.secret')
            ->missing('clients.0.secret_encrypted')
            ->where('configuration_source', 'database'));
        $this->assertStringNotContainsString($secret, $page->getContent());

        $log = ActivityLog::query()->where('action', 'integration.created')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString($secret, json_encode($log->properties));
    }

    public function test_rotate_secret_keeps_previous_secret_only_for_the_configured_grace_period(): void
    {
        $created = $this->createConnection();
        $client = IntegrationClient::query()->findOrFail($created['id']);
        $oldSecret = $created['secret'];

        config()->set('integrations.pc_website.secret_rotation_grace_seconds', 900);
        $response = $this->actingAs($this->admin)
            ->postJson("/settings/integrations/website-pc/clients/{$client->id}/rotate-secret")
            ->assertOk()
            ->assertJsonStructure(['secret', 'client' => ['secret_fingerprint', 'previous_secret_expires_at']]);

        $newSecret = $response->json('secret');
        $this->assertNotSame($oldSecret, $newSecret);

        $client->refresh();
        $this->assertSame($oldSecret, $client->previous_secret_encrypted);
        $this->assertSame($newSecret, $client->secret_encrypted);
        $this->assertTrue($client->previous_secret_expires_at->between(now()->addSeconds(895), now()->addSeconds(905)));

        $page = $this->actingAs($this->admin)->get('/settings/integrations/website-pc');
        $this->assertStringNotContainsString($oldSecret, $page->getContent());
        $this->assertStringNotContainsString($newSecret, $page->getContent());
    }

    public function test_environment_configuration_can_be_imported_once_without_exposing_secret(): void
    {
        $environmentSecret = 'environment-bootstrap-secret-'.Str::random(24);
        config()->set('integrations.pc_website', array_merge(config('integrations.pc_website'), [
            'enabled' => true,
            'client_id' => 'legacy-pc-client',
            'secret' => $environmentSecret,
            'default_branch_id' => $this->branch->id,
            'sales_channel' => 'Website PC Legacy',
        ]));

        $response = $this->actingAs($this->admin)
            ->postJson('/settings/integrations/website-pc/import-environment')
            ->assertCreated()
            ->assertJsonMissing(['secret' => $environmentSecret]);

        $client = IntegrationClient::query()->where('client_id', 'legacy-pc-client')->firstOrFail();
        $this->assertSame($environmentSecret, $client->secret_encrypted);
        $this->assertSame('Website PC Legacy', $client->sales_channel);
        $this->assertSame('database', $response->json('configuration_source'));

        $this->actingAs($this->admin)
            ->postJson('/settings/integrations/website-pc/import-environment')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DATABASE_CONFIGURATION_EXISTS');
    }

    public function test_soft_deleted_database_client_blocks_environment_fallback_and_old_credentials(): void
    {
        $environmentClientId = 'environment-fallback-client';
        $environmentSecret = 'environment-fallback-secret-with-sufficient-entropy';
        config()->set('integrations.pc_website', array_merge(config('integrations.pc_website'), [
            'enabled' => true,
            'client_id' => $environmentClientId,
            'secret' => $environmentSecret,
            'default_branch_id' => $this->branch->id,
        ]));

        $deletedSecret = 'soft-deleted-client-secret-with-sufficient-entropy';
        $deletedClient = IntegrationClient::create([
            'name' => 'Soft-deleted Website PC',
            'provider' => IntegrationClient::PROVIDER_PC_WEBSITE,
            'client_id' => 'soft-deleted-client',
            'secret_encrypted' => $deletedSecret,
            'secret_fingerprint' => substr(hash('sha256', $deletedSecret), 0, 8),
            'website_url' => 'https://deleted.example.test',
            'default_branch_id' => $this->branch->id,
            'sales_channel' => 'Website PC',
            'is_enabled' => true,
            'timestamp_tolerance_seconds' => 300,
            'nonce_ttl_seconds' => 600,
            'rate_limit_per_minute' => 60,
            'reservation_ttl_minutes' => 1440,
            'api_version' => 'v1',
            'secret_created_at' => now(),
        ]);
        $deletedClient->delete();

        $resolver = app(PcIntegrationCredentialResolver::class);
        $this->assertTrue($resolver->hasDatabaseConfiguration());
        $this->assertSame('database', $resolver->source());
        $this->assertNull($resolver->resolve());
        $this->assertNull($resolver->resolve($environmentClientId));
        $this->assertNull($resolver->resolve($deletedClient->client_id));
        $this->assertTrue(IntegrationClient::withTrashed()->findOrFail($deletedClient->id)->trashed());
        $this->assertFalse(IntegrationClient::query()->whereKey($deletedClient->id)->exists());

        $path = '/api/integrations/v1/pc/connection';
        $signature = app(PcIntegrationSignatureService::class);
        foreach ([
            [$environmentClientId, $environmentSecret],
            [$deletedClient->client_id, $deletedSecret],
        ] as [$clientId, $secret]) {
            $timestamp = (string) time();
            $nonce = (string) Str::uuid();
            $this->getJson($path, [
                'X-Integration-Key' => $clientId,
                'X-Timestamp' => $timestamp,
                'X-Nonce' => $nonce,
                'X-Signature' => $signature->sign('GET', $path, $timestamp, $nonce, '[]', $secret),
            ])->assertUnauthorized()
                ->assertJsonPath('error.code', 'INVALID_INTEGRATION_CLIENT');
        }
    }

    public function test_management_permissions_and_rollout_flag_are_enforced(): void
    {
        $viewerRole = Role::create([
            'name' => 'integration-viewer-'.Str::random(8),
            'display_name' => 'Integration Viewer',
            'permissions' => ['integrations.view'],
            'is_system' => false,
        ]);
        $viewer = User::factory()->create(['role_id' => $viewerRole->id]);

        $this->actingAs($viewer)->get('/settings/integrations/website-pc')->assertOk();
        $this->actingAs($viewer)->postJson('/settings/integrations/website-pc/clients', [])->assertForbidden();

        config()->set('integrations.pc_website.management_ui_enabled', false);
        $this->actingAs($this->admin)
            ->postJson('/settings/integrations/website-pc/clients', [])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'INTEGRATION_MANAGEMENT_DISABLED');
    }

    public function test_client_can_select_only_an_active_price_book_and_can_clear_selection(): void
    {
        $created = $this->createConnection();
        $active = PriceBook::create(['code' => 'WEB', 'name' => 'Website', 'is_active' => true, 'status' => 'active']);
        $inactive = PriceBook::create(['code' => 'OLD', 'name' => 'Old', 'is_active' => false, 'status' => 'inactive']);
        $expired = PriceBook::create([
            'code' => 'EXPIRED',
            'name' => 'Expired',
            'is_active' => true,
            'status' => 'active',
            'end_date' => today()->subDay(),
        ]);

        $this->actingAs($this->admin)
            ->patchJson("/settings/integrations/website-pc/clients/{$created['id']}", ['pc_product_price_book_id' => $active->id])
            ->assertOk()
            ->assertJsonPath('client.pc_product_price_book_id', $active->id);
        $this->assertDatabaseHas('integration_clients', ['id' => $created['id'], 'pc_product_price_book_id' => $active->id]);

        $this->actingAs($this->admin)
            ->patchJson("/settings/integrations/website-pc/clients/{$created['id']}", ['pc_product_price_book_id' => $inactive->id])
            ->assertUnprocessable();

        $this->actingAs($this->admin)
            ->patchJson("/settings/integrations/website-pc/clients/{$created['id']}", ['pc_product_price_book_id' => $expired->id])
            ->assertUnprocessable();

        $this->actingAs($this->admin)
            ->patchJson("/settings/integrations/website-pc/clients/{$created['id']}", ['pc_product_price_book_id' => null])
            ->assertOk()
            ->assertJsonPath('client.pc_product_price_book_id', null);
        $this->assertDatabaseHas('integration_clients', ['id' => $created['id'], 'pc_product_price_book_id' => null]);
    }

    public function test_enable_disable_and_revoke_are_audited_without_credentials(): void
    {
        $created = $this->createConnection();
        $client = IntegrationClient::query()->findOrFail($created['id']);

        $this->actingAs($this->admin)->postJson("/settings/integrations/website-pc/clients/{$client->id}/enable")->assertOk();
        $this->actingAs($this->admin)->postJson("/settings/integrations/website-pc/clients/{$client->id}/disable")->assertOk();
        $this->actingAs($this->admin)->postJson("/settings/integrations/website-pc/clients/{$client->id}/revoke")->assertOk();

        $client->refresh();
        $this->assertFalse($client->is_enabled);
        $this->assertNotNull($client->revoked_at);
        $this->assertNull($client->secret_encrypted);
        $this->assertNull($client->previous_secret_encrypted);

        $actions = ActivityLog::query()
            ->where('subject_type', IntegrationClient::class)
            ->where('subject_id', $client->id)
            ->pluck('action')
            ->all();
        $this->assertContains('integration.enabled', $actions);
        $this->assertContains('integration.disabled', $actions);
        $this->assertContains('integration.revoked', $actions);

        $serialized = ActivityLog::query()
            ->where('subject_type', IntegrationClient::class)
            ->where('subject_id', $client->id)
            ->get()
            ->toJson();
        $this->assertStringNotContainsString($created['secret'], $serialized);
    }

    /** @return array{id:int,secret:string} */
    private function createConnection(): array
    {
        $response = $this->actingAs($this->admin)->postJson('/settings/integrations/website-pc/clients', [
            'name' => 'Website PC '.Str::uuid(),
            'website_url' => 'https://pc.example.test',
            'default_branch_id' => $this->branch->id,
            'sales_channel' => 'Website PC',
            'timestamp_tolerance_seconds' => 300,
            'nonce_ttl_seconds' => 600,
            'rate_limit_per_minute' => 60,
            'reservation_ttl_minutes' => 1440,
        ])->assertCreated();

        return [
            'id' => (int) $response->json('client.id'),
            'secret' => (string) $response->json('secret'),
        ];
    }
}
