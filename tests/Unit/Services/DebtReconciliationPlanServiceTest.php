<?php

namespace Tests\Unit\Services;

use App\Services\Debt\DebtReconciliationPlanService;
use PHPUnit\Framework\TestCase;

class DebtReconciliationPlanServiceTest extends TestCase
{
    private DebtReconciliationPlanService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DebtReconciliationPlanService();
    }

    public function test_clean_row_proposes_no_action(): void
    {
        $plan = $this->service->planRow($this->row('OK'));

        $this->assertSame('NO_ACTION', $plan['proposed_action_type']);
        $this->assertFalse($plan['requires_backup']);
        $this->assertFalse($plan['requires_manual_approval']);
        $this->assertSame('PROPOSED', $plan['status']);
    }

    public function test_uncertain_source_of_truth_is_blocked(): void
    {
        $plan = $this->service->planRow($this->row('CUSTOMER_STORED_VS_DOCUMENT'));

        $this->assertSame('BLOCKED_UNCERTAIN_SOURCE_OF_TRUTH', $plan['proposed_action_type']);
        $this->assertSame(0.0, $plan['customer_delta']);
        $this->assertSame(0.0, $plan['supplier_delta']);
        $this->assertNull($plan['proposed_voucher']);
        $this->assertTrue($plan['requires_backup']);
        $this->assertTrue($plan['requires_manual_approval']);
    }

    public function test_virtual_opening_is_review_only_not_an_apply_instruction(): void
    {
        $plan = $this->service->planRow($this->row('VIRTUAL_OPENING_REQUIRED'));

        $this->assertSame('OPENING_BALANCE_REVIEW_ONLY', $plan['proposed_action_type']);
        $this->assertNull($plan['proposed_voucher']);
        $this->assertSame('low', $plan['confidence']);
    }

    public function test_duplicate_fallback_is_code_review_with_zero_data_delta(): void
    {
        $plan = $this->service->planRow($this->row('DUPLICATE_REAL_AND_FALLBACK'));

        $this->assertSame('CODE_REVIEW_REQUIRED', $plan['proposed_action_type']);
        $this->assertSame(0.0, $plan['customer_delta']);
        $this->assertSame(0.0, $plan['supplier_delta']);
        $this->assertFalse($plan['requires_backup']);
    }

    public function test_every_supported_plan_remains_proposal_only_with_zero_delta(): void
    {
        $plans = $this->service->generate([
            $this->row('OK'),
            $this->row('TECHNICAL_LEDGER_EXCLUDED'),
            $this->row('VIRTUAL_OPENING_REQUIRED'),
            $this->row('SUPPLIER_STORED_VS_DOCUMENT'),
        ]);

        foreach ($plans as $plan) {
            $this->assertSame('PROPOSED', $plan['status']);
            $this->assertSame(0.0, $plan['customer_delta']);
            $this->assertSame(0.0, $plan['supplier_delta']);
            $this->assertNull($plan['proposed_voucher']);
        }
    }

    private function row(string $classification): array
    {
        return [
            'partner_id' => 1,
            'partner_code' => 'PARTNER-001',
            'role' => 'customer_only',
            'risk_level' => $classification === 'OK' ? 'OK' : 'HIGH',
            'primary_classification' => $classification,
            'classification_flags' => [$classification],
        ];
    }
}
