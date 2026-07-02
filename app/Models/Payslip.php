<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payslip extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'paysheet_id',
        'employee_id',
        'base_salary',
        'bonus',
        'commission',
        'allowances',
        'deductions',
        'ot_pay',
        'total_salary',
        'paid_amount',
        'applied_advance',
        'remaining',
        'payment_status',
        'work_units',
        'paid_leave_units',
        'ot_minutes',
        'details',
        'notes',
    ];

    protected $casts = [
        'base_salary' => 'integer',
        'bonus' => 'integer',
        'commission' => 'integer',
        'allowances' => 'integer',
        'deductions' => 'integer',
        'ot_pay' => 'integer',
        'total_salary' => 'integer',
        'paid_amount' => 'integer',
        'applied_advance' => 'integer',
        'remaining' => 'integer',
        'details' => 'array',
    ];

    public function paysheet()
    {
        return $this->belongsTo(Paysheet::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function payments()
    {
        return $this->hasMany(PaysheetPayment::class);
    }

    public function adjustments()
    {
        return $this->hasMany(PayslipAdjustment::class);
    }

    public function advanceApplications()
    {
        return $this->hasMany(SalaryAdvanceApplication::class);
    }

    /**
     * Auto-generate next code: PL000001
     */
    public static function nextCode(): string
    {
        $last = static::orderByDesc('id')->value('code');
        $num = $last ? ((int) substr($last, 2)) + 1 : 1;

        return 'PL'.str_pad($num, 6, '0', STR_PAD_LEFT);
    }
}
