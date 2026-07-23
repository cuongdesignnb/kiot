<?php

namespace App\Support\Integrations\PcWebsite;

use Illuminate\Http\JsonResponse;

final class PcIntegrationResponse
{
    public static function success(array $data = [], array $meta = [], int $status = 200, array $extra = []): JsonResponse
    {
        $payload = array_merge(['success' => true], $extra, ['data' => $data]);
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function error(string $code, string $message, array $details = [], int $status = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
        ], $status);
    }
}
