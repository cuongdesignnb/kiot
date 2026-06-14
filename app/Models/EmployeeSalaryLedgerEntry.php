<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeSalaryLedgerEntry extends Model
{
    public const TYPE_PAYROLL_ACCRUAL = 'payroll_accrual';

    public const TYPE_SALARY_PAYMENT = 'salary_payment';

    public const TYPE_SALARY_ADVANCE = 'salary_advance';

    public const TYPE_ADJUSTMENT_INCREASE = 'adjustment_increase';

    public const TYPE_ADJUSTMENT_DECREASE = 'adjustment_decrease';

    public const TYPE_OPENING_BALANCE = 'opening_balance';

    public const TYPE_CANCEL_REVERSE = 'cancel_reverse';

    protected $fillable = [
        'employee_id', 'branch_id', 'paysheet_id', 'payslip_id', 'original_entry_id',
        'code', 'type', 'reference_type', 'reference_id', 'amount', 'balance_after',
        'is_effective', 'status', 'event_at', 'payment_method', 'note', 'reason',
        'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
        'is_effective' => 'boolean',
        'event_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function paysheet()
    {
        return $this->belongsTo(Paysheet::class);
    }

    public function payslip()
    {
        return $this->belongsTo(Payslip::class);
    }

    public function originalEntry()
    {
        return $this->belongsTo(self::class, 'original_entry_id');
    }

    public function reversalEntries()
    {
        return $this->hasMany(self::class, 'original_entry_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
