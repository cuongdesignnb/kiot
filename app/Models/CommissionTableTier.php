<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CommissionTableTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'commission_table_id',
        'revenue_from',
        'commission_value',
        'is_percentage',
        'sort_order',
    ];

    protected $casts = [
        'revenue_from' => 'integer',
        'commission_value' => 'integer',
        'is_percentage' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function commissionTable()
    {
        return $this->belongsTo(CommissionTable::class);
    }
}
