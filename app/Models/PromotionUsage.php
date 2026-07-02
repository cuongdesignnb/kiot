<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionUsage extends Model
{
    protected $guarded = ['id'];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
