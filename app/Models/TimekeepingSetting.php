<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimekeepingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'standard_hours_per_day',
        'use_shift_allowances',
        'late_grace_minutes',
        'early_grace_minutes',
        'allow_multiple_shifts_one_inout',
        'enforce_shift_checkin_window',
        'ot_rounding_minutes',
        'ot_after_minutes',
        'ot_before_minutes',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'use_shift_allowances' => 'boolean',
        'enforce_shift_checkin_window' => 'boolean',
        'allow_multiple_shifts_one_inout' => 'boolean',
    ];
}
