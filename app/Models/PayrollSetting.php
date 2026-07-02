<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PayrollSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'pay_cycle',
        'start_day',
        'end_day',
        'start_in_prev_month',
        'pay_day',
        'default_recalculate_timekeeping',
        'auto_generate_enabled',
        'late_half_day_enabled',
        'late_half_day_threshold',
        'late_penalty_enabled',
        'late_penalty_tiers',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'start_day' => 'integer',
        'end_day' => 'integer',
        'start_in_prev_month' => 'boolean',
        'pay_day' => 'integer',
        'default_recalculate_timekeeping' => 'boolean',
        'auto_generate_enabled' => 'boolean',
        'late_half_day_enabled' => 'boolean',
        'late_half_day_threshold' => 'integer',
        'late_penalty_enabled' => 'boolean',
        'late_penalty_tiers' => 'array',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
