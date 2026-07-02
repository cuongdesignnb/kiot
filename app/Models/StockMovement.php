<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    protected $fillable = [
        'product_id', 'serial_imei_id', 'branch_id',
        'type', 'direction',
        'qty', 'unit_cost', 'total_cost',
        'balance_qty', 'balance_cost',
        'ref_type', 'ref_id', 'ref_code',
        'user_id', 'employee_id',
        'note', 'moved_at',
    ];

    protected $casts = [
        'qty' => 'integer',
        'unit_cost' => 'decimal:0',
        'total_cost' => 'decimal:0',
        'balance_qty' => 'integer',
        'balance_cost' => 'decimal:0',
        'moved_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function serialImei(): BelongsTo
    {
        return $this->belongsTo(SerialImei::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function ref(): MorphTo
    {
        return $this->morphTo();
    }
}
