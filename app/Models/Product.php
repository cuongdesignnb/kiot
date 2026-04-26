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
        'location'
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
    public function recomputeFromSerials(): void
    {
        if (!$this->has_serial) {
            return;
        }

        $q = SerialImei::where('product_id', $this->id)
            ->where('status', 'in_stock');
        $count = (int) $q->count();
        $avg = $count > 0 ? (float) $q->avg('cost_price') : 0.0;

        $this->stock_quantity = $count;
        $this->cost_price = round($avg, 2);
        $this->save();
    }
}
