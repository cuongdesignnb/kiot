<?php

namespace App\Services\SalesControls;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\SerialImei;

/**
 * Batch 10 đợt 4 — item 32: "cảnh báo khi bán dưới vốn".
 *
 * Selling below cost is ALLOWED (it is a warning, never a block). The UI asks
 * preview() before saving and shows "Giá bán thấp hơn giá vốn" on the lines;
 * after the sale, recordForInvoice() writes one `invoice_below_cost` journal
 * row so the owner can review it in Nhật ký thao tác.
 *
 * A line is below cost when its net unit price (price × qty − line discount,
 * divided by qty) is lower than the cost of the goods: the cost snapshot on
 * the saved invoice line, or — for the preview — the selected serials' cost,
 * else the product's current average cost. Services and goods without a cost
 * (cost = 0) are never flagged.
 */
class BelowCostSaleService
{
    public function __construct(private readonly SalesControlService $controls) {}

    /**
     * @param  array<int, array{product_id?: mixed, quantity?: mixed, qty?: mixed, price?: mixed, discount?: mixed, serial_ids?: mixed}>  $items
     * @return array<int, array{index: int, product_id: int, product_name: string, sale_price: float, cost_price: float}>
     */
    public function preview(array $items): array
    {
        if (! $this->controls->belowCostWarningEnabled()) {
            return [];
        }

        $items = array_values($items);
        $products = Product::query()
            ->whereIn('id', collect($items)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique())
            ->get()
            ->keyBy('id');

        $lines = [];
        foreach ($items as $index => $item) {
            $product = $products->get((int) ($item['product_id'] ?? 0));
            if (! $product || $product->isService()) {
                continue;
            }

            $quantity = (float) ($item['quantity'] ?? $item['qty'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $cost = $this->previewCost($product, (array) ($item['serial_ids'] ?? []));
            $net = $this->netUnitPrice((float) ($item['price'] ?? 0), $quantity, (float) ($item['discount'] ?? 0));
            if ($cost > 0 && $net < $cost) {
                $lines[] = $this->line($index, $product->id, $product->name, $net, $cost);
            }
        }

        return $lines;
    }

    /**
     * Lines of a saved invoice sold below their cost snapshot.
     */
    public function forInvoice(Invoice $invoice): array
    {
        $invoice->loadMissing('items.product');

        $lines = [];
        foreach ($invoice->items->values() as $index => $item) {
            $quantity = (float) $item->quantity;
            $cost = (float) ($item->cost_price ?? 0);
            if ($quantity <= 0 || $cost <= 0 || ($item->product && $item->product->isService())) {
                continue;
            }

            $net = $this->netUnitPrice((float) $item->price, $quantity, (float) ($item->discount ?? 0));
            if ($net < $cost) {
                $lines[] = $this->line($index, (int) $item->product_id, (string) ($item->product?->name ?? ''), $net, $cost);
            }
        }

        return $lines;
    }

    /**
     * Write one journal row when a saved invoice has lines below cost.
     *
     * @return array the flagged lines (empty when none / warning disabled)
     */
    public function recordForInvoice(Invoice $invoice, string $source, ?string $reason = null): array
    {
        if (! $this->controls->belowCostWarningEnabled()) {
            return [];
        }

        $lines = $this->forInvoice($invoice);
        if ($lines === []) {
            return [];
        }

        ActivityLog::audit(
            ActivityLog::ACTION_INVOICE_BELOW_COST,
            ActivityLog::DOC_INVOICE,
            $invoice->code,
            $reason,
            $invoice,
            null,
            null,
            [
                'source' => $source,
                'below_cost_count' => count($lines),
                'lines' => $lines,
            ],
            "Hóa đơn {$invoice->code} có ".count($lines).' dòng bán dưới giá vốn',
        );

        return $lines;
    }

    private function previewCost(Product $product, array $serialIds): float
    {
        $serialIds = array_values(array_filter(array_map('intval', $serialIds)));
        if ($product->has_serial && $serialIds !== []) {
            $serialCost = (float) SerialImei::query()
                ->whereIn('id', $serialIds)
                ->where('product_id', $product->id)
                ->avg('cost_price');
            if ($serialCost > 0) {
                return round($serialCost, 2);
            }
        }

        return round((float) ($product->cost_price ?? 0), 2);
    }

    private function netUnitPrice(float $price, float $quantity, float $discount): float
    {
        return round((($price * $quantity) - $discount) / $quantity, 2);
    }

    private function line(int $index, int $productId, string $name, float $net, float $cost): array
    {
        return [
            'index' => $index,
            'product_id' => $productId,
            'product_name' => $name,
            'sale_price' => $net,
            'cost_price' => $cost,
        ];
    }
}
