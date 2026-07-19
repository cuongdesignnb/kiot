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
    public const OWNER_CONFIRMED_DUAL_ROLE_CODES = [
        'NCC177950763826',
    ];

    public const CUSTOMER_TARGET_TYPES = [
        'Khách hàng', 'Khach hang', 'Kh??ch h??ng', 'Customer', 'customer',
    ];

    public const SUPPLIER_TARGET_TYPES = [
        'Nhà cung cấp', 'Nha cung cap', 'Supplier', 'supplier',
    ];

    public static function role(Customer $partner): string
    {
        return self::roleForSides(...self::persistedSides($partner));
    }

    public static function evidenceRole(Customer $partner): string
    {
        return self::roleForSides(...self::evidenceSides($partner));
    }

    private static function roleForSides(bool $customer, bool $supplier): string
    {
        if (! $customer && ! $supplier) {
            return 'missing_role';
        }

        return $customer && $supplier ? 'dual_role' : ($supplier ? 'supplier_only' : 'customer_only');
    }

    public static function isDualRole(Customer $partner): bool
    {
        [$customer, $supplier] = self::persistedSides($partner);

        return $customer && $supplier;
    }

    /** @return array{bool, bool} */
    public static function sides(Customer $partner): array
    {
        return self::persistedSides($partner);
    }

    /** @return array{bool, bool} */
    public static function persistedSides(Customer $partner): array
    {
        $customer = (bool) ($partner->is_customer ?? false);
        $supplier = (bool) ($partner->is_supplier ?? false);

        return [$customer, $supplier];
    }

    /**
     * Evidence is audit data only. It must never silently promote a partner's
     * persisted role in list queries or runtime timeline applicability.
     *
     * @return array{bool, bool}
     */
    public static function evidenceSides(Customer $partner): array
    {
        [$persistedCustomer, $persistedSupplier] = self::persistedSides($partner);
        if (! $partner->exists) {
            return [$persistedCustomer, $persistedSupplier];
        }

        $hasOffset = DebtOffset::query()->where('customer_id', $partner->id)->exists();
        $customer = $persistedCustomer
            || abs((float) ($partner->debt_amount ?? 0)) > 0.01
            || Invoice::query()->where('customer_id', $partner->id)->exists()
            || OrderReturn::query()->where('customer_id', $partner->id)->exists()
            || CustomerDebt::query()->where('customer_id', $partner->id)->exists()
            || $hasOffset
            || CashFlow::withTrashed()
                ->where('target_id', $partner->id)
                ->whereIn('target_type', self::CUSTOMER_TARGET_TYPES)
                ->exists();

        $supplier = $persistedSupplier
            || abs((float) ($partner->supplier_debt_amount ?? 0)) > 0.01
            || Purchase::query()->where('supplier_id', $partner->id)->exists()
            || PurchaseReturn::query()->where('supplier_id', $partner->id)->exists()
            || SupplierDebtTransaction::query()->where('supplier_id', $partner->id)->exists()
            || $hasOffset
            || CashFlow::withTrashed()
                ->where('target_id', $partner->id)
                ->whereIn('target_type', self::SUPPLIER_TARGET_TYPES)
                ->exists();

        return [$customer, $supplier];
    }

    /** @return array<string, mixed> */
    public static function integrity(Customer $partner): array
    {
        [$persistedCustomer, $persistedSupplier] = self::persistedSides($partner);
        [$evidenceCustomer, $evidenceSupplier] = self::evidenceSides($partner);
        $ownerConfirmedDual = in_array((string) ($partner->code ?? ''), self::OWNER_CONFIRMED_DUAL_ROLE_CODES, true);
        $ownerMismatch = $ownerConfirmedDual && (! $persistedCustomer || ! $persistedSupplier);
        $evidenceMismatch = ($evidenceCustomer && ! $persistedCustomer)
            || ($evidenceSupplier && ! $persistedSupplier);

        return [
            'persisted_role' => self::roleForSides($persistedCustomer, $persistedSupplier),
            'effective_role' => self::roleForSides($persistedCustomer, $persistedSupplier),
            'evidence_role' => self::roleForSides($evidenceCustomer, $evidenceSupplier),
            'persisted_customer' => $persistedCustomer,
            'persisted_supplier' => $persistedSupplier,
            'evidence_customer' => $evidenceCustomer,
            'evidence_supplier' => $evidenceSupplier,
            'owner_confirmed_role' => $ownerConfirmedDual ? 'dual_role' : null,
            'role_integrity_status' => $ownerMismatch
                ? 'OWNER_CONFIRMED_ROLE_MISMATCH'
                : ($evidenceMismatch ? 'ROLE_FLAG_EVIDENCE_MISMATCH' : 'OK'),
            'has_role_integrity_mismatch' => $ownerMismatch || $evidenceMismatch,
        ];
    }
}
