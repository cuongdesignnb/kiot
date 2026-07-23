<?php

namespace App\Http\Requests\Integrations\PcWebsite;

class ProductSyncRequest extends PcIntegrationRequest
{
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', 'max:2048'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'updated_since' => ['nullable', 'date'],
            'sku' => ['nullable', 'string', 'max:255'],
            'include_inactive' => ['nullable', 'boolean'],
        ];
    }
}
