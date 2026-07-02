<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class PayrollCashFlowClassifier
{
    public const REFERENCE_TYPES = [
        'paysheet',
        'Paysheet',
        'PaysheetPayment',
        'paysheet_payment',
        'salary_payment',
        'SalaryAdvance',
        'salary_advance',
        'payroll_payment',
        'payroll_advance',
    ];

    public const CATEGORIES = [
        'Chi lương nhân viên',
        'Chi luong nhan vien',
        'Lương nhân viên',
        'Luong nhan vien',
        'Thanh toán lương',
        'Thanh toan luong',
        'Tạm ứng lương',
        'Tam ung luong',
        'Tạm ứng nhân viên',
        'Tam ung nhan vien',
        'salary_payment',
        'salary_advance',
        'payroll_payment',
        'payroll_advance',
        'paysheet_payment',
    ];

    public const SOURCE_TYPES = [
        'paysheet_payment',
        'salary_payment',
        'salary_advance',
        'payroll_payment',
        'payroll_advance',
    ];

    public function applyPayrollRelated(Builder $query): Builder
    {
        return $query->where(function (Builder $payroll) {
            $payroll->whereIn('reference_type', self::REFERENCE_TYPES)
                ->orWhereIn('category', self::CATEGORIES);

            if (Schema::hasColumn('cash_flows', 'source_type')) {
                $payroll->orWhereIn('source_type', self::SOURCE_TYPES);
            }
        });
    }

    public function applyNonPayrollForExpense(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $reference) {
                $reference->whereNull('reference_type')
                    ->orWhereNotIn('reference_type', self::REFERENCE_TYPES);
            })
            ->where(function (Builder $category) {
                $category->whereNull('category')
                    ->orWhereNotIn('category', self::CATEGORIES);
            })
            ->when(
                Schema::hasColumn('cash_flows', 'source_type'),
                fn (Builder $source) => $source->where(function (Builder $inner) {
                    $inner->whereNull('source_type')
                        ->orWhereNotIn('source_type', self::SOURCE_TYPES);
                })
            );
    }

    public function isPayrollRelated(object|array $cashFlow): bool
    {
        $value = fn (string $key) => is_array($cashFlow)
            ? ($cashFlow[$key] ?? null)
            : ($cashFlow->{$key} ?? null);

        return in_array($value('reference_type'), self::REFERENCE_TYPES, true)
            || in_array($value('category'), self::CATEGORIES, true)
            || in_array($value('source_type'), self::SOURCE_TYPES, true);
    }
}
