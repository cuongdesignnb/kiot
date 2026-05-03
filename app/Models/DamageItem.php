<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DamageItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'damage_id',
        'product_id',
        'qty',
        'cost_price',
        'total_value',
        'note',
        'serial_ids',
    ];

    protected $casts = [
        'serial_ids' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function damage()
    {
        return $this->belongsTo(Damage::class);
    }
}
