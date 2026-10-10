<?php

namespace Tests\Unit\Services;

use App\Models\AttendanceLog;
use App\Models\EmployeeWorkSchedule;
use App\Models\TimekeepingSetting;
use App\Services\TimekeepingService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TimekeepingPunchDirectionTest extends TestCase
{
    private function schedule(int $id = 1, string $start = '08:30', string $end = '18:00'): EmployeeWorkSchedule
    {
        return new EmployeeWorkSchedule([
            'id' => $id, 'employee_id' => 1, 'work_date' => '2030-05-06',
            'start_time' => $start, 'end_time' => $end, 'slot' => $id,
        ]);
    }

    private function resolve(array $punches, ?Collection $schedules = null, bool $infer = false): array
    {
        $logs = collect($punches)->map(fn ($punch, $index) => (new AttendanceLog([
            'employee_id' => 1,
            'punched_at' => strlen($punch[0]) > 8 ? $punch[0] : '2030-05-06 '.$punch[0],
            'event_type' => $punch[1],
        ]))->forceFill(['id' => $index + 1]));
        $schedules ??= collect([$this->schedule()->forceFill(['id' => 1])]);
        $originalLogs = $logs->map(fn ($log) => $log->getAttributes())->all();
        $result = (new \ReflectionMethod(TimekeepingService::class, 'assignPunchesToSchedules'))->invoke(
            app(TimekeepingService::class), $schedules, $logs, collect(),
            new TimekeepingSetting(['allow_multiple_shifts_one_inout' => $infer])
        );
        $this->assertSame($originalLogs, $logs->map(fn ($log) => $log->getAttributes())->all());

        return $result;
    }

    public static function orphanPunches(): array
    {
        return [
            'untyped after shift end' => ['18:13:00', null, null, '18:13:00'],
            'untyped near shift end' => ['17:55:00', 'punch', null, '17:55:00'],
            'untyped morning' => ['08:35:00', null, '08:35:00', null],
            'explicit checkout during shift' => ['10:00:00', 'out', null, '10:00:00'],
            'explicit late arrival' => ['18:13:00', 'in', '18:13:00', null],
        ];
    }

    #[DataProvider('orphanPunches')]
    public function test_orphan_punch_retains_its_direction_without_inventing_work(string $time, ?string $type, ?string $expectedIn, ?string $expectedOut): void
    {
        $row = $this->resolve([[$time, $type]])[1];
        $interval = $row['intervals'][0];
        $this->assertSame($expectedIn, $interval['check_in_at']?->format('H:i:s'));
        $this->assertSame($expectedOut, $interval['check_out_at']?->format('H:i:s'));
        $this->assertSame(0, $interval['worked_minutes']);
        $this->assertSame(0, $interval['regular_minutes']);
        $this->assertSame(0, $interval['ot_minutes']);
        $this->assertTrue($row['needs_review']);
        $this->assertSame([1], $row['unmatched_log_ids']);
    }

    public function test_explicit_orphan_checkout_does_not_shift_later_real_pairs(): void
    {
        $row = $this->resolve([['12:00:00', 'out'], ['13:30:00', 'in'], ['18:00:00', 'out']])[1];
        $this->assertCount(2, $row['intervals']);
        $this->assertNull($row['intervals'][0]['check_in_at']);
        $this->assertSame('12:00', $row['intervals'][0]['check_out_at']->format('H:i'));
        $this->assertSame('13:30', $row['intervals'][1]['check_in_at']->format('H:i'));
        $this->assertSame(270, (int) collect($row['intervals'])->sum('worked_minutes'));
        $this->assertTrue($row['needs_review']);
        $this->assertSame([1], $row['unmatched_log_ids']);
    }

    public function test_two_checkouts_are_never_counted_as_work(): void
    {
        foreach (['out', null] as $type) {
            $row = $this->resolve([['18:00:00', $type], ['18:13:00', $type]])[1];
            $this->assertSame(0, (int) collect($row['intervals'])->sum('worked_minutes'));
            $this->assertTrue(collect($row['intervals'])->every(fn ($interval) => $interval['check_in_at'] === null));
            $this->assertTrue($row['needs_review']);
            $this->assertSame([1, 2], $row['unmatched_log_ids']);
        }
    }

    public function test_two_entries_do_not_turn_the_second_entry_into_a_checkout(): void
    {
        $row = $this->resolve([['08:30:00', 'in'], ['09:00:00', 'in'], ['18:00:00', 'out']])[1];
        $this->assertCount(2, $row['intervals']);
        $this->assertNull($row['intervals'][0]['check_out_at']);
        $this->assertSame('09:00', $row['intervals'][1]['check_in_at']->format('H:i'));
        $this->assertSame(540, (int) collect($row['intervals'])->sum('worked_minutes'));
        $this->assertTrue($row['needs_review']);
    }

    public function test_complete_untyped_pairs_and_lunch_break_still_work(): void
    {
        $row = $this->resolve([['08:30:00', null], ['12:00:00', null], ['13:30:00', null], ['18:00:00', null]])[1];
        $this->assertSame([210, 270], collect($row['intervals'])->pluck('worked_minutes')->map(fn ($value) => (int) $value)->all());
        $this->assertFalse($row['needs_review']);
    }

    public function test_split_shift_explicit_checkout_before_end_is_not_an_entry(): void
    {
        $schedules = collect([
            $this->schedule(1, '08:30', '12:00')->forceFill(['id' => 1]),
            $this->schedule(2, '13:30', '18:00')->forceFill(['id' => 2]),
        ]);
        $rows = $this->resolve([['11:55:00', 'out'], ['13:30:00', 'in'], ['18:00:00', 'out']], $schedules);
        $this->assertNull($rows[1]['intervals'][0]['check_in_at']);
        $this->assertSame('11:55', $rows[1]['intervals'][0]['check_out_at']->format('H:i'));
        $this->assertSame(0, $rows[1]['intervals'][0]['worked_minutes']);
        $this->assertSame(270, (int) $rows[2]['intervals'][0]['worked_minutes']);
        $this->assertFalse($rows[2]['needs_review']);
    }

    public function test_multiple_slot_inference_requires_a_real_entry_and_exit(): void
    {
        $schedules = collect([
            $this->schedule(1, '08:30', '12:00')->forceFill(['id' => 1]),
            $this->schedule(2, '13:30', '18:00')->forceFill(['id' => 2]),
        ]);
        $rows = $this->resolve([['11:55:00', 'out'], ['18:00:00', 'out']], $schedules, true);
        $this->assertSame(0, (int) collect($rows)->sum(fn ($row) => collect($row['intervals'])->sum('worked_minutes')));
        $this->assertTrue(collect($rows)->every(fn ($row) => $row['needs_review']));
    }

    public function test_overnight_orphan_is_compared_with_absolute_shift_end(): void
    {
        $schedules = collect([$this->schedule(1, '22:00', '06:00')->forceFill(['id' => 1])]);
        $row = $this->resolve([['2030-05-07 06:13:00', null]], $schedules)[1];
        $this->assertNull($row['intervals'][0]['check_in_at']);
        $this->assertSame('2030-05-07 06:13:00', $row['intervals'][0]['check_out_at']->format('Y-m-d H:i:s'));
        $this->assertSame(0, $row['intervals'][0]['worked_minutes']);
        $this->assertTrue($row['needs_review']);
    }

    public function test_duplicates_are_reviewed_and_do_not_create_work_minutes(): void
    {
        $row = $this->resolve([['18:13:00', null], ['18:13:00', null]])[1];
        $this->assertTrue($row['needs_review']);
        $this->assertSame(0, (int) collect($row['intervals'])->sum('worked_minutes'));
        $this->assertEqualsCanonicalizing([1, 2], $row['unmatched_log_ids']);
    }
}
