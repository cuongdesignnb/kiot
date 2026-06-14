<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryAdvance extends Model
{
    protected $fillable = [
        'code', 'employee_id', 'branch_id', 'amount', 'applied_amount', 'remaining_amount',
        'advance_date', 'payment_method', 'status', 'note', 'cash_flow_id', 'created_by',
        'cancelled_by', 'cancelled_at', 'cancel_reason', 'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'integer',
        'applied_amount' => 'integer',
        'remaining_amount' => 'integer',
        'advance_date' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function cashFlow()
    {
        return $this->belongsTo(CashFlow::class);
    }

    public function applications()
    {
        return $this->hasMany(SalaryAdvanceApplication::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
