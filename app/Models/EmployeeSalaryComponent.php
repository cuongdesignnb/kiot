<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeSalaryComponent extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'type',
        'name',
        'amount',
        'is_percentage',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_percentage' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
