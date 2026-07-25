<?php

namespace App\Services\Integrations\PcWebsite;

use App\Models\Product;

class PcProductAvailabilityService
{
    public function payload(Product $product): array
    {
        $deleted = $product->trashed();
        $active = ! $deleted && (bool) $product->is_active && (bool) $product->sell_directly;
        $stock = max(0, (int) $product->stock_quantity);
        $reserved = max(0, (int) ($product->reserved_quantity ?? 0));
        $repairing = $product->has_serial ? max(0, (int) ($product->repairing_serial_quantity ?? 0)) : 0;
        $ready = $product->has_serial ? max(0, (int) ($product->ready_serial_quantity ?? 0)) : $stock;
        $available = $active ? max(0, $ready - $reserved) : 0;

        $status = match (true) {
            $deleted => 'deleted',
            ! $active => 'inactive',
            $stock === 0 => 'sold',
            $ready === 0 && $repairing > 0 => 'repairing',
            $available === 0 => 'reserved',
            default => 'available',
        };
        if ($status === 'repairing') {
            $available = 0;
        }

        return [
            'inventory' => [
                'stock_quantity' => $stock,
                'reserved_quantity' => $reserved,
                'available_quantity' => $available,
                'status' => $status,
            ],
            'availability' => [
                'is_available' => $status === 'available' && $available > 0,
                'is_under_repair' => $repairing > 0,
                'sell_directly' => ! $deleted && (bool) $product->sell_directly,
            ],
        ];
    }
}
