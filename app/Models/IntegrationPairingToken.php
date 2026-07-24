<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationPairingToken extends Model
{
    protected $fillable = [
        'integration_client_id',
        'reference',
        'token_hash',
        'expires_at',
        'used_at',
        'used_by_ip',
        'attempt_count',
        'created_by',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempt_count' => 'integer',
        ];
    }

    public function integrationClient(): BelongsTo
    {
        return $this->belongsTo(IntegrationClient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
