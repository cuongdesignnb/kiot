<?php

namespace App\Services;

use App\Models\Customer;
use App\Services\Debt\PartnerDebtTimelineOrientationService;

/**
 * Backward-compatible customer endpoint adapter.
 */
class CustomerDebtDocumentTimelineService
{
    public function __construct(private readonly PartnerDebtTimelineOrientationService $timeline) {}

    public function build(Customer $customer, array $options = []): array
    {
        return $this->timeline->customer($customer, $options);
    }
}
