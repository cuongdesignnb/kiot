<?php

namespace App\Services;

use App\Models\Customer;
use App\Services\Debt\PartnerDebtTimelineOrientationService;

/**
 * Legacy facade retained for callers that have not moved to the document
 * timeline endpoint. It must not query or construct a second event stream.
 */
class PartnerFinancialTimelineService
{
    public function __construct(private readonly PartnerDebtTimelineOrientationService $timeline) {}

    public function buildForCustomer(Customer $customer): array
    {
        $payload = $this->timeline->customer($customer);

        return array_merge($payload, [
            'ledger_entries' => collect(),
            'legacy_entries' => collect(),
            'summary' => array_merge($payload['summary'], [
                'customer_receivable_balance' => $payload['customer_receivable'],
                'supplier_payable_balance' => $payload['supplier_payable'],
                'partner_net_position' => $payload['customer_receivable'] - $payload['supplier_payable'],
                'is_actual_offset' => false,
                'is_net_view' => (bool) ($payload['summary']['is_dual_role'] ?? false),
                'display_timeline_mode' => true,
                'ledger_count' => 0,
                'legacy_count' => 0,
                'supplier_count' => collect($payload['entries'])
                    ->where('domain', 'supplier')
                    ->count(),
                'dedup_skipped' => 0,
            ]),
        ]);
    }
}
