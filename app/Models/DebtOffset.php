<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DebtOffset extends Model
{
    protected $fillable = [
        'code',
        'customer_id',
        'amount',
        'receivable_before',
        'payable_before',
        'receivable_after',
        'payable_after',
        'is_auto',
        'note',
        'user_id',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'receivable_before' => 'decimal:2',
        'payable_before' => 'decimal:2',
        'receivable_after' => 'decimal:2',
        'payable_after' => 'decimal:2',
        'is_auto' => 'boolean',
        'cancelled_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cancelledByUser()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
