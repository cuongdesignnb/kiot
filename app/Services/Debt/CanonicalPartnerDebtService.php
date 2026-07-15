<?php

namespace App\Services\Debt;

use App\Models\Customer;

class CanonicalPartnerDebtService
{
    private const SOURCE_VERSION = 'stored-cache-v1';

    /**
     * Return the single read contract for partner debt balances.
     *
     * The two stored fields remain a compatibility cache until the ledger,
     * opening-balance and supplier-allocation schemas are approved.
     */
    public function calculate(Customer $partner): array
    {
        $customerBalance = (float) ($partner->debt_amount ?? 0);
        $supplierBalance = (float) ($partner->supplier_debt_amount ?? 0);

        return [
            'customer_balance' => $customerBalance,
            'supplier_balance' => $supplierBalance,
            'net_display_balance' => $customerBalance - $supplierBalance,
            'calculated_at' => now()->toIso8601String(),
            'source_version' => self::SOURCE_VERSION.':'.$this->fingerprint(
                $partner,
                $customerBalance,
                $supplierBalance,
            ),
        ];
    }

    private function fingerprint(Customer $partner, float $customerBalance, float $supplierBalance): string
    {
        $payload = implode('|', [
            (string) ($partner->id ?? ''),
            number_format($customerBalance, 4, '.', ''),
            number_format($supplierBalance, 4, '.', ''),
        ]);

        return substr(hash('sha256', $payload), 0, 16);
    }
}
