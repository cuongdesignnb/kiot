<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCreatorTaskAssignments extends Command
{
    protected $signature = 'tasks:backfill-creator-assignments {--commit : Write missing creator assignments}';

    protected $description = 'Dry-run or backfill pending creator task assignments for My Tasks.';

    public function handle(TaskService $service): int
    {
        $commit = (bool) $this->option('commit');
        $stats = [
            'eligible' => 0,
            'created' => 0,
            'skipped_no_creator_employee' => 0,
            'skipped_has_assignment' => 0,
            'skipped_assigned_to_other' => 0,
        ];

        $this->info($commit
            ? 'Running creator assignment backfill with --commit.'
            : 'Dry-run only. No data will be changed. Re-run with --commit to write.');

        $query = Task::query()
            ->whereIn('status', [Task::STATUS_PENDING, Task::STATUS_IN_PROGRESS])
            ->orderBy('id');

        $query->chunkById(200, function ($tasks) use ($service, $commit, &$stats) {
            foreach ($tasks as $task) {
                if ($task->assignments()->exists()) {
                    $stats['skipped_has_assignment']++;
                    continue;
                }

                $employee = $task->created_by
                    ? Employee::where('user_id', $task->created_by)->where('is_active', true)->first()
                    : null;

                if (!$employee) {
                    $stats['skipped_no_creator_employee']++;
                    continue;
                }

                if ($task->assigned_employee_id && (int) $task->assigned_employee_id !== (int) $employee->id) {
                    $stats['skipped_assigned_to_other']++;
                    continue;
                }

                $stats['eligible']++;

                if ($commit) {
                    DB::transaction(function () use ($service, $task, $employee) {
                        $service->ensureCreatorAssignment($task, $employee->id, $task->created_by);
                    });
                    $stats['created']++;
                }
            }
        });

        $this->table(
            ['Metric', 'Count'],
            collect($stats)->map(fn ($count, $name) => [$name, $count])->all()
        );

        if (!$commit) {
            $this->warn('Dry-run completed. Database was not changed.');
        }

        return self::SUCCESS;
    }
}
