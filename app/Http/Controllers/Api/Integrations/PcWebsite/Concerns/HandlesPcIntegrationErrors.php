<?php

namespace App\Http\Controllers\Api\Integrations\PcWebsite\Concerns;

use App\Exceptions\PcIntegrationException;
use App\Support\Integrations\PcWebsite\PcIntegrationResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

trait HandlesPcIntegrationErrors
{
    private function integrationError(Throwable $exception): JsonResponse
    {
        if ($exception instanceof PcIntegrationException) {
            return PcIntegrationResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->details,
                $exception->httpStatus,
            );
        }

        Log::error('PC integration internal error', [
            'exception' => $exception::class,
            'message_hash' => hash('sha256', $exception->getMessage()),
        ]);

        return PcIntegrationResponse::error(
            'INTERNAL_INTEGRATION_ERROR',
            'Hệ thống không thể xử lý request tích hợp.',
            [],
            500,
        );
    }
}
