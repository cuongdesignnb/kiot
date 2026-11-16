<?php

namespace App\Services\Integrations\InventoryApi;

use App\Models\InventoryApiToken;
use App\Models\PublicApiWriteLog;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs one write function of the public Kiot API: Idempotency-Key handling
 * (unique per token) and the public_api_write_logs audit row for every
 * attempt, success or failure.
 *
 * Idempotency rules:
 *  - same key + same body  -> stored response is replayed (`duplicate: true`)
 *  - same key + other body -> 409 IDEMPOTENCY_CONFLICT
 *  - same key while the first call is still running -> 409 IDEMPOTENCY_IN_PROGRESS
 *  - a stored 5xx is not replayed: the key may be retried.
 */
class PublicApiWriteGateway
{
    /**
     * @param  callable(PublicApiWriteLog): array{status?: int, data: array, target_type?: ?string, target_id?: ?int, target_ref?: ?string, before?: ?array, after?: ?array}  $operation
     */
    public function run(
        Request $request,
        InventoryApiToken $token,
        string $action,
        bool $keyRequired,
        callable $operation,
        ?string $targetRef = null,
    ): JsonResponse {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        $body = $request->json()->all();
        $requestHash = hash('sha256', json_encode($this->normalize($body), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $base = [
            'inventory_api_token_id' => $token->id,
            'action' => $action,
            'target_ref' => $targetRef !== null ? mb_substr($targetRef, 0, 191) : null,
            'request_hash' => $requestHash,
            'ip_address' => $request->ip(),
            'user_agent' => ($agent = $request->userAgent()) ? mb_substr($agent, 0, 255) : null,
        ];

        if ($key === '' && $keyRequired) {
            return $this->rejectWithoutClaim($base, 422, 'IDEMPOTENCY_KEY_REQUIRED', 'Thiếu header Idempotency-Key (bắt buộc, duy nhất cho mỗi lần tạo).');
        }
        if ($key !== '' && (mb_strlen($key) < 8 || mb_strlen($key) > 191 || ! preg_match('/^[A-Za-z0-9._:\-]+$/', $key))) {
            return $this->rejectWithoutClaim($base, 422, 'INVALID_IDEMPOTENCY_KEY', 'Idempotency-Key phải dài 8-191 ký tự, chỉ gồm chữ, số và . _ : -');
        }

        $log = null;
        if ($key !== '') {
            try {
                $log = PublicApiWriteLog::create($base + ['idempotency_key' => $key]);
            } catch (QueryException $exception) {
                if (! in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true)) {
                    throw $exception;
                }
                $existing = PublicApiWriteLog::query()
                    ->where('inventory_api_token_id', $token->id)
                    ->where('idempotency_key', $key)
                    ->first();
                if (! $existing) {
                    throw $exception;
                }
                if (! hash_equals((string) $existing->request_hash, $requestHash) || $existing->action !== $action) {
                    return $this->rejectWithoutClaim($base, 409, 'IDEMPOTENCY_CONFLICT', 'Idempotency-Key này đã được dùng cho một request khác (nội dung khác).', ['original_log_id' => $existing->id]);
                }
                if ($existing->status_code === null) {
                    return $this->rejectWithoutClaim($base, 409, 'IDEMPOTENCY_IN_PROGRESS', 'Request với Idempotency-Key này đang được xử lý, vui lòng thử lại sau.', ['original_log_id' => $existing->id]);
                }
                if ($existing->status_code < 500) {
                    return $this->replay($base, $existing);
                }

                // A previous attempt hit an internal error: retry on the same row.
                $existing->forceFill([
                    'status_code' => null,
                    'response' => null,
                    'error_code' => null,
                    'completed_at' => null,
                    'ip_address' => $base['ip_address'],
                    'user_agent' => $base['user_agent'],
                ])->save();
                $log = $existing;
            }
        } else {
            $log = PublicApiWriteLog::create($base);
        }

        try {
            $result = $operation($log);
            $status = (int) ($result['status'] ?? 200);
            $data = array_merge($result['data'], ['log_id' => $log->id]);
            $payload = ['duplicate' => false, 'data' => $data];
            $this->complete($log, $status, $payload, null, $result);

            return response()->json($payload, $status);
        } catch (InventoryApiException $exception) {
            return $this->fail($log, $exception->status, $exception->errorCode, $exception->getMessage(), $exception->details);
        } catch (ValidationException $exception) {
            return $this->fail($log, 422, 'VALIDATION_FAILED', 'Dữ liệu không hợp lệ.', $exception->errors());
        } catch (Throwable $exception) {
            Log::error('public_api.write_failed', [
                'action' => $action,
                'log_id' => $log->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->fail($log, 500, 'INTERNAL_ERROR', 'Lỗi hệ thống, chưa ghi dữ liệu. Có thể thử lại với cùng Idempotency-Key.');
        }
    }

    private function fail(PublicApiWriteLog $log, int $status, string $code, string $message, array $details = []): JsonResponse
    {
        $payload = ['error' => array_filter([
            'code' => $code,
            'message' => $message,
            'details' => $details ?: null,
            'log_id' => $log->id,
        ], fn ($value) => $value !== null)];
        $this->complete($log, $status, $payload, $code);

        return response()->json($payload, $status);
    }

    private function complete(PublicApiWriteLog $log, int $status, array $payload, ?string $errorCode, array $result = []): void
    {
        $log->forceFill(array_filter([
            'status_code' => $status,
            'response' => $payload,
            'error_code' => $errorCode,
            'completed_at' => now(),
            'target_type' => $result['target_type'] ?? null,
            'target_id' => $result['target_id'] ?? null,
            'target_ref' => isset($result['target_ref']) ? mb_substr((string) $result['target_ref'], 0, 191) : null,
            'before' => $result['before'] ?? null,
            'after' => $result['after'] ?? null,
        ], fn ($value, $field) => $value !== null || in_array($field, ['error_code'], true), ARRAY_FILTER_USE_BOTH))->save();
    }

    private function replay(array $base, PublicApiWriteLog $original): JsonResponse
    {
        $response = (array) ($original->response ?? []);
        if ($original->status_code < 300) {
            $response['duplicate'] = true;
            $status = 200;
        } else {
            $status = (int) $original->status_code;
        }

        PublicApiWriteLog::create($base + [
            'target_type' => $original->target_type,
            'target_id' => $original->target_id,
            'target_ref' => $original->target_ref,
            'status_code' => $status,
            'response' => ['replay_of_log_id' => $original->id],
            'error_code' => 'IDEMPOTENT_REPLAY',
            'completed_at' => now(),
        ]);

        return response()->json($response, $status);
    }

    private function rejectWithoutClaim(array $base, int $status, string $code, string $message, array $details = []): JsonResponse
    {
        $log = PublicApiWriteLog::create($base);

        return $this->fail($log, $status, $code, $message, $details);
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->normalize($item), $value);
    }
}
