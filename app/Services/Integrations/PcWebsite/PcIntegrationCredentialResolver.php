<?php

namespace App\Services\Integrations\PcWebsite;

use App\Models\IntegrationClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PcIntegrationCredentialResolver
{
    public function hasDatabaseConfiguration(): bool
    {
        return Schema::hasTable('integration_clients')
            && IntegrationClient::withTrashed()
                ->where('provider', IntegrationClient::PROVIDER_PC_WEBSITE)
                ->exists();
    }

    public function source(): string
    {
        if ($this->hasDatabaseConfiguration()) {
            return 'database';
        }

        $config = (array) config('integrations.pc_website', []);

        return trim((string) ($config['client_id'] ?? '')) !== ''
            || (string) ($config['secret'] ?? '') !== ''
            || ! empty($config['default_branch_id'])
                ? 'environment'
                : 'none';
    }

    public function resolve(?string $clientId = null): ?RuntimePcIntegrationConfig
    {
        if ($this->hasDatabaseConfiguration()) {
            $query = IntegrationClient::query()
                ->where('provider', IntegrationClient::PROVIDER_PC_WEBSITE);

            if ($clientId !== null && trim($clientId) !== '') {
                $query->where('client_id', trim($clientId));
            } else {
                if ((clone $query)->count() !== 1) {
                    return null;
                }
                $query->orderByDesc('is_enabled')->orderBy('id');
            }

            $client = $query->first();

            return $client ? $this->fromDatabase($client) : null;
        }

        return $this->fromEnvironment();
    }

    public function recordAuthenticatedRequest(RuntimePcIntegrationConfig $runtime, Request $request, bool $handshake = false): void
    {
        if ($runtime->source !== 'database' || $runtime->integrationClientId === null) {
            return;
        }

        $updates = [
            'last_request_at' => now(),
            'last_request_ip' => mb_substr((string) $request->ip(), 0, 45),
            'updated_at' => now(),
        ];
        if ($handshake) {
            $updates['last_connected_at'] = now();
        }

        IntegrationClient::query()->whereKey($runtime->integrationClientId)->update($updates);
    }

    private function fromDatabase(IntegrationClient $client): RuntimePcIntegrationConfig
    {
        return new RuntimePcIntegrationConfig(
            source: 'database',
            integrationClientId: $client->id,
            enabled: (bool) $client->is_enabled,
            clientId: trim((string) $client->client_id),
            currentSecret: (string) ($client->secret_encrypted ?? ''),
            previousSecret: $client->previous_secret_encrypted,
            previousSecretExpiresAt: $client->previous_secret_expires_at,
            defaultBranchId: $this->integerOrNull($client->default_branch_id),
            salesChannel: (string) $client->sales_channel,
            timestampToleranceSeconds: max(1, (int) $client->timestamp_tolerance_seconds),
            nonceTtlSeconds: max(1, (int) $client->nonce_ttl_seconds),
            rateLimitPerMinute: max(1, (int) $client->rate_limit_per_minute),
            reservationTtlMinutes: max(1, (int) $client->reservation_ttl_minutes),
            apiVersion: (string) $client->api_version,
            revoked: $client->revoked_at !== null,
        );
    }

    private function fromEnvironment(): RuntimePcIntegrationConfig
    {
        $config = (array) config('integrations.pc_website', []);

        return new RuntimePcIntegrationConfig(
            source: 'environment',
            integrationClientId: null,
            enabled: (bool) ($config['enabled'] ?? false),
            clientId: trim((string) ($config['client_id'] ?? '')),
            currentSecret: (string) ($config['secret'] ?? ''),
            previousSecret: null,
            previousSecretExpiresAt: null,
            defaultBranchId: $this->integerOrNull($config['default_branch_id'] ?? null),
            salesChannel: (string) ($config['sales_channel'] ?? 'Website PC'),
            timestampToleranceSeconds: max(1, (int) ($config['timestamp_tolerance_seconds'] ?? 300)),
            nonceTtlSeconds: max(1, (int) ($config['nonce_ttl_seconds'] ?? 600)),
            rateLimitPerMinute: max(1, (int) ($config['rate_limit_per_minute'] ?? 60)),
            reservationTtlMinutes: max(1, (int) ($config['reservation_ttl_minutes'] ?? 1440)),
        );
    }

    private function integerOrNull(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer !== false && $integer > 0 ? (int) $integer : null;
    }
}
