<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\EmployeeSalarySetting;
use App\Models\Holiday;
use App\Models\PayrollSetting;
use App\Models\TimekeepingRecord;
use App\Models\WorkdaySetting;
use App\Observers\SalarySettingObserver;
use App\Observers\TimekeepingRecordObserver;
use App\Services\Debt\PartnerDebtMutationCoordinator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Nested debt services must share the outer mutation context.
        $this->app->singleton(PartnerDebtMutationCoordinator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every `{customer}` endpoint is a customer-screen route. Persisted
        // role is the only admission rule; supplier evidence, history, code
        // prefixes and debt values must never promote a supplier-only row.
        Route::bind('customer', function (string $value): Customer {
            $customer = new Customer;

            return Customer::query()
                ->where('is_customer', true)
                ->where($customer->getRouteKeyName(), $value)
                ->firstOrFail();
        });
        Route::bind('supplier', function (string $value): Customer {
            $supplier = new Customer;

            return Customer::query()
                ->where('is_supplier', true)
                ->where($supplier->getRouteKeyName(), $value)
                ->firstOrFail();
        });

        // ===== Payroll Auto-Recalc Observers =====
        // Khi dữ liệu liên quan lương thay đổi → đánh dấu paysheet cần tính lại
        TimekeepingRecord::observe(TimekeepingRecordObserver::class);

        $salaryObserver = new SalarySettingObserver;
        EmployeeSalarySetting::updated(fn ($m) => $salaryObserver->updatedSalarySetting($m));
        Holiday::created(fn ($m) => $salaryObserver->createdHoliday($m));
        Holiday::updated(fn ($m) => $salaryObserver->updatedHoliday($m));
        Holiday::deleted(fn ($m) => $salaryObserver->deletedHoliday($m));
        WorkdaySetting::updated(fn ($m) => $salaryObserver->updatedWorkday($m));
        PayrollSetting::updated(fn ($m) => $salaryObserver->updatedPayroll($m));
    }
}
