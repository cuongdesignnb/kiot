<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockTransferItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'stock_transfer_id',
        'product_id',
        'quantity',
        'received_quantity',
        'price',
        'cost_at_transfer',
        'serial_ids',
    ];

    protected $casts = [
        'cost_at_transfer' => 'decimal:2',
        'serial_ids'       => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
