<?php

namespace App\Services\Integrations\PcWebsite;

use App\Exceptions\PcIntegrationException;
use App\Models\ExternalInventoryReservation;
use App\Models\PriceBook;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SerialImei;
use App\Support\Integrations\PcWebsite\PcSyncCursor;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PcProductSyncService
{
    public function __construct(
        private readonly PcPriceBookSyncService $priceBooks,
        private readonly PcProductPricingService $pricing,
        private readonly PcProductAvailabilityService $availability,
    ) {}

    public function paginate(array $filters, ?RuntimePcIntegrationConfig $runtime = null): array
    {
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 50)));
        $includeInactive = filter_var($filters['include_inactive'] ?? false, FILTER_VALIDATE_BOOL);
        $updatedSince = ! empty($filters['updated_since'])
            ? Carbon::parse($filters['updated_since'])->setTimezone((string) config('app.timezone', 'UTC'))
            : null;
        $selectedBook = $this->priceBooks->activeBook($runtime?->productPriceBookId);

        $query = $this->query($includeInactive || $updatedSince !== null, $selectedBook);
        if (! empty($filters['sku'])) {
            $query->whereRaw('BINARY products.sku = ?', [trim((string) $filters['sku'])]);
        }
        if (! empty($filters['id'])) {
            $query->whereKey((int) $filters['id']);
        }
        if ($updatedSince) {
            $query->where('products.updated_at', '>=', $updatedSince);
        }
        if (! $includeInactive && $updatedSince === null) {
            $query->where('products.is_active', true)->where('products.sell_directly', true);
        }

        $query->orderBy('products.updated_at')->orderBy('products.id');
        /** @var CursorPaginator $paginator */
        $paginator = $query->cursorPaginate($limit, ['*'], 'cursor', PcSyncCursor::decode($filters['cursor'] ?? null));

        return [
            'data' => collect($paginator->items())->map(fn (Product $product) => $this->transform($product, $selectedBook))->all(),
            'meta' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }

    public function findBySku(string $sku, ?RuntimePcIntegrationConfig $runtime = null): array
    {
        $normalized = trim($sku);
        $selectedBook = $this->priceBooks->activeBook($runtime?->productPriceBookId);
        $matches = $this->query(true, $selectedBook)
            ->where('sku', $normalized)
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

        return $this->transform($matches->first(), $selectedBook);
    }

    public function transform(Product $product, ?PriceBook $selectedBook = null): array
    {
        $deleted = $product->trashed();
        $active = ! $deleted && $product->type !== 'service' && (bool) $product->is_active;
        $inventory = $this->availability->payload($product);
        $pricing = $this->pricing->payload($product, $selectedBook);
        $images = $product->images->map(fn (ProductImage $image) => $image->integrationPayload())->values();
        $primary = $images->firstWhere('is_primary', true);
        $category = $product->category;
        $categoryPublished = $category !== null
            && ! $category->trashed()
            && (bool) $category->is_active
            && (bool) $category->show_on_pc_website;
        $publishingAllowed = $active && (bool) $product->sell_directly && $categoryPublished;

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $category ? [
                'id' => $category->id,
                'code' => $category->code ?: 'CAT-'.$category->id,
                'name' => $category->name,
                'slug' => $category->slug ?: Str::slug($category->name),
                'parent_id' => (int) $category->parent_id ?: null,
                'show_on_pc_website' => $categoryPublished,
            ] : null,
            'publishing' => [
                'show_on_pc_website' => $publishingAllowed,
                'blocked_reason' => $publishingAllowed ? null : $this->publishingBlockedReason($product, $categoryPublished, $deleted),
            ],
            'pricing' => $pricing,
            'inventory' => $inventory['inventory'],
            'availability' => $inventory['availability'],
            'primary_image' => $primary,
            'images' => $images->all(),
            'has_serial' => (bool) $product->has_serial,
            'weight' => is_numeric($product->weight) ? (float) $product->weight : null,
            'warranty_months' => $product->warranty_months !== null ? (int) $product->warranty_months : null,
            'is_active' => $active,
            'sync_status' => $deleted ? 'deleted' : ($active && (bool) $product->sell_directly ? 'active' : 'inactive'),
            'updated_at' => $product->updated_at?->utc()->toIso8601String(),

            // Existing v1 fields remain unchanged for current consumers.
            'retail_price' => $pricing['retail_price'],
            'stock_quantity' => $inventory['inventory']['stock_quantity'],
            'reserved_quantity' => $inventory['inventory']['reserved_quantity'],
            'available_quantity' => $inventory['inventory']['available_quantity'],
            'sell_directly' => $inventory['availability']['sell_directly'],
        ];
    }

    private function query(bool $withInactive, ?PriceBook $selectedBook): Builder
    {
        $query = Product::query()->where('type', '!=', 'service');
        if ($withInactive) {
            $query->withTrashed();
        }

        $with = [
            'category' => fn ($category) => $category->withTrashed()
                ->select(['id', 'name', 'code', 'slug', 'parent_id', 'is_active', 'show_on_pc_website', 'deleted_at']),
            'images' => fn ($images) => $images->select([
                'id', 'product_id', 'storage_disk', 'storage_path', 'checksum', 'width', 'height', 'sort_order', 'is_primary',
            ])->orderBy('sort_order')->orderBy('id'),
        ];
        if ($selectedBook) {
            $with['priceBookProducts'] = fn ($prices) => $prices
                ->where('price_book_id', $selectedBook->id)
                ->select(['id', 'price_book_id', 'product_id', 'price']);
        }
        $query->with($with);

        return $query->select([
            'products.id', 'products.sku', 'products.barcode', 'products.name', 'products.description',
            'products.type', 'products.category_id', 'products.retail_price', 'products.stock_quantity',
            'products.has_serial', 'products.is_active', 'products.sell_directly', 'products.weight',
            'products.warranty_months', 'products.updated_at', 'products.deleted_at',
        ])->selectSub(
            ExternalInventoryReservation::query()
                ->selectRaw('COALESCE(SUM(quantity), 0)')
                ->whereColumn('product_id', 'products.id')
                ->where('status', ExternalInventoryReservation::STATUS_ACTIVE),
            'reserved_quantity'
        )->selectSub(
            SerialImei::query()
                ->selectRaw('COUNT(*)')
                ->whereColumn('product_id', 'products.id')
                ->where('status', 'in_stock')
                ->where(function ($serials) {
                    $serials->whereNull('repair_status')->orWhereNotIn('repair_status', ['not_started', 'repairing']);
                }),
            'ready_serial_quantity'
        )->selectSub(
            SerialImei::query()
                ->selectRaw('COUNT(*)')
                ->whereColumn('product_id', 'products.id')
                ->where('status', 'in_stock')
                ->whereIn('repair_status', ['not_started', 'repairing']),
            'repairing_serial_quantity'
        );
    }

    private function publishingBlockedReason(Product $product, bool $categoryPublished, bool $deleted): string
    {
        if ($deleted) {
            return 'PRODUCT_DELETED';
        }
        if (! $product->is_active) {
            return 'PRODUCT_INACTIVE';
        }
        if (! $product->sell_directly) {
            return 'PRODUCT_NOT_SELLABLE';
        }
        if (! $categoryPublished) {
            return 'CATEGORY_NOT_PUBLISHED';
        }

        return 'PRODUCT_NOT_PUBLISHABLE';
    }
}
