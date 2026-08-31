<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sku',
        'barcode',
        'name',
        'type',
        'category_id',
        'brand_id',
        'cost_price',
        'last_purchase_price',
        'retail_price',
        'stock_quantity',
        'inventory_total_cost',
        'min_stock',
        'max_stock',
        'has_serial',
        'has_variants',
        'is_active',
        'allow_point_accumulation',
        'sell_directly',
        'image',
        'description',
        'weight',
        'location',
        // Step 24.9 — warranty/maintenance configuration
        'warranty_months',
        'warranty_policies',
        'maintenance_policies',
    ];

    protected $casts = [
        'has_serial' => 'boolean',
        'has_variants' => 'boolean',
        'is_active' => 'boolean',
        'allow_point_accumulation' => 'boolean',
        'sell_directly' => 'boolean',
        'cost_price' => 'decimal:2',
        'last_purchase_price' => 'decimal:2',
        'retail_price' => 'decimal:2',
        'inventory_total_cost' => 'decimal:2',
        // Step 24.9
        'warranty_months' => 'integer',
        'warranty_policies' => 'array',
        'maintenance_policies' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(SerialImei::class);
    }

    public function components(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_combos', 'combo_product_id', 'component_product_id')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function warranties(): HasMany
    {
        return $this->hasMany(Warranty::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function serialImeis()
    {
        return $this->hasMany(SerialImei::class);
    }

    public function externalInventoryReservations(): HasMany
    {
        return $this->hasMany(ExternalInventoryReservation::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function primaryImage()
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    public function priceBookProducts(): HasMany
    {
        return $this->hasMany(PriceBookProduct::class);
    }

    public function isService(): bool
    {
        return $this->type === 'service';
    }

    public function tracksInventory(): bool
    {
        return ! $this->isService();
    }

    public function canHaveSerial(): bool
    {
        return $this->type === 'standard';
    }

    /**
     * Lấy ngày nhập hàng sớm nhất cho sản phẩm này.
     * Fallback về created_at nếu chưa có phiếu nhập nào.
     */
    public function getEarliestImportDate(): ?Carbon
    {
        $earliestPurchaseDate = Purchase::whereHas('items', function ($q) {
            $q->where('product_id', $this->id);
        })->where('status', 'completed')
            ->min('purchase_date');

        if ($earliestPurchaseDate) {
            return Carbon::parse($earliestPurchaseDate);
        }

        return $this->created_at;
    }

    /**
     * Đồng bộ projection của sản phẩm quản lý Serial/IMEI.
     *
     * Với hàng serial, từng serial là nguồn giá vốn đích danh. Vì vậy mọi
     * projection ở products phải được tính lại từ những serial còn in_stock:
     * stock_quantity, inventory_total_cost và cost_price. Không được giữ một
     * BQ cũ sau khi serial đã đổi trạng thái, vì nó có thể bị dùng nhầm làm
     * COGS cho một serial khác ở lần bán kế tiếp.
     */
    public function recomputeFromSerials(): void
    {
        if (! $this->has_serial || $this->isService()) {
            return;
        }

        $aggregate = SerialImei::query()
            ->where('product_id', $this->id)
            ->where('status', 'in_stock')
            ->selectRaw('COUNT(*) as quantity, COALESCE(SUM(cost_price), 0) as total_cost')
            ->first();

        $count = (int) ($aggregate->quantity ?? 0);
        $totalCost = round((float) ($aggregate->total_cost ?? 0), 2);
        $averageCost = $count > 0 ? round($totalCost / $count, 2) : 0.0;

        if (
            (int) $this->stock_quantity !== $count
            || round((float) $this->inventory_total_cost, 2) !== $totalCost
            || round((float) $this->cost_price, 2) !== $averageCost
        ) {
            $this->stock_quantity = $count;
            $this->inventory_total_cost = $totalCost;
            $this->cost_price = $averageCost;
            $this->save();
        }
    }
}
