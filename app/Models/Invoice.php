<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'transaction_date' => 'datetime',
        'lock_started_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'order_deposit_applied_amount' => 'decimal:2',
    ];

    /**
     * Scope: chỉ lấy hóa đơn hợp lệ (loại trừ status = 'Đã hủy').
     *
     * Dùng trong báo cáo, dashboard, metric để không tính HĐ đã hủy.
     * KHÔNG dùng global scope vì InvoiceController@show/index cần xem cả HĐ hủy.
     */
    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'Đã hủy');
    }

    /**
     * Invoices whose sale date ("Ngày bán", editable) falls in the range.
     * Reports use this so an invoice whose date was changed appears on the
     * same day everywhere (list, P&L, sales, products, staff, end of day).
     */
    public function scopeBusinessBetween($query, $from, $to)
    {
        $table = $query->getModel()->getTable();

        return $query->whereRaw("COALESCE({$table}.transaction_date, {$table}.created_at) BETWEEN ? AND ?", [$from, $to]);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator()
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function waybills()
    {
        return $this->hasMany(Waybill::class);
    }

    public function activeWaybill()
    {
        return $this->hasOne(Waybill::class)->where('is_active', true);
    }

    public function customerPaymentAllocations()
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }
}
