<?php

namespace App\Services\Debt;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SupplierDebtTransaction;

class PartnerDebtRoleResolver
{
    public const CUSTOMER_TARGET_TYPES = [
        'Khách hàng', 'Khach hang', 'Kh??ch h??ng', 'Customer', 'customer',
    ];

    public const SUPPLIER_TARGET_TYPES = [
        'Nhà cung cấp', 'Nha cung cap', 'Supplier', 'supplier',
    ];

    public static function role(Customer $partner): string
    {
        [$customer, $supplier] = self::sides($partner);

        return $customer && $supplier ? 'dual_role' : ($supplier ? 'supplier_only' : 'customer_only');
    }

    public static function isDualRole(Customer $partner): bool
    {
        [$customer, $supplier] = self::sides($partner);

        return $customer && $supplier;
    }

    /** @return array{bool, bool} */
    public static function sides(Customer $partner): array
    {
        $customer = (bool) ($partner->is_customer ?? false);
        $supplier = (bool) ($partner->is_supplier ?? false);

        if (! $partner->exists || ($customer && $supplier)) {
            return [$customer, $supplier];
        }

        if (! $customer) {
            $customer = Invoice::query()->where('customer_id', $partner->id)->exists()
                || OrderReturn::query()->where('customer_id', $partner->id)->exists()
                || CustomerDebt::query()->where('customer_id', $partner->id)->exists()
                || DebtOffset::query()->where('customer_id', $partner->id)->exists()
                || CashFlow::withTrashed()
                    ->where('target_id', $partner->id)
                    ->whereIn('target_type', self::CUSTOMER_TARGET_TYPES)
                    ->exists();
        }

        if (! $supplier) {
            $supplier = Purchase::query()->where('supplier_id', $partner->id)->exists()
                || PurchaseReturn::query()->where('supplier_id', $partner->id)->exists()
                || SupplierDebtTransaction::query()->where('supplier_id', $partner->id)->exists()
                || DebtOffset::query()->where('customer_id', $partner->id)->exists()
                || CashFlow::withTrashed()
                    ->where('target_id', $partner->id)
                    ->whereIn('target_type', self::SUPPLIER_TARGET_TYPES)
                    ->exists();
        }

        return [$customer, $supplier];
    }
}
