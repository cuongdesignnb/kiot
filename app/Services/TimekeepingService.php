<?php

namespace App\Services;

use App\Models\TimekeepingRecord;
use App\Models\EmployeeWorkSchedule;
use App\Models\AttendanceLog;
use App\Models\Shift;
use App\Models\TimekeepingSetting;
use App\Models\Holiday;
use App\Models\Setting;
use App\Models\WorkdaySetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TimekeepingService
{
    public function recalculateForRange(Carbon $from, Carbon $to, ?int $employeeId = null): array
    {
        // 1. Dữ liệu tham chiếu
        $holidayMap = Holiday::whereBetween('holiday_date', [$from, $to])
            ->where('status', 'active')
            ->get()->keyBy(fn($h) => \Carbon\Carbon::parse($h->holiday_date)->toDateString());

        // Lấy danh sách ngày làm việc trong tuần (VD: [1,2,3,4,5,6] = T2→T7)
        // Ngày KHÔNG nằm trong danh sách = ngày nghỉ tuần (VD: CN = 0)
        $workdaySettings = WorkdaySetting::all();

        $schedules = EmployeeWorkSchedule::whereBetween('work_date', [$from, $to])
            ->when($employeeId, fn($q) => $q->where('employee_id', $employeeId))
            ->orderBy('work_date')->orderBy('slot')
            ->get();

        $shifts = Shift::whereIn('id', $schedules->pluck('shift_id')->filter())->get()->keyBy('id');
        $settings = TimekeepingSetting::where('status', 'active')->get()
            ->keyBy(fn($s) => $s->branch_id ?? 'global');
        $globalSetting = $settings->all()['global'] ?? null;
        $halfWorkEnabled = (bool) Setting::get('attendance_half_work_enabled', true);
        $halfWorkMaxMinutes = (int) Setting::get('attendance_half_work_max_minutes', 480);
        $halfWorkMinMinutes = (int) Setting::get('attendance_half_work_min_minutes', 0);
        $payrollSetting = \App\Models\PayrollSetting::first();
        $lateHalfDayEnabled = (bool) ($payrollSetting->late_half_day_enabled ?? false);
        $lateHalfDayThreshold = (int) ($payrollSetting->late_half_day_threshold ?? 120);
        $overtimeBeforeEnabled = (bool) Setting::get('attendance_overtime_before_enabled', true);
        $overtimeBeforeMinutes = (int) Setting::get('attendance_overtime_before_minutes', 0);

        $created = 0;
        $updated = 0;

        foreach ($schedules as $schedule) {

            // Skip bản ghi đã chỉnh tay
            $existing = TimekeepingRecord::where('employee_work_schedule_id', $schedule->id)->first();
            if ($existing && $existing->manual_override)
                continue;

            $shift = $schedule->shift_id ? ($shifts->all()[$schedule->shift_id] ?? null) : null;
            $setting = $settings->all()[(string) $schedule->branch_id] ?? $globalSetting;

            // Xác định thời gian ca
            $scheduleStart = $this->buildScheduleDateTime(
                $schedule->work_date,
                $schedule->start_time,
                $shift?->start_time
            );
            $scheduleEnd = $this->buildScheduleDateTime(
                $schedule->work_date,
                $schedule->end_time,
                $shift?->end_time
            );

            // Ca đêm
            if ($scheduleStart && $scheduleEnd && $scheduleEnd <= $scheduleStart) {
                $scheduleEnd->addDay();
            }

            // Tìm log
            $windowStart = ($scheduleStart ?? Carbon::parse($schedule->work_date))->copy()->subHours(8);
            $windowEnd = ($scheduleEnd ?? Carbon::parse($schedule->work_date)->endOfDay())->copy()->addHours(8);

            $logs = AttendanceLog::where('employee_id', $schedule->employee_id)
                ->whereBetween('punched_at', [$windowStart, $windowEnd])
                ->orderBy('punched_at')
                ->get();

            $checkIn = null;
            $checkOut = null;

            if ($setting?->enforce_shift_checkin_window && $shift?->checkin_start_time && $logs->isNotEmpty()) {
                $winStart = $this->buildScheduleDateTime($schedule->work_date, $shift->checkin_start_time, null);
                $winEnd = $this->buildScheduleDateTime($schedule->work_date, $shift->checkin_end_time, null);
                if ($winEnd <= $winStart)
                    $winEnd->addDay();

                $first = $logs->first(fn($l) => $l->punched_at >= $winStart && $l->punched_at <= $winEnd);
                if ($first) {
                    $checkIn = $first->punched_at;
                    $last = $logs->last(fn($l) => $l->id !== $first->id && $l->punched_at > $checkIn);
                    $checkOut = $last?->punched_at;
                }
            }

            if (!$checkIn && $logs->isNotEmpty()) {
                if ($logs->count() === 1 && $scheduleStart && $scheduleEnd) {
                    // Chỉ có 1 lần chấm: so sánh khoảng cách tới giờ bắt đầu/kết thúc ca
                    // Nếu gần giờ kết thúc ca hơn → coi là check_out (nhân viên quên chấm vào)
                    $punch = Carbon::parse($logs->first()->punched_at);
                    $midShift = $scheduleStart->copy()->addMinutes(
                        abs($scheduleStart->diffInMinutes($scheduleEnd)) / 2
                    );
                    if ($punch->greaterThan($midShift)) {
                        $checkOut = $logs->first()->punched_at;
                    } else {
                        $checkIn = $logs->first()->punched_at;
                    }
                } else {
                    $checkIn = $logs->first()->punched_at;
                    if ($logs->count() > 1 && $logs->last()->id !== $logs->first()->id) {
                        $checkOut = $logs->last()->punched_at;
                    }
                }
            }

            // Tính toán chỉ số
            $useShiftAllowances = (bool) ($setting?->use_shift_allowances ?? true);
            $allowLate = $useShiftAllowances ? ($shift?->allow_late_minutes ?? 0) : ($setting?->late_grace_minutes ?? 0);
            $allowEarly = $useShiftAllowances ? ($shift?->allow_early_minutes ?? 0) : ($setting?->early_grace_minutes ?? 0);
            $otAfter = (int) ($setting?->ot_after_minutes ?? 0);
            $otRounding = (int) ($setting?->ot_rounding_minutes ?? 0);

            $lateMinutes = $earlyMinutes = $otMinutes = $workedMinutes = 0;

            if ($checkIn && $checkOut) {
                $workedMinutes = abs(Carbon::parse($checkOut)->diffInMinutes(Carbon::parse($checkIn)));
            } elseif ($checkIn && !$checkOut && $scheduleEnd) {
                // Chỉ có check_in, không có check_out → ước tính làm đến hết ca
                $workedMinutes = abs($scheduleEnd->diffInMinutes(Carbon::parse($checkIn)));
            } elseif (!$checkIn && $checkOut && $scheduleStart) {
                // Chỉ có check_out, không có check_in → ước tính làm từ đầu ca
                $workedMinutes = abs(Carbon::parse($checkOut)->diffInMinutes($scheduleStart));
            }

            if ($scheduleStart && $checkIn) {
                $checkInCarbon = Carbon::parse($checkIn);
                $lateMinutes = max(0, (int) $checkInCarbon->diffInMinutes($scheduleStart, false));
                $lateMinutes = max(0, $lateMinutes - $allowLate);

                if ($overtimeBeforeEnabled) {
                    if ($checkInCarbon->lessThan($scheduleStart)) {
                        $rawBeforeOt = abs($scheduleStart->diffInMinutes($checkInCarbon));
                        $rawBeforeOt = max(0, $rawBeforeOt - $overtimeBeforeMinutes);
                        if ($otRounding > 0) {
                            $rawBeforeOt = intdiv($rawBeforeOt, $otRounding) * $otRounding;
                        }
                        $otMinutes += $rawBeforeOt;
                    }
                }
            }

            if ($scheduleEnd && $checkOut) {
                $checkOutCarbon = Carbon::parse($checkOut);

                if ($checkOutCarbon->lessThan($scheduleEnd)) {
                    $diffEarly = abs($scheduleEnd->diffInMinutes($checkOutCarbon));
                    $earlyMinutes = max(0, $diffEarly - $allowEarly);
                } elseif ($checkOutCarbon->greaterThan($scheduleEnd)) {
                    $rawOt = abs($checkOutCarbon->diffInMinutes($scheduleEnd));
                    $rawOt = max(0, $rawOt - $otAfter);
                    if ($otRounding > 0) {
                        $rawOt = intdiv($rawOt, $otRounding) * $otRounding;
                    }
                    $otMinutes = $rawOt;
                }
            }

            $holiday = $holidayMap->get(Carbon::parse($schedule->work_date)->toDateString());

            // Kiểm tra ngày nghỉ tuần (VD: Chủ nhật không nằm trong week_days)
            $isRestDay = false;
            if (!$holiday) {
                $dayOfWeek = Carbon::parse($schedule->work_date)->dayOfWeek; // 0=CN, 1=T2...6=T7
                $branchWorkday = $workdaySettings->firstWhere('branch_id', $schedule->branch_id);
                $globalWorkday = $workdaySettings->firstWhere('branch_id', null);
                $weekDays = ($branchWorkday ?? $globalWorkday)?->week_days ?? [1, 2, 3, 4, 5, 6]; // Mặc định T2-T7
                $isRestDay = !in_array($dayOfWeek, $weekDays);
            }

            // Tính work_units: 0 (vắng), 0.5 (nửa ngày), 1 (đủ ngày)
            $standardHours = (float) ($setting?->standard_hours_per_day ?? 8);
            $halfDayThreshold = $standardHours / 2;
            $workUnits = 0;
            if ($workedMinutes > 0) {
                if ($halfWorkEnabled) {
                    if ($workedMinutes < $halfWorkMinMinutes) {
                        $workUnits = 0;
                    } elseif ($workedMinutes <= $halfWorkMaxMinutes) {
                        $workUnits = 0.5;
                    } else {
                        $workUnits = 1.0;
                    }
                } else {
                    $workedHours = $workedMinutes / 60;
                    if ($workedHours >= $halfDayThreshold) {
                        $workUnits = 1.0;
                    } else {
                        $workUnits = 0.5;
                    }
                }
            }

            // Đi muộn quá ngưỡng → tính nửa ngày công
            if ($lateHalfDayEnabled && $lateMinutes >= $lateHalfDayThreshold && $workUnits > 0.5) {
                $workUnits = 0.5;
            }

            $attributes = [
                'employee_id' => $schedule->employee_id,
                'employee_work_schedule_id' => $schedule->id,
                'branch_id' => $schedule->branch_id,
                'shift_id' => $schedule->shift_id,
                'work_date' => $schedule->work_date,
                'slot' => $schedule->slot ?? 1,
                'scheduled_start_at' => $scheduleStart,
                'scheduled_end_at' => $scheduleEnd,
                'check_in_at' => $checkIn,
                'check_out_at' => $checkOut,
                'source' => $logs->isNotEmpty() ? 'device' : 'none',
                'attendance_type' => 'work',
                'manual_override' => false,
                'late_minutes' => $lateMinutes,
                'early_minutes' => $earlyMinutes,
                'ot_minutes' => $otMinutes,
                'worked_minutes' => $workedMinutes,
                'work_units' => $workUnits,
                'is_holiday' => (bool) $holiday || $isRestDay,
                'holiday_multiplier' => $holiday ? (float) $holiday->multiplier : ($isRestDay ? 2.0 : 1),
                'raw' => [
                    'log_ids' => $logs->pluck('id')->values()->all(),
                    'device_ids' => $logs->pluck('attendance_device_id')->unique()->values()->all(),
                ],
            ];

            if ($existing) {
                $existing->fill($attributes)->save();
                $updated++;
            } else {
                TimekeepingRecord::create($attributes);
                $created++;
            }
        }

        return compact('created', 'updated');
    }

    private function buildScheduleDateTime($workDate, $scheduleTime, $fallbackShiftTime): ?Carbon
    {
        $time = $scheduleTime ?? $fallbackShiftTime;
        if (!$time)
            return null;
        return Carbon::parse($workDate)->startOfDay()->setTimeFromTimeString((string) $time);
    }
}
