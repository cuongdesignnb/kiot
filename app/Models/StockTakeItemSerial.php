<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockTakeItemSerial extends Model
{
    use HasFactory;

    protected $table = 'stock_take_item_serials';

    protected $fillable = [
        'stock_take_item_id',
        'serial_imei_id',
        'serial_number_snapshot',
        'system_present',
        'actual_present',
        'status_snapshot',
        'repair_status_snapshot',
        'cost_price_snapshot',
        'checked_at',
    ];

    protected $casts = [
        'system_present' => 'boolean',
        'actual_present' => 'boolean',
        'cost_price_snapshot' => 'decimal:2',
        'checked_at' => 'datetime',
    ];

    public function stockTakeItem()
    {
        return $this->belongsTo(StockTakeItem::class);
    }

    public function serialImei()
    {
        return $this->belongsTo(SerialImei::class);
    }
}
