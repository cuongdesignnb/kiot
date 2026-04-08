<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayslipAdjustment extends Model
{
    protected $fillable = [
        'payslip_id',
        'type',
        'name',
        'amount',
        'notes',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function payslip()
    {
        return $this->belongsTo(Payslip::class);
    }
}
