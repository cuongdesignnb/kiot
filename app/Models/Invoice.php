<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'transaction_date' => 'datetime',
        'lock_started_at'  => 'datetime',
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
}
