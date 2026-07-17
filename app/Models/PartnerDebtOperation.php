<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerDebtOperation extends Model
{
    protected $fillable = [
        'operation_uuid',
        'partner_id',
        'operation_type',
        'idempotency_key',
        'request_hash',
        'request_hash_version',
        'status',
        'source_type',
        'source_id',
        'reverses_operation_id',
        'result',
        'attempt_count',
        'initiated_by',
        'initiated_at',
        'committed_at',
        'failed_at',
        'failure_code',
        'metadata',
    ];

    protected $casts = [
        'result' => 'array',
        'metadata' => 'array',
        'initiated_at' => 'datetime',
        'committed_at' => 'datetime',
        'failed_at' => 'datetime',
        'request_hash_version' => 'integer',
        'attempt_count' => 'integer',
    ];

    public function partner()
    {
        return $this->belongsTo(Customer::class, 'partner_id');
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function reversedOperation()
    {
        return $this->belongsTo(self::class, 'reverses_operation_id');
    }

    public function reversalOperation()
    {
        return $this->hasOne(self::class, 'reverses_operation_id');
    }

    public function participants()
    {
        return $this->hasMany(PartnerDebtOperationParticipant::class, 'operation_id');
    }

    public function outboxEvents()
    {
        return $this->hasMany(PartnerDebtOutboxEvent::class, 'operation_id');
    }
}
