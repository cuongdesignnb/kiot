<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PartnerTransactionGuard
{
    public function assertCanTransact(?int $partnerId, string $field = 'partner_id'): ?Customer
    {
        if (!$partnerId) {
            return null;
        }

        $query = Customer::query()->with('mergedInto:id,code');
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $partner = $query->findOrFail($partnerId);
        if ($partner->merged_into_id !== null) {
            $targetCode = $partner->mergedInto?->code ?? ('#' . $partner->merged_into_id);

            throw ValidationException::withMessages([
                $field => "Đối tác này đã được gộp vào {$targetCode}. Vui lòng chọn đối tác đích.",
            ]);
        }

        return $partner;
    }

    public function availablePartners(): Builder
    {
        return Customer::query()
            ->whereNull('merged_into_id')
            ->where(function (Builder $query) {
                $query->whereNull('status')->orWhere('status', '!=', 'inactive');
            });
    }
}
