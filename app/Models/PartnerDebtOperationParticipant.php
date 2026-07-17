<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerDebtOperationParticipant extends Model
{
    protected $fillable = [
        'operation_id',
        'partner_id',
        'participant_role',
        'effect_role',
        'customer_delta',
        'supplier_delta',
    ];

    protected $casts = [
        'customer_delta' => 'decimal:2',
        'supplier_delta' => 'decimal:2',
    ];

    public function operation()
    {
        return $this->belongsTo(PartnerDebtOperation::class, 'operation_id');
    }

    public function partner()
    {
        return $this->belongsTo(Customer::class, 'partner_id');
    }
}
