<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\SalaryTemplate;
use App\Models\CommissionTable;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\WorkdaySetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SalaryCalculationService
{
    /**
     * Tính lương đầy đủ cho nhân viên theo mẫu lương trong khoảng thời gian
     */
    public function calculateForEmployee(Employee $employee, Carbon $from, Carbon $to): array
    {
        $setting = $employee->salarySetting;
        if (!$setting) {
            return $this->emptyResult();
        }

        $template = $setting->salary_template_id
            ? SalaryTemplate::with(['bonuses', 'commissions.commissionTable.tiers', 'allowances', 'deductions'])->find($setting->salary_template_id)
            : null;

        // Tính ngày công chuẩn theo lịch thực tế (WorkdaySetting + Holiday)
        $standardWorkUnits = $this->getStandardWorkUnits($employee->branch_id, $from, $to);

        // Lấy dữ liệu chấm công
        $records = $employee->timekeepingRecords()
            ->whereBetween('work_date', [$from, $to])
            ->get();

        $workUnits = $records->where('attendance_type', 'work')->sum('work_units');
        $paidLeaveUnits = $records->where('attendance_type', 'leave_paid')->sum('work_units');
        $totalUnits = $workUnits + $paidLeaveUnits;
        $otMinutes = $records->sum('ot_minutes');
        $lateCount = $records->where('late_minutes', '>', 0)->count();
        $lateTotalMinutes = $records->sum('late_minutes');
        $earlyLeaveCount = $records->where('early_minutes', '>', 0)->count();
        $earlyTotalMinutes = $records->sum('early_minutes');

        // Tính lương cơ bản theo tỷ lệ ngày công
        $baseSalary = $setting->base_salary;
        if ($setting->salary_type === 'hourly') {
            // Lương theo giờ: base_salary = hourly_rate × totalUnits × 8h
            $baseSalary = $totalUnits * 8 * $setting->base_salary;
        } else {
            // Lương cố định: tính theo tỷ lệ ngày công thực tế / ngày công chuẩn
            // VD: lương 10tr, công chuẩn 26, đi 20 → 10tr × 20/26 = 7.69tr
            if ($standardWorkUnits > 0) {
                $baseSalary = $setting->base_salary * $totalUnits / $standardWorkUnits;
            }
        }

        // Doanh thu cá nhân (dùng cho thưởng/hoa hồng)
        $personalRevenue = $this->getPersonalRevenue($employee, $from, $to);
        $branchRevenue = $this->getBranchRevenue($employee, $from, $to);
        $personalGrossProfit = null; // Lazy load khi cần

        $bonusAmount = 0;
        $commissionAmount = 0;
        $allowanceAmount = 0;
        $deductionAmount = 0;
        $bonusDetails = [];
        $commissionDetails = [];
        $allowanceDetails = [];
        $deductionDetails = [];

        if ($template) {
            // ===== THƯỞNG =====
            if ($template->has_bonus && $template->bonuses->count()) {
                if ($template->bonus_type === 'personal_gross_profit') {
                    $personalGrossProfit = $personalGrossProfit ?? $this->getPersonalGrossProfit($employee, $from, $to);
                    $revenue = $personalGrossProfit;
                } elseif ($template->bonus_type === 'branch_revenue') {
                    $revenue = $branchRevenue;
                } else {
                    $revenue = $personalRevenue;
                }
                $result = $this->calculateBonus($template, $revenue);
                $bonusAmount = $result['amount'];
                $bonusDetails = $result['details'];
            }

            // ===== HOA HỒNG =====
            if ($template->has_commission && $template->commissions->count()) {
                $result = $this->calculateCommission($template, $personalRevenue);
                $commissionAmount = $result['amount'];
                $commissionDetails = $result['details'];
            }

            // ===== PHỤ CẤP =====
            if ($template->has_allowance && $template->allowances->count()) {
                $result = $this->calculateAllowances($template, $baseSalary, $totalUnits);
                $allowanceAmount = $result['amount'];
                $allowanceDetails = $result['details'];
            }

            // ===== GIẢM TRỪ =====
            if ($template->has_deduction && $template->deductions->count()) {
                $result = $this->calculateDeductions(
                    $template, $lateCount, $lateTotalMinutes,
                    $earlyLeaveCount, $earlyTotalMinutes
                );
                $deductionAmount = $result['amount'];
                $deductionDetails = $result['details'];
            }
        }

        $totalSalary = $baseSalary + $bonusAmount + $commissionAmount + $allowanceAmount - $deductionAmount;

        return [
            'base' => round($baseSalary),
            'base_salary_full' => round($setting->base_salary),
            'bonus' => round($bonusAmount),
            'commission' => round($commissionAmount),
            'allowances' => round($allowanceAmount),
            'deductions' => round($deductionAmount),
            'ot_minutes' => $otMinutes,
            'standard_work_units' => $standardWorkUnits,
            'work_units' => $totalUnits,
            'paid_leave_units' => $paidLeaveUnits,
            'late_count' => $lateCount,
            'late_minutes' => $lateTotalMinutes,
            'early_leave_count' => $earlyLeaveCount,
            'early_minutes' => $earlyTotalMinutes,
            'personal_revenue' => $personalRevenue,
            'total' => round(max(0, $totalSalary)),
            'details' => [
                'bonus' => $bonusDetails,
                'commission' => $commissionDetails,
                'allowances' => $allowanceDetails,
                'deductions' => $deductionDetails,
            ],
        ];
    }

    /**
     * Tính thưởng theo doanh thu
     */
    private function calculateBonus(SalaryTemplate $template, float $revenue): array
    {
        $amount = 0;
        $details = [];

        if ($template->bonus_calculation === 'total_revenue') {
            // Tính theo mức tổng: lấy tier cao nhất mà doanh thu đạt được
            $matchedTier = $template->bonuses
                ->where('revenue_from', '<=', $revenue)
                ->sortByDesc('revenue_from')
                ->first();

            if ($matchedTier) {
                $tierAmount = $matchedTier->bonus_is_percentage
                    ? $revenue * $matchedTier->bonus_value / 100
                    : $matchedTier->bonus_value;
                $amount = $tierAmount;
                $details[] = [
                    'role_type' => $matchedTier->role_type,
                    'revenue_from' => $matchedTier->revenue_from,
                    'bonus_value' => $matchedTier->bonus_value,
                    'is_percentage' => $matchedTier->bonus_is_percentage,
                    'calculated' => round($tierAmount),
                ];
            }
        } else {
            // Lũy tiến: tính thưởng cho từng bậc doanh thu
            $sortedTiers = $template->bonuses->sortBy('revenue_from')->values();
            $remainingRevenue = $revenue;

            for ($i = 0; $i < $sortedTiers->count(); $i++) {
                $tier = $sortedTiers[$i];
                if ($revenue < $tier->revenue_from) break;

                $nextThreshold = ($i + 1 < $sortedTiers->count()) ? $sortedTiers[$i + 1]->revenue_from : $revenue;
                $tierRevenue = min($remainingRevenue, $nextThreshold - $tier->revenue_from);

                if ($tierRevenue > 0) {
                    $tierAmount = $tier->bonus_is_percentage
                        ? $tierRevenue * $tier->bonus_value / 100
                        : $tier->bonus_value;
                    $amount += $tierAmount;
                    $details[] = [
                        'role_type' => $tier->role_type,
                        'revenue_from' => $tier->revenue_from,
                        'tier_revenue' => round($tierRevenue),
                        'bonus_value' => $tier->bonus_value,
                        'is_percentage' => $tier->bonus_is_percentage,
                        'calculated' => round($tierAmount),
                    ];
                    $remainingRevenue -= $tierRevenue;
                }
            }
        }

        return ['amount' => $amount, 'details' => $details];
    }

    /**
     * Tính hoa hồng
     */
    private function calculateCommission(SalaryTemplate $template, float $revenue): array
    {
        $amount = 0;
        $details = [];

        foreach ($template->commissions as $commission) {
            if ($revenue < $commission->revenue_from) continue;

            if ($commission->commission_table_id && $commission->commissionTable) {
                // Dùng bảng hoa hồng
                $tiers = $commission->commissionTable->tiers->sortByDesc('revenue_from');
                $matchedTier = $tiers->where('revenue_from', '<=', $revenue)->first();

                if ($matchedTier) {
                    $tierAmount = $matchedTier->is_percentage
                        ? $revenue * $matchedTier->commission_value / 100
                        : $matchedTier->commission_value;
                    $amount += $tierAmount;
                    $details[] = [
                        'role_type' => $commission->role_type,
                        'table_name' => $commission->commissionTable->name,
                        'tier_revenue_from' => $matchedTier->revenue_from,
                        'commission_value' => $matchedTier->commission_value,
                        'is_percentage' => $matchedTier->is_percentage,
                        'calculated' => round($tierAmount),
                    ];
                }
            } else {
                // Hoa hồng cố định
                $tierAmount = $commission->commission_is_percentage
                    ? $revenue * $commission->commission_value / 100
                    : $commission->commission_value;
                $amount += $tierAmount;
                $details[] = [
                    'role_type' => $commission->role_type,
                    'commission_value' => $commission->commission_value,
                    'is_percentage' => $commission->commission_is_percentage,
                    'calculated' => round($tierAmount),
                ];
            }
        }

        return ['amount' => $amount, 'details' => $details];
    }

    /**
     * Tính phụ cấp
     */
    private function calculateAllowances(SalaryTemplate $template, float $baseSalary, float $workUnits): array
    {
        $amount = 0;
        $details = [];

        foreach ($template->allowances as $allowance) {
            $alAmount = 0;
            switch ($allowance->allowance_type) {
                case 'fixed_per_day':
                    $alAmount = $allowance->amount * $workUnits;
                    break;
                case 'fixed_per_month':
                    $alAmount = $allowance->amount;
                    break;
                case 'percentage':
                    $alAmount = $baseSalary * $allowance->amount / 100;
                    break;
            }
            $amount += $alAmount;
            $details[] = [
                'name' => $allowance->name,
                'type' => $allowance->allowance_type,
                'config_amount' => $allowance->amount,
                'calculated' => round($alAmount),
            ];
        }

        return ['amount' => $amount, 'details' => $details];
    }

    /**
     * Tính giảm trừ
     */
    private function calculateDeductions(
        SalaryTemplate $template,
        int $lateCount, int $lateTotalMinutes,
        int $earlyLeaveCount, int $earlyTotalMinutes
    ): array {
        $amount = 0;
        $details = [];

        foreach ($template->deductions as $deduction) {
            $dedAmount = 0;
            $occurrences = 0;

            switch ($deduction->deduction_category) {
                case 'late':
                    $occurrences = $lateCount;
                    if ($deduction->calculation_type === 'per_occurrence') {
                        $dedAmount = $deduction->amount * $lateCount;
                    } elseif ($deduction->calculation_type === 'per_minute') {
                        $dedAmount = $deduction->amount * $lateTotalMinutes;
                    } else {
                        $dedAmount = $deduction->amount;
                    }
                    break;
                case 'early_leave':
                    $occurrences = $earlyLeaveCount;
                    if ($deduction->calculation_type === 'per_occurrence') {
                        $dedAmount = $deduction->amount * $earlyLeaveCount;
                    } elseif ($deduction->calculation_type === 'per_minute') {
                        $dedAmount = $deduction->amount * $earlyTotalMinutes;
                    } else {
                        $dedAmount = $deduction->amount;
                    }
                    break;
                case 'absence':
                case 'violation':
                    // Cố định hoặc theo lần (sẽ cần data riêng, mặc định cố định/tháng)
                    $dedAmount = $deduction->amount;
                    break;
            }

            $amount += $dedAmount;
            $details[] = [
                'name' => $deduction->name,
                'category' => $deduction->deduction_category,
                'calc_type' => $deduction->calculation_type,
                'config_amount' => $deduction->amount,
                'occurrences' => $occurrences,
                'calculated' => round($dedAmount),
            ];
        }

        return ['amount' => $amount, 'details' => $details];
    }

    /**
     * Tính ngày công chuẩn trong kỳ lương dựa trên WorkdaySetting + Holiday
     *
     * Duyệt từng ngày trong khoảng [from, to]:
     * - Kiểm tra ngày đó có phải ngày làm việc theo WorkdaySetting (T2-T7, v.v.) không
     * - Trừ ngày lễ (Holiday) không tính công
     * - Ngày lễ có paid_leave=true vẫn tính vào công chuẩn
     */
    private function getStandardWorkUnits(?int $branchId, Carbon $from, Carbon $to): float
    {
        // Lấy cấu hình ngày làm theo chi nhánh, fallback sang cấu hình chung
        $workdaySetting = WorkdaySetting::where('branch_id', $branchId)->first()
            ?? WorkdaySetting::whereNull('branch_id')->first();

        // Mặc định: T2-T7 làm, CN nghỉ
        $weekDays = $workdaySetting?->week_days ?? [
            'mon' => true, 'tue' => true, 'wed' => true,
            'thu' => true, 'fri' => true, 'sat' => true, 'sun' => false,
        ];

        // Map Carbon dayOfWeek (0=CN, 1=T2, ... 6=T7) sang key
        $dayMap = [0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat'];

        // Lấy danh sách ngày lễ trong kỳ (không tính công)
        $holidays = Holiday::where('status', 'active')
            ->whereBetween('holiday_date', [$from->toDateString(), $to->toDateString()])
            ->where(function ($q) {
                $q->where('paid_leave', false)->orWhereNull('paid_leave');
            })
            ->pluck('holiday_date')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->toArray();

        // Ngày lễ có paid_leave=true vẫn đếm vào công chuẩn (không trừ)

        $units = 0;
        $current = $from->copy();
        $endDate = $to->copy();

        while ($current->lte($endDate)) {
            $dayKey = $dayMap[$current->dayOfWeek] ?? 'sun';
            $isWorkday = !empty($weekDays[$dayKey]);
            $isUnpaidHoliday = in_array($current->toDateString(), $holidays, true);

            if ($isWorkday && !$isUnpaidHoliday) {
                $units += 1;
            }

            $current->addDay();
        }

        return (float) $units;
    }

    /**
     * Doanh thu cá nhân trong kỳ (từ đơn hàng / hóa đơn)
     */
    private function getPersonalRevenue(Employee $employee, Carbon $from, Carbon $to): float
    {
        $startDate = $from->copy()->startOfDay();
        $endDate = $to->copy()->endOfDay();

        $orderRevenue = Order::where('created_by', $employee->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('total_payment');

        $invoiceRevenue = Invoice::where('created_by', $employee->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('total');

        return (float) ($orderRevenue + $invoiceRevenue);
    }

    /**
     * Doanh thu chi nhánh trong kỳ
     */
    private function getBranchRevenue(Employee $employee, Carbon $from, Carbon $to): float
    {
        if (!$employee->branch_id) return 0;

        $startDate = $from->copy()->startOfDay();
        $endDate = $to->copy()->endOfDay();

        $orderRevenue = Order::where('branch_id', $employee->branch_id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('total_payment');

        $invoiceRevenue = Invoice::where('branch_id', $employee->branch_id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('total');

        return (float) ($orderRevenue + $invoiceRevenue);
    }

    /**
     * Lợi nhuận gộp cá nhân trong kỳ = Doanh thu - Giá vốn
     * Tính từ đơn hàng: SUM(order_items.subtotal) - SUM(order_items.qty * products.cost_price)
     */
    private function getPersonalGrossProfit(Employee $employee, Carbon $from, Carbon $to): float
    {
        $startDate = $from->copy()->startOfDay();
        $endDate = $to->copy()->endOfDay();

        $orders = Order::where('created_by', $employee->id)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->with('items.product:id,cost_price')
            ->get();

        $grossProfit = 0;
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $costPrice = $item->product?->cost_price ?? 0;
                $grossProfit += $item->subtotal - ($item->qty * $costPrice);
            }
        }

        return max(0, (float) $grossProfit);
    }

    private function emptyResult(): array
    {
        return [
            'base' => 0, 'base_salary_full' => 0, 'bonus' => 0, 'commission' => 0,
            'allowances' => 0, 'deductions' => 0, 'ot_minutes' => 0,
            'standard_work_units' => 0, 'work_units' => 0,
            'paid_leave_units' => 0, 'late_count' => 0, 'late_minutes' => 0,
            'early_leave_count' => 0, 'early_minutes' => 0,
            'personal_revenue' => 0, 'total' => 0, 'details' => [],
        ];
    }
}
