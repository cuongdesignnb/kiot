<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\TimekeepingRecord;
use App\Models\EmployeeWorkSchedule;
use App\Services\TimekeepingService;
use Carbon\Carbon;

class AuditManualWorkUnits extends Command
{
    protected $signature = 'timekeeping:audit-manual-work-units
                            {--from= : Từ ngày YYYY-MM-DD}
                            {--to= : Đến ngày YYYY-MM-DD}
                            {--employee= : Tên nhân viên lọc}
                            {--apply : Thực hiện cập nhật thật vào database}';

    protected $description = 'Audit và cập nhật work_units cho các record chấm công thủ công (manual)';

    public function __construct(private readonly TimekeepingService $timekeepingService)
    {
        parent::__construct();
    }

    public function handle()
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $employeeOpt = $this->option('employee');
        $apply = $this->option('apply');

        if (!$from || !$to) {
            $this->error('Vui lòng cung cấp --from và --to (định dạng YYYY-MM-DD)');
            return 1;
        }

        $query = TimekeepingRecord::with(['employee', 'schedule.shift'])
            ->where('source', 'manual')
            ->where('manual_override', true)
            ->whereBetween('work_date', [$from, $to]);

        if ($employeeOpt) {
            $query->whereHas('employee', function($q) use ($employeeOpt) {
                $q->where('name', 'like', "%{$employeeOpt}%")
                  ->orWhere('code', 'like', "%{$employeeOpt}%");
            });
        }

        $records = $query->get();

        if ($records->isEmpty()) {
            $this->info('Không tìm thấy bản ghi chấm công thủ công nào trong khoảng thời gian trên.');
            return 0;
        }

        $headers = [
            'Record ID', 'Nhân viên', 'Ngày', 'Vào/Ra',
            'Min Cũ/Mới', 'Unit Cũ/Mới', 'Multiplier Cũ/Mới', 'Sửa?', 'Lý do'
        ];
        $rows = [];
        $updatedCount = 0;

        foreach ($records as $record) {
            $schedule = $record->schedule;
            if (!$schedule) {
                $rows[] = [
                    $record->id,
                    $record->employee->name ?? 'N/A',
                    $record->work_date->toDateString(),
                    $this->formatInOut($record),
                    "{$record->worked_minutes}/-",
                    "{$record->work_units}/-",
                    "{$record->holiday_multiplier}/-",
                    'Không',
                    'Không tìm thấy lịch làm việc (schedule)'
                ];
                continue;
            }

            $proposed = $this->timekeepingService->buildManualRecordAttributes(
                $schedule,
                $record->attendance_type,
                $record->check_in_at ? Carbon::parse($record->check_in_at)->format('H:i') : null,
                $record->check_out_at ? Carbon::parse($record->check_out_at)->format('H:i') : null,
                $record->ot_minutes,
                $record->notes
            );

            $canUpdate = false;
            $reasons = [];

            if ((float)$record->work_units !== (float)$proposed['work_units']) {
                $canUpdate = true;
                $reasons[] = "Đổi công: {$record->work_units} -> {$proposed['work_units']}";
            }
            if ((int)$record->worked_minutes !== (int)$proposed['worked_minutes']) {
                $canUpdate = true;
                $reasons[] = "Đổi phút làm: {$record->worked_minutes} -> {$proposed['worked_minutes']}";
            }
            if ((bool)$record->is_holiday !== (bool)$proposed['is_holiday']) {
                $canUpdate = true;
                $reasons[] = "Đổi ngày lễ: " . ($record->is_holiday ? 'Có' : 'Không') . " -> " . ($proposed['is_holiday'] ? 'Có' : 'Không');
            }
            if ((float)$record->holiday_multiplier !== (float)$proposed['holiday_multiplier']) {
                $canUpdate = true;
                $reasons[] = "Đổi hệ số: {$record->holiday_multiplier} -> {$proposed['holiday_multiplier']}";
            }

            if ($canUpdate) {
                if ($apply) {
                    $record->update($proposed);
                    $updatedCount++;
                    $status = 'Đã sửa';
                } else {
                    $status = 'Có thể sửa';
                }
            } else {
                $status = 'Khớp';
                $reasons[] = 'Không có thay đổi';
            }

            $rows[] = [
                $record->id,
                $record->employee->name,
                $record->work_date->toDateString(),
                $this->formatInOut($record),
                "{$record->worked_minutes} -> {$proposed['worked_minutes']}",
                "{$record->work_units} -> {$proposed['work_units']}",
                "{$record->holiday_multiplier} -> {$proposed['holiday_multiplier']}",
                $status,
                implode(', ', $reasons)
            ];
        }

        $this->table($headers, $rows);

        if ($apply) {
            $this->info("Đã cập nhật thành công {$updatedCount} bản ghi chấm công thủ công.");
        } else {
            $this->info("Chế độ DRY-RUN: Chạy lại với --apply để lưu thay đổi thực tế.");
        }

        return 0;
    }

    private function formatInOut($record): string
    {
        $in = $record->check_in_at ? Carbon::parse($record->check_in_at)->format('H:i') : '--';
        $out = $record->check_out_at ? Carbon::parse($record->check_out_at)->format('H:i') : '--';
        return "{$in} - {$out}";
    }
}
