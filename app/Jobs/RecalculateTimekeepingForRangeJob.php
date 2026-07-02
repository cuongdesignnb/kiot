<?php

namespace App\Jobs;

use App\Services\TimekeepingService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecalculateTimekeepingForRangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param string $from ISO/date string
     * @param string $to ISO/date string
     * @param array<int>|int|null $employeeIds Employee ID(s) to recalculate
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly array|int|null $employeeIds = [],
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(TimekeepingService $timekeepingService): void
    {
        $from = Carbon::parse($this->from);
        $to = Carbon::parse($this->to);

        // Normalize to array
        $ids = $this->employeeIds;
        if (is_int($ids)) {
            $ids = [$ids];
        } elseif (is_null($ids)) {
            $ids = [];
        }

        $employeeIds = array_values(array_unique(array_map('intval', $ids)));
        $employeeIds = array_values(array_filter($employeeIds, fn($id) => $id > 0));

        if (empty($employeeIds)) {
            // No specific employees — recalc for all
            $timekeepingService->recalculateForRange($from, $to);
            \Log::info("RecalculateTimekeepingForRangeJob executed for ALL employees, range {$this->from} to {$this->to}");
            return;
        }

        foreach ($employeeIds as $employeeId) {
            $timekeepingService->recalculateForRange($from, $to, $employeeId);
        }

        \Log::info("RecalculateTimekeepingForRangeJob executed for " . count($employeeIds) . " employees, range {$this->from} to {$this->to}");
    }
}
