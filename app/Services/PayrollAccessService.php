<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

class PayrollAccessService
{
    public function assertEmployee(Employee $employee): void
    {
        $user = auth()->user();
        if (! $user || $user->isAdmin()) {
            return;
        }

        abort_unless(
            $employee->branch_id && in_array($employee->branch_id, $user->getAccessibleBranchIds(), true),
            403
        );
    }

    public function assertBranch(?int $branchId): void
    {
        $user = auth()->user();
        if (! $user || $user->isAdmin()) {
            return;
        }

        abort_unless($branchId && in_array($branchId, $user->getAccessibleBranchIds(), true), 403);
    }

    public function scopeEmployees(Builder $query): Builder
    {
        $user = auth()->user();
        if (! $user || $user->isAdmin()) {
            return $query;
        }

        return $query->whereIn('branch_id', $user->getAccessibleBranchIds());
    }
}
