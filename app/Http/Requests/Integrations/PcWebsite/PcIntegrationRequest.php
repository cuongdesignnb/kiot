<?php

namespace App\Http\Requests\Integrations\PcWebsite;

use App\Services\Integrations\PcWebsite\PcIntegrationAuditService;
use App\Support\Integrations\PcWebsite\PcIntegrationResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class PcIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        app(PcIntegrationAuditService::class)->recordInvalidMutation($this);

        $details = collect($validator->errors()->toArray())
            ->map(fn (array $messages, string $field) => [
                'field' => $field,
                'reason' => $messages[0] ?? 'invalid',
            ])
            ->values()
            ->all();

        throw new HttpResponseException(
            PcIntegrationResponse::error('INVALID_PAYLOAD', 'Payload không hợp lệ.', $details, 422)
        );
    }
}
