<?php

namespace App\Http\Middleware;

use App\Models\InventoryApiAccessLog;
use App\Models\InventoryApiToken;
use App\Services\Integrations\InventoryApi\InventoryApiTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Bearer-token gate for /api/public/v1/* (inventory, data and write routes).
 *
 * Usage: `inventory.api` (= inventory:read) or `inventory.api:<ability>`.
 *
 * Tokens are separate from Sanctum and from the Website PC HMAC credentials,
 * so they can never reach any other API route. Read abilities accept only
 * safe (GET/HEAD) requests; each write ability accepts only the one method of
 * its route (PATCH / POST) and has its own, stricter per-token rate limit.
 * Every request is recorded in inventory_api_access_logs.
 */
class AuthenticateInventoryApiToken
{
    public const RESULT_COUNT_ATTRIBUTE = 'inventory_api_result_count';

    public const TOKEN_ATTRIBUTE = 'inventory_api_token';

    private const FORBIDDEN_MESSAGES = [
        InventoryApiToken::ABILITY_INVENTORY_READ => 'Token không có quyền đọc tồn kho.',
        InventoryApiToken::ABILITY_DATA_READ => 'Token không có quyền đọc dữ liệu (data:read).',
        InventoryApiToken::ABILITY_TECHNICIAN_PRICE_WRITE => 'Token không có quyền sửa giá bán thợ (products:technician_price:write).',
        InventoryApiToken::ABILITY_INVOICES_CREATE => 'Token không có quyền tạo hóa đơn (invoices:create).',
    ];

    public function __construct(private readonly InventoryApiTokenService $tokens) {}

    public function handle(Request $request, Closure $next, string $ability = InventoryApiToken::ABILITY_INVENTORY_READ): Response
    {
        $startedAt = microtime(true);

        if (! config('integrations.inventory_api.enabled', true)) {
            return $this->reject($request, null, 503, 'API_DISABLED', 'API tồn kho đang tạm tắt.', 'disabled', $startedAt);
        }

        $isWrite = InventoryApiToken::isWriteAbility($ability);
        $allowedMethods = $isWrite
            ? [InventoryApiToken::WRITE_ABILITY_METHODS[$ability]]
            : ['GET', 'HEAD'];
        if (! in_array($request->getMethod(), $allowedMethods, true)) {
            $message = $isWrite
                ? 'Chức năng ghi này chỉ nhận phương thức '.$allowedMethods[0].'.'
                : 'API này chỉ cho phép đọc (GET).';

            return $this->reject($request, null, 405, 'METHOD_NOT_ALLOWED', $message, 'method_not_allowed', $startedAt, [
                'Allow' => implode(', ', $allowedMethods),
            ]);
        }

        $failKey = 'inventory-api:failed-auth:'.sha1((string) $request->ip());
        $failLimit = max(1, (int) config('integrations.inventory_api.failed_auth_per_minute', 20));
        if (RateLimiter::tooManyAttempts($failKey, $failLimit)) {
            return $this->reject($request, null, 429, 'TOO_MANY_FAILED_ATTEMPTS', 'Quá nhiều lần xác thực sai. Vui lòng thử lại sau.', 'rate_limited', $startedAt, [
                'Retry-After' => (string) RateLimiter::availableIn($failKey),
            ]);
        }

        $plain = (string) $request->bearerToken();
        if ($plain === '') {
            RateLimiter::hit($failKey, 60);

            return $this->reject($request, null, 401, 'MISSING_TOKEN', 'Thiếu header Authorization: Bearer <token>.', 'missing_token', $startedAt);
        }

        $token = $this->tokens->findByPlainToken($plain);
        if (! $token) {
            RateLimiter::hit($failKey, 60);

            return $this->reject($request, null, 401, 'INVALID_TOKEN', 'Token không hợp lệ.', 'invalid_token', $startedAt);
        }
        if ($token->isRevoked()) {
            RateLimiter::hit($failKey, 60);

            return $this->reject($request, $token, 401, 'TOKEN_REVOKED', 'Token đã bị thu hồi.', 'revoked', $startedAt);
        }
        if (! in_array($ability, InventoryApiToken::allAbilities(), true) || ! $token->allows($ability)) {
            return $this->reject($request, $token, 403, 'FORBIDDEN', self::FORBIDDEN_MESSAGES[$ability] ?? 'Token không có quyền cho chức năng này.', 'forbidden', $startedAt);
        }

        $limit = $isWrite
            ? max(1, (int) config('integrations.inventory_api.write_rate_limit_per_minute', 30))
            : max(1, (int) config('integrations.inventory_api.rate_limit_per_minute', 60));
        $rateKey = ($isWrite ? 'inventory-api:write:' : 'inventory-api:token:').$token->id;
        if (RateLimiter::tooManyAttempts($rateKey, $limit)) {
            return $this->reject($request, $token, 429, 'RATE_LIMITED', 'Vượt giới hạn '.$limit.' request/phút.', 'rate_limited', $startedAt, [
                'Retry-After' => (string) RateLimiter::availableIn($rateKey),
                'X-RateLimit-Limit' => (string) $limit,
                'X-RateLimit-Remaining' => '0',
            ]);
        }
        RateLimiter::hit($rateKey, 60);

        $request->attributes->set(self::TOKEN_ATTRIBUTE, $token);

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($rateKey, $limit)));
        $response->headers->set('Cache-Control', 'no-store');

        InventoryApiToken::query()->whereKey($token->id)->update([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
            'request_count' => DB::raw('request_count + 1'),
        ]);
        $this->audit($request, $token, $response->getStatusCode(), $response->isSuccessful() ? 'ok' : 'error', $startedAt);

        return $response;
    }

    private function reject(
        Request $request,
        ?InventoryApiToken $token,
        int $status,
        string $code,
        string $message,
        string $outcome,
        float $startedAt,
        array $headers = [],
    ): JsonResponse {
        $this->audit($request, $token, $status, $outcome, $startedAt);

        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status, $headers + ['Cache-Control' => 'no-store']);
    }

    private function audit(Request $request, ?InventoryApiToken $token, int $status, string $outcome, float $startedAt): void
    {
        try {
            $resultCount = $request->attributes->get(self::RESULT_COUNT_ATTRIBUTE);
            InventoryApiAccessLog::create([
                'inventory_api_token_id' => $token?->id,
                'method' => $request->getMethod(),
                'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 191),
                'query' => $this->safeQuery($request),
                'status_code' => $status,
                'outcome' => $outcome,
                'result_count' => is_int($resultCount) ? $resultCount : null,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'ip_address' => $request->ip(),
                'user_agent' => ($agent = $request->userAgent()) ? mb_substr($agent, 0, 255) : null,
            ]);
        } catch (Throwable $exception) {
            // Auditing must never break a read; surface it in the app log instead.
            Log::warning('inventory_api.audit_failed', ['message' => $exception->getMessage()]);
        }
    }

    private function safeQuery(Request $request): ?array
    {
        $allowed = ['limit', 'updated_since', 'sku', 'category_id', 'in_stock', 'status', 'readiness', 'from', 'to', 'type', 'customer_code'];
        $query = array_intersect_key($request->query(), array_flip($allowed));
        $query = array_map(fn ($value) => is_scalar($value) ? mb_substr((string) $value, 0, 100) : null, $query);
        if ($request->query('cursor')) {
            $query['cursor'] = '[present]';
        }

        return $query ?: null;
    }
}
