<?php

namespace App\Http\Requests\Integrations\PcWebsite;

class ImportExternalOrderRequest extends PcIntegrationRequest
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
            'external_order_id' => ['required', 'string', 'max:255'],
            'external_order_code' => ['required', 'string', 'max:255'],
            'ordered_at' => ['required', 'date'],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.phone' => ['required', 'string', 'max:50'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'delivery' => ['required', 'array'],
            'delivery.is_delivery' => ['required', 'boolean'],
            'delivery.receiver_name' => ['nullable', 'required_if:delivery.is_delivery,true', 'string', 'max:255'],
            'delivery.receiver_phone' => ['nullable', 'required_if:delivery.is_delivery,true', 'string', 'max:50'],
            'delivery.receiver_address' => ['nullable', 'required_if:delivery.is_delivery,true', 'string', 'max:1000'],
            'delivery.receiver_ward' => ['nullable', 'string', 'max:255'],
            'delivery.receiver_district' => ['nullable', 'string', 'max:255'],
            'delivery.receiver_city' => ['nullable', 'string', 'max:255'],
            'delivery.weight' => ['nullable', 'numeric', 'min:0'],
            'delivery.shipping_fee' => ['required', 'numeric', 'min:0'],
            'payment' => ['required', 'array'],
            'payment.method' => ['nullable', 'string', 'max:100'],
            'payment.status' => ['nullable', 'string', 'max:100'],
            'totals' => ['required', 'array'],
            'totals.subtotal' => ['required', 'numeric', 'min:0'],
            'totals.discount' => ['required', 'numeric', 'min:0'],
            'totals.shipping_fee' => ['required', 'numeric', 'min:0'],
            'totals.total' => ['required', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.sku' => ['required', 'string', 'max:255'],
            'items.*.product_name' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['required', 'numeric', 'min:0'],
            'items.*.line_total' => ['required', 'numeric', 'min:0'],
            'items.*.bundle_ref' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
