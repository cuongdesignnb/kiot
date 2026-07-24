<?php

namespace App\Services\Integrations\PcWebsite;

use App\Exceptions\PcIntegrationException;
use App\Models\ActivityLog;
use App\Models\IntegrationPairingToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class PcIntegrationPairingService
{
    public function __construct(private readonly SecureIntegrationUrl $url) {}

    /** @return array{client_id:string,secret:string,provider_url:string,api_version:string} */
    public function pair(array $payload, Request $request): array
    {
        $reference = trim((string) ($payload['reference'] ?? ''));
        $code = (string) ($payload['pairing_code'] ?? '');
        $rateKey = 'pc-integration:pair:'.hash('sha256', $reference."\n".(string) $request->ip());
        $maxAttempts = max(1, min(5, (int) config('integrations.pc_website.pairing_max_attempts', 5)));
        if (RateLimiter::tooManyAttempts($rateKey, $maxAttempts)) {
            throw new PcIntegrationException('PAIRING_ATTEMPTS_EXCEEDED', 'Đã vượt quá số lần thử mã ghép nối.', 429, [
                'retry_after_seconds' => RateLimiter::availableIn($rateKey),
            ]);
        }
        RateLimiter::hit($rateKey, 600);

        $result = DB::transaction(function () use ($reference, $code, $payload, $request, $maxAttempts) {
            $token = IntegrationPairingToken::query()
                ->with('integrationClient')
                ->where('reference', $reference)
                ->lockForUpdate()
                ->first();

            if (! $token) {
                return ['error' => new PcIntegrationException('INVALID_PAIRING_TOKEN', 'Mã ghép nối không hợp lệ.', 401)];
            }
            if ($token->attempt_count >= $maxAttempts) {
                return ['error' => new PcIntegrationException('PAIRING_ATTEMPTS_EXCEEDED', 'Đã vượt quá số lần thử mã ghép nối.', 429)];
            }
            if ($token->used_at !== null) {
                return ['error' => new PcIntegrationException('PAIRING_TOKEN_USED', 'Mã ghép nối đã được sử dụng.', 409)];
            }
            if ($token->expires_at->isPast()) {
                return ['error' => new PcIntegrationException('PAIRING_TOKEN_EXPIRED', 'Mã ghép nối đã hết hạn.', 410)];
            }

            $websiteUrl = $this->url->normalize((string) ($payload['website_url'] ?? ''));
            $client = $token->integrationClient;
            if (! $client || $client->revoked_at !== null || ! $client->secret_encrypted) {
                return ['error' => new PcIntegrationException('INTEGRATION_REVOKED', 'Kết nối đã bị thu hồi.', 409)];
            }
            if (! hash_equals((string) $client->website_url, $websiteUrl)) {
                $token->increment('attempt_count');

                return ['error' => new PcIntegrationException('PAIRING_ORIGIN_MISMATCH', 'Website URL không khớp với kết nối đã tạo.', 422)];
            }

            $providedHash = hash('sha256', $code);
            if (! hash_equals((string) $token->token_hash, $providedHash)) {
                $token->increment('attempt_count');

                return ['error' => new PcIntegrationException('INVALID_PAIRING_TOKEN', 'Mã ghép nối không hợp lệ.', 401)];
            }

            $token->update([
                'used_at' => now(),
                'used_by_ip' => mb_substr((string) $request->ip(), 0, 45),
            ]);
            ActivityLog::log('integration.paired', 'Ghép nối Website PC thành công', $client, [
                'website_url' => $client->website_url,
            ]);

            return ['credentials' => [
                'client_id' => $client->client_id,
                'secret' => $client->secret_encrypted,
                'provider_url' => rtrim((string) config('app.url'), '/'),
                'api_version' => (string) $client->api_version,
            ]];
        });

        if (isset($result['error'])) {
            throw $result['error'];
        }

        return $result['credentials'];
    }
}
