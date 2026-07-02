<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id', 'sku', 'barcode', 'name',
        'cost_price', 'retail_price', 'stock_quantity',
        'image', 'is_active',
    ];

    protected $casts = [
        'cost_price' => 'decimal:2',
        'retail_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(
            ProductAttributeValue::class,
            'product_variant_attribute_values',
            'variant_id',
            'attribute_value_id'
        );
    }
}
