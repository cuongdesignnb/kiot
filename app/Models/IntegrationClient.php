<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class IntegrationClient extends Model
{
    use SoftDeletes;

    public const PROVIDER_PC_WEBSITE = 'pc_website';

    protected $fillable = [
        'name',
        'provider',
        'client_id',
        'secret_encrypted',
        'secret_fingerprint',
        'previous_secret_encrypted',
        'previous_secret_expires_at',
        'website_url',
        'default_branch_id',
        'pc_product_price_book_id',
        'sales_channel',
        'is_enabled',
        'timestamp_tolerance_seconds',
        'nonce_ttl_seconds',
        'rate_limit_per_minute',
        'reservation_ttl_minutes',
        'api_version',
        'last_connected_at',
        'last_request_at',
        'last_request_ip',
        'secret_created_at',
        'secret_rotated_at',
        'revoked_at',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'secret_encrypted',
        'previous_secret_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'secret_encrypted' => 'encrypted',
            'previous_secret_encrypted' => 'encrypted',
            'previous_secret_expires_at' => 'datetime',
            'is_enabled' => 'boolean',
            'last_connected_at' => 'datetime',
            'last_request_at' => 'datetime',
            'secret_created_at' => 'datetime',
            'secret_rotated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function productPriceBook(): BelongsTo
    {
        return $this->belongsTo(PriceBook::class, 'pc_product_price_book_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function pairingTokens(): HasMany
    {
        return $this->hasMany(IntegrationPairingToken::class);
    }
}
