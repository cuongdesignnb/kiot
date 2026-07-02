<?php

namespace App\Observers;

use App\Models\Paysheet;
use App\Models\TimekeepingRecord;
use App\Models\EmployeeSalarySetting;
use App\Models\Holiday;
use App\Models\WorkdaySetting;
use App\Models\PayrollSetting;
use Illuminate\Support\Facades\Log;

/**
 * Observer đánh dấu paysheet cần tính lại khi dữ liệu liên quan thay đổi.
 * 
 * Cơ chế "Lazy Recalc": chỉ đánh dấu needs_recalc = true (1 query nhẹ),
 * KHÔNG tính lại ngay → tính lại khi user mở bảng lương.
 */
class PayrollDataObserver
{
    // ===== TimekeepingRecord =====

    public function timekeepingCreated(TimekeepingRecord $record): void
    {
        $this->markPaysheetsByEmployee($record->employee_id, $record->work_date);
    }

    public function timekeepingUpdated(TimekeepingRecord $record): void
    {
        // Chỉ mark nếu giá trị quan trọng thay đổi
        $importantFields = ['work_units', 'worked_minutes', 'ot_minutes', 'late_minutes',
                           'early_minutes', 'check_in_at', 'check_out_at', 'attendance_type',
                           'is_holiday', 'holiday_multiplier'];

        if ($record->wasChanged($importantFields)) {
            $this->markPaysheetsByEmployee($record->employee_id, $record->work_date);
        }
    }

    public function timekeepingDeleted(TimekeepingRecord $record): void
    {
        $this->markPaysheetsByEmployee($record->employee_id, $record->work_date);
    }

    // ===== EmployeeSalarySetting =====

    public function salarySettingUpdated(EmployeeSalarySetting $setting): void
    {
        $this->markPaysheetsByEmployee($setting->employee_id);
    }

    // ===== Holiday =====

    public function holidayChanged(Holiday $holiday): void
    {
        // Ảnh hưởng tất cả paysheet có kỳ trùng ngày lễ
        $date = $holiday->holiday_date;
        Paysheet::whereNotIn('status', ['locked', 'cancelled'])
            ->where('period_start', '<=', $date)
            ->where('period_end', '>=', $date)
            ->where('needs_recalc', false)
            ->update(['needs_recalc' => true]);
    }

    // ===== WorkdaySetting =====

    public function workdaySettingUpdated(WorkdaySetting $setting): void
    {
        // Ảnh hưởng tất cả paysheet thuộc branch
        $query = Paysheet::whereNotIn('status', ['locked', 'cancelled'])
            ->where('needs_recalc', false);

        if ($setting->branch_id) {
            $query->where('branch_id', $setting->branch_id);
        }

        $query->update(['needs_recalc' => true]);
    }

    // ===== PayrollSetting =====

    public function payrollSettingUpdated(PayrollSetting $setting): void
    {
        $query = Paysheet::whereNotIn('status', ['locked', 'cancelled'])
            ->where('needs_recalc', false);

        if ($setting->branch_id) {
            $query->where('branch_id', $setting->branch_id);
        }

        $query->update(['needs_recalc' => true]);
    }

    // ===== PRIVATE HELPERS =====

    /**
     * Đánh dấu paysheet chứa employee_id cần tính lại.
     * Nếu có work_date, chỉ mark paysheet có kỳ trùng ngày đó.
     */
    private function markPaysheetsByEmployee(int $employeeId, $workDate = null): void
    {
        $query = Paysheet::whereNotIn('status', ['locked', 'cancelled'])
            ->where('needs_recalc', false)
            ->whereHas('payslips', fn($q) => $q->where('employee_id', $employeeId));

        if ($workDate) {
            $query->where('period_start', '<=', $workDate)
                  ->where('period_end', '>=', $workDate);
        }

        $count = $query->update(['needs_recalc' => true]);

        if ($count > 0) {
            Log::debug("PayrollDataObserver: marked {$count} paysheet(s) needs_recalc for employee #{$employeeId}");
        }
    }
}
