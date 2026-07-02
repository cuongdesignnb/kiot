<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockTransfer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'from_branch_id',
        'to_branch_id',
        'total_quantity',
        'total_price',
        'status',
        'note',
        'sent_date',
        'receive_date'
    ];

    protected $casts = [
        'sent_date' => 'datetime',
        'receive_date' => 'datetime',
    ];

    public function fromBranch()
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch()
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }
}
