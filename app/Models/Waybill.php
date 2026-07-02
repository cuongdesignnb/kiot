<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Waybill extends Model
{
    use SoftDeletes;

    const STATUS_PENDING = 'pending';
    const STATUS_WAITING_PICKUP = 'waiting_pickup';
    const STATUS_IN_TRANSIT = 'in_transit';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_RETURNING = 'returning';
    const STATUS_RETURNED = 'returned';
    const STATUS_CANCELED = 'canceled';
    const STATUS_FAILED = 'failed';

    const PARTNER_SELF = 'self_delivery';
    const PARTNER_INTEGRATED = 'integrated';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Check if this waybill can be manually updated (self-delivery only).
     */
    public function isSelfDelivery(): bool
    {
        return $this->partner_type === self::PARTNER_SELF;
    }

    /**
     * Check if waybill is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_RETURNED, self::STATUS_CANCELED]);
    }
}
