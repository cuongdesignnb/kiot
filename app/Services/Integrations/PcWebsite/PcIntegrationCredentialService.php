<?php

namespace App\Services\Integrations\PcWebsite;

use App\Exceptions\PcIntegrationException;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Models\IntegrationPairingToken;
use App\Models\PriceBook;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PcIntegrationCredentialService
{
    public function __construct(
        private readonly PcIntegrationCredentialResolver $resolver,
        private readonly SecureIntegrationUrl $url,
    ) {}

    /** @return array{client:IntegrationClient,secret:string} */
    public function create(array $data, User $actor): array
    {
        $secret = $this->generateSecret();

        $client = DB::transaction(function () use ($data, $actor, $secret) {
            $client = IntegrationClient::create(array_merge($this->runtimeFields($data), [
                'name' => trim((string) $data['name']),
                'provider' => IntegrationClient::PROVIDER_PC_WEBSITE,
                'client_id' => $this->generateClientId(),
                'secret_encrypted' => $secret,
                'secret_fingerprint' => $this->fingerprint($secret),
                'website_url' => $this->url->normalize((string) $data['website_url']),
                'is_enabled' => false,
                'api_version' => 'v1',
                'secret_created_at' => now(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]));

            $this->audit('integration.created', 'Tạo kết nối Website PC', $client, $actor, [
                'source' => 'database',
                'enabled' => false,
            ]);

            return $client;
        });

        return compact('client', 'secret');
    }

    public function update(IntegrationClient $client, array $data, User $actor): IntegrationClient
    {
        $this->assertPcClient($client);
        $updates = $this->runtimeFields($data);
        if (array_key_exists('name', $data)) {
            $updates['name'] = trim((string) $data['name']);
        }
        if (array_key_exists('website_url', $data)) {
            $updates['website_url'] = $this->url->normalize((string) $data['website_url']);
        }
        $updates['updated_by'] = $actor->id;
        $client->update($updates);
        $this->audit('integration.updated', 'Cập nhật kết nối Website PC', $client, $actor, [
            'changed_fields' => array_values(array_diff(array_keys($updates), ['updated_by'])),
        ]);

        return $client->refresh();
    }

    /** @return array{client:IntegrationClient,secret:string} */
    public function rotate(IntegrationClient $client, User $actor): array
    {
        $this->assertPcClient($client);
        if ($client->revoked_at !== null || ! $client->secret_encrypted) {
            throw new PcIntegrationException('INTEGRATION_REVOKED', 'Kết nối đã bị thu hồi.', 409);
        }

        $secret = $this->generateSecret();
        $graceSeconds = max(60, min(900, (int) config('integrations.pc_website.secret_rotation_grace_seconds', 900)));

        $client = DB::transaction(function () use ($client, $actor, $secret, $graceSeconds) {
            $locked = IntegrationClient::query()->lockForUpdate()->findOrFail($client->id);
            if ($locked->revoked_at !== null || ! $locked->secret_encrypted) {
                throw new PcIntegrationException('INTEGRATION_REVOKED', 'Kết nối đã bị thu hồi.', 409);
            }
            $locked->update([
                'previous_secret_encrypted' => $locked->secret_encrypted,
                'previous_secret_expires_at' => now()->addSeconds($graceSeconds),
                'secret_encrypted' => $secret,
                'secret_fingerprint' => $this->fingerprint($secret),
                'secret_rotated_at' => now(),
                'updated_by' => $actor->id,
            ]);
            $this->audit('integration.secret_rotated', 'Xoay vòng secret Website PC', $locked, $actor, [
                'grace_seconds' => $graceSeconds,
                'previous_secret_expires_at' => $locked->previous_secret_expires_at?->toIso8601String(),
            ]);

            return $locked;
        });

        return compact('client', 'secret');
    }

    public function setEnabled(IntegrationClient $client, bool $enabled, User $actor): IntegrationClient
    {
        $this->assertPcClient($client);
        if ($enabled && ! $this->isComplete($client)) {
            throw new PcIntegrationException('INTEGRATION_NOT_CONFIGURED', 'Kết nối chưa đủ cấu hình để bật.', 409);
        }

        $client->update(['is_enabled' => $enabled, 'updated_by' => $actor->id]);
        $this->audit(
            $enabled ? 'integration.enabled' : 'integration.disabled',
            $enabled ? 'Bật kết nối Website PC' : 'Tắt kết nối Website PC',
            $client,
            $actor,
            ['enabled' => $enabled],
        );

        return $client->refresh();
    }

    public function revoke(IntegrationClient $client, User $actor): IntegrationClient
    {
        $this->assertPcClient($client);
        $client = DB::transaction(function () use ($client, $actor) {
            $locked = IntegrationClient::query()->lockForUpdate()->findOrFail($client->id);
            $locked->update([
                'is_enabled' => false,
                'secret_encrypted' => null,
                'previous_secret_encrypted' => null,
                'previous_secret_expires_at' => null,
                'revoked_at' => $locked->revoked_at ?? now(),
                'updated_by' => $actor->id,
            ]);
            $locked->pairingTokens()->whereNull('used_at')->update(['used_at' => now()]);
            $this->audit('integration.revoked', 'Thu hồi kết nối Website PC', $locked, $actor);

            return $locked;
        });

        return $client->refresh();
    }

    /** @return array{client:IntegrationClient,reference:string,pairing_code:string,expires_at:\Illuminate\Support\Carbon} */
    public function issuePairingToken(IntegrationClient $client, User $actor): array
    {
        $this->assertPcClient($client);
        if (! $this->isComplete($client) || trim((string) $client->website_url) === '') {
            throw new PcIntegrationException('INTEGRATION_NOT_CONFIGURED', 'Kết nối chưa đủ cấu hình để ghép nối.', 409);
        }

        $ttl = max(60, min(600, (int) config('integrations.pc_website.pairing_ttl_seconds', 600)));
        $reference = Str::lower(Str::random(32));
        $pairingCode = $this->generateSecret();
        $expiresAt = now()->addSeconds($ttl);

        DB::transaction(function () use ($client, $actor, $reference, $pairingCode, $expiresAt) {
            IntegrationPairingToken::query()
                ->where('integration_client_id', $client->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
            IntegrationPairingToken::create([
                'integration_client_id' => $client->id,
                'reference' => $reference,
                'token_hash' => hash('sha256', $pairingCode),
                'expires_at' => $expiresAt,
                'created_by' => $actor->id,
            ]);
            $this->audit('integration.pairing_created', 'Tạo mã ghép nối Website PC', $client, $actor, [
                'expires_at' => $expiresAt->toIso8601String(),
            ]);
        });

        return [
            'client' => $client,
            'reference' => $reference,
            'pairing_code' => $pairingCode,
            'expires_at' => $expiresAt,
        ];
    }

    public function importEnvironment(User $actor): IntegrationClient
    {
        if ($this->resolver->hasDatabaseConfiguration()) {
            throw new PcIntegrationException('DATABASE_CONFIGURATION_EXISTS', 'Đã tồn tại cấu hình database.', 409);
        }

        $config = (array) config('integrations.pc_website', []);
        $clientId = trim((string) ($config['client_id'] ?? ''));
        $secret = (string) ($config['secret'] ?? '');
        $branchId = filter_var($config['default_branch_id'] ?? null, FILTER_VALIDATE_INT);
        $priceBookId = filter_var($config['product_price_book_id'] ?? null, FILTER_VALIDATE_INT);
        if ($clientId === '' || $secret === '' || ! $branchId || ! Branch::query()->whereKey($branchId)->exists()) {
            throw new PcIntegrationException('ENVIRONMENT_CONFIGURATION_INVALID', 'Cấu hình môi trường chưa hoàn chỉnh.', 409);
        }

        $client = IntegrationClient::create([
            'name' => 'Website PC (import từ môi trường)',
            'provider' => IntegrationClient::PROVIDER_PC_WEBSITE,
            'client_id' => $clientId,
            'secret_encrypted' => $secret,
            'secret_fingerprint' => $this->fingerprint($secret),
            'default_branch_id' => (int) $branchId,
            'pc_product_price_book_id' => $priceBookId && PriceBook::query()
                ->whereKey($priceBookId)
                ->where('is_active', true)
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
                ->exists() ? (int) $priceBookId : null,
            'sales_channel' => (string) ($config['sales_channel'] ?? 'Website PC'),
            'is_enabled' => (bool) ($config['enabled'] ?? false),
            'timestamp_tolerance_seconds' => max(1, (int) ($config['timestamp_tolerance_seconds'] ?? 300)),
            'nonce_ttl_seconds' => max(1, (int) ($config['nonce_ttl_seconds'] ?? 600)),
            'rate_limit_per_minute' => max(1, (int) ($config['rate_limit_per_minute'] ?? 60)),
            'reservation_ttl_minutes' => max(1, (int) ($config['reservation_ttl_minutes'] ?? 1440)),
            'secret_created_at' => now(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $this->audit('integration.created', 'Import cấu hình Website PC từ môi trường', $client, $actor, [
            'source' => 'environment_import',
            'enabled' => $client->is_enabled,
        ]);

        return $client;
    }

    public function isComplete(IntegrationClient $client): bool
    {
        return $client->revoked_at === null
            && trim((string) $client->client_id) !== ''
            && (string) $client->secret_encrypted !== ''
            && $client->default_branch_id !== null
            && Branch::query()->whereKey($client->default_branch_id)->exists();
    }

    private function runtimeFields(array $data): array
    {
        $fields = array_filter([
            'default_branch_id' => $data['default_branch_id'] ?? null,
            'sales_channel' => $data['sales_channel'] ?? null,
            'timestamp_tolerance_seconds' => $data['timestamp_tolerance_seconds'] ?? null,
            'nonce_ttl_seconds' => $data['nonce_ttl_seconds'] ?? null,
            'rate_limit_per_minute' => $data['rate_limit_per_minute'] ?? null,
            'reservation_ttl_minutes' => $data['reservation_ttl_minutes'] ?? null,
        ], fn ($value) => $value !== null);

        if (array_key_exists('pc_product_price_book_id', $data)) {
            $fields['pc_product_price_book_id'] = $data['pc_product_price_book_id'] ?: null;
        }

        return $fields;
    }

    private function generateClientId(): string
    {
        do {
            $clientId = 'pc_'.Str::lower(Str::random(32));
        } while (IntegrationClient::query()->where('provider', IntegrationClient::PROVIDER_PC_WEBSITE)->where('client_id', $clientId)->exists());

        return $clientId;
    }

    private function generateSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function fingerprint(string $secret): string
    {
        return substr(hash('sha256', $secret), 0, 8);
    }

    private function assertPcClient(IntegrationClient $client): void
    {
        if ($client->provider !== IntegrationClient::PROVIDER_PC_WEBSITE) {
            throw new PcIntegrationException('INTEGRATION_CLIENT_NOT_FOUND', 'Không tìm thấy kết nối Website PC.', 404);
        }
    }

    private function audit(string $action, string $description, IntegrationClient $client, User $actor, array $properties = []): void
    {
        ActivityLog::log($action, $description, $client, $properties, $actor->id);
    }
}
