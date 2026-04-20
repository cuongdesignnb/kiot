<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceTableItem extends Model
{
    protected $guarded = ['id'];

    public function priceTable()
    {
        return $this->belongsTo(PriceTable::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
