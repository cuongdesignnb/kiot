<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Services\Integrations\PcWebsite\PcIntegrationSignatureService;
use App\Support\Integrations\PcWebsite\PcIntegrationResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class VerifyPcIntegrationSignature
{
    public function __construct(private readonly PcIntegrationSignatureService $signatureService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $config = (array) config('integrations.pc_website', []);
        if (! (bool) ($config['enabled'] ?? false)) {
            return $this->reject($request, 'INTEGRATION_DISABLED', 'Tích hợp Website PC đang tắt.', 503);
        }

        $configuredClient = trim((string) ($config['client_id'] ?? ''));
        $secret = (string) ($config['secret'] ?? '');
        $branchId = filter_var($config['default_branch_id'] ?? null, FILTER_VALIDATE_INT);
        if ($configuredClient === '' || $secret === '' || ! $branchId || ! Branch::query()->whereKey($branchId)->exists()) {
            return $this->reject($request, 'INTEGRATION_NOT_CONFIGURED', 'Cấu hình tích hợp Website PC chưa hoàn chỉnh.', 503);
        }

        $client = trim((string) $request->header('X-Integration-Key', ''));
        $timestamp = trim((string) $request->header('X-Timestamp', ''));
        $nonce = trim((string) $request->header('X-Nonce', ''));
        $signature = strtolower(trim((string) $request->header('X-Signature', '')));

        if ($client === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return $this->reject($request, 'INVALID_INTEGRATION_CLIENT', 'Thiếu header xác thực tích hợp.', 401, $client);
        }
        if (strlen($nonce) > 128 || ! ctype_digit($timestamp) || ! preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return $this->reject($request, 'INVALID_SIGNATURE', 'Thông tin chữ ký tích hợp không hợp lệ.', 401, $client);
        }
        if (! hash_equals($configuredClient, $client)) {
            return $this->reject($request, 'INVALID_INTEGRATION_CLIENT', 'Client tích hợp không hợp lệ.', 401, $client);
        }

        $tolerance = max(1, (int) ($config['timestamp_tolerance_seconds'] ?? 300));
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return $this->reject($request, 'EXPIRED_TIMESTAMP', 'Timestamp của request đã hết hạn hoặc lệch quá giới hạn.', 401, $client);
        }

        $rawBody = (string) $request->getContent();
        $expected = $this->signatureService->sign(
            $request->getMethod(),
            $request->getPathInfo(),
            $timestamp,
            $nonce,
            $rawBody,
            $secret,
        );
        if (! hash_equals($expected, $signature)) {
            return $this->reject($request, 'INVALID_SIGNATURE', 'Chữ ký tích hợp không hợp lệ.', 401, $client);
        }

        $rateKey = 'pc-integration:rate:'.hash('sha256', $client);
        $maxAttempts = max(1, (int) ($config['rate_limit_per_minute'] ?? 60));
        if (RateLimiter::tooManyAttempts($rateKey, $maxAttempts)) {
            return PcIntegrationResponse::error('RATE_LIMITED', 'Đã vượt quá giới hạn request.', [
                'retry_after_seconds' => RateLimiter::availableIn($rateKey),
            ], 429);
        }
        RateLimiter::hit($rateKey, 60);

        $nonceKey = 'pc-integration:nonce:'.hash('sha256', $client."\n".$nonce);
        $nonceTtl = max($tolerance, (int) ($config['nonce_ttl_seconds'] ?? 600));
        if (! Cache::add($nonceKey, true, now()->addSeconds($nonceTtl))) {
            return $this->reject($request, 'REPLAYED_NONCE', 'Nonce đã được sử dụng.', 409, $client);
        }

        $request->attributes->set('pc_integration_client', $client);
        $request->attributes->set('pc_integration_payload_hash', hash('sha256', $rawBody));

        return $next($request);
    }

    private function reject(Request $request, string $code, string $message, int $status, string $client = ''): JsonResponse
    {
        Log::warning('PC integration request rejected', [
            'code' => $code,
            'client_hash' => $client !== '' ? substr(hash('sha256', $client), 0, 12) : null,
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'ip' => $request->ip(),
        ]);

        return PcIntegrationResponse::error($code, $message, [], $status);
    }
}
