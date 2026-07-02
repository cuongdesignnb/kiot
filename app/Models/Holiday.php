<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HasFactory;

    protected $fillable = [
        'holiday_date',
        'name',
        'multiplier',
        'paid_leave',
        'status',
        'notes',
    ];

    protected $casts = [
        'holiday_date' => 'date',
        'multiplier' => 'decimal:2',
        'paid_leave' => 'boolean',
    ];
}
