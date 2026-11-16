<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bearer token for the public Kiot API (read-only by default).
 * Only the SHA-256 hash of the secret is stored.
 *
 * Abilities: two read scopes plus two separate, logged write functions that
 * must be ticked explicitly when the token is created.
 */
class InventoryApiToken extends Model
{
    public const ABILITY_INVENTORY_READ = 'inventory:read';

    public const ABILITY_DATA_READ = 'data:read';

    public const ABILITY_TECHNICIAN_PRICE_WRITE = 'products:technician_price:write';

    public const ABILITY_INVOICES_CREATE = 'invoices:create';

    public const READ_ABILITIES = [self::ABILITY_INVENTORY_READ, self::ABILITY_DATA_READ];

    public const WRITE_ABILITIES = [self::ABILITY_TECHNICIAN_PRICE_WRITE, self::ABILITY_INVOICES_CREATE];

    /**
     * Ability => the single HTTP method its routes accept.
     */
    public const WRITE_ABILITY_METHODS = [
        self::ABILITY_TECHNICIAN_PRICE_WRITE => 'PATCH',
        self::ABILITY_INVOICES_CREATE => 'POST',
    ];

    public const ABILITY_LABELS = [
        self::ABILITY_INVENTORY_READ => 'Đọc tồn kho/serial',
        self::ABILITY_DATA_READ => 'Đọc dữ liệu (hóa đơn, trả hàng, nhập hàng, sửa chữa, công việc, công nợ, sổ quỹ, tổng quan)',
        self::ABILITY_TECHNICIAN_PRICE_WRITE => 'GHI: sửa giá bán thợ',
        self::ABILITY_INVOICES_CREATE => 'GHI: tạo hóa đơn bán hàng mới',
    ];

    public static function allAbilities(): array
    {
        return [...self::READ_ABILITIES, ...self::WRITE_ABILITIES];
    }

    public static function isWriteAbility(string $ability): bool
    {
        return in_array($ability, self::WRITE_ABILITIES, true);
    }

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
