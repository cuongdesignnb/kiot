<?php

namespace App\Observers;

use App\Models\EmployeeSalarySetting;
use App\Models\Holiday;
use App\Models\WorkdaySetting;
use App\Models\PayrollSetting;

class SalarySettingObserver
{
    protected PayrollDataObserver $payrollObserver;

    public function __construct()
    {
        $this->payrollObserver = new PayrollDataObserver();
    }

    // ===== EmployeeSalarySetting =====
    public function updatedSalarySetting(EmployeeSalarySetting $setting): void
    {
        $this->payrollObserver->salarySettingUpdated($setting);
    }

    // ===== Holiday =====
    public function createdHoliday(Holiday $holiday): void
    {
        $this->payrollObserver->holidayChanged($holiday);
    }

    public function updatedHoliday(Holiday $holiday): void
    {
        $this->payrollObserver->holidayChanged($holiday);
    }

    public function deletedHoliday(Holiday $holiday): void
    {
        $this->payrollObserver->holidayChanged($holiday);
    }

    // ===== WorkdaySetting =====
    public function updatedWorkday(WorkdaySetting $setting): void
    {
        $this->payrollObserver->workdaySettingUpdated($setting);
    }

    // ===== PayrollSetting =====
    public function updatedPayroll(PayrollSetting $setting): void
    {
        $this->payrollObserver->payrollSettingUpdated($setting);
    }
}
