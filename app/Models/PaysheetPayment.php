<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaysheetPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'paysheet_id',
        'payslip_id',
        'employee_id',
        'amount',
        'status',
        'cash_flow_id',
        'method',
        'notes',
        'paid_at',
        'created_by',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'integer',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function paysheet()
    {
        return $this->belongsTo(Paysheet::class);
    }

    public function payslip()
    {
        return $this->belongsTo(Payslip::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function cashFlow()
    {
        return $this->belongsTo(CashFlow::class);
    }
}
