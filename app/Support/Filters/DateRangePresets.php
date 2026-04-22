<?php

namespace App\Support\Filters;

use Carbon\Carbon;

/**
 * Shared date-range preset resolver for sidebar filters.
 *
 * Supported presets:
 *   today, yesterday, this_week, last_week, last_7_days,
 *   this_month, last_month, last_30_days,
 *   this_quarter, last_quarter,
 *   this_year, last_year, custom, all
 */
class DateRangePresets
{
    /**
     * Resolve a preset (+ optional custom bounds) into [from, to] Carbon dates.
     * Returns [null, null] when no bounds should apply (preset="all" or unknown).
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public static function resolve(?string $preset, ?string $from = null, ?string $to = null): array
    {
        $now = Carbon::now();

        switch ($preset) {
            case 'today':
                return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
            case 'yesterday':
                $y = $now->copy()->subDay();
                return [$y->copy()->startOfDay(), $y->copy()->endOfDay()];
            case 'this_week':
                return [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()];
            case 'last_week':
                $lw = $now->copy()->subWeek();
                return [$lw->copy()->startOfWeek(), $lw->copy()->endOfWeek()];
            case 'last_7_days':
                return [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()];
            case 'this_month':
                return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
            case 'last_month':
                $lm = $now->copy()->subMonthNoOverflow();
                return [$lm->copy()->startOfMonth(), $lm->copy()->endOfMonth()];
            case 'last_30_days':
                return [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()];
            case 'this_quarter':
                return [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()];
            case 'last_quarter':
                $lq = $now->copy()->subQuarter();
                return [$lq->copy()->startOfQuarter(), $lq->copy()->endOfQuarter()];
            case 'this_year':
                return [$now->copy()->startOfYear(), $now->copy()->endOfYear()];
            case 'last_year':
                $ly = $now->copy()->subYear();
                return [$ly->copy()->startOfYear(), $ly->copy()->endOfYear()];
            case 'custom':
                $f = $from ? Carbon::parse($from)->startOfDay() : null;
                $t = $to ? Carbon::parse($to)->endOfDay() : null;
                return [$f, $t];
            case 'all':
            default:
                return [null, null];
        }
    }

    /**
     * Preset option list for the frontend (value + label).
     *
     * @return array<int, array{value:string,label:string}>
     */
    public static function options(): array
    {
        return [
            ['value' => 'all',          'label' => 'Toàn thời gian'],
            ['value' => 'today',        'label' => 'Hôm nay'],
            ['value' => 'yesterday',    'label' => 'Hôm qua'],
            ['value' => 'this_week',    'label' => 'Tuần này'],
            ['value' => 'last_week',    'label' => 'Tuần trước'],
            ['value' => 'last_7_days',  'label' => '7 ngày qua'],
            ['value' => 'this_month',   'label' => 'Tháng này'],
            ['value' => 'last_month',   'label' => 'Tháng trước'],
            ['value' => 'last_30_days', 'label' => '30 ngày qua'],
            ['value' => 'this_quarter', 'label' => 'Quý này'],
            ['value' => 'last_quarter', 'label' => 'Quý trước'],
            ['value' => 'this_year',    'label' => 'Năm này'],
            ['value' => 'last_year',    'label' => 'Năm trước'],
            ['value' => 'custom',       'label' => 'Tùy chỉnh'],
        ];
    }
}
