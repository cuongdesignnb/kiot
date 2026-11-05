<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Read-only bearer token for the public inventory API.
 * Only the SHA-256 hash of the secret is stored.
 */
class InventoryApiToken extends Model
{
    public const ABILITY_INVENTORY_READ = 'inventory:read';

    public const TOKEN_PREFIX = 'kinv_';

    protected $fillable = [
        'name',
        'account_label',
        'token_prefix',
        'token_hash',
        'abilities',
        'last_used_at',
        'last_used_ip',
        'request_count',
        'created_by',
        'revoked_at',
        'revoked_by',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'abilities' => 'array',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'request_count' => 'integer',
    ];

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(InventoryApiAccessLog::class);
    }
}
