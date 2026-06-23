<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Damage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'branch_id',
        'status',
        'created_by_name',
        'destroyed_by_name',
        'destroyed_date',
        'total_qty',
        'total_value',
        'note',
        'cancel_reason',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'destroyed_date' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(DamageItem::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
