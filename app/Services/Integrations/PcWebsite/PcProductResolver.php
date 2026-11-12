<?php

namespace App\Services\Integrations\PcWebsite;

use App\Exceptions\PcIntegrationException;
use App\Models\Product;
use Illuminate\Support\Collection;

class PcProductResolver
{
    /** @return array<string, Product> */
    public function resolveForImport(array $skus): array
    {
        [$resolved, $notFound] = $this->partitionForImport($skus);

        if ($notFound !== []) {
            throw new PcIntegrationException(
                'UNKNOWN_SKU',
                'Một hoặc nhiều SKU không tồn tại trong KIOT.',
                422,
                array_map(fn (string $sku) => ['sku' => $sku, 'reason' => 'not_found'], $notFound),
            );
        }

        return $resolved;
    }

    /**
     * Resolve the SKUs that exist (duplicates, inactive/deleted products and
     * non-sellable products still throw) and return the SKUs that do not
     * exist at all - not even as a soft-deleted row - separately so the
     * caller can decide what to do with them.
     *
     * @return array{0: array<string, Product>, 1: list<string>}
     */
    public function partitionForImport(array $skus): array
    {
        $normalized = collect($skus)->map(fn ($sku) => trim((string) $sku))->unique()->values();
        $rows = Product::withTrashed()
            ->whereIn('sku', $normalized->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $resolved = [];
        $notFound = [];
        $duplicates = [];
        foreach ($normalized as $sku) {
            $matches = $rows->filter(fn (Product $product) => trim((string) $product->sku) === $sku)->values();
            if ($matches->isEmpty()) {
                $notFound[] = $sku;

                continue;
            }
            if ($matches->count() > 1) {
                $duplicates[] = ['sku' => $sku, 'reason' => 'duplicate'];

                continue;
            }
            $resolved[$sku] = $matches->first();
        }

        if ($duplicates !== []) {
            throw new PcIntegrationException('DUPLICATE_SKU_IN_KIOT', 'Một hoặc nhiều SKU bị trùng trong KIOT.', 409, $duplicates);
        }

        $this->assertSellable(collect($resolved));

        return [$resolved, $notFound];
    }

    private function assertSellable(Collection $products): void
    {
        $inactive = [];
        $notSellable = [];
        foreach ($products as $product) {
            if ($product->trashed() || ! (bool) $product->is_active) {
                $inactive[] = ['sku' => $product->sku, 'reason' => $product->trashed() ? 'deleted' : 'inactive'];
            } elseif (! (bool) $product->sell_directly || $product->type === 'service') {
                $notSellable[] = ['sku' => $product->sku, 'reason' => $product->type === 'service' ? 'service_out_of_scope' : 'sell_directly_disabled'];
            }
        }

        if ($inactive !== []) {
            throw new PcIntegrationException('PRODUCT_INACTIVE', 'Một hoặc nhiều sản phẩm không hoạt động.', 422, $inactive);
        }
        if ($notSellable !== []) {
            throw new PcIntegrationException('PRODUCT_NOT_SELLABLE', 'Một hoặc nhiều sản phẩm không được phép bán qua website.', 422, $notSellable);
        }
    }
}
