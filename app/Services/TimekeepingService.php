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
                // Carbon 3: diffInMinutes trả về giá trị có dấu → dùng abs() + kiểm tra hướng
                if ($checkInCarbon->greaterThan($scheduleStart)) {
                    $lateMinutes = max(0, abs($checkInCarbon->diffInMinutes($scheduleStart)) - $allowLate);
                }

                // OT TRƯỚC CA: ceil(seconds/60) - threshold (KiotViet truncate checkin xuống phút)
                $otBeforeShift = (int) ($setting?->ot_before_minutes ?? 0);
                if ($otBeforeShift > 0 && $checkInCarbon->lessThan($scheduleStart)) {
                    $earlyArrival = (int) ceil(abs($scheduleStart->diffInSeconds($checkInCarbon)) / 60);
                    $otMinutes += max(0, $earlyArrival - $otBeforeShift);
                }
            }

            if ($scheduleEnd && $checkOut) {
                $checkOutCarbon = Carbon::parse($checkOut);

                if ($checkOutCarbon->lessThan($scheduleEnd)) {
                    $diffEarly = abs($scheduleEnd->diffInMinutes($checkOutCarbon));
                    $earlyMinutes = max(0, $diffEarly - $allowEarly);
                } elseif ($checkOutCarbon->greaterThan($scheduleEnd)) {
                    // OT SAU CA: floor(seconds/60) - threshold (KiotViet: bỏ qua X phút đầu sau ca)
                    $rawOt = max(0, intdiv(abs($checkOutCarbon->diffInSeconds($scheduleEnd)), 60) - $otAfter);
                    if ($otRounding > 0) {
                        $rawOt = intdiv($rawOt, $otRounding) * $otRounding;
                    }
                    $otMinutes += $rawOt;
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

            // Ngày nghỉ / ngày lễ: OT, late, early tính BÌNH THƯỜNG theo ca
            // work_units vẫn tính bình thường (1.0 / 0.5)
            // Hệ số nhân (2x, 3x) áp dụng trong SalaryCalculationService qua holiday_multiplier

            $fullDayMinutes = $this->resolveFullDayMinutes($scheduleStart, $scheduleEnd);

            $workUnits = $this->calculateWorkUnitsFromMinutes(
                $workedMinutes,
                $fullDayMinutes,
                $halfWorkEnabled,
                $halfWorkMinMinutes,
                $halfWorkMaxMinutes,
                $lateHalfDayEnabled,
                $lateMinutes,
                $lateHalfDayThreshold
            );

            // Ngày nghỉ/lễ: work_units GIỮ NGUYÊN (1.0 hoặc 0.5)
            // Hệ số nhân (2x, 3x) sẽ được áp dụng trong SalaryCalculationService
            // qua trường holiday_multiplier

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

        // ===== XỬ LÝ NGÀY NGHỈ không có schedule nhưng có chấm công =====
        // Tìm tất cả attendance log trong khoảng thời gian, nhóm theo employee+date
        $allLogs = AttendanceLog::whereBetween('punched_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($employeeId, fn($q) => $q->where('employee_id', $employeeId))
            ->orderBy('punched_at')
            ->get();

        $logsByEmpDate = $allLogs->groupBy(function ($log) {
            return $log->employee_id . '_' . Carbon::parse($log->punched_at)->toDateString();
        });

        foreach ($logsByEmpDate as $key => $logs) {
            [$empId, $dateStr] = explode('_', $key, 2);
            $empId = (int) $empId;

            // Nếu đã có TimekeepingRecord cho ngày này (từ schedule) → bỏ qua
            $existingRecord = TimekeepingRecord::where('employee_id', $empId)
                ->where('work_date', $dateStr)
                ->first();
            if ($existingRecord && $existingRecord->manual_override) continue;
            if ($existingRecord && $existingRecord->employee_work_schedule_id) continue;

            // Kiểm tra có phải ngày nghỉ không
            $dayOfWeek = Carbon::parse($dateStr)->dayOfWeek;
            $employee = \App\Models\Employee::find($empId);
            if (!$employee) continue;

            $holiday = $holidayMap->get($dateStr);
            $isRestDay = false;
            if (!$holiday) {
                $branchWorkday = $workdaySettings->firstWhere('branch_id', $employee->branch_id);
                $globalWorkday = $workdaySettings->firstWhere('branch_id', null);
                $weekDays = ($branchWorkday ?? $globalWorkday)?->week_days ?? [1, 2, 3, 4, 5, 6];
                $isRestDay = !in_array($dayOfWeek, $weekDays);
            }

            // Chỉ tạo record cho ngày nghỉ/lễ (ngày thường không có schedule = không tính)
            if (!$holiday && !$isRestDay) continue;

            // Lấy shift thực tế của nhân viên từ lịch làm việc gần nhất trong kỳ
            $empSchedule = EmployeeWorkSchedule::where('employee_id', $empId)
                ->whereBetween('work_date', [$from, $to])
                ->whereNotNull('shift_id')
                ->orderByRaw("ABS(DATEDIFF(work_date, ?))", [$dateStr])
                ->first();
            $empShift = $empSchedule?->shift_id ? ($shifts->all()[$empSchedule->shift_id] ?? Shift::find($empSchedule->shift_id)) : null;
            if (!$empShift) {
                $empShift = Shift::where('branch_id', $employee->branch_id)->first() ?? Shift::first();
            }

            $setting = $settings->all()[(string) $employee->branch_id] ?? $globalSetting;

            // Xác định thời gian ca từ shift thực tế của nhân viên
            $scheduleStart = $empShift
                ? $this->buildScheduleDateTime($dateStr, null, $empShift->start_time)
                : Carbon::parse($dateStr)->setTimeFromTimeString('08:30');
            $scheduleEnd = $empShift
                ? $this->buildScheduleDateTime($dateStr, null, $empShift->end_time)
                : Carbon::parse($dateStr)->setTimeFromTimeString('18:00');
            if ($scheduleStart && $scheduleEnd && $scheduleEnd <= $scheduleStart) {
                $scheduleEnd->addDay();
            }

            // Tính check_in / check_out từ logs
            $checkIn = $logs->first()->punched_at;
            $checkOut = $logs->count() > 1 ? $logs->last()->punched_at : null;

            $workedMinutes = 0;
            if ($checkIn && $checkOut) {
                $workedMinutes = abs(Carbon::parse($checkOut)->diffInMinutes(Carbon::parse($checkIn)));
            }

            // Tính late/early/OT theo ca mặc định (giống ngày thường)
            $useShiftAllowances = (bool) ($setting?->use_shift_allowances ?? true);
            $allowLate = $useShiftAllowances ? ($empShift?->allow_late_minutes ?? 0) : ($setting?->late_grace_minutes ?? 0);
            $allowEarly = $useShiftAllowances ? ($empShift?->allow_early_minutes ?? 0) : ($setting?->early_grace_minutes ?? 0);
            $otAfter = (int) ($setting?->ot_after_minutes ?? 0);
            $otRounding = (int) ($setting?->ot_rounding_minutes ?? 0);

            $lateMinutes = $earlyMinutes = $otMinutes = 0;

            if ($scheduleStart && $checkIn) {
                $checkInCarbon = Carbon::parse($checkIn);
                if ($checkInCarbon->greaterThan($scheduleStart)) {
                    $lateMinutes = max(0, abs($checkInCarbon->diffInMinutes($scheduleStart)) - $allowLate);
                }
                if ((bool) Setting::get('attendance_overtime_before_enabled', true)) {
                    if ($checkInCarbon->lessThan($scheduleStart)) {
                        $rawBeforeOt = (int) abs($scheduleStart->diffInMinutes($checkInCarbon));
                        $otBefore = (int) Setting::get('attendance_overtime_before_minutes', 0);
                        if ($rawBeforeOt < $otBefore) {
                            $rawBeforeOt = 0;
                        }
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
                    $rawOt = (int) abs($checkOutCarbon->diffInMinutes($scheduleEnd));
                    if ($rawOt < $otAfter) {
                        $rawOt = 0;
                    }
                    if ($otRounding > 0) {
                        $rawOt = intdiv($rawOt, $otRounding) * $otRounding;
                    }
                    $otMinutes += $rawOt;
                }
            }

            $fullDayMinutesRest = $this->resolveFullDayMinutes($scheduleStart, $scheduleEnd);

            $workUnitsRest = $this->calculateWorkUnitsFromMinutes(
                $workedMinutes,
                $fullDayMinutesRest,
                $halfWorkEnabled,
                $halfWorkMinMinutes,
                $halfWorkMaxMinutes,
                false,
                0,
                null
            );

            $attributes = [
                'employee_id' => $empId,
                'employee_work_schedule_id' => null,
                'branch_id' => $employee->branch_id,
                'shift_id' => $empShift?->id,
                'work_date' => $dateStr,
                'slot' => 1,
                'scheduled_start_at' => $scheduleStart,
                'scheduled_end_at' => $scheduleEnd,
                'check_in_at' => $checkIn,
                'check_out_at' => $checkOut,
                'source' => 'device',
                'attendance_type' => 'work',
                'manual_override' => false,
                'late_minutes' => $lateMinutes,
                'early_minutes' => $earlyMinutes,
                'ot_minutes' => $otMinutes,
                'worked_minutes' => $workedMinutes,
                'work_units' => $workUnitsRest,
                'is_holiday' => true,
                'holiday_multiplier' => $holiday ? (float) $holiday->multiplier : 2.0,
                'raw' => [
                    'log_ids' => $logs->pluck('id')->values()->all(),
                    'device_ids' => $logs->pluck('attendance_device_id')->unique()->values()->all(),
                ],
            ];

            if ($existingRecord) {
                $existingRecord->fill($attributes)->save();
                $updated++;
            } else {
                TimekeepingRecord::create($attributes);
                $created++;
            }
        }

        return compact('created', 'updated');
    }

    public function buildManualRecordAttributes(
        EmployeeWorkSchedule $schedule,
        string $attendanceType,
        ?string $checkInTime,
        ?string $checkOutTime,
        int $otMinutes = 0,
        ?string $notes = null
    ): array {
        // Load branch-specific or global settings
        $setting = TimekeepingSetting::where('branch_id', $schedule->branch_id)->first()
            ?? TimekeepingSetting::whereNull('branch_id')->first();

        // Calculate scheduleStart / scheduleEnd
        $scheduleStart = $this->buildScheduleDateTime($schedule->work_date, $schedule->start_time, $schedule->shift?->start_time);
        $scheduleEnd = $this->buildScheduleDateTime($schedule->work_date, $schedule->end_time, $schedule->shift?->end_time);
        if ($scheduleStart && $scheduleEnd && $scheduleEnd <= $scheduleStart) {
            $scheduleEnd->addDay(); // ca đêm
        }

        // Calculate checkIn / checkOut
        $checkInAt = null;
        $checkOutAt = null;
        if ($attendanceType === 'work') {
            $dateStr = Carbon::parse($schedule->work_date)->toDateString();
            $checkInAt = !empty($checkInTime) ? Carbon::parse($dateStr . ' ' . $checkInTime) : null;
            $checkOutAt = !empty($checkOutTime) ? Carbon::parse($dateStr . ' ' . $checkOutTime) : null;
            if ($checkOutAt && $checkInAt && $checkOutAt <= $checkInAt) {
                $checkOutAt->addDay(); // ca đêm
            }
        }

        $useShiftAllowances = (bool) ($setting?->use_shift_allowances ?? true);
        $allowLate = $useShiftAllowances ? ($schedule->shift?->allow_late_minutes ?? 0) : ($setting?->late_grace_minutes ?? 0);
        $allowEarly = $useShiftAllowances ? ($schedule->shift?->allow_early_minutes ?? 0) : ($setting?->early_grace_minutes ?? 0);

        // Calculate late / early / worked minutes
        $lateMinutes = 0;
        $earlyMinutes = 0;
        $workedMinutes = 0;

        if ($attendanceType === 'work') {
            if ($checkInAt && $checkOutAt) {
                $workedMinutes = abs($checkOutAt->diffInMinutes($checkInAt));
            } elseif ($checkInAt && !$checkOutAt && $scheduleEnd) {
                $workedMinutes = abs($scheduleEnd->diffInMinutes($checkInAt));
            } elseif (!$checkInAt && $checkOutAt && $scheduleStart) {
                $workedMinutes = abs($checkOutAt->diffInMinutes($scheduleStart));
            }

            if ($scheduleStart && $checkInAt && $checkInAt->greaterThan($scheduleStart)) {
                $lateMinutes = max(0, abs($checkInAt->diffInMinutes($scheduleStart)) - $allowLate);
            }

            if ($scheduleEnd && $checkOutAt && $checkOutAt->lessThan($scheduleEnd)) {
                $earlyMinutes = max(0, abs($scheduleEnd->diffInMinutes($checkOutAt)) - $allowEarly);
            }
        }

        // Holiday & Day Off logic
        $holiday = Holiday::where('holiday_date', $schedule->work_date)
            ->where('status', 'active')
            ->first();

        $isRestDay = false;
        if (!$holiday) {
            $dayOfWeek = Carbon::parse($schedule->work_date)->dayOfWeek;
            $workdaySettings = WorkdaySetting::all();
            $branchWorkday = $workdaySettings->firstWhere('branch_id', $schedule->branch_id);
            $globalWorkday = $workdaySettings->firstWhere('branch_id', null);
            $weekDays = ($branchWorkday ?? $globalWorkday)?->week_days ?? [1, 2, 3, 4, 5, 6];
            $isRestDay = !in_array($dayOfWeek, $weekDays);
        }

        $isHoliday = (bool) $holiday || $isRestDay;
        $holidayMultiplier = $holiday ? (float) $holiday->multiplier : ($isRestDay ? 2.0 : 1.0);

        // Calculate work_units
        $halfWorkEnabled = (bool) Setting::get('attendance_half_work_enabled', true);
        $halfWorkMaxMinutes = (int) Setting::get('attendance_half_work_max_minutes', 480);
        $halfWorkMinMinutes = (int) Setting::get('attendance_half_work_min_minutes', 0);
        $payrollSetting = \App\Models\PayrollSetting::first();
        $lateHalfDayEnabled = (bool) ($payrollSetting->late_half_day_enabled ?? false);
        $lateHalfDayThreshold = (int) ($payrollSetting->late_half_day_threshold ?? 120);

        $workUnits = 0.0;
        if ($attendanceType === 'leave_paid') {
            $workUnits = 1.0;
        } elseif ($attendanceType === 'leave_unpaid') {
            $workUnits = 0.0;
        } else { // 'work'
            $fullDayMinutes = $this->resolveFullDayMinutes($scheduleStart, $scheduleEnd);

            $workUnits = $this->calculateWorkUnitsFromMinutes(
                $workedMinutes,
                $fullDayMinutes,
                $halfWorkEnabled,
                $halfWorkMinMinutes,
                $halfWorkMaxMinutes,
                $lateHalfDayEnabled,
                $lateMinutes,
                $lateHalfDayThreshold
            );
        }

        return [
            'employee_id' => $schedule->employee_id,
            'employee_work_schedule_id' => $schedule->id,
            'branch_id' => $schedule->branch_id,
            'shift_id' => $schedule->shift_id,
            'work_date' => $schedule->work_date,
            'slot' => $schedule->slot ?? 1,
            'scheduled_start_at' => $scheduleStart,
            'scheduled_end_at' => $scheduleEnd,
            'check_in_at' => $checkInAt,
            'check_out_at' => $checkOutAt,
            'source' => 'manual',
            'attendance_type' => $attendanceType,
            'manual_override' => true,
            'late_minutes' => $lateMinutes,
            'early_minutes' => $earlyMinutes,
            'ot_minutes' => $otMinutes,
            'worked_minutes' => $workedMinutes,
            'work_units' => $workUnits,
            'is_holiday' => $isHoliday,
            'holiday_multiplier' => $holidayMultiplier,
            'raw' => [
                'source' => 'manual',
            ],
            'notes' => $notes,
        ];
    }

    private function buildScheduleDateTime($workDate, $scheduleTime, $fallbackShiftTime): ?Carbon
    {
        $time = $scheduleTime ?? $fallbackShiftTime;
        if (!$time)
            return null;
        return Carbon::parse($workDate)->startOfDay()->setTimeFromTimeString((string) $time);
    }

    private function calculateWorkUnitsFromMinutes(
        int $workedMinutes,
        int $fullDayMinutes,
        bool $halfWorkEnabled,
        int $halfWorkMinMinutes,
        int $halfWorkMaxMinutes,
        bool $lateHalfDayEnabled = false,
        int $lateMinutes = 0,
        ?int $lateHalfDayThreshold = null
    ): float {
        if ($workedMinutes <= 0) {
            return 0.0;
        }

        if ($workedMinutes >= $fullDayMinutes) {
            return 1.0;
        }

        $workUnits = 0.0;
        if ($halfWorkEnabled) {
            if ($workedMinutes >= $halfWorkMinMinutes) {
                if ($workedMinutes <= $halfWorkMaxMinutes) {
                    $workUnits = 0.5;
                } else {
                    $workUnits = 1.0;
                }
            } else {
                $workUnits = 0.0;
            }
        } else {
            if ($workedMinutes >= ($fullDayMinutes / 2)) {
                $workUnits = 1.0;
            } else {
                $workUnits = 0.5;
            }
        }

        if ($lateHalfDayEnabled && $lateMinutes >= $lateHalfDayThreshold && $workUnits > 0.5) {
            $workUnits = 0.5;
        }

        return $workUnits;
    }

    private function resolveFullDayMinutes(?Carbon $scheduleStart, ?Carbon $scheduleEnd): int
    {
        $standardWorkMinutes = (int) Setting::get('attendance_standard_work_minutes', 480);

        $scheduleMinutes = null;
        if ($scheduleStart && $scheduleEnd && $scheduleEnd->greaterThan($scheduleStart)) {
            $scheduleMinutes = abs($scheduleEnd->diffInMinutes($scheduleStart));
        }

        if ($standardWorkMinutes > 0 && $scheduleMinutes && $scheduleMinutes > 0) {
            return min($standardWorkMinutes, $scheduleMinutes);
        }

        if ($standardWorkMinutes > 0) {
            return $standardWorkMinutes;
        }

        if ($scheduleMinutes && $scheduleMinutes > 0) {
            return $scheduleMinutes;
        }

        return 480;
    }
}
