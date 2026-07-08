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
        'branch_id',
        'status',
        'user_name',
        'balancer_name',
        'balanced_date',
        'created_by',
        'balanced_by',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'total_actual_qty',
        'total_diff_qty',
        'total_diff_increase',
        'total_diff_decrease',
        'total_diff_value',
        'note'
    ];

    protected $casts = [
        'balanced_date' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(StockTakeItem::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
