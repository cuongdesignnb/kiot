<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per write attempt through the public Kiot API
 * (technician price update / invoice create), success or failure.
 */
class PublicApiWriteLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_TECHNICIAN_PRICE_UPDATE = 'technician_price.update';

    public const ACTION_INVOICE_CREATE = 'invoice.create';

    protected $fillable = [
        'inventory_api_token_id',
        'action',
        'target_type',
        'target_id',
        'target_ref',
        'before',
        'after',
        'idempotency_key',
        'request_hash',
        'status_code',
        'response',
        'error_code',
        'ip_address',
        'user_agent',
        'completed_at',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'response' => 'array',
        'status_code' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function token(): BelongsTo
    {
        return $this->belongsTo(InventoryApiToken::class, 'inventory_api_token_id');
    }
}
