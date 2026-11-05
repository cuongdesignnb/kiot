<?php

namespace App\Support\Exports;

/**
 * Gives large synchronous exports enough headroom without changing the
 * global PHP limits for every other request.
 */
final class ExportRuntime
{
    public static function prepare(string $memoryLimit = '512M', int $timeLimitSeconds = 300): void
    {
        $current = self::bytes((string) ini_get('memory_limit'));
        if ($current !== -1 && $current < self::bytes($memoryLimit)) {
            @ini_set('memory_limit', $memoryLimit);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit($timeLimitSeconds);
        }
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
