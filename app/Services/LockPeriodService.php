<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Setting;
use Carbon\Carbon;

class LockPeriodService
{
    /**
     * Get the current lock date, or null if not set.
     */
    public function getLockDate(): ?Carbon
    {
        $value = Setting::get('lock_date');
        return $value ? Carbon::parse($value)->endOfDay() : null;
    }

    /**
     * Set the lock date. Pass null to disable.
     */
    public function setLockDate(?string $date): void
    {
        $oldDate = $this->getLockDate()?->format('Y-m-d');
        $newDate = $date;

        Setting::set('lock_date', $date, 'system', 'string');

        ActivityLog::log(
            'lock_period_change',
            "Thay đổi khóa sổ: " . ($oldDate ?? 'không') . " → " . ($newDate ?? 'không'),
            null,
            ['old' => $oldDate, 'new' => $newDate]
        );
    }

    /**
     * Check if a given date falls within the locked period.
     */
    public function isLocked($date): bool
    {
        $lockDate = $this->getLockDate();
        if (!$lockDate) return false;

        $checkDate = $date instanceof Carbon ? $date : Carbon::parse($date);
        return $checkDate->endOfDay()->lte($lockDate);
    }

    /**
     * Assert that the date is NOT in the locked period. Throws on violation.
     */
    public function assertNotLocked($date, string $context = ''): void
    {
        if ($this->isLocked($date)) {
            $lockDate = $this->getLockDate()->format('d/m/Y');
            $msg = "Không thể thao tác: ngày giao dịch nằm trong kỳ khóa sổ (trước {$lockDate}).";
            if ($context) $msg .= " [{$context}]";
            throw new \App\Exceptions\LockPeriodException($msg);
        }
    }
}
