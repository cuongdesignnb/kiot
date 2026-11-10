<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Payroll consumes recorded units; attendance owns minute-to-unit conversion. */
class PayrollConfirmedAttendance
{
    public function summarize(Collection $records, array $holidays, float $restRate, float $holidayRate, string $salaryType = 'by_workday', bool $hasDayBasedExtras = false): array
    {
        $normal = $weighted = $leave = 0.0;
        $issues = [];
        $days = [];
        // Slots awaiting review are listed once per payslip, not one line each.
        $reviewSlots = $incompleteSlots = [];
        foreach ($records->groupBy(fn ($r) => Carbon::parse($r->work_date)->toDateString()) as $date => $rows) {
            $work = $rows->where('attendance_type', 'work');
            $units = (float) $work->sum('work_units');
            $paidLeave = (float) $rows->where('attendance_type', 'leave_paid')->sum('work_units');
            if ($work->contains(fn ($r) => (int) $r->worked_minutes < 0 || (int) $r->ot_minutes < 0
                || ($r->regular_minutes !== null && ((int) $r->regular_minutes < 0 || (int) $r->regular_minutes > (int) $r->worked_minutes)))
                || $work->contains(fn ($r) => (int) $r->ot_minutes + (int) ($r->regular_minutes ?? max(0, (int) $r->worked_minutes - (int) $r->ot_minutes)) > (int) $r->worked_minutes)
                || $work->pluck('is_holiday')->unique()->count() > 1) {
                $issues[] = "Dữ liệu giờ làm hoặc loại ngày {$date} mâu thuẫn giữa các ca.";
            }
            foreach ($rows->filter(fn ($r) => $r->needs_review) as $row) {
                $reviewSlots[] = $this->slotLabel($date, $row);
            }
            if ($salaryType === 'hourly') {
                foreach ($work as $row) {
                    // A zero-hour, zero-unit slot without punches adds no pay.
                    // Do not infer absence or require punches merely for a scheduled slot.
                    // needs_review is still enforced above, including manual empty work.
                    if (! $row->needs_review && ((bool) $row->check_in_at !== (bool) $row->check_out_at
                        || (! $row->check_in_at && ! $row->check_out_at && (int) $row->worked_minutes === 0 && (float) $row->work_units != 0))) {
                        $incompleteSlots[] = $this->slotLabel($date, $row);
                    }
                }
            }
            if (($salaryType !== 'hourly' || $hasDayBasedExtras) && ($rows->contains(fn ($r) => $r->work_units === null || (float) $r->work_units < 0)
                || $units + $paidLeave > 1.00001)) {
                $issues[] = "Số công ngày {$date} thiếu hoặc vượt một công; kiểm tra các ca trùng.";
            }
            $previousEnd = null;
            foreach ($work->filter(fn ($r) => $r->check_in_at && $r->check_out_at)->sortBy('check_in_at') as $row) {
                if (Carbon::parse($row->check_out_at)->lte(Carbon::parse($row->check_in_at))) {
                    $issues[] = "Giờ ra phải sau giờ vào ngày {$date}, ca ".($row->slot ?? 1).'.';
                }
                if ($previousEnd && Carbon::parse($row->check_in_at)->lt($previousEnd)) {
                    $issues[] = "Khoảng làm việc ngày {$date} bị trùng; cần xác nhận giờ thường và tăng ca.";
                }
                $end = Carbon::parse($row->check_out_at);
                $previousEnd = $previousEnd && $previousEnd->gt($end) ? $previousEnd : $end;
            }
            $rate = in_array($date, $holidays, true) ? $holidayRate : ($work->contains(fn ($r) => $r->is_holiday) ? $restRate : 1);
            $normal += $units;
            $weighted += $units * $rate;
            $leave += $paidLeave;
            $days[] = ['date' => $date, 'work_units' => $units, 'paid_leave_units' => $paidLeave, 'multiplier' => $rate];
        }

        $summary = [];
        if ($reviewSlots) {
            $summary[] = count($reviewSlots).' ca chấm công cần kiểm tra: '.implode(', ', $reviewSlots).'. Vào Chấm công từng ngày: nghỉ thì chọn loại nghỉ, đi làm thì nhập giờ vào/ra.';
        }
        if ($incompleteSlots) {
            $summary[] = count($incompleteSlots).' ca đi làm chưa đủ giờ vào/ra: '.implode(', ', $incompleteSlots).'. Nếu nghỉ, chọn loại nghỉ; không tự coi thiếu chấm công là nghỉ.';
        }

        return ['normal' => $normal, 'weighted' => $weighted, 'leave' => $leave, 'days' => $days, 'issues' => array_values(array_unique(array_merge($summary, $issues)))];
    }

    private function slotLabel(string $date, $row): string
    {
        $slot = (int) ($row->slot ?? 1);

        return Carbon::parse($date)->format('d/m').($slot > 1 ? " (ca {$slot})" : '');
    }
}
