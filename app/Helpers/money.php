<?php

/**
 * Format VND currency for display.
 *
 * @param  float|int|string|null  $value
 * @return string  e.g. "1.000.000đ"
 */
if (!function_exists('format_vnd')) {
    function format_vnd($value): string
    {
        $number = (float) ($value ?? 0);
        return number_format($number, 0, ',', '.') . 'đ';
    }
}
