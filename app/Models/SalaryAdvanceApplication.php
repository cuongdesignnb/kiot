<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryAdvanceApplication extends Model
{
    protected $fillable = [
        'salary_advance_id', 'employee_id', 'paysheet_id', 'payslip_id', 'amount',
        'status', 'note', 'created_by', 'cancelled_by', 'cancelled_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'cancelled_at' => 'datetime',
    ];

    public function advance()
    {
        return $this->belongsTo(SalaryAdvance::class, 'salary_advance_id');
    }

    public function payslip()
    {
        return $this->belongsTo(Payslip::class);
    }

    public function paysheet()
    {
        return $this->belongsTo(Paysheet::class);
    }
}
