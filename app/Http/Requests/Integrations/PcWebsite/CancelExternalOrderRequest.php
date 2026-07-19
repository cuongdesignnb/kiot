<?php

namespace App\Http\Requests\Integrations\PcWebsite;

class CancelExternalOrderRequest extends PcIntegrationRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['_idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return [
            '_idempotency_key' => ['required', 'string', 'max:255'],
            'event_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
