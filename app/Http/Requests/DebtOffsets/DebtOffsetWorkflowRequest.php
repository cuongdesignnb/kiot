<?php

namespace App\Http\Requests\DebtOffsets;

use App\Exceptions\DebtOffsetWorkflowException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class DebtOffsetWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function idempotencyKey(): string
    {
        $key = trim((string) $this->header('Idempotency-Key', ''));
        if ($key === '') {
            throw DebtOffsetWorkflowException::invalid(
                'IDEMPOTENCY_KEY_REQUIRED',
                'Idempotency-Key là bắt buộc.'
            );
        }
        if (strlen($key) < 16 || strlen($key) > 191) {
            throw DebtOffsetWorkflowException::invalid(
                'IDEMPOTENCY_KEY_INVALID',
                'Idempotency-Key phải có từ 16 đến 191 ký tự.'
            );
        }

        return $key;
    }

    /** @return array<int, mixed> */
    protected function exactMoneyRules(): array
    {
        return [
            'required',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ((! is_string($value) && ! is_int($value))
                    || ! preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', (string) $value)
                    || preg_match('/^0+(?:\.0{1,2})?$/', (string) $value)) {
                    $fail('Số tiền cấn trừ phải là số thập phân dương, tối đa hai chữ số lẻ.');
                }
            },
        ];
    }

    protected function passedValidation(): void
    {
        $this->idempotencyKey();
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Dữ liệu yêu cầu không hợp lệ.',
            'error_code' => 'VALIDATION_FAILED',
            'errors' => $validator->errors(),
        ], 422));
    }
}
