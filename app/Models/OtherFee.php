<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtherFee extends Model
{
    protected $fillable = [
        'code', 'name', 'value', 'value_type',
        'auto_apply', 'refund_on_return',
        'scope', 'branch_id', 'status',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'auto_apply' => 'boolean',
        'refund_on_return' => 'boolean',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    protected static function booted()
    {
        static::creating(function ($fee) {
            if (empty($fee->code)) {
                $fee->code = 'THK' . str_pad(static::max('id') + 1, 4, '0', STR_PAD_LEFT);
            }
        });
    }
}
