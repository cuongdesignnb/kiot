<?php

namespace App\Http\Requests\Integrations\PcWebsite;

class CatalogSyncRequest extends PcIntegrationRequest
{
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', 'max:2048'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'updated_since' => ['nullable', 'date'],
            'include_inactive' => ['nullable', 'boolean'],
        ];
    }
}
