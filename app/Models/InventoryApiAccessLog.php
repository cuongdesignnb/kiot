<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryApiAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'inventory_api_token_id',
        'method',
        'path',
        'query',
        'status_code',
        'outcome',
        'result_count',
        'duration_ms',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'query' => 'array',
    ];

    public function token(): BelongsTo
    {
        return $this->belongsTo(InventoryApiToken::class, 'inventory_api_token_id');
    }
}
