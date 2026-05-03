<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnItem extends Model
{
    protected $table = 'return_items';
    protected $guarded = ['id'];

    protected $casts = [
        'serial_ids' => 'array',
    ];

    public function orderReturn()
    {
        return $this->belongsTo(OrderReturn::class, 'return_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
