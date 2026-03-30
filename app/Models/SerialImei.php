<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SerialImei extends Model
{
    protected $fillable = [
        'product_id',
        'variant_id',
        'serial_number',
        'status',
        'purchase_id',
        'repair_status',
        'cost_price',
        'original_cost',
        'sold_at',
        'invoice_id',
        'warranty_expires_at',
    ];

    protected $casts = [
        'cost_price' => 'decimal:0',
        'original_cost' => 'decimal:0',
        'sold_at' => 'datetime',
        'warranty_expires_at' => 'datetime',
    ];

    const REPAIR_STATUS_MAP = [
        'not_started' => 'Chưa làm',
        'repairing'   => 'Đang xử lý',
        'ready'       => 'Sẵn bán',
    ];

    const REPAIR_STATUS_COLORS = [
        'not_started' => 'red',
        'repairing'   => 'yellow',
        'ready'       => 'green',
    ];

    public function getRepairStatusLabelAttribute(): ?string
    {
        return self::REPAIR_STATUS_MAP[$this->repair_status] ?? null;
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function deviceRepairs()
    {
        return $this->hasMany(DeviceRepair::class, 'serial_imei_id');
    }
}
