<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalaryTemplateCommission extends Model
{
    use HasFactory;

    protected $fillable = [
        'salary_template_id',
        'role_type',
        'revenue_from',
        'commission_table_id',
        'commission_value',
        'commission_is_percentage',
        'sort_order',
    ];

    protected $casts = [
        'revenue_from' => 'integer',
        'commission_value' => 'integer',
        'commission_is_percentage' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function template()
    {
        return $this->belongsTo(SalaryTemplate::class, 'salary_template_id');
    }

    public function commissionTable()
    {
        return $this->belongsTo(CommissionTable::class);
    }
}
