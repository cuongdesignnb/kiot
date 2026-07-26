<?php

namespace App\Services\Integrations\PcWebsite;

use App\Models\PriceBook;
use App\Models\PriceBookProduct;
use App\Models\Product;

class PcProductPricingService
{
    public function payload(Product $product, ?PriceBook $selectedBook): array
    {
        $retail = (float) max(0, (float) $product->retail_price);
        /** @var PriceBookProduct|null $bookPrice */
        $bookPrice = $selectedBook
            ? $product->priceBookProducts->firstWhere('price_book_id', $selectedBook->id)
            : null;
        $hasSelectedPrice = $bookPrice !== null && is_numeric($bookPrice->price) && (float) $bookPrice->price >= 0;

        return [
            // KIOT currently has one public base/retail price. Cost fields are never exposed.
            'base_price' => $retail,
            'retail_price' => $retail,
            'selected_price' => $hasSelectedPrice ? (float) $bookPrice->price : $retail,
            'selected_price_book_id' => $selectedBook?->id,
            'selected_price_book_code' => $selectedBook?->code,
            'selected_price_book_name' => $selectedBook?->name,
            'fallback_used' => ! $hasSelectedPrice,
        ];
    }
}
