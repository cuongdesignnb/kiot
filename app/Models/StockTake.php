<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockTake extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'status',
        'user_name',
        'balancer_name',
        'balanced_date',
        'total_actual_qty',
        'total_diff_qty',
        'total_diff_increase',
        'total_diff_decrease',
        'total_diff_value',
        'note'
    ];

    protected $casts = [
        'balanced_date' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(StockTakeItem::class);
    }
}
