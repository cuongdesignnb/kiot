<?php

namespace App\Http\Requests\Integrations\PcWebsite;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIntegrationClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'website_url' => ['required', 'string', 'max:2048'],
            'default_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'sales_channel' => ['required', 'string', 'max:255'],
            'timestamp_tolerance_seconds' => ['required', 'integer', 'min:30', 'max:900'],
            'nonce_ttl_seconds' => ['required', 'integer', 'min:30', 'max:3600'],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:1000'],
            'reservation_ttl_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'pc_product_price_book_id' => [
                'nullable',
                'integer',
                Rule::exists('price_books', 'id')->where(fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->where('status', 'active')
                    ->where(fn ($dates) => $dates->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
                    ->where(fn ($dates) => $dates->whereNull('end_date')->orWhereDate('end_date', '>=', today()))),
            ],
        ];
    }
}
