<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\TimekeepingRecord;
use App\Services\TimekeepingService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditAttendancePunchDirections extends Command
{
    protected $signature = 'attendance:audit-punch-directions {--date= : YYYY-MM-DD work date} {--from= : Range start YYYY-MM-DD (instead of --date)} {--to= : Range end YYYY-MM-DD, at most 62 days after --from} {--employee= : Optional employee code} {--details : Print every finding instead of ten}';

    protected $description = 'Read-only comparison of stored scheduled attendance with device punch directions; no payroll or attendance mutation.';

    public function handle(TimekeepingService $service): int
    {
        $valid = fn ($value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            && Carbon::canBeCreatedFromFormat($value, 'Y-m-d')
            && Carbon::createFromFormat('!Y-m-d', $value)->format('Y-m-d') === $value;
        $date = $this->option('date');
        $from = $this->option('from') ?? $date;
        $to = $this->option('to') ?? $from;
        if (! $valid($from) || ! $valid($to) || ($date && ($this->option('from') || $this->option('to')))) {
            $this->error('Provide a valid --date=YYYY-MM-DD, or --from=YYYY-MM-DD --to=YYYY-MM-DD.');

            return self::INVALID;
        }
        $fromDate = Carbon::createFromFormat('!Y-m-d', $from);
        $toDate = Carbon::createFromFormat('!Y-m-d', $to);
        if ($toDate->lt($fromDate) || $fromDate->diffInDays($toDate) > 62) {
            $this->error('--to must be on or after --from and at most 62 days later.');

            return self::INVALID;
        }
        if (DB::connection()->getDriverName() === 'mysql' && DB::transactionLevel() === 0) {
            DB::statement('SET TRANSACTION READ ONLY');
        }
        DB::beginTransaction();
        try {
            $employee = $this->option('employee') ? Employee::where('code', $this->option('employee'))->firstOrFail() : null;
            $schedules = EmployeeWorkSchedule::whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)
                ->when($employee, fn ($query) => $query->where('employee_id', $employee->id))
                ->orderBy('work_date')->orderBy('employee_id')->orderBy('slot')->get();
            if ($schedules->isEmpty()) {
                throw new \RuntimeException('No schedules found for the requested scope.');
            }
            $preview = $service->previewScheduledDevicePunches($schedules);
            $stored = TimekeepingRecord::with('intervals')->whereIn('employee_work_schedule_id', $schedules->pluck('id'))->get()->keyBy('employee_work_schedule_id');
            $findings = [];
            $manualProtected = $legacyProtected = $mismatches = $incomplete = $wouldComplete = 0;
            foreach ($schedules as $schedule) {
                $record = $stored->get($schedule->id);
                if ($record?->manual_override) {
                    $manualProtected++;

                    continue;
                }
                $resolved = $preview[$schedule->id];
                if ($record && $record->intervals->isEmpty() && $resolved['logs']->isEmpty()) {
                    $legacyProtected++;

                    continue;
                }
                $intervals = collect($resolved['intervals']);
                $normalize = fn ($time) => $time ? Carbon::parse($time)->format('Y-m-d H:i:s') : null;
                $after = [
                    'check_in_at' => $normalize($intervals->filter(fn ($interval) => $interval['check_in_at'])->sortBy('check_in_at')->first()['check_in_at'] ?? null),
                    'check_out_at' => $normalize($intervals->filter(fn ($interval) => $interval['check_out_at'])->sortByDesc('check_out_at')->first()['check_out_at'] ?? null),
                    'worked_minutes' => (int) $intervals->sum('worked_minutes'),
                    'needs_review' => $resolved['needs_review'],
                ];
                $before = [
                    'check_in_at' => $normalize($record?->check_in_at), 'check_out_at' => $normalize($record?->check_out_at),
                    'worked_minutes' => (int) ($record?->worked_minutes ?? 0), 'needs_review' => (bool) ($record?->needs_review ?? false),
                ];
                $changed = $before !== $after;
                $wouldComplete += (int) ($changed && $before['needs_review'] && ! $after['needs_review']);
                $mismatches += (int) $changed;
                $incomplete += (int) $resolved['needs_review'];
                if ($changed || $resolved['needs_review']) {
                    $findings[] = [
                        'work_date' => Carbon::parse($schedule->work_date)->toDateString(),
                        'employee_id' => $schedule->employee_id, 'schedule_id' => $schedule->id, 'record_id' => $record?->id,
                        'slot' => $schedule->slot, 'stored' => $before, 'preview' => $after,
                        'projection_mismatch' => $changed, 'log_ids' => $resolved['logs']->pluck('id')->all(),
                        'punches' => $resolved['logs']->take(20)->map(fn ($log) => [
                            'log_id' => $log->id, 'punched_at' => $normalize($log->punched_at), 'device_direction' => $log->event_type,
                        ])->values()->all(),
                        'unmatched_log_ids' => $resolved['unmatched_log_ids'],
                        'review_reasons' => $intervals->pluck('raw.review_reason')->filter()->unique()->values()->all(),
                    ];
                }
            }
            $this->line(json_encode([
                'summary' => [
                    'work_date' => $from === $to ? $from : $from.'..'.$to, 'schedules_scanned' => $schedules->count(),
                    'manual_records_protected' => $manualProtected, 'legacy_records_protected' => $legacyProtected,
                    'projection_mismatches' => $mismatches, 'needs_actual_time_confirmation' => $incomplete,
                    'review_cleared_by_recalculation' => $wouldComplete,
                    'findings_total' => count($findings), 'audit_read_only' => true,
                    'production_business_data_mutation' => 'NO',
                ],
                'findings' => $this->option('details') ? $findings : array_slice($findings, 0, 10),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            DB::rollBack();
        }
    }
}
