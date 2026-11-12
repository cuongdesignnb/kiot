<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Schema;

/**
 * Blocks invoicing goods that the Website PC order import auto-created and
 * that still have no purchase receipt and no cost price ("Chưa có giá vốn").
 * Selling them would book COGS = 0 and silently inflate gross profit.
 */
class CostPendingSaleGuard
{
    /**
     * @param  iterable<int|string|null>  $productIds
     *
     * @throws \Exception
     */
    public function assertCanInvoiceProductIds(iterable $productIds): void
    {
        $ids = collect($productIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        // Defensive: never break POS/invoices if the code ships before the
        // 2026_11_12_000001 migration has run.
        if ($ids->isEmpty() || ! Schema::hasColumn('products', 'cost_pending')) {
            return;
        }

        Product::query()
            ->whereIn('id', $ids->all())
            ->where('cost_pending', true)
            ->orderBy('id')
            ->get()
            ->each(fn (Product $product) => $this->assertCanInvoice($product));
    }

    /**
     * @throws \Exception
     */
    public function assertCanInvoice(Product $product): void
    {
        if ($product->isCostPending()) {
            throw new \Exception(self::message($product));
        }
    }

    public static function message(Product $product): string
    {
        return "Hàng {$product->sku} do web tạo chưa có phiếu nhập/giá vốn, hãy nhập hàng trước khi xuất hoá đơn.";
    }
}
