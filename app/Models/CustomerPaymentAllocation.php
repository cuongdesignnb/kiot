<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPaymentAllocation extends Model
{
    protected $fillable = [
        'cash_flow_id',
        'customer_id',
        'invoice_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function cashFlow()
    {
        return $this->belongsTo(CashFlow::class);
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
