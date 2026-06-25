<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Carbon\Carbon;

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
        'warranty_months'      => 'integer',
        'warranty_policies'    => 'array',
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

    public function isService(): bool
    {
        return $this->type === 'service';
    }

    public function tracksInventory(): bool
    {
        return !$this->isService();
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
     * Đối với sản phẩm có serial/IMEI: giá vốn bình quân và tồn kho
     * chỉ tính trên các serial còn TỒN (status = 'in_stock'), KHÔNG tính
     * những serial đã bán/đã trả NCC. Gọi sau mỗi thao tác làm thay đổi
     * trạng thái serial (nhập, bán, trả hàng bán, trả NCC, điều chuyển...).
     */
    /**
     * Đồng bộ stock_quantity với số serial in_stock thực tế.
     *
     * LƯU Ý: Sau khi chuyển sang BQ di động (MovingAvgCostingService), method này
     * KHÔNG còn tính lại cost_price từ serial. cost_price = BQ moving avg, được duy trì
     * bởi service. Method chỉ giữ vai trò sync stock_quantity (audit count) cho hàng có serial.
     */
    public function recomputeFromSerials(): void
    {
        if (!$this->has_serial || $this->isService()) {
            return;
        }

        $count = (int) SerialImei::where('product_id', $this->id)
            ->where('status', 'in_stock')
            ->count();

        // Nếu lệch — sync số lượng. Không đụng cost_price (đã do MovingAvgCostingService quản).
        if ((int) $this->stock_quantity !== $count) {
            $this->stock_quantity = $count;
            $this->save();
        }
    }
}
