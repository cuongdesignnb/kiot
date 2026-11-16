<?php

namespace App\Http\Requests\Integrations\PcWebsite;

class DeliverExternalOrderRequest extends PcIntegrationRequest
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
            'delivered_at' => ['required', 'date'],
            'tracking_code' => ['nullable', 'string', 'max:100'],
            'carrier' => ['nullable', 'string', 'max:100'],
        ];
    }
}
