<?php

namespace App\Support\Status;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class BusinessStatus
{
    public static function normalize(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $value = trim(mb_strtolower($status));
        $ascii = self::ascii($value);
        $key = str_replace([' ', '-'], '_', $ascii);

        return match ($key) {
            'hoan_thanh', 'completed', 'paid', 'done' => 'completed',
            'da_huy', 'da_huyy', 'huy', 'cancelled', 'canceled', 'void', 'deleted' => 'cancelled',
            'active', 'hoat_dong' => 'active',
            'dang_xu_ly', 'pending', 'processing', 'in_progress' => 'processing',
            'balanced', 'da_can_bang' => 'balanced',
            'draft', 'nhap' => 'draft',
            'da_tra' => 'returned',
            default => $key,
        };
    }

    public static function isCompleted(?string $status): bool
    {
        return self::normalize($status) === 'completed';
    }

    public static function isCancelled(?string $status): bool
    {
        return self::normalize($status) === 'cancelled';
    }

    public static function isValidCashFlow(?string $status): bool
    {
        if ($status === null || trim($status) === '') {
            return true;
        }

        return in_array(self::normalize($status), ['active', 'completed'], true);
    }

    public static function cancelledDatabaseValues(): array
    {
        return [
            'cancelled',
            'canceled',
            'da huy',
            'đã hủy',
            'đã huỷ',
            'hủy',
            'huỷ',
            'huy',
            'void',
            'deleted',
        ];
    }

    public static function scopeNotCancelled(Builder $query, string $column = 'status'): Builder
    {
        return $query->where(function (Builder $statusQuery) use ($column) {
            $statusQuery->whereNull($column)
                ->orWhereNotIn(
                    DB::raw("LOWER(TRIM({$column}))"),
                    self::cancelledDatabaseValues()
                );
        });
    }

    public static function notCancelledSql(string $column): string
    {
        $values = collect(self::cancelledDatabaseValues())
            ->map(fn (string $value) => "'" . str_replace("'", "''", mb_strtolower(trim($value))) . "'")
            ->implode(', ');

        return "({$column} IS NULL OR LOWER(TRIM({$column})) NOT IN ({$values}))";
    }

    public static function isBalanced(?string $status): bool
    {
        return self::normalize($status) === 'balanced';
    }

    public static function isReturnCompleted(?string $status): bool
    {
        $normalized = self::normalize($status);

        return in_array($normalized, ['completed', 'returned'], true);
    }

    private static function ascii(string $value): string
    {
        $map = [
            'à' => 'a', 'á' => 'a', 'ạ' => 'a', 'ả' => 'a', 'ã' => 'a',
            'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a',
            'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
            'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e',
            'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e', 'ể' => 'e', 'ễ' => 'e',
            'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o',
            'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o', 'ổ' => 'o', 'ỗ' => 'o',
            'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u',
            'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u', 'ử' => 'u', 'ữ' => 'u',
            'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y',
            'đ' => 'd',
        ];

        return strtr($value, $map);
    }
}
