<?php

namespace Tests\Unit\Services;

use App\Models\AttendanceLog;
use App\Models\EmployeeWorkSchedule;
use App\Models\TimekeepingSetting;
use App\Services\TimekeepingService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Default behaviour: the device's In/Out label is not evidence. The real
 * device stores morning arrivals as "out" (inOutMode 4) and evening exits as
 * "in" (inOutMode 0), so punches are paired by time order and shift bounds.
 */
class TimekeepingUntrustedDirectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.attendance_agent.trust_device_direction' => false]);
    }

    private function schedule(int $id = 1, string $start = '08:30', string $end = '18:00'): EmployeeWorkSchedule
    {
        return (new EmployeeWorkSchedule([
            'employee_id' => 1, 'work_date' => '2026-10-03',
            'start_time' => $start, 'end_time' => $end, 'slot' => $id,
        ]))->forceFill(['id' => $id]);
    }

    private function resolve(array $punches, ?Collection $schedules = null, bool $infer = false): array
    {
        $logs = collect($punches)->map(fn ($punch, $index) => (new AttendanceLog([
            'employee_id' => 1,
            'punched_at' => strlen($punch[0]) > 8 ? $punch[0] : '2026-10-03 '.$punch[0],
            'event_type' => $punch[1] ?? null,
        ]))->forceFill(['id' => $index + 1]));
        $schedules ??= collect([$this->schedule()]);

        return (new \ReflectionMethod(TimekeepingService::class, 'assignPunchesToSchedules'))->invoke(
            app(TimekeepingService::class), $schedules, $logs, collect(),
            new TimekeepingSetting(['allow_multiple_shifts_one_inout' => $infer])
        );
    }

    private function times(array $row): array
    {
        return collect($row['intervals'])->map(fn ($interval) => [
            $interval['check_in_at']?->format('H:i'),
            $interval['check_out_at']?->format('H:i'),
        ])->all();
    }

    public static function deviceLabels(): array
    {
        return [
            'both labelled in (reported case)' => ['in', 'in'],
            'morning labelled out' => ['out', 'in'],
            'both labelled out' => ['out', 'out'],
            'reversed labels' => ['out', 'in'],
            'no label' => [null, null],
            'correct labels' => ['in', 'out'],
        ];
    }

    #[DataProvider('deviceLabels')]
    public function test_full_time_entry_and_exit_form_one_interval_whatever_the_device_label(?string $morning, ?string $evening): void
    {
        $row = $this->resolve([['08:46:00', $morning], ['17:02:00', $evening]])[1];

        $this->assertSame([['08:46', '17:02']], $this->times($row));
        $this->assertSame(496, (int) $row['intervals'][0]['worked_minutes']);
        $this->assertSame('complete', $row['intervals'][0]['status']);
        $this->assertFalse($row['needs_review']);
        $this->assertSame([], $row['unmatched_log_ids']);
    }

    public function test_single_morning_punch_is_an_entry_waiting_for_exit(): void
    {
        $row = $this->resolve([['08:46:00', 'out']])[1];

        $this->assertSame([['08:46', null]], $this->times($row));
        $this->assertSame(0, (int) $row['intervals'][0]['worked_minutes']);
        $this->assertSame('missing_check_out', $row['intervals'][0]['raw']['review_reason']);
        $this->assertTrue($row['needs_review']);
    }

    public function test_single_evening_punch_is_an_exit_with_missing_entry(): void
    {
        // The PR #98 case: a lone end-of-shift punch must not become an entry.
        $row = $this->resolve([['18:13:00', 'in']])[1];

        $this->assertSame([[null, '18:13']], $this->times($row));
        $this->assertSame(0, (int) $row['intervals'][0]['worked_minutes']);
        $this->assertSame('missing_check_in', $row['intervals'][0]['raw']['review_reason']);
        $this->assertTrue($row['needs_review']);
    }

    public function test_repeated_tap_is_folded_into_one_punch(): void
    {
        $row = $this->resolve([['08:46:00', 'in'], ['08:47:30', 'in'], ['17:02:00', 'in']])[1];

        $this->assertSame([['08:46', '17:02']], $this->times($row));
        $this->assertSame(496, (int) $row['intervals'][0]['worked_minutes']);
        $this->assertFalse($row['needs_review']);
        $this->assertSame([1, 2, 3], $row['intervals'][0]['raw']['log_ids']);
    }

    public function test_three_distinct_punches_keep_first_entry_and_last_exit_and_ask_for_review(): void
    {
        $row = $this->resolve([['08:30:00', 'in'], ['12:00:00', 'in'], ['18:00:00', 'in']])[1];

        $this->assertSame([['08:30', '18:00']], $this->times($row));
        $this->assertSame(570, (int) $row['intervals'][0]['worked_minutes']);
        $this->assertSame('extra_punches', $row['intervals'][0]['raw']['review_reason']);
        $this->assertSame([2], $row['intervals'][0]['raw']['extra_log_ids']);
        $this->assertTrue($row['needs_review']);
        $this->assertSame([2], $row['unmatched_log_ids']);
    }

    public function test_four_punches_with_lunch_break_are_two_pairs(): void
    {
        $row = $this->resolve([['08:30:00', 'in'], ['12:00:00', 'in'], ['13:30:00', 'out'], ['18:00:00', 'in']])[1];

        $this->assertSame([['08:30', '12:00'], ['13:30', '18:00']], $this->times($row));
        $this->assertSame(480, (int) collect($row['intervals'])->sum('worked_minutes'));
        $this->assertFalse($row['needs_review']);
    }

    public function test_two_punches_after_shift_end_are_still_not_work(): void
    {
        $row = $this->resolve([['18:00:00', 'in'], ['18:13:00', 'in']])[1];

        $this->assertSame(0, (int) collect($row['intervals'])->sum('worked_minutes'));
        $this->assertTrue($row['needs_review']);
    }

    public function test_exact_duplicate_still_needs_review(): void
    {
        $row = $this->resolve([['08:46:00', 'in'], ['08:46:00', 'in'], ['17:02:00', 'in']])[1];

        $this->assertSame([['08:46', '17:02']], $this->times($row));
        $this->assertTrue($row['needs_review']);
        $this->assertEqualsCanonicalizing([1, 2], $row['unmatched_log_ids']);
    }

    public function test_overnight_shift_pairs_across_midnight(): void
    {
        $schedules = collect([$this->schedule(1, '22:00', '06:00')]);
        $row = $this->resolve([['2026-10-03 21:55:00', 'out'], ['2026-10-04 06:05:00', 'in']], $schedules)[1];

        $this->assertSame([['21:55', '06:05']], $this->times($row));
        $this->assertSame(490, (int) $row['intervals'][0]['worked_minutes']);
        $this->assertFalse($row['needs_review']);
    }

    public function test_split_shift_still_assigns_each_pair_to_its_slot(): void
    {
        $schedules = collect([$this->schedule(1, '08:30', '12:00'), $this->schedule(2, '13:30', '18:00')]);
        $rows = $this->resolve([['08:30:00', 'out'], ['12:00:00', 'in'], ['13:30:00', 'in'], ['18:00:00', 'in']], $schedules);

        $this->assertSame([['08:30', '12:00']], $this->times($rows[1]));
        $this->assertSame([['13:30', '18:00']], $this->times($rows[2]));
        $this->assertFalse($rows[1]['needs_review']);
        $this->assertFalse($rows[2]['needs_review']);
    }

    public function test_split_shift_one_inout_inference_ignores_device_labels(): void
    {
        $schedules = collect([$this->schedule(1, '08:30', '12:00'), $this->schedule(2, '13:30', '18:00')]);
        $rows = $this->resolve([['08:30:00', 'out'], ['18:00:00', 'in']], $schedules, true);

        $this->assertSame([['08:30', '12:00']], $this->times($rows[1]));
        $this->assertSame([['13:30', '18:00']], $this->times($rows[2]));
    }
}
