<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PriceTable extends Model
{
    use SoftDeletes;

    const STATUS_APPLIED = 'applied';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_EXPIRED = 'expired';

    const FORMULA_FIXED = 'fixed';
    const FORMULA_PERCENT_BASE = 'percent_base';

    protected $guarded = ['id'];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'auto_update_from_base' => 'boolean',
        'restrict_items' => 'boolean',
        'branch_scope' => 'array',
        'customer_group_scope' => 'array',
    ];

    public function items()
    {
        return $this->hasMany(PriceTableItem::class);
    }

    public function scopeApplied($query)
    {
        return $query->where('status', self::STATUS_APPLIED);
    }

    public function scopeCurrentlyValid($query)
    {
        $now = now();
        return $query->applied()
            ->where(function ($q) use ($now) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $now);
            });
    }

    /**
     * Check if scope matches.
     */
    public function matchesScope(?string $branchId, ?string $customerGroup): bool
    {
        if ($this->branch_scope && !in_array($branchId, $this->branch_scope)) {
            return false;
        }
        if ($this->customer_group_scope && !in_array($customerGroup, $this->customer_group_scope)) {
            return false;
        }
        return true;
    }

    /**
     * Get table price for a product. Returns null if not in table.
     */
    public function getPriceFor(int $productId): ?float
    {
        $item = $this->items()->where('product_id', $productId)->first();
        return $item ? (float) $item->table_price : null;
    }

    /**
     * Apply formula to base price.
     */
    public function applyFormula(?float $basePrice): float
    {
        $basePrice = $basePrice ?? 0;
        if ($this->formula_type === self::FORMULA_PERCENT_BASE) {
            $price = $basePrice * (1 - $this->formula_value / 100);
        } else {
            $price = $basePrice; // fixed = no formula change
        }

        if ($this->rounding && $this->rounding > 0) {
            $price = round($price / $this->rounding) * $this->rounding;
        }

        return max(0, $price);
    }

    /**
     * Check if product is allowed (for restricted tables).
     */
    public function isProductAllowed(int $productId): bool
    {
        if (!$this->restrict_items) {
            return true; // unrestricted
        }
        return $this->items()->where('product_id', $productId)->exists();
    }
}
