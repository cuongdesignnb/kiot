<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Services\Debt\CanonicalPartnerDebtService;
use App\Services\Debt\PartnerDebtInvariantChecker;
use App\Services\Debt\PartnerDebtParityAuditService;
use Mockery;
use Tests\TestCase;

class PartnerDebtInvariantCheckerTest extends TestCase
{
    public function test_it_reports_the_largest_difference_without_mutating_the_partner(): void
    {
        $partner = new Customer;
        $partner->forceFill([
            'id' => 210,
            'code' => 'NCC210',
            'name' => 'Dual Role Partner',
            'debt_amount' => 12_300_000,
            'supplier_debt_amount' => 2_700_000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);
        $audit = Mockery::mock(PartnerDebtParityAuditService::class);
        $audit->shouldReceive('audit')->once()->with($partner)->andReturn([
            'role' => 'dual_role',
            'primary_classification' => 'CUSTOMER_STORED_VS_DOCUMENT',
            'classification_flags' => ['CUSTOMER_STORED_VS_DOCUMENT', 'MULTIPLE_MISMATCHES'],
            'risk_level' => 'HIGH',
            'customer_stored_vs_document_raw' => 3_800_000,
            'supplier_stored_vs_document_raw' => -500_000,
            'audit_error' => null,
        ]);
        $before = $partner->getAttributes();

        $result = (new PartnerDebtInvariantChecker(
            app(CanonicalPartnerDebtService::class),
            $audit,
        ))->check($partner);

        $this->assertTrue($result['drift_detected']);
        $this->assertSame(3_800_000.0, $result['difference']);
        $this->assertSame('CUSTOMER_STORED_VS_DOCUMENT', $result['root_cause']);
        $this->assertSame(9_600_000.0, $result['net_display_balance']);
        $this->assertSame($before, $partner->getAttributes());
    }

    public function test_ok_audit_is_not_reported_as_drift(): void
    {
        $partner = new Customer;
        $partner->forceFill([
            'id' => 1,
            'code' => 'KH001',
            'name' => 'Customer',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
        ]);
        $audit = Mockery::mock(PartnerDebtParityAuditService::class);
        $audit->shouldReceive('audit')->once()->andReturn([
            'role' => 'customer_only',
            'primary_classification' => 'OK',
            'classification_flags' => ['OK'],
            'risk_level' => 'OK',
            'audit_error' => null,
        ]);

        $result = (new PartnerDebtInvariantChecker(
            app(CanonicalPartnerDebtService::class),
            $audit,
        ))->check($partner);

        $this->assertFalse($result['drift_detected']);
        $this->assertSame(0.0, $result['difference']);
    }
}
