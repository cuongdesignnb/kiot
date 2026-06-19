<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Paysheet extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'pay_period',
        'period_start',
        'period_end',
        'standard_working_days',
        'branch_id',
        'scope',
        'status',
        'payment_status',
        'needs_recalc',
        'total_salary',
        'total_paid',
        'total_remaining',
        'employee_count',
        'created_by',
        'locked_by',
        'locked_at',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'locked_at' => 'datetime',
        'total_salary' => 'integer',
        'total_paid' => 'integer',
        'total_remaining' => 'integer',
        'needs_recalc' => 'boolean',
        // Step 24.12 — float so the UI can show "26" or "25.5" without trailing zeros.
        'standard_working_days' => 'float',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    public function payments()
    {
        return $this->hasMany(PaysheetPayment::class);
    }

    /**
     * Auto-generate next code: BL000001
     */
    public static function nextCode(): string
    {
        $last = static::orderByDesc('id')->value('code');
        $num = $last ? ((int) substr($last, 2)) + 1 : 1;

        return 'BL'.str_pad($num, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Recalculate totals from payslips
     */
    public function recalculateTotals(): void
    {
        $this->total_salary = $this->payslips()->sum('total_salary');
        $this->total_paid = $this->payslips()->sum('paid_amount');
        $this->total_remaining = $this->payslips()->sum('remaining');
        $this->employee_count = $this->payslips()->count();
        $statuses = $this->payslips()->pluck('payment_status');
        $this->payment_status = $statuses->isNotEmpty() && $statuses->every(fn ($status) => $status === 'paid')
            ? 'paid'
            : ($statuses->contains(fn ($status) => in_array($status, ['partial', 'paid'], true)) ? 'partial' : 'unpaid');
        $this->save();
    }
}
