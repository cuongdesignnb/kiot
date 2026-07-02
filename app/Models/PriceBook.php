<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PriceBook extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'formula_is_percent' => 'boolean',
        'cashier_warn_not_in_book' => 'boolean',
        'enable_retail_price' => 'boolean',
        'enable_technician_price' => 'boolean',
        'branch_ids' => 'array',
        'customer_group_ids' => 'array',
        'formula_value' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function priceBookProducts(): HasMany
    {
        return $this->hasMany(PriceBookProduct::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'price_book_products')
            ->withPivot('price', 'retail_price', 'technician_price')
            ->withTimestamps();
    }
}
