<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    protected $fillable = [
        'account_number', 'bank_name', 'account_holder',
        'type', 'scope', 'branch_id', 'note', 'status',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
