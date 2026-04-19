<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashFlow extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'type',
        'amount',
        'time',
        'category',
        'target_type',
        'target_id',
        'target_name',
        'accounting_result',
        'payment_method',
        'bank_account_id',
        'reference_type',
        'reference_code',
        'description',
        'status',
    ];

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }
}
