<?php

namespace App\Observers;

use App\Models\TimekeepingRecord;

class TimekeepingRecordObserver
{
    protected PayrollDataObserver $payrollObserver;

    public function __construct()
    {
        $this->payrollObserver = new PayrollDataObserver();
    }

    public function created(TimekeepingRecord $record): void
    {
        $this->payrollObserver->timekeepingCreated($record);
    }

    public function updated(TimekeepingRecord $record): void
    {
        $this->payrollObserver->timekeepingUpdated($record);
    }

    public function deleted(TimekeepingRecord $record): void
    {
        $this->payrollObserver->timekeepingDeleted($record);
    }
}
