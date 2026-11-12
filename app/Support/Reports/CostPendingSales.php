<?php

namespace App\Support\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sold invoice lines whose gross profit is not trustworthy because the goods
 * were auto-created from a Website PC order and had no cost price when sold
 * ("Chưa có giá vốn"): the product is still cost_pending, or it was created
 * from the web and the line snapshot cost is 0.
 */
class CostPendingSales
{
    public const LABEL = 'Chưa có giá vốn';

    /**
     * @param  iterable<int>  $invoiceIds
     * @return Collection<int, array{product_id:int, sku:string, name:string, quantity:float, revenue:float, cost_pending:bool, label:string}>
     */
    public static function forInvoiceIds(iterable $invoiceIds): Collection
    {
        $ids = collect($invoiceIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty() || ! Schema::hasColumn('products', 'cost_pending')) {
            return collect();
        }

        return $ids->chunk(1000)->flatMap(fn (Collection $chunk) => DB::table('invoice_items')
            ->join('products', 'invoice_items.product_id', '=', 'products.id')
            ->whereIn('invoice_items.invoice_id', $chunk->all())
            ->where(function ($query) {
                $query->where('products.cost_pending', true)
                    ->orWhere(function ($query) {
                        $query->whereNotNull('products.created_from_web_at')
                            ->where(fn ($query) => $query->whereNull('invoice_items.cost_price')
                                ->orWhere('invoice_items.cost_price', '<=', 0));
                    });
            })
            ->groupBy('products.id', 'products.sku', 'products.name', 'products.cost_pending')
            ->select(
                'products.id as product_id',
                'products.sku',
                'products.name',
                'products.cost_pending',
                DB::raw('SUM(invoice_items.quantity) as quantity'),
                DB::raw('SUM(invoice_items.quantity * invoice_items.price) as revenue'),
            )
            ->get())
            ->groupBy('product_id')
            ->map(function (Collection $rows) {
                $first = $rows->first();

                return [
                    'product_id' => (int) $first->product_id,
                    'sku' => (string) $first->sku,
                    'name' => (string) $first->name,
                    'quantity' => (float) $rows->sum('quantity'),
                    'revenue' => (float) $rows->sum('revenue'),
                    'cost_pending' => (bool) $first->cost_pending,
                    'label' => self::LABEL,
                ];
            })
            ->values();
    }
}
