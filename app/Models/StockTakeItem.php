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
        'actual_stock',
        'diff_qty',
        'diff_value'
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
