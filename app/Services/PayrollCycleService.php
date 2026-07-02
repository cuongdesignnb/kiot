<?php

namespace App\Services;

use App\Models\PayrollSetting;
use Carbon\Carbon;

class PayrollCycleService
{
    /**
     * Sinh danh sách chu kỳ lương chính xác theo lịch thực tế.
     * Tự động xử lý tháng có 28/29/30/31 ngày (clamp ngày cuối tháng).
     *
     * @param  int|null  $branchId   Chi nhánh (null = mặc định)
     * @param  int       $year       Năm cần lấy (VD: 2026)
     * @param  int       $extraPast  Số chu kỳ thêm trước năm (mặc định 1)
     * @param  int       $extraFuture Số chu kỳ thêm sau năm (mặc định 1)
     * @return array     Mảng [ ['period_start' => 'Y-m-d', 'period_end' => 'Y-m-d', 'label' => '...', 'month' => int, 'year' => int] ]
     */
    public function getCyclesForYear(?int $branchId, int $year, int $extraPast = 1, int $extraFuture = 1): array
    {
        $setting = $this->getSetting($branchId);

        $payCycle = $setting['pay_cycle'] ?? 'monthly';

        if ($payCycle === 'monthly') {
            return $this->buildMonthlyCycles($setting, $year, $extraPast, $extraFuture);
        }

        // Fallback: calendar months
        return $this->buildCalendarMonths($year, $extraPast, $extraFuture);
    }

    /**
     * Lấy chu kỳ lương hiện tại (đang trong kỳ) và N kỳ trước.
     */
    public function getRecentCycles(?int $branchId, int $count = 12): array
    {
        $setting = $this->getSetting($branchId);
        $payCycle = $setting['pay_cycle'] ?? 'monthly';

        if ($payCycle !== 'monthly') {
            return $this->buildCalendarMonths(now()->year, 6, 1);
        }

        $startDay = (int) ($setting['start_day'] ?? 26);
        $endDay = (int) ($setting['end_day'] ?? 25);
        $spansPrevMonth = !empty($setting['start_in_prev_month']) || ($startDay > $endDay);

        $today = Carbon::today();
        $cycles = [];

        // Xác định kỳ hiện tại: tìm anchor month
        // Kỳ hiện tại kết thúc ở tháng nào?
        if ($spansPrevMonth) {
            // VD: 26/prev → 25/current. Nếu hôm nay > endDay → đang ở kỳ tiếp theo
            $anchorMonth = $today->day > $endDay
                ? $today->copy()->addMonth()->startOfMonth()
                : $today->copy()->startOfMonth();
        } else {
            // VD: 1/current → 25/current
            $anchorMonth = $today->day < $startDay
                ? $today->copy()->subMonth()->startOfMonth()
                : $today->copy()->startOfMonth();
        }

        for ($i = 0; $i < $count; $i++) {
            $endRef = $anchorMonth->copy()->subMonths($i);
            $endDate = $this->clampDate($endRef->year, $endRef->month, $endDay);

            if ($spansPrevMonth) {
                $startRef = $endRef->copy()->subMonth();
            } else {
                $startRef = $endRef->copy();
            }
            $startDate = $this->clampDate($startRef->year, $startRef->month, $startDay);

            $label = $startDate->format('d/m/Y') . ' - ' . $endDate->format('d/m/Y');

            $cycles[] = [
                'period_start' => $startDate->toDateString(),
                'period_end' => $endDate->toDateString(),
                'label' => $label,
                'month' => $endDate->month,
                'year' => $endDate->year,
            ];
        }

        return $cycles;
    }

    /**
     * Xác định kỳ lương hiện tại dựa trên ngày hôm nay.
     */
    public function getCurrentCycle(?int $branchId): array
    {
        $cycles = $this->getRecentCycles($branchId, 1);
        return $cycles[0] ?? [];
    }

    // ==================== PRIVATE ====================

    private function getSetting(?int $branchId): array
    {
        $setting = PayrollSetting::where('branch_id', $branchId)->first()
            ?? PayrollSetting::whereNull('branch_id')->first();

        if ($setting) {
            return $setting->toArray();
        }

        return [
            'pay_cycle' => 'monthly',
            'start_day' => 26,
            'end_day' => 25,
            'start_in_prev_month' => true,
            'pay_day' => 5,
        ];
    }

    /**
     * Sinh chu kỳ lương theo tháng, tự clamp ngày theo lịch thực.
     * VD: start_day=31, tháng 2 → clamp thành 28 (hoặc 29 năm nhuận).
     */
    private function buildMonthlyCycles(array $setting, int $year, int $extraPast, int $extraFuture): array
    {
        $startDay = (int) ($setting['start_day'] ?? 26);
        $endDay = (int) ($setting['end_day'] ?? 25);
        $spansPrevMonth = !empty($setting['start_in_prev_month']) || ($startDay > $endDay);

        $cycles = [];

        // Sinh từ tháng 1-extraPast đến tháng 12+extraFuture
        $fromMonth = 1 - $extraPast;
        $toMonth = 12 + $extraFuture;

        for ($m = $fromMonth; $m <= $toMonth; $m++) {
            // endRef = tháng m của năm year (Carbon auto-rolls over)
            $endRef = Carbon::create($year, $m, 1);
            $endDate = $this->clampDate($endRef->year, $endRef->month, $endDay);

            if ($spansPrevMonth) {
                $startRef = $endRef->copy()->subMonth();
            } else {
                $startRef = $endRef->copy();
            }
            $startDate = $this->clampDate($startRef->year, $startRef->month, $startDay);

            $label = $startDate->format('d/m/Y') . ' - ' . $endDate->format('d/m/Y');

            $cycles[] = [
                'period_start' => $startDate->toDateString(),
                'period_end' => $endDate->toDateString(),
                'label' => $label,
                'month' => $endDate->month,
                'year' => $endDate->year,
            ];
        }

        return $cycles;
    }

    private function buildCalendarMonths(int $year, int $extraPast, int $extraFuture): array
    {
        $cycles = [];
        $fromMonth = 1 - $extraPast;
        $toMonth = 12 + $extraFuture;

        for ($m = $fromMonth; $m <= $toMonth; $m++) {
            $ref = Carbon::create($year, $m, 1);
            $startDate = $ref->copy()->startOfMonth();
            $endDate = $ref->copy()->endOfMonth();

            $label = $startDate->format('d/m/Y') . ' - ' . $endDate->format('d/m/Y');

            $cycles[] = [
                'period_start' => $startDate->toDateString(),
                'period_end' => $endDate->toDateString(),
                'label' => $label,
                'month' => $ref->month,
                'year' => $ref->year,
            ];
        }

        return $cycles;
    }

    /**
     * Clamp ngày vào ngày cuối tháng nếu vượt quá.
     * VD: clampDate(2026, 2, 31) → 2026-02-28
     *     clampDate(2024, 2, 29) → 2024-02-29 (năm nhuận)
     */
    private function clampDate(int $year, int $month, int $day): Carbon
    {
        $maxDay = Carbon::create($year, $month, 1)->daysInMonth;
        return Carbon::create($year, $month, min($day, $maxDay));
    }
}
