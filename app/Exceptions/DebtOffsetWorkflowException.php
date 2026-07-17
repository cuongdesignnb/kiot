<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class DebtOffsetWorkflowException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    public static function conflict(string $code, string $message, array $errors = []): self
    {
        return new self($code, $message, 409, $errors);
    }

    public static function forbidden(string $code, string $message, array $errors = []): self
    {
        return new self($code, $message, 403, $errors);
    }

    public static function invalid(string $code, string $message, array $errors = []): self
    {
        return new self($code, $message, 422, $errors);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
            'errors' => (object) $this->errors,
        ], $this->httpStatus);
    }
}
