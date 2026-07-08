<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockTakeItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'stock_take_id',
        'product_id',
        'system_stock',
        'system_stock_snapshot',
        'actual_stock',
        'checked',
        'diff_qty',
        'diff_value',
        'cost_price_snapshot',
        'unit_name',
        'category_id',
    ];

    protected $casts = [
        'actual_stock' => 'integer',
        'checked' => 'boolean',
        'diff_value' => 'decimal:2',
        'cost_price_snapshot' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function stockTake()
    {
        return $this->belongsTo(StockTake::class);
    }
}
