<?php

namespace App\Http\Requests\Integrations\PcWebsite;

class ExternalOrderPaymentRequest extends PcIntegrationRequest
{
    public const METHODS = ['bank_transfer', 'cash', 'cod', 'sepay', 'other'];

    protected function prepareForValidation(): void
    {
        $this->merge(['_idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            '_idempotency_key' => ['required', 'string', 'max:255'],
            'event_id' => ['required', 'uuid'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'paid_at' => ['required', 'date'],
            'method' => ['required', 'string', 'in:'.implode(',', self::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
