<?php

namespace App\Services\Debt;

use App\Models\Customer;

class CanonicalPartnerDebtService
{
    private const SOURCE_VERSION = 'canonical-partner-event-reducer-v3';

    private const SOURCE_KIND = 'VALID_BUSINESS_DOCUMENTS';

    public function __construct(
        private readonly CanonicalPartnerDebtEventService $events,
    ) {}

    /**
     * Reduce persisted business evidence into the only read contract for debt.
     * Stored columns are projections used for comparison, never reducer input.
     */
    public function calculate(Customer $partner): array
    {
        $storedCustomer = (float) ($partner->debt_amount ?? 0);
        $storedSupplier = (float) ($partner->supplier_debt_amount ?? 0);

        // Unsaved DTO-style models are used by presentation tests. There can
        // be no persisted event stream for them, so keep a clearly labelled
        // non-canonical snapshot instead of querying unrelated database rows.
        if (! $partner->exists) {
            return $this->contract(
                $partner,
                $storedCustomer,
                $storedSupplier,
                $storedCustomer,
                $storedSupplier,
                'UNPERSISTED_PARTNER_SNAPSHOT',
                false,
                [],
            );
        }

        $stream = $this->events->build($partner);
        $affecting = $stream->where('affects_balance', true);
        $customerReceivable = (float) $affecting->sum('customer_delta');
        $supplierPayable = (float) $affecting->sum('supplier_delta');
        $events = $stream
            ->map(fn (array $event) => [
                'identity' => (string) $event['event_identity'],
                'customer_delta' => (float) $event['customer_delta'],
                'supplier_delta' => (float) $event['supplier_delta'],
                'affects_balance' => (bool) $event['affects_balance'],
            ])
            ->sortBy('identity')
            ->values()
            ->all();

        return $this->contract(
            $partner,
            $customerReceivable,
            $supplierPayable,
            $storedCustomer,
            $storedSupplier,
            self::SOURCE_KIND,
            true,
            $events,
        );
    }

    private function contract(
        Customer $partner,
        float $customerReceivable,
        float $supplierPayable,
        float $storedCustomer,
        float $storedSupplier,
        string $sourceKind,
        bool $isCanonical,
        array $events,
    ): array {
        [$isCustomer, $isSupplier] = PartnerDebtRoleResolver::sides($partner);
        $isDualRole = $isCustomer && $isSupplier;
        $netBalance = $customerReceivable - $supplierPayable;
        $supplierOrientedNet = $supplierPayable - $customerReceivable;
        $storedNet = $storedCustomer - $storedSupplier;

        if ($isDualRole) {
            $displayContract = 'net_balance';
            $rawTimelineFinal = $netBalance;
            $storedDisplay = $storedNet;
        } elseif ($isSupplier && ! $isCustomer) {
            $displayContract = 'supplier_payable';
            $rawTimelineFinal = $supplierPayable;
            $storedDisplay = $storedSupplier;
        } else {
            $displayContract = 'customer_receivable';
            $rawTimelineFinal = $customerReceivable;
            $storedDisplay = $storedCustomer;
        }

        $customerDifference = $customerReceivable - $storedCustomer;
        $supplierDifference = $supplierPayable - $storedSupplier;
        $difference = $rawTimelineFinal - $storedDisplay;
        $hasMismatch = abs($customerDifference) > 1.0 || abs($supplierDifference) > 1.0;

        return [
            'customer_receivable' => $customerReceivable,
            'supplier_payable' => $supplierPayable,
            'net_balance' => $netBalance,
            'supplier_oriented_net' => $supplierOrientedNet,
            'display_contract' => $displayContract,
            'raw_timeline_final' => $rawTimelineFinal,
            'stored_projection' => [
                'customer_receivable' => $storedCustomer,
                'supplier_payable' => $storedSupplier,
                'net_balance' => $storedNet,
                'display_balance' => $storedDisplay,
            ],
            'differences' => [
                'customer_receivable' => $customerDifference,
                'supplier_payable' => $supplierDifference,
                'display_balance' => $difference,
            ],
            'difference' => $difference,
            'has_mismatch' => $hasMismatch,

            // Compatibility aliases. All reads now resolve to canonical data.
            'customer_balance' => $customerReceivable,
            'supplier_balance' => $supplierPayable,
            'net_display_balance' => $netBalance,
            'source_kind' => $sourceKind,
            'is_canonical' => $isCanonical,
            'staleness_status' => $hasMismatch ? 'DRIFT' : 'ALIGNED',
            'calculated_at' => now()->toIso8601String(),
            'source_version' => self::SOURCE_VERSION.':'.$this->fingerprint(
                $partner,
                $customerReceivable,
                $supplierPayable,
                $events,
            ),
        ];
    }

    private function fingerprint(
        Customer $partner,
        float $customerReceivable,
        float $supplierPayable,
        array $events,
    ): string {
        $payload = json_encode([
            'partner_id' => (string) ($partner->id ?? ''),
            'customer_receivable' => number_format($customerReceivable, 4, '.', ''),
            'supplier_payable' => number_format($supplierPayable, 4, '.', ''),
            'events' => $events,
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);

        return substr(hash('sha256', (string) $payload), 0, 16);
    }
}
