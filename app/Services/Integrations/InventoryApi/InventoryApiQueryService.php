<?php

namespace App\Services\Integrations\InventoryApi;

use App\Models\ExternalInventoryReservation;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SerialImei;
use App\Services\Integrations\InventoryApi\Concerns\SyncCursorPagination;
use App\Services\Integrations\PcWebsite\PcProductAvailabilityService;
use App\Support\Inventory\SerialReadiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Read-only projections for the partner inventory API.
 *
 * Never exposes cost fields (cost_price, inventory_total_cost, serial cost
 * snapshots), supplier, purchase or invoice references.
 */
class InventoryApiQueryService
{
    use SyncCursorPagination;

    public const SERIAL_STATUSES = ['in_stock', 'sold', 'returning', 'warranty', 'defective', 'returned', 'dismantled'];

    public function __construct(private readonly PcProductAvailabilityService $availability) {}

    public function products(array $filters): array
    {
        $watermark = now();
        $limit = $this->limit($filters);
        $since = $this->since($filters);

        $query = Product::query()->where('products.type', '!=', 'service');

        if ($since) {
            // Include deleted products so the consumer can drop them, and any
            // product whose serials or reservations changed (repair status,
            // sale, reservation) even when the product row itself did not.
            $query->withTrashed()->where(function (Builder $changed) use ($since) {
                $changed->where('products.updated_at', '>=', $since)
                    ->orWhereExists(fn ($serials) => $serials->selectRaw('1')
                        ->from('serial_imeis')
                        ->whereColumn('serial_imeis.product_id', 'products.id')
                        ->where('serial_imeis.updated_at', '>=', $since))
                    ->orWhereExists(fn ($reservations) => $reservations->selectRaw('1')
                        ->from('external_inventory_reservations')
                        ->whereColumn('external_inventory_reservations.product_id', 'products.id')
                        ->where('external_inventory_reservations.updated_at', '>=', $since));
            });
        }

        if (! empty($filters['sku'])) {
            $query->where('products.sku', trim((string) $filters['sku']));
        }
        if (! empty($filters['category_id'])) {
            $query->where('products.category_id', (int) $filters['category_id']);
        }
        if (filter_var($filters['in_stock'] ?? false, FILTER_VALIDATE_BOOL)) {
            $query->where('products.stock_quantity', '>', 0);
        }

        $query->with(['category' => fn ($category) => $category->withTrashed()->select(['id', 'name'])])
            ->select([
                'products.id', 'products.sku', 'products.barcode', 'products.name', 'products.type',
                'products.category_id', 'products.retail_price', 'products.stock_quantity', 'products.has_serial',
                'products.is_active', 'products.sell_directly', 'products.updated_at', 'products.deleted_at',
            ])
            ->selectSub(
                ExternalInventoryReservation::query()
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('product_id', 'products.id')
                    ->where('status', ExternalInventoryReservation::STATUS_ACTIVE),
                'reserved_quantity'
            );
        SerialReadiness::addProductCountSelects($query);

        $this->addSyncKey($query, 'products');
        $paginator = $query->orderBy('sync_updated_at')->orderBy('products.id')
            ->cursorPaginate($limit, ['*'], 'cursor', $this->cursor($filters));

        return [
            'data' => collect($paginator->items())->map(fn (Product $product) => $this->transformProduct($product))->all(),
            'meta' => $this->meta($paginator->nextCursor()?->encode(), $paginator->hasMorePages(), $limit, $since, $watermark),
        ];
    }

    public function serials(array $filters): array
    {
        $watermark = now();
        $limit = $this->limit($filters);
        $since = $this->since($filters);
        // Without updated_since the default is the current stock; with it,
        // every status is returned so sold/returned serials can be removed.
        $status = $filters['status'] ?? ($since ? 'all' : 'in_stock');

        $query = SerialImei::query()
            ->select([
                'serial_imeis.id', 'serial_imeis.product_id', 'serial_imeis.serial_number',
                'serial_imeis.status', 'serial_imeis.repair_status', 'serial_imeis.created_at', 'serial_imeis.updated_at',
            ])
            // Only the import date of the linked purchase (phiếu nhập) is
            // exposed, never the purchase id/code.
            ->selectSub(
                Purchase::query()->select('purchase_date')->whereColumn('purchases.id', 'serial_imeis.purchase_id')->limit(1),
                'imported_at'
            )
            ->with(['product' => fn ($product) => $product->withTrashed()->select(['id', 'sku', 'name', 'deleted_at'])]);

        if ($status !== 'all') {
            $query->where('serial_imeis.status', $status);
        }
        if (($filters['readiness'] ?? null) === 'ready') {
            SerialReadiness::scopeReady($query);
        } elseif (($filters['readiness'] ?? null) === 'repairing') {
            SerialReadiness::scopeRepairing($query);
        }
        if ($since) {
            $query->where('serial_imeis.updated_at', '>=', $since);
        }
        if (! empty($filters['sku'])) {
            $sku = trim((string) $filters['sku']);
            $query->whereIn('serial_imeis.product_id', Product::withTrashed()->select('id')->where('sku', $sku));
        }

        $this->addSyncKey($query, 'serial_imeis');
        $paginator = $query->orderBy('sync_updated_at')->orderBy('serial_imeis.id')
            ->cursorPaginate($limit, ['*'], 'cursor', $this->cursor($filters));

        return [
            'data' => collect($paginator->items())->map(fn (SerialImei $serial) => $this->transformSerial($serial))->all(),
            'meta' => $this->meta($paginator->nextCursor()?->encode(), $paginator->hasMorePages(), $limit, $since, $watermark),
        ];
    }

    private function transformProduct(Product $product): array
    {
        $inventory = $this->availability->payload($product)['inventory'];
        $hasSerial = (bool) $product->has_serial;
        $retail = (float) $product->retail_price;

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'name' => $product->name,
            'category' => $product->category ? ['id' => $product->category->id, 'name' => $product->category->name] : null,
            'retail_price' => floor($retail) === $retail ? (int) $retail : $retail,
            'has_serial' => $hasSerial,
            'stock_quantity' => $inventory['stock_quantity'],
            'reserved_quantity' => $inventory['reserved_quantity'],
            'available_quantity' => $inventory['available_quantity'],
            'ready_serial_quantity' => $hasSerial ? (int) $product->ready_serial_quantity : null,
            'repairing_serial_quantity' => $hasSerial ? (int) $product->repairing_serial_quantity : null,
            'inventory_status' => $inventory['status'],
            'is_active' => (bool) $product->is_active,
            'sell_directly' => (bool) $product->sell_directly,
            'deleted' => $product->trashed(),
            'updated_at' => $this->time($product->updated_at),
        ];
    }

    private function transformSerial(SerialImei $serial): array
    {
        $product = $serial->product;

        return [
            'id' => $serial->id,
            'serial' => $serial->serial_number,
            'product' => $product ? [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'deleted' => $product->trashed(),
            ] : null,
            'status' => $serial->status,
            'repair_status' => $serial->repair_status,
            'repair_status_label' => $serial->repair_status_label,
            'readiness' => SerialReadiness::readinessOf($serial->status, $serial->repair_status),
            'created_at' => $this->time($serial->created_at),
            'imported_at' => $serial->imported_at ? $this->time(Carbon::parse($serial->imported_at)) : null,
            'updated_at' => $this->time($serial->updated_at),
        ];
    }
}
