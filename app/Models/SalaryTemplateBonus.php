<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalaryTemplateBonus extends Model
{
    use HasFactory;

    protected $fillable = [
        'salary_template_id',
        'role_type',
        'revenue_from',
        'bonus_value',
        'bonus_is_percentage',
        'sort_order',
    ];

    protected $casts = [
        'revenue_from' => 'integer',
        'bonus_value' => 'integer',
        'bonus_is_percentage' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function template()
    {
        return $this->belongsTo(SalaryTemplate::class, 'salary_template_id');
    }
}
