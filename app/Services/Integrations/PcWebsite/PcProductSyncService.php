<?php

namespace App\Services\Integrations\PcWebsite;

use App\Exceptions\PcIntegrationException;
use App\Models\ExternalInventoryReservation;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\CursorPaginator;

class PcProductSyncService
{
    public function paginate(array $filters): array
    {
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 50)));
        $includeInactive = filter_var($filters['include_inactive'] ?? false, FILTER_VALIDATE_BOOL);
        $updatedSince = ! empty($filters['updated_since']) ? Carbon::parse($filters['updated_since'])->utc() : null;

        $query = Product::query();
        if ($includeInactive || $updatedSince) {
            $query->withTrashed();
        } else {
            $query->where('is_active', true)
                ->where('sell_directly', true)
                ->where('type', '!=', 'service');
        }

        if (! empty($filters['sku'])) {
            $query->whereRaw('BINARY products.sku = ?', [trim((string) $filters['sku'])]);
        }
        if ($updatedSince) {
            $query->where('updated_at', '>=', $updatedSince);
        }

        $query->select([
            'products.id',
            'products.sku',
            'products.barcode',
            'products.name',
            'products.type',
            'products.retail_price',
            'products.stock_quantity',
            'products.has_serial',
            'products.is_active',
            'products.sell_directly',
            'products.weight',
            'products.warranty_months',
            'products.updated_at',
            'products.deleted_at',
        ])->selectSub(
            ExternalInventoryReservation::query()
                ->selectRaw('COALESCE(SUM(quantity), 0)')
                ->whereColumn('product_id', 'products.id')
                ->where('status', ExternalInventoryReservation::STATUS_ACTIVE),
            'reserved_quantity'
        )->orderBy('updated_at')->orderBy('id');

        /** @var CursorPaginator $paginator */
        $paginator = $query->cursorPaginate($limit);

        return [
            'data' => collect($paginator->items())->map(fn (Product $product) => $this->transform($product))->all(),
            'meta' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }

    public function findBySku(string $sku): array
    {
        $normalized = trim($sku);
        $matches = Product::withTrashed()
            ->where('sku', $normalized)
            ->select([
                'products.id', 'products.sku', 'products.barcode', 'products.name', 'products.type',
                'products.retail_price', 'products.stock_quantity', 'products.has_serial',
                'products.is_active', 'products.sell_directly', 'products.weight',
                'products.warranty_months', 'products.updated_at', 'products.deleted_at',
            ])
            ->selectSub(
                ExternalInventoryReservation::query()
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('product_id', 'products.id')
                    ->where('status', ExternalInventoryReservation::STATUS_ACTIVE),
                'reserved_quantity'
            )
            ->limit(3)
            ->get()
            ->filter(fn (Product $product) => trim((string) $product->sku) === $normalized)
            ->values();

        if ($matches->isEmpty()) {
            throw new PcIntegrationException('UNKNOWN_SKU', 'Không tìm thấy SKU trong KIOT.', 404, [['sku' => $normalized, 'reason' => 'not_found']]);
        }
        if ($matches->count() > 1) {
            throw new PcIntegrationException('DUPLICATE_SKU_IN_KIOT', 'SKU bị trùng trong KIOT.', 409, [['sku' => $normalized, 'reason' => 'duplicate']]);
        }

        return $this->transform($matches->first());
    }

    public function transform(Product $product): array
    {
        $deleted = $product->trashed();
        $service = $product->type === 'service';
        $active = ! $deleted && ! $service && (bool) $product->is_active;
        $sellDirectly = ! $deleted && ! $service && (bool) $product->sell_directly;
        $stock = max(0, (int) $product->stock_quantity);
        $reserved = max(0, (int) ($product->reserved_quantity ?? 0));

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'name' => $product->name,
            'retail_price' => (float) $product->retail_price,
            'stock_quantity' => $stock,
            'reserved_quantity' => $reserved,
            'available_quantity' => max(0, $stock - $reserved),
            'has_serial' => (bool) $product->has_serial,
            'is_active' => $active,
            'sell_directly' => $sellDirectly,
            'weight' => is_numeric($product->weight) ? (float) $product->weight : null,
            'warranty_months' => $product->warranty_months !== null ? (int) $product->warranty_months : null,
            'sync_status' => $deleted ? 'deleted' : ($active && $sellDirectly ? 'active' : 'inactive'),
            'updated_at' => $product->updated_at?->utc()->toIso8601String(),
        ];
    }
}
