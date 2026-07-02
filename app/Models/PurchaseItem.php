<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id',
        'product_id',
        'product_name',
        'product_code',
        'quantity',
        'price',
        'discount',
        'subtotal',
        'unit_cost_allocated',
        'warranty_months',
        'warranty_expires_at',
    ];

    protected $casts = [
        'warranty_expires_at' => 'date',
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
