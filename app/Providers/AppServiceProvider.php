<?php

namespace App\Providers;

use App\Models\TimekeepingRecord;
use App\Models\EmployeeSalarySetting;
use App\Models\Holiday;
use App\Models\WorkdaySetting;
use App\Models\PayrollSetting;
use App\Observers\TimekeepingRecordObserver;
use App\Observers\SalarySettingObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ===== Payroll Auto-Recalc Observers =====
        // Khi dữ liệu liên quan lương thay đổi → đánh dấu paysheet cần tính lại
        TimekeepingRecord::observe(TimekeepingRecordObserver::class);

        $salaryObserver = new SalarySettingObserver();
        EmployeeSalarySetting::updated(fn($m) => $salaryObserver->updatedSalarySetting($m));
        Holiday::created(fn($m) => $salaryObserver->createdHoliday($m));
        Holiday::updated(fn($m) => $salaryObserver->updatedHoliday($m));
        Holiday::deleted(fn($m) => $salaryObserver->deletedHoliday($m));
        WorkdaySetting::updated(fn($m) => $salaryObserver->updatedWorkday($m));
        PayrollSetting::updated(fn($m) => $salaryObserver->updatedPayroll($m));
    }
}
