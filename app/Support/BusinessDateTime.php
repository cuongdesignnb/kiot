<?php

namespace App\Support;

use Carbon\Carbon;

class BusinessDateTime
{
    public static function forCreate($value = null): Carbon
    {
        if ($value === null || $value === '') {
            return now();
        }

        return Carbon::parse($value);
    }

    public static function forUpdate($value = null, $currentValue = null): Carbon
    {
        if ($value === null || $value === '') {
            return $currentValue ? Carbon::parse($currentValue) : now();
        }

        return Carbon::parse($value);
    }

    public static function nullable($value = null): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
