<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    use SoftDeletes;

    const TYPE_INVOICE_DISCOUNT = 'invoice_discount';
    const TYPE_PRODUCT_DISCOUNT = 'product_discount';
    const TYPE_GIFT_ITEM = 'gift_item';

    const STATUS_DRAFT = 'draft';
    const STATUS_ACTIVE = 'active';
    const STATUS_EXPIRED = 'expired';
    const STATUS_DISABLED = 'disabled';

    const CONDITION_NONE = 'none';
    const CONDITION_MIN_AMOUNT = 'min_amount';
    const CONDITION_MIN_QTY = 'min_qty';

    const DISCOUNT_PERCENT = 'percent';
    const DISCOUNT_FIXED = 'fixed';

    protected $guarded = ['id'];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'allow_stacking' => 'boolean',
        'branch_scope' => 'array',
        'customer_group_scope' => 'array',
    ];

    public function targetProduct()
    {
        return $this->belongsTo(Product::class, 'target_product_id');
    }

    public function giftProduct()
    {
        return $this->belongsTo(Product::class, 'gift_product_id');
    }

    public function usages()
    {
        return $this->hasMany(PromotionUsage::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeCurrentlyValid($query)
    {
        $now = now();
        return $query->active()
            ->where(function ($q) use ($now) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $now);
            });
    }

    /**
     * Check if invoice context is eligible for this promotion.
     */
    public function isEligible(float $subtotal, int $qty, ?string $branchId, ?string $customerGroup): bool
    {
        // Check scope
        if ($this->branch_scope && !in_array($branchId, $this->branch_scope)) {
            return false;
        }
        if ($this->customer_group_scope && !in_array($customerGroup, $this->customer_group_scope)) {
            return false;
        }

        // Check usage limit
        if ($this->max_usage !== null && $this->usage_count >= $this->max_usage) {
            return false;
        }

        // Check condition
        if ($this->condition_type === self::CONDITION_MIN_AMOUNT && $subtotal < $this->condition_value) {
            return false;
        }
        if ($this->condition_type === self::CONDITION_MIN_QTY && $qty < $this->condition_value) {
            return false;
        }

        return true;
    }

    /**
     * Calculate discount amount for given subtotal.
     */
    public function calculateDiscount(float $subtotal): float
    {
        if ($this->discount_type === self::DISCOUNT_PERCENT) {
            return round($subtotal * $this->discount_value / 100, 0);
        }
        return min($this->discount_value, $subtotal);
    }

    /**
     * Has any usage records.
     */
    public function hasTransactions(): bool
    {
        return $this->usages()->exists();
    }
}
