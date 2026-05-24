<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPaymentDiscountAllocation extends Model
{
    protected $fillable = [
        'customer_payment_discount_id',
        'customer_id',
        'invoice_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function discount()
    {
        return $this->belongsTo(CustomerPaymentDiscount::class, 'customer_payment_discount_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
