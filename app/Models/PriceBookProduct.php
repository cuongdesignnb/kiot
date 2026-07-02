<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceBookProduct extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'price' => 'decimal:2',
        'retail_price' => 'decimal:2',
        'technician_price' => 'decimal:2',
    ];

    public function priceBook(): BelongsTo
    {
        return $this->belongsTo(PriceBook::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
