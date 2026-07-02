<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkdaySetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'week_days',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'week_days' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
