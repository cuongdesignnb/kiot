<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalaryTemplateDeduction extends Model
{
    use HasFactory;

    protected $fillable = [
        'salary_template_id',
        'name',
        'deduction_category',
        'calculation_type',
        'amount',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'integer',
        'sort_order' => 'integer',
    ];

    public function template()
    {
        return $this->belongsTo(SalaryTemplate::class, 'salary_template_id');
    }
}
