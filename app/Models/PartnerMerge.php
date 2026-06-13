<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerMerge extends Model
{
    protected $fillable = [
        'ref_code',
        'source_partner_id',
        'target_partner_id',
        'source_debt_amount',
        'source_supplier_debt_amount',
        'target_debt_amount_before',
        'target_supplier_debt_amount_before',
        'source_total_spent_before',
        'source_total_returns_before',
        'source_total_bought_before',
        'target_total_spent_before',
        'target_total_returns_before',
        'target_total_bought_before',
        'target_debt_amount_after',
        'target_supplier_debt_amount_after',
        'target_total_spent_after',
        'target_total_returns_after',
        'target_total_bought_after',
        'merged_by',
        'merged_at',
    ];

    protected $casts = [
        'source_debt_amount' => 'decimal:2',
        'source_supplier_debt_amount' => 'decimal:2',
        'target_debt_amount_before' => 'decimal:2',
        'target_supplier_debt_amount_before' => 'decimal:2',
        'source_total_spent_before' => 'decimal:2',
        'source_total_returns_before' => 'decimal:2',
        'source_total_bought_before' => 'decimal:2',
        'target_total_spent_before' => 'decimal:2',
        'target_total_returns_before' => 'decimal:2',
        'target_total_bought_before' => 'decimal:2',
        'target_debt_amount_after' => 'decimal:2',
        'target_supplier_debt_amount_after' => 'decimal:2',
        'target_total_spent_after' => 'decimal:2',
        'target_total_returns_after' => 'decimal:2',
        'target_total_bought_after' => 'decimal:2',
        'merged_at' => 'datetime',
    ];
}
