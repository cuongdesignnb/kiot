<?php

namespace App\Enums;

/**
 * Centralized payment method options for sidebar filters.
 *
 * NOTE: CashFlow stores 'bank' in DB while Purchase/Invoice/POS store 'transfer'.
 * Both mean "Chuyển khoản". We expose separate option sets so each module's
 * filter dropdown matches its actual stored values — no data migration required.
 *
 * @see STEP-24.4 audit — P0 priority action
 */
final class PaymentMethod
{
    // ── Standard values (used by Invoice, Purchase, POS, PurchaseReturn) ──
    public const CASH     = 'cash';
    public const TRANSFER = 'transfer';
    public const CARD     = 'card';
    public const EWALLET  = 'ewallet';

    // ── CashFlow-specific alias (stores 'bank' instead of 'transfer') ──
    public const BANK     = 'bank';

    /**
     * Options for Invoice / Order / Purchase / PurchaseReturn sidebar filters.
     * Values match what these modules store in their payment_method column.
     */
    public static function options(): array
    {
        return [
            ['value' => self::CASH,     'label' => 'Tiền mặt'],
            ['value' => self::TRANSFER, 'label' => 'Chuyển khoản'],
            ['value' => self::CARD,     'label' => 'Thẻ'],
            ['value' => self::EWALLET,  'label' => 'Ví điện tử'],
        ];
    }

    /**
     * Options for CashFlow sidebar filters.
     * CashFlow stores 'bank' instead of 'transfer' for chuyển khoản.
     */
    public static function cashFlowOptions(): array
    {
        return [
            ['value' => self::CASH,    'label' => 'Tiền mặt'],
            ['value' => self::BANK,    'label' => 'Chuyển khoản'],
            ['value' => self::EWALLET, 'label' => 'Ví điện tử'],
        ];
    }

    /**
     * Subset for modules that only support cash/transfer (Purchase, PurchaseReturn, POS).
     */
    public static function basicOptions(): array
    {
        return [
            ['value' => self::CASH,     'label' => 'Tiền mặt'],
            ['value' => self::TRANSFER, 'label' => 'Chuyển khoản'],
        ];
    }

    /**
     * All valid values for validation rules.
     */
    public static function validationRule(): string
    {
        return 'in:cash,transfer,card,ewallet,bank';
    }
}
