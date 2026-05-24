<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPaymentDiscount extends Model
{
    protected $fillable = [
        'code',
        'customer_id',
        'amount',
        'discount_at',
        'performed_by',
        'created_by',
        'allocate_to_invoices',
        'status',
        'note',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'discount_at' => 'datetime',
        'allocate_to_invoices' => 'boolean',
        'cancelled_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function allocations()
    {
        return $this->hasMany(CustomerPaymentDiscountAllocation::class);
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }
}
