<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class PayrollDateGuard
{
    public function assertAllowed($date, ?string $overrideReason = null, string $context = 'payroll'): Carbon
    {
        $eventAt = Carbon::parse($date);
        $user = auth()->user();
        $lockService = app(LockPeriodService::class);

        if ($lockService->isLocked($eventAt)) {
            if (! $user?->hasPermission('payroll.override_locked_period')) {
                throw ValidationException::withMessages([
                    'event_at' => 'Ngày nghiệp vụ nằm trong kỳ đã khóa sổ.',
                ]);
            }
            $this->requireReason($overrideReason, 'override_reason');
            $this->logOverride($context, $eventAt, $overrideReason, 'locked_period');

            return $eventAt;
        }

        $limitDays = (int) Setting::get('payroll_backdate_limit_days', 30);
        if ($eventAt->lt(now()->subDays($limitDays)->startOfDay())) {
            if (! $user?->hasPermission('payroll.override_backdate_limit')) {
                throw ValidationException::withMessages([
                    'event_at' => "Ngày nghiệp vụ vượt giới hạn lùi {$limitDays} ngày.",
                ]);
            }
            $this->requireReason($overrideReason, 'override_reason');
            $this->logOverride($context, $eventAt, $overrideReason, 'backdate_limit');
        }

        return $eventAt;
    }

    private function requireReason(?string $reason, string $field): void
    {
        if (mb_strlen(trim((string) $reason)) < 10) {
            throw ValidationException::withMessages([
                $field => 'Lý do override phải có ít nhất 10 ký tự.',
            ]);
        }
    }

    private function logOverride(string $context, Carbon $eventAt, string $reason, string $kind): void
    {
        ActivityLog::log(
            'payroll_date_override',
            "Override ngày nghiệp vụ payroll: {$context}",
            null,
            ['kind' => $kind, 'event_at' => $eventAt->toDateTimeString(), 'reason' => $reason]
        );
    }
}
