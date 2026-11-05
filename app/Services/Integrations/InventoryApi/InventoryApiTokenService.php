<?php

namespace App\Services\Integrations\InventoryApi;

use App\Models\ActivityLog;
use App\Models\InventoryApiToken;
use Illuminate\Support\Str;

class InventoryApiTokenService
{
    /**
     * Creates a token and returns the plain-text secret exactly once.
     *
     * @return array{0: InventoryApiToken, 1: string}
     */
    public function create(string $name, ?string $accountLabel, ?int $userId): array
    {
        $plain = InventoryApiToken::TOKEN_PREFIX.Str::random(48);

        $token = InventoryApiToken::create([
            'name' => trim($name),
            'account_label' => $accountLabel !== null && trim($accountLabel) !== '' ? trim($accountLabel) : null,
            'token_prefix' => substr($plain, 0, 12),
            'token_hash' => self::hash($plain),
            'abilities' => [InventoryApiToken::ABILITY_INVENTORY_READ],
            'created_by' => $userId,
        ]);

        ActivityLog::log(
            ActivityLog::ACTION_INVENTORY_API_TOKEN_CREATED,
            'Tạo token API tồn kho (chỉ đọc): '.$token->name,
            $token,
            ['token_prefix' => $token->token_prefix, 'account_label' => $token->account_label],
            $userId,
        );

        return [$token, $plain];
    }

    public function revoke(InventoryApiToken $token, ?int $userId): InventoryApiToken
    {
        if ($token->isRevoked()) {
            return $token;
        }

        $token->forceFill(['revoked_at' => now(), 'revoked_by' => $userId])->save();

        ActivityLog::log(
            ActivityLog::ACTION_INVENTORY_API_TOKEN_REVOKED,
            'Thu hồi token API tồn kho: '.$token->name,
            $token,
            ['token_prefix' => $token->token_prefix],
            $userId,
        );

        return $token;
    }

    public function findByPlainToken(string $plain): ?InventoryApiToken
    {
        if (! str_starts_with($plain, InventoryApiToken::TOKEN_PREFIX) || strlen($plain) > 128) {
            return null;
        }

        $hash = self::hash($plain);
        $token = InventoryApiToken::query()->where('token_hash', $hash)->first();

        return $token && hash_equals((string) $token->token_hash, $hash) ? $token : null;
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
