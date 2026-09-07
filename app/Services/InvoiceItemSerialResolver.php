<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\SerialImei;
use Illuminate\Support\Collection;

/**
 * Resolve the serials belonging to every invoice line for read-only views.
 *
 * There are three historical storage shapes in this application:
 * - invoice_item_serials (the canonical line-to-serial link);
 * - SerialImei.invoice_id (safe only when the product has one invoice line);
 * - invoice_items.serial (legacy text entered on the line).
 *
 * The second source is deliberately not distributed across duplicate product
 * lines. That would make a read-only screen look complete while assigning a
 * serial to the wrong line.
 */
class InvoiceItemSerialResolver
{
    public function resolve(Invoice $invoice, bool $includeCost = false): Collection
    {
        $invoice->loadMissing(['items.product', 'items.serials.serial']);

        $items = $invoice->items;
        $itemsByProduct = $items->groupBy('product_id');
        $directSerialsByProduct = SerialImei::query()
            ->where('invoice_id', $invoice->id)
            ->orderBy('id')
            ->get(['id', 'product_id', 'serial_number', 'sold_cost_price', 'cost_price'])
            ->groupBy('product_id');

        return $items->map(function ($item) use ($itemsByProduct, $directSerialsByProduct, $includeCost) {
            $serialSource = 'invoice_item_serial';
            $serials = $item->serials
                ->map(function ($link) use ($includeCost) {
                    $serialNumber = $link->serial_number
                        ?: $link->serial?->serial_number
                        ?: ($link->serial_imei_id ? '#'.$link->serial_imei_id : '');

                    $serial = [
                        'id' => $link->id,
                        'serial_imei_id' => $link->serial_imei_id,
                        'serial_number' => $serialNumber,
                        'legacy' => false,
                        'source' => 'invoice_item_serial',
                    ];

                    if ($includeCost) {
                        $serial['cost_price'] = (float) ($link->cost_price
                            ?? $link->serial?->sold_cost_price
                            ?? $link->serial?->cost_price
                            ?? 0);
                    }

                    return $serial;
                })
                ->filter(fn (array $serial): bool => $serial['serial_number'] !== '')
                ->values();

            $sameProductItems = $itemsByProduct->get($item->product_id, collect());
            $directSerials = $directSerialsByProduct->get($item->product_id, collect());
            $quantity = (float) $item->quantity;

            if ($serials->isEmpty()
                && $sameProductItems->count() === 1
                && $quantity === (float) $directSerials->count()) {
                $serialSource = 'direct_invoice_assignment';
                $serials = $directSerials
                    ->map(function ($serial) use ($includeCost, $serialSource) {
                        $serialPayload = [
                            'id' => null,
                            'serial_imei_id' => $serial->id,
                            'serial_number' => $serial->serial_number ?: '#'.$serial->id,
                            'legacy' => false,
                            'source' => $serialSource,
                        ];

                        if ($includeCost) {
                            $serialPayload['cost_price'] = (float) ($serial->sold_cost_price
                                ?? $serial->cost_price
                                ?? 0);
                        }

                        return $serialPayload;
                    })
                    ->values();
            }

            if ($serials->isEmpty() && ! empty($item->serial)) {
                $serialSource = 'legacy_invoice_item_text';
                $serials = collect(array_filter(array_map(
                    'trim',
                    preg_split('/[\r\n,;|]+/', (string) $item->serial) ?: []
                )))
                    ->map(function (string $serialNumber) use ($includeCost, $serialSource) {
                        $serialPayload = [
                            'id' => null,
                            'serial_imei_id' => null,
                            'serial_number' => $serialNumber,
                            'legacy' => true,
                            'source' => $serialSource,
                        ];

                        if ($includeCost) {
                            $serialPayload['cost_price'] = 0;
                        }

                        return $serialPayload;
                    })
                    ->values();
            }

            $serialPayload = $serials->all();

            return [
                'invoice_item_id' => (int) $item->id,
                'product_code' => $item->product?->sku
                    ?: $item->product?->code
                    ?: $item->product?->barcode
                    ?: '',
                'product_name' => $item->product?->name ?: '',
                'has_serial' => (bool) ($item->product?->has_serial ?? false),
                'quantity' => $item->quantity,
                'price' => (float) $item->price,
                'discount' => (float) ($item->discount ?? 0),
                'sell_price' => (float) ($item->price - ($item->discount ?? 0)),
                'subtotal' => (float) $item->subtotal,
                'serial' => collect($serialPayload)->pluck('serial_number')->implode(', '),
                'serials' => $serialPayload,
                'serial_count' => count($serialPayload),
                'serial_source' => $serials->isEmpty() ? null : $serialSource,
            ];
        })->values();
    }
}
