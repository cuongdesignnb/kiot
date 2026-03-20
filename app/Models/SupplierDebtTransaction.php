<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierDebtTransaction extends Model
{
    protected $fillable = [
        'supplier_id',
        'code',
        'type',
        'amount',
        'debt_remain',
        'purchase_id',
        'note',
        'user_id',
    ];

    public function supplier()
    {
        return $this->belongsTo(Customer::class, 'supplier_id');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
