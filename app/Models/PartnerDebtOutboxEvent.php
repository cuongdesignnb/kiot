<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartnerDebtOutboxEvent extends Model
{
    protected $fillable = [
        'event_uuid',
        'operation_id',
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'schema_version',
        'payload',
        'status',
        'occurred_at',
        'next_attempt_at',
        'attempts',
        'locked_at',
        'lease_expires_at',
        'locked_by',
        'claim_token',
        'published_at',
        'last_error_code',
        'last_error',
        'dead_lettered_at',
        'resolved_by',
        'resolved_at',
        'resolution_note',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'locked_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'published_at' => 'datetime',
        'dead_lettered_at' => 'datetime',
        'resolved_at' => 'datetime',
        'schema_version' => 'integer',
        'attempts' => 'integer',
    ];

    public function operation()
    {
        return $this->belongsTo(PartnerDebtOperation::class, 'operation_id');
    }
}
