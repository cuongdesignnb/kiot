<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * RR-06: Ledger công nợ khách hàng — pattern tham chiếu SupplierDebtTransaction.
 *
 * Bảng customer_debts đã tồn tại từ migration 2026_03_01_100000 + bổ sung type ở
 * 2026_04_09_150000. Model này là interface duy nhất ghi vào bảng. Mọi update
 * customers.debt_amount cho luồng đã refactor đi qua CustomerDebtService.
 */
class CustomerDebt extends Model
{
    protected $table = 'customer_debts';

    protected $fillable = [
        'customer_id',
        'order_id',
        'order_return_id',
        'ref_code',
        'amount',
        'debt_total',
        'type',
        'note',
        'created_by',
        'recorded_at',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'debt_total'  => 'decimal:2',
        'recorded_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function orderReturn()
    {
        return $this->belongsTo(OrderReturn::class);
    }
}
