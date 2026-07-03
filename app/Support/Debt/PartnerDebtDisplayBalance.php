<?php

namespace App\Support\Debt;

use App\Models\Customer;

class PartnerDebtDisplayBalance
{
    public static function customerReceivable(Customer $partner): float
    {
        return (float) ($partner->debt_amount ?? 0);
    }

    public static function supplierPayable(Customer $partner): float
    {
        return (float) ($partner->supplier_debt_amount ?? 0);
    }

    public static function isDualRole(Customer $partner): bool
    {
        return (bool) (($partner->is_customer ?? false) && ($partner->is_supplier ?? false));
    }

    public static function customerScreen(Customer $partner): float
    {
        return self::customerReceivable($partner) - self::supplierPayable($partner);
    }

    public static function supplierScreen(Customer $partner): float
    {
        $payable = self::supplierPayable($partner);

        return self::isDualRole($partner)
            ? $payable - self::customerReceivable($partner)
            : $payable;
    }

    public static function aliases(Customer $partner): array
    {
        $receivable = self::customerReceivable($partner);
        $payable = self::supplierPayable($partner);
        $customerScreen = self::customerScreen($partner);
        $supplierScreen = self::supplierScreen($partner);

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
            'supplier_list_debt_amount' => $supplierScreen,
            'is_dual_role' => self::isDualRole($partner),
            'is_dual_role_partner' => self::isDualRole($partner),
        ];
    }

    public static function responseAliases(Customer $partner): array
    {
        return self::aliases($partner);
    }
}
