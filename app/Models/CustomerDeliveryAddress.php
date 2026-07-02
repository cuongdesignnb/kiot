<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerDeliveryAddress extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
