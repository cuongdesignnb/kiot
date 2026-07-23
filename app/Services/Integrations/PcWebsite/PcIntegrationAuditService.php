<?php

namespace App\Services\Integrations\PcWebsite;

use App\Models\IntegrationEvent;
use Illuminate\Http\Request;
use Throwable;

class PcIntegrationAuditService
{
    public function recordInvalidMutation(Request $request): void
    {
        if (! $request->isMethod('POST')) {
            return;
        }

        try {
            $rawBody = (string) $request->getContent();
            $decoded = json_decode($rawBody, true);
            $payload = is_array($decoded) ? $this->sanitize($decoded) : ['_invalid_json' => true];
            $eventId = isset($payload['event_id']) && is_scalar($payload['event_id'])
                ? mb_substr((string) $payload['event_id'], 0, 64)
                : null;
            $idempotencyKey = trim((string) $request->header('Idempotency-Key', ''));
            $idempotencyKey = $idempotencyKey !== '' ? mb_substr($idempotencyKey, 0, 255) : null;
            $externalOrderId = $payload['external_order_id'] ?? $request->route('externalOrderId');
            $payloadHash = hash('sha256', $rawBody);

            $existing = null;
            if ($eventId !== null || $idempotencyKey !== null) {
                $existing = IntegrationEvent::query()
                    ->where('source', PcOrderImportService::SOURCE)
                    ->where(function ($query) use ($eventId, $idempotencyKey) {
                        if ($eventId !== null) {
                            $query->where('event_id', $eventId);
                        }
                        if ($idempotencyKey !== null) {
                            $method = $eventId !== null ? 'orWhere' : 'where';
                            $query->{$method}('idempotency_key', $idempotencyKey);
                        }
                    })
                    ->first();
            }

            if ($existing) {
                if (hash_equals((string) $existing->payload_hash, $payloadHash)
                    && $existing->status === IntegrationEvent::STATUS_FAILED) {
                    $existing->increment('attempt_count');
                }

                return;
            }

            IntegrationEvent::create([
                'source' => PcOrderImportService::SOURCE,
                'event_id' => $eventId,
                'event_type' => str_ends_with($request->path(), '/cancel') ? 'order.cancel' : 'order.create',
                'external_order_id' => is_scalar($externalOrderId) ? mb_substr((string) $externalOrderId, 0, 255) : null,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'payload' => $payload,
                'status' => IntegrationEvent::STATUS_FAILED,
                'last_error_code' => 'INVALID_PAYLOAD',
                'last_error_message' => 'Payload không hợp lệ.',
                'received_at' => now(),
                'processed_at' => now(),
            ]);
        } catch (Throwable) {
            // Audit storage must not replace the stable validation response.
        }
    }

    private function sanitize(array $payload): array
    {
        $sensitiveKeys = [
            'authorization', 'password', 'secret', 'clientsecret', 'integrationsecret',
            'signature', 'apikey', 'token', 'accesstoken', 'refreshtoken',
        ];
        foreach ($payload as $key => $value) {
            $normalizedKey = preg_replace('/[^a-z0-9]/', '', strtolower((string) $key));
            if (in_array($normalizedKey, $sensitiveKeys, true)) {
                $payload[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->sanitize($value);
            }
        }

        return $payload;
    }
}
