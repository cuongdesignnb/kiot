<?php

namespace App\Support\Debt;

use App\Models\Customer;
use App\Services\Debt\CanonicalPartnerDebtService;
use App\Services\Debt\PartnerDebtRoleResolver;

class PartnerDebtDisplayBalance
{
    public static function customerReceivable(Customer $partner): float
    {
        return self::canonical($partner)['customer_balance'];
    }

    public static function supplierPayable(Customer $partner): float
    {
        return self::canonical($partner)['supplier_balance'];
    }

    public static function isDualRole(Customer $partner): bool
    {
        return PartnerDebtRoleResolver::isDualRole($partner);
    }

    public static function customerScreen(Customer $partner): float
    {
        if (! (bool) $partner->is_customer) {
            return 0.0;
        }

        $canonical = self::canonical($partner);

        return self::isDualRole($partner)
            ? $canonical['net_display_balance']
            : $canonical['customer_balance'];
    }

    public static function supplierScreen(Customer $partner): float
    {
        if (! (bool) $partner->is_supplier) {
            return 0.0;
        }

        $canonical = self::canonical($partner);

        return self::isDualRole($partner)
            ? -$canonical['net_display_balance']
            : $canonical['supplier_balance'];
    }

    public static function aliases(Customer $partner): array
    {
        $canonical = self::canonical($partner);
        $receivable = $canonical['customer_balance'];
        $payable = $canonical['supplier_balance'];
        [$isCustomer, $isSupplier] = PartnerDebtRoleResolver::sides($partner);
        $isDualRole = $isCustomer && $isSupplier;
        $customerScreen = ! $isCustomer ? 0.0 : ($isDualRole ? $canonical['net_display_balance'] : $receivable);
        $supplierScreen = ! $isSupplier ? 0.0 : ($isDualRole ? -$canonical['net_display_balance'] : $payable);

        return [
            'customer_receivable_balance' => $receivable,
            'supplier_payable_balance' => $payable,
            'partner_net_position' => $customerScreen,
            'customer_screen_debt' => $customerScreen,
            'customer_display_balance' => $customerScreen,
            'customer_oriented_balance' => $customerScreen,
            'supplier_screen_debt' => $supplierScreen,
            'supplier_oriented_balance' => $supplierScreen,
            'supplier_display_balance' => $supplierScreen,
            'supplier_picker_display_balance' => $supplierScreen,
            'supplier_list_debt_amount' => $supplierScreen,
            'is_dual_role' => $isDualRole,
            'is_dual_role_partner' => $isDualRole,
            'debt_display_contract' => $canonical['display_contract'],
            'debt_raw_timeline_final' => $canonical['raw_timeline_final'],
            'debt_stored_projection' => $canonical['stored_projection'],
            'debt_difference' => $canonical['difference'],
            'debt_has_mismatch' => $canonical['has_mismatch'],
        ];
    }

    public static function responseAliases(Customer $partner): array
    {
        return self::aliases($partner);
    }

    private static function canonical(Customer $partner): array
    {
        return app(CanonicalPartnerDebtService::class)->calculate($partner);
    }
}
