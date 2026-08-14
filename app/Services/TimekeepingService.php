<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\EmployeeWorkSchedule;
use App\Models\Holiday;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\TimekeepingRecord;
use App\Models\TimekeepingSetting;
use App\Models\WorkdaySetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimekeepingService
{
    public function recalculateForRange(Carbon $from, Carbon $to, ?int $employeeId = null): array
    {
        // 1. Dữ liệu tham chiếu
        $holidayMap = Holiday::whereBetween('holiday_date', [$from, $to])
            ->where('status', 'active')
            ->get()->keyBy(fn ($h) => \Carbon\Carbon::parse($h->holiday_date)->toDateString());

        // Lấy danh sách ngày làm việc trong tuần (VD: [1,2,3,4,5,6] = T2→T7)
        // Ngày KHÔNG nằm trong danh sách = ngày nghỉ tuần (VD: CN = 0)
        $workdaySettings = WorkdaySetting::all();

        $schedules = EmployeeWorkSchedule::whereBetween('work_date', [$from, $to])
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->orderBy('work_date')->orderBy('slot')
            ->get();

        $shifts = Shift::whereIn('id', $schedules->pluck('shift_id')->filter())->get()->keyBy('id');
        $settings = TimekeepingSetting::where('status', 'active')->get()
            ->keyBy(fn ($s) => $s->branch_id ?? 'global');
        $globalSetting = $settings->all()['global'] ?? null;
        $halfWorkEnabled = (bool) Setting::get('attendance_half_work_enabled', true);
        $halfWorkMaxMinutes = (int) Setting::get('attendance_half_work_max_minutes', 480);
        $halfWorkMinMinutes = (int) Setting::get('attendance_half_work_min_minutes', 0);
        $payrollSetting = \App\Models\PayrollSetting::first();
        $lateHalfDayEnabled = (bool) ($payrollSetting->late_half_day_enabled ?? false);
        $lateHalfDayThreshold = (int) ($payrollSetting->late_half_day_threshold ?? 120);
        $resolvedIntervals = $this->resolveIntervalsForSchedules($schedules, $shifts, $settings, $globalSetting);
        $scheduleCountsByDay = $schedules->groupBy(fn ($item) => $item->employee_id.'_'.Carbon::parse($item->work_date)->toDateString())
            ->map(fn (Collection $items) => $items->count());

        $created = 0;
        $updated = 0;

        foreach ($schedules as $schedule) {

            // Skip bản ghi đã chỉnh tay
            $existing = TimekeepingRecord::where('employee_work_schedule_id', $schedule->id)->first();
            if ($existing && $existing->manual_override) {
                continue;
            }

            $wasExisting = (bool) $existing;
            $hadStoredIntervals = $wasExisting && $existing->intervals()->exists();

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
            $resolved = $resolvedIntervals[$schedule->id] ?? [
                'intervals' => [],
                'logs' => collect(),
                'needs_review' => false,
            ];
            $intervals = collect($resolved['intervals']);
            $logs = collect($resolved['logs']);

            // A legacy record without interval rows is read as-is when there
            // are no current punches. Recalculation must not erase its old
            // check-in/out values or silently backfill the interval table.
            if ($wasExisting && ! $hadStoredIntervals && $logs->isEmpty()) {
                continue;
            }
            // Intervals are the source of truth. An incomplete interval contributes
            // zero worked minutes and is surfaced as needs_review; no schedule
            // boundary is silently substituted for a missing punch.
            $checkIn = $intervals->filter(fn ($interval) => $interval['check_in_at'])->sortBy('check_in_at')->first()['check_in_at'] ?? null;
            $checkOut = $intervals->filter(fn ($interval) => $interval['check_out_at'])->sortByDesc('check_out_at')->first()['check_out_at'] ?? null;
            $workedMinutes = (int) $intervals->sum('worked_minutes');
            $regularMinutes = (int) $intervals->sum('regular_minutes');
            $lateMinutes = (int) $intervals->sum('late_minutes');
            $earlyMinutes = (int) $intervals->sum('early_minutes');
            $otMinutes = (int) $intervals->sum('ot_minutes');
            $needsReview = (bool) ($resolved['needs_review'] ?? false);

            $holiday = $holidayMap->get(Carbon::parse($schedule->work_date)->toDateString());

            // Kiểm tra ngày nghỉ tuần (VD: Chủ nhật không nằm trong week_days)
            $isRestDay = false;
            if (! $holiday) {
                $dayOfWeek = Carbon::parse($schedule->work_date)->dayOfWeek; // 0=CN, 1=T2...6=T7
                $branchWorkday = $workdaySettings->firstWhere('branch_id', $schedule->branch_id);
                $globalWorkday = $workdaySettings->firstWhere('branch_id', null);
                $weekDays = ($branchWorkday ?? $globalWorkday)?->week_days ?? [1, 2, 3, 4, 5, 6]; // Mặc định T2-T7
                $isRestDay = ! in_array($dayOfWeek, $weekDays);
            }

            // Ngày nghỉ / ngày lễ: OT, late, early tính BÌNH THƯỜNG theo ca
            // work_units vẫn tính bình thường (1.0 / 0.5)
            // Hệ số nhân (2x, 3x) áp dụng trong SalaryCalculationService qua holiday_multiplier

            $dayKey = $schedule->employee_id.'_'.Carbon::parse($schedule->work_date)->toDateString();
            $fullDayMinutes = ($scheduleCountsByDay[$dayKey] ?? 1) > 1
                ? $this->resolveStandardWorkMinutes($setting)
                : $this->resolveFullDayMinutes($scheduleStart, $scheduleEnd);

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
                'regular_minutes' => $regularMinutes,
                'needs_review' => $needsReview,
                'work_units' => $workUnits,
                'is_holiday' => (bool) $holiday || $isRestDay,
                'holiday_multiplier' => $holiday ? (float) $holiday->multiplier : ($isRestDay ? 2.0 : 1),
                'raw' => [
                    'log_ids' => $logs->pluck('id')->values()->all(),
                    'device_ids' => $logs->pluck('attendance_device_id')->unique()->values()->all(),
                    'unmatched_log_ids' => $resolved['unmatched_log_ids'] ?? [],
                    'intervals' => $intervals->values()->all(),
                ],
            ];

            if ($existing) {
                $existing->fill($attributes)->save();
                $updated++;
            } else {
                $existing = TimekeepingRecord::create($attributes);
                $created++;
            }

            // Do not backfill legacy records automatically. New records and
            // records already using the interval model are persisted normally.
            if (! $wasExisting || $hadStoredIntervals) {
                $this->persistIntervals($existing, $intervals, $schedule);
            }
        }

        // ===== XỬ LÝ NGÀY NGHỈ không có schedule nhưng có chấm công =====
        // Tìm tất cả attendance log trong khoảng thời gian, nhóm theo employee+date
        $allLogs = AttendanceLog::whereBetween('punched_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->orderBy('punched_at')
            ->get();

        $logsByEmpDate = $allLogs->groupBy(function ($log) {
            return $log->employee_id.'_'.Carbon::parse($log->punched_at)->toDateString();
        });

        foreach ($logsByEmpDate as $key => $logs) {
            [$empId, $dateStr] = explode('_', $key, 2);
            $empId = (int) $empId;

            // Nếu đã có TimekeepingRecord cho ngày này (từ schedule) → bỏ qua
            $existingRecord = TimekeepingRecord::where('employee_id', $empId)
                ->where('work_date', $dateStr)
                ->first();
            if ($existingRecord && $existingRecord->manual_override) {
                continue;
            }
            if ($existingRecord && $existingRecord->employee_work_schedule_id) {
                continue;
            }

            // Kiểm tra có phải ngày nghỉ không
            $dayOfWeek = Carbon::parse($dateStr)->dayOfWeek;
            $employee = \App\Models\Employee::find($empId);
            if (! $employee) {
                continue;
            }

            $holiday = $holidayMap->get($dateStr);
            $isRestDay = false;
            if (! $holiday) {
                $branchWorkday = $workdaySettings->firstWhere('branch_id', $employee->branch_id);
                $globalWorkday = $workdaySettings->firstWhere('branch_id', null);
                $weekDays = ($branchWorkday ?? $globalWorkday)?->week_days ?? [1, 2, 3, 4, 5, 6];
                $isRestDay = ! in_array($dayOfWeek, $weekDays);
            }

            // Chỉ tạo record cho ngày nghỉ/lễ (ngày thường không có schedule = không tính)
            if (! $holiday && ! $isRestDay) {
                continue;
            }

            // Lấy shift thực tế của nhân viên từ lịch làm việc gần nhất trong kỳ
            $empSchedule = EmployeeWorkSchedule::where('employee_id', $empId)
                ->whereBetween('work_date', [$from, $to])
                ->whereNotNull('shift_id')
                ->orderByRaw('ABS(DATEDIFF(work_date, ?))', [$dateStr])
                ->first();
            $empShift = $empSchedule?->shift_id ? ($shifts->all()[$empSchedule->shift_id] ?? Shift::find($empSchedule->shift_id)) : null;
            if (! $empShift) {
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

    /**
     * Resolve all device punches once per employee/day and assign them to the
     * configured schedule slots. This prevents the old broad-window query from
     * counting the same punch for both halves of a split shift.
     */
    private function resolveIntervalsForSchedules(Collection $schedules, Collection $shifts, Collection $settings, $globalSetting): array
    {
        $resolved = [];

        $groups = $schedules->groupBy(fn ($schedule) => $schedule->employee_id.'_'.Carbon::parse($schedule->work_date)->toDateString());
        foreach ($groups as $group) {
            $ordered = $group->sortBy('slot')->values();
            $firstBounds = $this->scheduleBounds($ordered->first(), $shifts);
            $lastBounds = $this->scheduleBounds($ordered->last(), $shifts);
            $windowStart = ($firstBounds['start'] ?? Carbon::parse($ordered->first()->work_date))->copy()->subHours(8);
            $windowEnd = ($lastBounds['end'] ?? Carbon::parse($ordered->last()->work_date)->endOfDay())->copy()->addHours(8);

            $logs = AttendanceLog::where('employee_id', $ordered->first()->employee_id)
                ->whereBetween('punched_at', [$windowStart, $windowEnd])
                ->orderBy('punched_at')
                ->get()
                ->values();

            $setting = $settings->all()[(string) $ordered->first()->branch_id] ?? $globalSetting;
            $assignments = $this->assignPunchesToSchedules($ordered, $logs, $shifts, $setting);

            foreach ($ordered as $schedule) {
                $item = $assignments[$schedule->id] ?? [
                    'intervals' => [],
                    'needs_review' => false,
                    'unmatched_log_ids' => [],
                ];
                $item['logs'] = $logs;
                $resolved[$schedule->id] = $item;
            }
        }

        return $resolved;
    }

    private function assignPunchesToSchedules(Collection $schedules, Collection $logs, Collection $shifts, $setting): array
    {
        $result = [];
        $orderedLogs = $logs->sortBy('punched_at')->values();

        if ($orderedLogs->isEmpty()) {
            return $schedules->mapWithKeys(fn ($schedule) => [
                $schedule->id => ['intervals' => [], 'needs_review' => false, 'unmatched_log_ids' => []],
            ])->all();
        }

        $duplicateLogIds = $orderedLogs
            ->groupBy(fn ($log) => Carbon::parse($log->punched_at)->format('Y-m-d H:i:s'))
            ->filter(fn (Collection $sameTime) => $sameTime->count() > 1)
            ->flatten(1)
            ->pluck('id')
            ->values()
            ->all();

        // Two actual punches across multiple configured slots can be expanded
        // only when the operator explicitly enabled the setting.
        if ($schedules->count() > 1 && (bool) ($setting?->allow_multiple_shifts_one_inout ?? false) && $orderedLogs->count() === 2) {
            foreach ($schedules as $index => $schedule) {
                $bounds = $this->scheduleBounds($schedule, $shifts);
                $checkIn = $index === 0 ? $orderedLogs->first()->punched_at : $bounds['start'];
                $checkOut = $index === $schedules->count() - 1 ? $orderedLogs->last()->punched_at : $bounds['end'];

                $result[$schedule->id] = [
                    'intervals' => [$this->makeInterval($schedule, $shifts, $setting, $checkIn, $checkOut, 'inferred', $orderedLogs->pluck('id')->all())],
                    'needs_review' => false,
                    'unmatched_log_ids' => [],
                ];
            }

            return $this->flagDuplicatePunches($result, $schedules, $duplicateLogIds);
        }

        // A single schedule may contain multiple complete in/out pairs.
        if ($schedules->count() === 1) {
            $schedule = $schedules->first();
            $intervals = [];
            $needsReview = false;
            for ($index = 0; $index < $orderedLogs->count(); $index += 2) {
                $in = $orderedLogs->get($index);
                $out = $orderedLogs->get($index + 1);
                if (! $out) {
                    $needsReview = true;
                    $intervals[] = $this->makeInterval($schedule, $shifts, $setting, $in->punched_at, null, 'device', [$in->id]);

                    continue;
                }
                $intervals[] = $this->makeInterval($schedule, $shifts, $setting, $in->punched_at, $out->punched_at, 'device', [$in->id, $out->id]);
            }
            $result[$schedule->id] = [
                'intervals' => $intervals,
                'needs_review' => $needsReview,
                'unmatched_log_ids' => $needsReview ? [$orderedLogs->last()->id] : [],
            ];

            return $this->flagDuplicatePunches($result, $schedules, $duplicateLogIds);
        }

        // Multiple slots: assign one pair to each slot using the next slot as
        // the hard boundary. Extra or missing punches are retained as review
        // evidence and never silently reused.
        $remaining = $orderedLogs->values();
        foreach ($schedules as $index => $schedule) {
            $bounds = $this->scheduleBounds($schedule, $shifts);
            $nextBounds = isset($schedules[$index + 1])
                ? $this->scheduleBounds($schedules[$index + 1], $shifts)
                : null;
            $nextStart = $nextBounds['start'] ?? $bounds['end']->copy()->addHours(8);

            $inLog = $remaining->first(fn ($log) => $log->punched_at >= $bounds['start']->copy()->subHours(8)
                && $log->punched_at < $nextStart
                && (! $bounds['end'] || $log->punched_at < $bounds['end']));
            if ($inLog) {
                $remaining = $remaining->reject(fn ($log) => $log->id === $inLog->id)->values();
            }

            $outLog = $inLog
                ? $remaining->first(fn ($log) => $log->punched_at > $inLog->punched_at && $log->punched_at < $nextStart)
                : null;
            if ($outLog) {
                $remaining = $remaining->reject(fn ($log) => $log->id === $outLog->id)->values();
            }

            $intervals = [];
            $needsReview = false;
            if ($inLog && $outLog) {
                $intervals[] = $this->makeInterval($schedule, $shifts, $setting, $inLog->punched_at, $outLog->punched_at, 'device', [$inLog->id, $outLog->id]);
            } elseif ($inLog) {
                $needsReview = true;
                $intervals[] = $this->makeInterval($schedule, $shifts, $setting, $inLog->punched_at, null, 'device', [$inLog->id]);
            } elseif ($remaining->isNotEmpty() && $remaining->first()->punched_at <= $nextStart) {
                $orphan = $remaining->shift();
                $needsReview = true;
                $intervals[] = $this->makeInterval($schedule, $shifts, $setting, null, $orphan->punched_at, 'device', [$orphan->id]);
            } elseif ($orderedLogs->isNotEmpty()) {
                // A configured slot with no pair is still a review case when
                // the employee has punched elsewhere on that work date.
                $needsReview = true;
            }

            $result[$schedule->id] = [
                'intervals' => $intervals,
                'needs_review' => $needsReview,
                'unmatched_log_ids' => [],
            ];
        }

        if ($remaining->isNotEmpty()) {
            $lastSchedule = $schedules->last();
            $result[$lastSchedule->id]['needs_review'] = true;
            $result[$lastSchedule->id]['unmatched_log_ids'] = $remaining->pluck('id')->values()->all();
        }

        return $this->flagDuplicatePunches($result, $schedules, $duplicateLogIds);
    }

    private function flagDuplicatePunches(array $result, Collection $schedules, array $duplicateLogIds): array
    {
        if (! $duplicateLogIds || $schedules->isEmpty()) {
            return $result;
        }

        $lastSchedule = $schedules->last();
        $result[$lastSchedule->id] ??= [
            'intervals' => [],
            'needs_review' => false,
            'unmatched_log_ids' => [],
        ];
        $result[$lastSchedule->id]['needs_review'] = true;
        $result[$lastSchedule->id]['unmatched_log_ids'] = array_values(array_unique(array_merge(
            $result[$lastSchedule->id]['unmatched_log_ids'] ?? [],
            $duplicateLogIds
        )));

        return $result;
    }

    private function makeInterval(EmployeeWorkSchedule $schedule, Collection $shifts, $setting, $checkIn, $checkOut, string $source, array $logIds): array
    {
        $bounds = $this->scheduleBounds($schedule, $shifts);
        $checkIn = $checkIn ? Carbon::parse($checkIn) : null;
        $checkOut = $checkOut ? Carbon::parse($checkOut) : null;
        $complete = $checkIn && $checkOut && $checkOut->greaterThan($checkIn);
        $workedMinutes = $complete ? abs($checkOut->diffInMinutes($checkIn)) : 0;
        $regularMinutes = 0;

        if ($complete && $bounds['start'] && $bounds['end']) {
            $regularStart = $checkIn->greaterThan($bounds['start']) ? $checkIn : $bounds['start'];
            $regularEnd = $checkOut->lessThan($bounds['end']) ? $checkOut : $bounds['end'];
            if ($regularEnd->greaterThan($regularStart)) {
                $regularMinutes = abs($regularEnd->diffInMinutes($regularStart));
            }
        }

        $metrics = $this->intervalMetrics($schedule, $shifts, $setting, $checkIn, $checkOut);

        return [
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
            'worked_minutes' => $workedMinutes,
            'regular_minutes' => $regularMinutes,
            'late_minutes' => $metrics['late_minutes'],
            'early_minutes' => $metrics['early_minutes'],
            'ot_minutes' => $metrics['ot_minutes'],
            'source' => $source,
            'status' => $complete ? 'complete' : 'needs_review',
            'raw' => ['log_ids' => $logIds, 'source' => $source],
        ];
    }

    private function intervalMetrics(EmployeeWorkSchedule $schedule, Collection $shifts, $setting, ?Carbon $checkIn, ?Carbon $checkOut): array
    {
        $bounds = $this->scheduleBounds($schedule, $shifts);
        if (! $checkIn || ! $checkOut || ! $bounds['start'] || ! $bounds['end']) {
            return ['late_minutes' => 0, 'early_minutes' => 0, 'ot_minutes' => 0];
        }

        $useShiftAllowances = (bool) ($setting?->use_shift_allowances ?? true);
        $shift = $schedule->shift_id ? $shifts->get($schedule->shift_id) : null;
        $allowLate = $useShiftAllowances ? ($shift?->allow_late_minutes ?? 0) : ($setting?->late_grace_minutes ?? 0);
        $allowEarly = $useShiftAllowances ? ($shift?->allow_early_minutes ?? 0) : ($setting?->early_grace_minutes ?? 0);
        $late = $checkIn->greaterThan($bounds['start']) ? max(0, $checkIn->diffInMinutes($bounds['start']) - $allowLate) : 0;
        $early = $checkOut->lessThan($bounds['end']) ? max(0, $bounds['end']->diffInMinutes($checkOut) - $allowEarly) : 0;
        $ot = 0;

        $otBefore = (int) ($setting?->ot_before_minutes ?? 0);
        if ($otBefore > 0 && $checkIn->lessThan($bounds['start'])) {
            $ot += max(0, (int) ceil($bounds['start']->diffInSeconds($checkIn) / 60) - $otBefore);
        }

        if ($checkOut->greaterThan($bounds['end'])) {
            $otAfter = (int) ($setting?->ot_after_minutes ?? 0);
            $ot = max($ot, max(0, intdiv($checkOut->diffInSeconds($bounds['end']), 60) - $otAfter));
        }

        $rounding = (int) ($setting?->ot_rounding_minutes ?? 0);
        if ($rounding > 0 && $ot > 0) {
            $ot = intdiv($ot, $rounding) * $rounding;
        }

        return ['late_minutes' => $late, 'early_minutes' => $early, 'ot_minutes' => $ot];
    }

    private function scheduleBounds(EmployeeWorkSchedule $schedule, Collection $shifts): array
    {
        $shift = $schedule->shift_id ? $shifts->get($schedule->shift_id) : null;
        $start = $this->buildScheduleDateTime($schedule->work_date, $schedule->start_time, $shift?->start_time);
        $end = $this->buildScheduleDateTime($schedule->work_date, $schedule->end_time, $shift?->end_time);
        if ($start && $end && $end <= $start) {
            $end->addDay();
        }

        return ['start' => $start, 'end' => $end];
    }

    private function persistIntervals(TimekeepingRecord $record, Collection $intervals, EmployeeWorkSchedule $schedule): void
    {
        $record->intervals()->delete();
        foreach ($intervals as $interval) {
            $record->intervals()->create([
                'employee_work_schedule_id' => $schedule->id,
                'employee_id' => $schedule->employee_id,
                'work_date' => $schedule->work_date,
                'slot' => $schedule->slot ?? 1,
                'scheduled_start_at' => $record->scheduled_start_at,
                'scheduled_end_at' => $record->scheduled_end_at,
                'check_in_at' => $interval['check_in_at'],
                'check_out_at' => $interval['check_out_at'],
                'worked_minutes' => $interval['worked_minutes'],
                'source' => $interval['source'],
                'status' => $interval['status'],
                'raw' => $interval['raw'],
            ]);
        }
    }

    private function resolveStandardWorkMinutes($setting = null): int
    {
        if ($setting?->standard_hours_per_day) {
            return max(1, (int) round((float) $setting->standard_hours_per_day * 60));
        }

        return max(1, (int) Setting::get('attendance_standard_work_minutes', 480));
    }

    public function buildManualRecordAttributes(
        EmployeeWorkSchedule $schedule,
        string $attendanceType,
        ?string $checkInTime,
        ?string $checkOutTime,
        int $otMinutes = 0,
        ?string $notes = null,
        ?array $manualIntervals = null
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
            $checkInAt = ! empty($checkInTime) ? Carbon::parse($dateStr.' '.$checkInTime) : null;
            $checkOutAt = ! empty($checkOutTime) ? Carbon::parse($dateStr.' '.$checkOutTime) : null;
            if ($checkOutAt && $checkInAt && $checkOutAt <= $checkInAt) {
                $checkOutAt->addDay(); // ca đêm
            }
        }

        $manualShiftCollection = $schedule->shift_id && $schedule->relationLoaded('shift')
            ? collect([$schedule->shift_id => $schedule->shift])
            : Shift::where('id', $schedule->shift_id)->get()->keyBy('id');
        $resolvedManualIntervals = collect();
        if ($attendanceType === 'work') {
            $definitions = is_array($manualIntervals) && count($manualIntervals) > 0
                ? $manualIntervals
                : (($checkInTime || $checkOutTime)
                    ? [['check_in_time' => $checkInTime, 'check_out_time' => $checkOutTime]]
                    : []);

            foreach ($definitions as $definition) {
                $dateString = Carbon::parse($schedule->work_date)->toDateString();
                $intervalIn = ! empty($definition['check_in_time'])
                    ? Carbon::parse($dateString.' '.$definition['check_in_time'])
                    : null;
                $intervalOut = ! empty($definition['check_out_time'])
                    ? Carbon::parse($dateString.' '.$definition['check_out_time'])
                    : null;
                if ($intervalOut && $intervalIn && $intervalOut <= $intervalIn) {
                    $intervalOut->addDay();
                }
                $resolvedManualIntervals->push($this->makeInterval(
                    $schedule,
                    $manualShiftCollection,
                    $setting,
                    $intervalIn,
                    $intervalOut,
                    'manual',
                    []
                ));
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
            } elseif ($checkInAt && ! $checkOutAt && $scheduleEnd) {
                $workedMinutes = abs($scheduleEnd->diffInMinutes($checkInAt));
            } elseif (! $checkInAt && $checkOutAt && $scheduleStart) {
                $workedMinutes = abs($checkOutAt->diffInMinutes($scheduleStart));
            }

            if ($scheduleStart && $checkInAt && $checkInAt->greaterThan($scheduleStart)) {
                $lateMinutes = max(0, abs($checkInAt->diffInMinutes($scheduleStart)) - $allowLate);
            }

            if ($scheduleEnd && $checkOutAt && $checkOutAt->lessThan($scheduleEnd)) {
                $earlyMinutes = max(0, abs($scheduleEnd->diffInMinutes($checkOutAt)) - $allowEarly);
            }
        }

        if ($attendanceType === 'work') {
            $checkInAt = $resolvedManualIntervals->filter(fn ($interval) => $interval['check_in_at'])->sortBy('check_in_at')->first()['check_in_at'] ?? null;
            $checkOutAt = $resolvedManualIntervals->filter(fn ($interval) => $interval['check_out_at'])->sortByDesc('check_out_at')->first()['check_out_at'] ?? null;
            $workedMinutes = (int) $resolvedManualIntervals->sum('worked_minutes');
            $regularMinutes = (int) $resolvedManualIntervals->sum('regular_minutes');
            $lateMinutes = (int) $resolvedManualIntervals->sum('late_minutes');
            $earlyMinutes = (int) $resolvedManualIntervals->sum('early_minutes');
            // Manual OT is an explicit override. Prefer the larger of the
            // supplied value and the interval-derived value so the same
            // overtime is never counted twice.
            $otMinutes = max($otMinutes, (int) $resolvedManualIntervals->sum('ot_minutes'));
            $needsReview = $resolvedManualIntervals->contains(fn ($interval) => $interval['status'] === 'needs_review');
        } else {
            $regularMinutes = 0;
            $needsReview = false;
        }

        // Holiday & Day Off logic
        $holiday = Holiday::where('holiday_date', $schedule->work_date)
            ->where('status', 'active')
            ->first();

        $isRestDay = false;
        if (! $holiday) {
            $dayOfWeek = Carbon::parse($schedule->work_date)->dayOfWeek;
            $workdaySettings = WorkdaySetting::all();
            $branchWorkday = $workdaySettings->firstWhere('branch_id', $schedule->branch_id);
            $globalWorkday = $workdaySettings->firstWhere('branch_id', null);
            $weekDays = ($branchWorkday ?? $globalWorkday)?->week_days ?? [1, 2, 3, 4, 5, 6];
            $isRestDay = ! in_array($dayOfWeek, $weekDays);
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
            $hasMultipleSlots = EmployeeWorkSchedule::query()
                ->where('employee_id', $schedule->employee_id)
                ->whereDate('work_date', $schedule->work_date)
                ->count() > 1;
            $fullDayMinutes = $hasMultipleSlots
                ? $this->resolveStandardWorkMinutes($setting)
                : $this->resolveFullDayMinutes($scheduleStart, $scheduleEnd);

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
            'regular_minutes' => $regularMinutes,
            'needs_review' => $needsReview,
            'work_units' => $workUnits,
            'is_holiday' => $isHoliday,
            'holiday_multiplier' => $holidayMultiplier,
            'raw' => [
                'source' => 'manual',
                'intervals' => $resolvedManualIntervals->values()->all(),
            ],
            'notes' => $notes,
            '_intervals' => $resolvedManualIntervals,
        ];
    }

    private function buildScheduleDateTime($workDate, $scheduleTime, $fallbackShiftTime): ?Carbon
    {
        $time = $scheduleTime ?? $fallbackShiftTime;
        if (! $time) {
            return null;
        }

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
