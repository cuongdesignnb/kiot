<?php

namespace Tests\Unit\Services;

use App\Models\PriceBook;
use App\Models\PriceBookProduct;
use App\Models\Product;
use App\Services\Integrations\PcWebsite\PcProductPricingService;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class PcProductPricingServiceTest extends TestCase
{
    public function test_selected_zero_price_is_valid_and_does_not_fallback(): void
    {
        $product = new Product(['retail_price' => 125000]);
        $book = new PriceBook(['code' => 'WEB', 'name' => 'Website']);
        $book->id = 7;
        $price = new PriceBookProduct(['price_book_id' => 7, 'price' => 0]);
        $product->setRelation('priceBookProducts', new Collection([$price]));

        $payload = (new PcProductPricingService)->payload($product, $book);

        $this->assertSame(0.0, $payload['selected_price']);
        $this->assertFalse($payload['fallback_used']);
        $this->assertSame(7, $payload['selected_price_book_id']);
    }

    public function test_missing_selected_price_explicitly_falls_back_to_non_negative_retail(): void
    {
        $product = new Product(['retail_price' => -1]);
        $book = new PriceBook(['code' => 'WEB', 'name' => 'Website']);
        $book->id = 7;
        $product->setRelation('priceBookProducts', new Collection);

        $payload = (new PcProductPricingService)->payload($product, $book);

        $this->assertSame(0.0, $payload['selected_price']);
        $this->assertTrue($payload['fallback_used']);
    }
}
