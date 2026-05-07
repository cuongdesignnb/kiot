<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'code',
        'name',
        'phone',
        'phone2',
        'type',
        'gender',
        'email',
        'facebook',
        'address',
        'city',
        'district',
        'ward',
        'customer_group',
        'avatar',
        'birthday',
        'note',
        'tax_code',
        'debt_amount',
        'total_spent',
        'total_returns',
        'invoice_name',
        'invoice_address',
        'invoice_city',
        'invoice_district',
        'invoice_ward',
        'id_card',
        'passport',
        'invoice_email',
        'invoice_phone',
        'bank_name',
        'bank_account',
        'is_supplier',
        'is_customer',
        'supplier_debt_amount',
        'total_bought',
        'status',
        'branch_id',
        'created_by',
    ];

    protected $casts = [
        'birthday' => 'date',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function returns()
    {
        return $this->hasMany(OrderReturn::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class, 'supplier_id');
    }

    public function deliveryAddresses()
    {
        return $this->hasMany(CustomerDeliveryAddress::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
