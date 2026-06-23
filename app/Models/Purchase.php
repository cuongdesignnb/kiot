<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $fillable = [
        'code',
        'supplier_id',
        'user_id',
        'employee_id',
        'total_amount',
        'discount',
        'other_costs',
        'other_costs_total',
        'paid_amount',
        'debt_amount',
        'note',
        'status',
        'purchase_date',
        'payment_method',
        'bank_account_info',
        'purchase_order_id',
        'cancel_reason',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'purchase_date' => 'datetime',
        'cancelled_at' => 'datetime',
        'other_costs' => 'array',
        'other_costs_total' => 'decimal:2',
    ];

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Customer::class, 'supplier_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
