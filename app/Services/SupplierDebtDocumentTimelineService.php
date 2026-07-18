<?php

namespace App\Services;

use App\Models\Customer;
use App\Services\Debt\PartnerDebtTimelineOrientationService;

/**
 * Backward-compatible supplier endpoint adapter.
 */
class SupplierDebtDocumentTimelineService
{
    public function __construct(private readonly PartnerDebtTimelineOrientationService $timeline) {}

    public function build(Customer $supplier, array $options = []): array
    {
        return $this->timeline->supplier($supplier, $options);
    }
}
