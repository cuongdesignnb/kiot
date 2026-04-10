<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\Holiday;
use App\Models\SalaryTemplate;
use App\Models\CommissionTable;
use App\Models\Order;
use App\Models\Invoice;
use App\Models\WorkdaySetting;
use App\Models\Setting;
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

        // Xây dựng dữ liệu thưởng/hoa hồng/phụ cấp/giảm trừ: ưu tiên per-employee, fallback template
        $hasBonus = $setting->has_bonus ?? ($template->has_bonus ?? false);
        $hasCommission = $setting->has_commission ?? ($template->has_commission ?? false);
        $hasAllowance = $setting->has_allowance ?? ($template->has_allowance ?? false);
        $hasDeduction = $setting->has_deduction ?? ($template->has_deduction ?? false);
        $bonusType = $setting->bonus_type ?? ($template->bonus_type ?? 'personal_revenue');
        $bonusCalculation = $setting->bonus_calculation ?? ($template->bonus_calculation ?? 'total_revenue');
        $bonusList = !empty($setting->custom_bonuses) ? collect($setting->custom_bonuses) : ($template ? $template->bonuses : collect());
        $commissionList = !empty($setting->custom_commissions) ? collect($setting->custom_commissions) : ($template ? $template->commissions : collect());
        $allowanceList = !empty($setting->custom_allowances) ? collect($setting->custom_allowances) : ($template ? $template->allowances : collect());
        $deductionList = !empty($setting->custom_deductions) ? collect($setting->custom_deductions) : ($template ? $template->deductions : collect());

        // Tính ngày công chuẩn theo lịch thực tế (WorkdaySetting + Holiday)
        $standardWorkUnits = $this->getStandardWorkUnits($employee->branch_id, $from, $to);

        // Lấy dữ liệu chấm công
        $records = $employee->timekeepingRecords()
            ->whereBetween('work_date', [$from, $to])
            ->get();

        // Lấy hệ số nhân từ CÀI ĐẶT LƯƠNG NV (không từ timekeeping record)
        $restDayMultiplier = ($setting->holiday_rate ?? 200) / 100; // VD: 200% = 2.0
        $holidayMultiplier = ($setting->tet_rate ?? 300) / 100;     // VD: 300% = 3.0

        // Lấy danh sách ngày lễ chính thức để phân biệt CN vs Lễ
        $officialHolidayDates = Holiday::whereBetween('holiday_date', [$from, $to])
            ->where('status', 'active')
            ->pluck('holiday_date')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->toArray();

        // Tính ngày công: áp dụng hệ số nhân cho ngày nghỉ/lễ
        // VD: CN (holiday_rate=200%) → 1 ngày = 2 units, Lễ (tet_rate=300%) → 1 ngày = 3 units
        $workUnits = 0;
        $normalWorkUnits = 0; // Ngày công thật (không nhân hệ số, dùng cho hiển thị)
        foreach ($records->where('attendance_type', 'work') as $rec) {
            $units = (float) $rec->work_units;
            $multiplier = 1;
            if ($rec->is_holiday && $units > 0) {
                $dateStr = Carbon::parse($rec->work_date)->toDateString();
                $isOfficialHoliday = in_array($dateStr, $officialHolidayDates);
                $multiplier = $isOfficialHoliday ? $holidayMultiplier : $restDayMultiplier;
            }
            $workUnits += $units * $multiplier;
            $normalWorkUnits += $units;
        }
        $paidLeaveUnits = $records->where('attendance_type', 'leave_paid')->sum('work_units');
        $totalUnits = $workUnits + $paidLeaveUnits;
        $otMinutes = $records->sum('ot_minutes');
        $lateCount = $records->where('late_minutes', '>', 0)->count();
        $lateTotalMinutes = $records->sum('late_minutes');
        $earlyLeaveCount = $records->where('early_minutes', '>', 0)->count();
        $earlyTotalMinutes = $records->sum('early_minutes');

        // Tổng phút làm thực tế (dùng cho lương giờ)
        $totalWorkedMinutes = $records->where('attendance_type', 'work')->sum('worked_minutes');

        // Tính lương cơ bản theo loại lương
        $baseSalary = $setting->base_salary;
        if ($setting->salary_type === 'hourly') {
            // Lương theo giờ: tổng giờ làm thực tế × đơn giá giờ
            $totalWorkedHours = $totalWorkedMinutes / 60;
            $baseSalary = $totalWorkedHours * $setting->base_salary;
        } elseif ($setting->salary_type === 'by_workday') {
            // Theo ngày công chuẩn: tính theo tỷ lệ ngày công thực tế / ngày công chuẩn
            // VD: lương 10tr, công chuẩn 26, đi 20 → 10tr × 20/26 = 7.69tr
            if ($standardWorkUnits > 0) {
                $baseSalary = $setting->base_salary * $totalUnits / $standardWorkUnits;
            }
        }
        // salary_type === 'fixed': giữ nguyên base_salary, không chia theo ngày công

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

        // ===== THƯỞNG =====
        if ($hasBonus && $bonusList->count()) {
            if ($bonusType === 'personal_gross_profit') {
                $personalGrossProfit = $personalGrossProfit ?? $this->getPersonalGrossProfit($employee, $from, $to);
                $revenue = $personalGrossProfit;
            } elseif ($bonusType === 'branch_revenue') {
                $revenue = $branchRevenue;
            } else {
                $revenue = $personalRevenue;
            }
            $result = $this->calculateBonusFromList($bonusList, $bonusCalculation, $revenue);
            $bonusAmount = $result['amount'];
            $bonusDetails = $result['details'];
        }

        // ===== HOA HỒNG =====
        if ($hasCommission && $commissionList->count()) {
            $result = $this->calculateCommissionFromList($commissionList, $personalRevenue);
            $commissionAmount = $result['amount'];
            $commissionDetails = $result['details'];
        }

        // ===== PHỤ CẤP =====
        if ($hasAllowance && $allowanceList->count()) {
            $result = $this->calculateAllowancesFromList($allowanceList, $baseSalary, $totalUnits);
            $allowanceAmount = $result['amount'];
            $allowanceDetails = $result['details'];
        }

        // ===== GIẢM TRỪ =====
        if ($hasDeduction && $deductionList->count()) {
            $result = $this->calculateDeductionsFromList(
                $deductionList, $lateCount, $lateTotalMinutes,
                $earlyLeaveCount, $earlyTotalMinutes, $records
            );
            $deductionAmount = $result['amount'];
            $deductionDetails = $result['details'];
        }

        // ===== GIẢM TRỪ ĐI MUỘN THEO MỨC (PayrollSetting) =====
        $latePenaltyAmount = 0;
        $latePenaltyDetails = [];
        $payrollSetting = \App\Models\PayrollSetting::first();
        if ($payrollSetting && $payrollSetting->late_penalty_enabled) {
            $tiers = collect($payrollSetting->late_penalty_tiers ?? [])->sortByDesc('minutes')->values();
            if ($tiers->isNotEmpty()) {
                foreach ($records->where('late_minutes', '>', 0) as $rec) {
                    $mins = (int) $rec->late_minutes;
                    $matched = $tiers->first(fn($t) => $mins >= (int) $t['minutes']);
                    if ($matched) {
                        $tierMinutes = (int) $matched['minutes'];
                        $tierAmount = (float) $matched['amount'];
                        // Block-based: mỗi khối X phút phạt Y đồng
                        // VD: tier 10 phút / 10.000đ → 25 phút = floor(25/10)*10000 = 20.000
                        $penalty = floor($mins / $tierMinutes) * $tierAmount;
                        $latePenaltyAmount += $penalty;
                        $latePenaltyDetails[] = [
                            'date' => $rec->work_date,
                            'late_minutes' => $mins,
                            'tier_minutes' => $tierMinutes,
                            'penalty' => $penalty,
                        ];
                    }
                }
            }
        }
        $deductionAmount += $latePenaltyAmount;

        // ===== TÍNH TIỀN TĂNG CA (OT PAY) =====
        // Logic: Ngày nghỉ/lễ → lương đã tính qua work_units × multiplier ở lương chính
        //        OT = chỉ giờ vượt ca trên mọi loại ngày
        $otPay = 0;
        $otBreakdown = [];

        // Tính giờ ca chuẩn từ CA LÀM VIỆC (Shift model) — không auto-detect từ records
        // VD: ca 8:30-18:30 → duration_minutes = 600 → 10h
        $standardHoursPerDay = 8; // fallback
        $employeeShift = EmployeeWorkSchedule::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from, $to])
            ->whereNotNull('shift_id')
            ->first();
        if ($employeeShift?->shift) {
            $shiftDuration = $employeeShift->shift->duration_minutes;
            if ($shiftDuration > 0) {
                $standardHoursPerDay = round($shiftDuration / 60, 2); // 600/60 = 10h
            }
        }
        if ($otMinutes > 0 && ($setting->has_overtime ?? false)) {
            // Hệ số OT từ cài đặt lương NV (dùng chung cho mọi loại ngày)
            // Vì hệ số ngày nghỉ/lễ đã nhân vào work_units ở bước tính lương chính
            $overtimeRate = ($setting->overtime_rate ?? 150) / 100; // VD: 150% = 1.5x

            // Lương theo ngày
            $dailyWage = ($standardWorkUnits > 0) ? $setting->base_salary / $standardWorkUnits : 0;

            // Lương theo giờ (dùng cho OT ngày thường/T7 và lương giờ)
            if ($setting->salary_type === 'hourly') {
                $hourlyRate = $setting->base_salary;
            } else {
                $hourlyRate = ($standardWorkUnits > 0)
                    ? $setting->base_salary / $standardWorkUnits / $standardHoursPerDay
                    : 0;
            }

            // Lấy danh sách ngày lễ chính thức
            $officialHolidayDates = Holiday::whereBetween('holiday_date', [$from, $to])
                ->where('status', 'active')
                ->pluck('holiday_date')
                ->map(fn($d) => Carbon::parse($d)->toDateString())
                ->toArray();

            $typeLabels = [
                'weekday' => 'Ngày thường',
                'saturday' => 'Thứ 7',
                'rest_day' => 'Ngày nghỉ',
                'holiday' => 'Ngày lễ tết',
            ];

            if ($setting->salary_type === 'hourly') {
                // ======= LƯƠNG GIỜ: tính OT theo giờ cho mọi loại ngày =======
                $otByType = ['weekday' => 0, 'saturday' => 0, 'rest_day' => 0, 'holiday' => 0];
                foreach ($records->where('ot_minutes', '>', 0) as $rec) {
                    $dateStr = Carbon::parse($rec->work_date)->toDateString();
                    $dayOfWeek = Carbon::parse($rec->work_date)->dayOfWeek;
                    $mins = (int) $rec->ot_minutes;

                    if (in_array($dateStr, $officialHolidayDates)) {
                        $otByType['holiday'] += $mins;
                    } elseif ($rec->is_holiday || $dayOfWeek === 0) {
                        $otByType['rest_day'] += $mins;
                    } elseif ($dayOfWeek === 6) {
                        $otByType['saturday'] += $mins;
                    } else {
                        $otByType['weekday'] += $mins;
                    }
                }

                $typeRates = [
                    'weekday' => $overtimeRate,
                    'saturday' => $overtimeRate,
                    'rest_day' => $restDayOtRate,
                    'holiday' => $tetOtRate,
                ];

                foreach ($otByType as $type => $mins) {
                    if ($mins <= 0) continue;
                    $rate = $typeRates[$type];
                    $hours = round($mins / 60, 2);
                    $amount = round($hours * $hourlyRate * $rate);
                    $otPay += $amount;
                    $otBreakdown[] = [
                        'type' => $type,
                        'label' => $typeLabels[$type],
                        'rate_percent' => round($rate * 100),
                        'hourly_rate' => round($hourlyRate),
                        'hours' => $hours,
                        'minutes' => $mins,
                        'amount' => $amount,
                    ];
                }
            } else {
                // ======= LƯƠNG CỐ ĐỊNH / NGÀY CÔNG: ngày nghỉ → tính theo ngày công × hệ số =======
                // Bước 1: Tính OT ngày thường/T7 (vượt giờ ca) — vẫn theo giờ
                $weekdayOtMins = 0;
                $saturdayOtMins = 0;
                foreach ($records->where('ot_minutes', '>', 0) as $rec) {
                    $dateStr = Carbon::parse($rec->work_date)->toDateString();
                    $dayOfWeek = Carbon::parse($rec->work_date)->dayOfWeek;
                    if (in_array($dateStr, $officialHolidayDates) || $rec->is_holiday || $dayOfWeek === 0) {
                        continue; // Ngày nghỉ/lễ xử lý riêng bên dưới
                    }
                    $mins = (int) $rec->ot_minutes;
                    if ($dayOfWeek === 6) {
                        $saturdayOtMins += $mins;
                    } else {
                        $weekdayOtMins += $mins;
                    }
                }

                // OT ngày thường (vượt giờ ca)
                if ($weekdayOtMins > 0) {
                    $hours = round($weekdayOtMins / 60, 2);
                    $amount = round($hours * $hourlyRate * $overtimeRate);
                    $otPay += $amount;
                    $otBreakdown[] = [
                        'type' => 'weekday',
                        'label' => 'Ngày thường',
                        'rate_percent' => round($overtimeRate * 100),
                        'hourly_rate' => round($hourlyRate),
                        'hours' => $hours,
                        'minutes' => $weekdayOtMins,
                        'amount' => $amount,
                    ];
                }

                // OT thứ 7 (vượt giờ ca)
                if ($saturdayOtMins > 0) {
                    $hours = round($saturdayOtMins / 60, 2);
                    $amount = round($hours * $hourlyRate * $overtimeRate);
                    $otPay += $amount;
                    $otBreakdown[] = [
                        'type' => 'saturday',
                        'label' => 'Thứ 7',
                        'rate_percent' => round($overtimeRate * 100),
                        'hourly_rate' => round($hourlyRate),
                        'hours' => $hours,
                        'minutes' => $saturdayOtMins,
                        'amount' => $amount,
                    ];
                }

                // Bước 2: OT giờ vượt ca trên ngày nghỉ/lễ
                // Lương ngày nghỉ đã tính qua work_units × multiplier ở lương chính
                // Ở đây chỉ tính phần giờ VƯỢT ca chuẩn (VD: làm 10h, ca 8h → 2h OT)
                $restDayOtMins = 0;
                $holidayOtMins = 0;

                foreach ($records->where('is_holiday', true)->where('ot_minutes', '>', 0) as $rec) {
                    $dateStr = Carbon::parse($rec->work_date)->toDateString();
                    $isOfficialHoliday = in_array($dateStr, $officialHolidayDates);
                    $mins = (int) $rec->ot_minutes;

                    if ($isOfficialHoliday) {
                        $holidayOtMins += $mins;
                    } else {
                        $restDayOtMins += $mins;
                    }
                }

                if ($restDayOtMins > 0) {
                    $hours = round($restDayOtMins / 60, 2);
                    // Dùng overtimeRate (150%) — vì hệ số ngày nghỉ (2x) đã tính trong lương chính
                    $amount = round($hours * $hourlyRate * $overtimeRate);
                    $otPay += $amount;
                    $otBreakdown[] = [
                        'type' => 'rest_day',
                        'label' => 'Vượt ca ngày nghỉ',
                        'rate_percent' => round($overtimeRate * 100),
                        'hourly_rate' => round($hourlyRate),
                        'hours' => $hours,
                        'minutes' => $restDayOtMins,
                        'amount' => $amount,
                    ];
                }

                if ($holidayOtMins > 0) {
                    $hours = round($holidayOtMins / 60, 2);
                    // Dùng overtimeRate (150%) — vì hệ số lễ tết (3x) đã tính trong lương chính
                    $amount = round($hours * $hourlyRate * $overtimeRate);
                    $otPay += $amount;
                    $otBreakdown[] = [
                        'type' => 'holiday',
                        'label' => 'Vượt ca ngày lễ tết',
                        'rate_percent' => round($overtimeRate * 100),
                        'hourly_rate' => round($hourlyRate),
                        'hours' => $hours,
                        'minutes' => $holidayOtMins,
                        'amount' => $amount,
                    ];
                }
            }
        }

        // ===== PHẦN CHÊNH LỆCH NGÀY NGHỈ + NGÀY LỄ =====
        // Không cần tính riêng nữa vì hệ số nhân (2x CN, 3x Lễ) đã được áp dụng
        // trực tiếp vào work_units ở bước tính lương chính.
        // VD: 1 ngày CN = 2 work_units → lương chính đã bao gồm phần chênh lệch.
        $holidayPay = 0;
        $holidayPayDetails = [];

        $totalSalary = $baseSalary + $bonusAmount + $commissionAmount + $allowanceAmount + $otPay + $holidayPay - $deductionAmount;

        // ===== ĐÁNH GIÁ NĂNG SUẤT SỬA CHỮA (chỉ khi module bật) =====
        $repairPerformance = null;
        if (Setting::get('repair_performance_salary_enabled', false) && class_exists(RepairService::class)) {
            $repairService = new RepairService();
            $repairPerformance = $repairService->getEmployeePerformance($employee->id, $from->toDateString(), $to->toDateString());
            if ($repairPerformance['assigned'] > 0) {
                $factor = $repairPerformance['salary_percent'] / 100;
                $baseSalary = $baseSalary * $factor;
                $totalSalary = $baseSalary + $bonusAmount + $commissionAmount + $allowanceAmount + $otPay + $holidayPay - $deductionAmount;
            }
        }

        return [
            'base' => round($baseSalary),
            'base_salary_full' => round($setting->base_salary),
            'bonus' => round($bonusAmount),
            'commission' => round($commissionAmount),
            'allowances' => round($allowanceAmount),
            'deductions' => round($deductionAmount),
            'ot_pay' => round($otPay),
            'holiday_pay' => round($holidayPay),
            'ot_minutes' => $otMinutes,
            'standard_work_units' => $standardWorkUnits,
            'work_units' => $totalUnits,
            'normal_work_units' => $normalWorkUnits, // Ngày công thật (không nhân hệ số)
            'total_worked_minutes' => $totalWorkedMinutes,
            'paid_leave_units' => $paidLeaveUnits,
            'late_count' => $lateCount,
            'late_minutes' => $lateTotalMinutes,
            'early_leave_count' => $earlyLeaveCount,
            'early_minutes' => $earlyTotalMinutes,
            'personal_revenue' => $personalRevenue,
            'repair_performance' => $repairPerformance,
            'total' => round(max(0, $totalSalary)),
            'late_penalty' => round($latePenaltyAmount),
            'details' => [
                'bonus' => $bonusDetails,
                'commission' => $commissionDetails,
                'allowances' => $allowanceDetails,
                'deductions' => $deductionDetails,
                'late_penalty' => $latePenaltyDetails,
                'holiday_pay' => $holidayPayDetails,
                'ot_breakdown' => $otBreakdown,
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
     * Tính thưởng từ danh sách (hỗ trợ cả Eloquent Collection và JSON array)
     */
    private function calculateBonusFromList(Collection $bonusList, string $bonusCalculation, float $revenue): array
    {
        $amount = 0;
        $details = [];

        if ($bonusCalculation === 'total_revenue') {
            $matchedTier = $bonusList
                ->filter(fn($b) => data_get($b, 'revenue_from') <= $revenue)
                ->sortByDesc(fn($b) => data_get($b, 'revenue_from'))
                ->first();

            if ($matchedTier) {
                $isPct = data_get($matchedTier, 'bonus_is_percentage');
                $val = data_get($matchedTier, 'bonus_value');
                $tierAmount = $isPct ? $revenue * $val / 100 : $val;
                $amount = $tierAmount;
                $details[] = [
                    'role_type' => data_get($matchedTier, 'role_type'),
                    'revenue_from' => data_get($matchedTier, 'revenue_from'),
                    'bonus_value' => $val,
                    'is_percentage' => $isPct,
                    'calculated' => round($tierAmount),
                ];
            }
        } else {
            $sortedTiers = $bonusList->sortBy(fn($b) => data_get($b, 'revenue_from'))->values();
            $remainingRevenue = $revenue;

            for ($i = 0; $i < $sortedTiers->count(); $i++) {
                $tier = $sortedTiers[$i];
                $from = data_get($tier, 'revenue_from');
                if ($revenue < $from) break;

                $nextThreshold = ($i + 1 < $sortedTiers->count()) ? data_get($sortedTiers[$i + 1], 'revenue_from') : $revenue;
                $tierRevenue = min($remainingRevenue, $nextThreshold - $from);

                if ($tierRevenue > 0) {
                    $isPct = data_get($tier, 'bonus_is_percentage');
                    $val = data_get($tier, 'bonus_value');
                    $tierAmount = $isPct ? $tierRevenue * $val / 100 : $val;
                    $amount += $tierAmount;
                    $details[] = [
                        'role_type' => data_get($tier, 'role_type'),
                        'revenue_from' => $from,
                        'tier_revenue' => round($tierRevenue),
                        'bonus_value' => $val,
                        'is_percentage' => $isPct,
                        'calculated' => round($tierAmount),
                    ];
                    $remainingRevenue -= $tierRevenue;
                }
            }
        }

        return ['amount' => $amount, 'details' => $details];
    }

    /**
     * Tính hoa hồng từ danh sách
     */
    private function calculateCommissionFromList(Collection $commissionList, float $revenue): array
    {
        $amount = 0;
        $details = [];

        foreach ($commissionList as $commission) {
            $revenueFrom = data_get($commission, 'revenue_from', 0);
            if ($revenue < $revenueFrom) continue;

            $tableId = data_get($commission, 'commission_table_id');
            if ($tableId) {
                $table = CommissionTable::with('tiers')->find($tableId);
                if ($table) {
                    $matchedTier = $table->tiers->sortByDesc('revenue_from')
                        ->where('revenue_from', '<=', $revenue)->first();
                    if ($matchedTier) {
                        $tierAmount = $matchedTier->is_percentage
                            ? $revenue * $matchedTier->commission_value / 100
                            : $matchedTier->commission_value;
                        $amount += $tierAmount;
                        $details[] = [
                            'role_type' => data_get($commission, 'role_type'),
                            'table_name' => $table->name,
                            'tier_revenue_from' => $matchedTier->revenue_from,
                            'commission_value' => $matchedTier->commission_value,
                            'is_percentage' => $matchedTier->is_percentage,
                            'calculated' => round($tierAmount),
                        ];
                    }
                }
            } else {
                $isPct = data_get($commission, 'commission_is_percentage');
                $val = data_get($commission, 'commission_value', 0);
                $tierAmount = $isPct ? $revenue * $val / 100 : $val;
                $amount += $tierAmount;
                $details[] = [
                    'role_type' => data_get($commission, 'role_type'),
                    'commission_value' => $val,
                    'is_percentage' => $isPct,
                    'calculated' => round($tierAmount),
                ];
            }
        }

        return ['amount' => $amount, 'details' => $details];
    }

    /**
     * Tính phụ cấp từ danh sách
     */
    private function calculateAllowancesFromList(Collection $allowanceList, float $baseSalary, float $workUnits): array
    {
        $amount = 0;
        $details = [];

        foreach ($allowanceList as $allowance) {
            $alAmount = 0;
            $type = data_get($allowance, 'allowance_type');
            $amt = data_get($allowance, 'amount', 0);

            switch ($type) {
                case 'fixed_per_day':
                    $alAmount = $amt * $workUnits;
                    break;
                case 'fixed_per_month':
                    $alAmount = $amt;
                    break;
                case 'percentage':
                    $alAmount = $baseSalary * $amt / 100;
                    break;
            }
            $amount += $alAmount;
            $details[] = [
                'name' => data_get($allowance, 'name'),
                'type' => $type,
                'config_amount' => $amt,
                'calculated' => round($alAmount),
            ];
        }

        return ['amount' => $amount, 'details' => $details];
    }

    /**
     * Tính giảm trừ từ danh sách
     */
    private function calculateDeductionsFromList(
        Collection $deductionList,
        int $lateCount, int $lateTotalMinutes,
        int $earlyLeaveCount, int $earlyTotalMinutes,
        Collection $records = null
    ): array {
        $amount = 0;
        $details = [];

        foreach ($deductionList as $deduction) {
            $dedAmount = 0;
            $occurrences = 0;
            $category = data_get($deduction, 'deduction_category');
            $calcType = data_get($deduction, 'calculation_type');
            $amt = data_get($deduction, 'amount', 0);
            $perMinutes = data_get($deduction, 'per_minutes');

            // Per-employee custom deductions: chỉ có name + amount (cố định/tháng)
            // Template deductions: có đầy đủ category + calc_type
            if (!$category) {
                // Simple fixed deduction (per-employee)
                $dedAmount = $amt;
            } else {
                // Template-based deduction with attendance logic
                switch ($category) {
                    case 'late':
                        $occurrences = $lateCount;
                        if ($calcType === 'per_occurrence') {
                            $dedAmount = $amt * $lateCount;
                        } elseif ($calcType === 'per_minute') {
                            // Block-based: mỗi khối X phút phạt Y đồng, tính theo từng ngày
                            $blockSize = $perMinutes && $perMinutes > 0 ? (int) $perMinutes : 1;
                            if ($records) {
                                foreach ($records->where('late_minutes', '>', 0) as $rec) {
                                    $mins = (int) $rec->late_minutes;
                                    $dedAmount += floor($mins / $blockSize) * $amt;
                                }
                            } else {
                                $dedAmount = floor($lateTotalMinutes / $blockSize) * $amt;
                            }
                        } else {
                            $dedAmount = $amt;
                        }
                        break;
                    case 'early_leave':
                        $occurrences = $earlyLeaveCount;
                        if ($calcType === 'per_occurrence') {
                            $dedAmount = $amt * $earlyLeaveCount;
                        } elseif ($calcType === 'per_minute') {
                            $blockSize = $perMinutes && $perMinutes > 0 ? (int) $perMinutes : 1;
                            if ($records) {
                                foreach ($records->where('early_minutes', '>', 0) as $rec) {
                                    $mins = (int) $rec->early_minutes;
                                    $dedAmount += floor($mins / $blockSize) * $amt;
                                }
                            } else {
                                $dedAmount = floor($earlyTotalMinutes / $blockSize) * $amt;
                            }
                        } else {
                            $dedAmount = $amt;
                        }
                        break;
                    case 'absence':
                    case 'violation':
                        $dedAmount = $amt;
                        break;
                }
            }

            // Tổng số phút (cho per_minute calc_type)
            $totalMinutes = 0;
            if ($calcType === 'per_minute') {
                if ($category === 'late') $totalMinutes = $lateTotalMinutes;
                elseif ($category === 'early_leave') $totalMinutes = $earlyTotalMinutes;
            }

            $amount += $dedAmount;
            $details[] = [
                'name' => data_get($deduction, 'name'),
                'category' => $category ?? 'fixed',
                'calc_type' => $calcType ?? 'fixed_per_month',
                'config_amount' => $amt,
                'per_minutes' => $perMinutes,
                'occurrences' => $occurrences,
                'total_minutes' => $totalMinutes,
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
            'allowances' => 0, 'deductions' => 0, 'ot_pay' => 0, 'holiday_pay' => 0,
            'ot_minutes' => 0, 'standard_work_units' => 0, 'work_units' => 0,
            'paid_leave_units' => 0, 'late_count' => 0, 'late_minutes' => 0,
            'early_leave_count' => 0, 'early_minutes' => 0,
            'personal_revenue' => 0, 'total' => 0, 'late_penalty' => 0, 'details' => [],
        ];
    }
}
