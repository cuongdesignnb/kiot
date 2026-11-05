<?php

namespace App\Services\Integrations\InventoryApi;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class InventoryApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => array_filter([
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details ?: null,
            ], fn ($value) => $value !== null),
        ], $this->status);
    }
}
