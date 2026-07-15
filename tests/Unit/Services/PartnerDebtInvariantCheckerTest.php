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
        $this->assertSame(PartnerDebtInvariantChecker::STATUS_DRIFT, $result['invariant_status']);
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
        $this->assertSame(PartnerDebtInvariantChecker::STATUS_OK, $result['invariant_status']);
        $this->assertSame(0.0, $result['difference']);
    }

    public function test_technical_alias_warning_is_not_material_drift(): void
    {
        $result = $this->checkWithAudit([
            'primary_classification' => 'TARGET_TYPE_ALIAS_SUSPECT',
            'classification_flags' => ['TARGET_TYPE_ALIAS_SUSPECT'],
            'risk_level' => 'MEDIUM',
        ]);

        $this->assertSame(PartnerDebtInvariantChecker::STATUS_TECHNICAL, $result['invariant_status']);
        $this->assertTrue($result['technical_warning']);
        $this->assertFalse($result['drift_detected']);
    }

    public function test_virtual_display_alignment_is_not_material_drift(): void
    {
        $result = $this->checkWithAudit([
            'primary_classification' => 'VIRTUAL_DISPLAY_ALIGNMENT_ONLY',
            'classification_flags' => ['VIRTUAL_DISPLAY_ALIGNMENT_ONLY'],
            'risk_level' => 'LOW',
        ]);

        $this->assertSame(PartnerDebtInvariantChecker::STATUS_TECHNICAL, $result['invariant_status']);
        $this->assertFalse($result['drift_detected']);
    }

    public function test_invoice_allocation_mismatch_is_material_drift(): void
    {
        $result = $this->checkWithAudit([
            'primary_classification' => 'INVOICE_RECEIPT_ALLOCATION_MISMATCH',
            'classification_flags' => ['INVOICE_RECEIPT_ALLOCATION_MISMATCH'],
            'risk_level' => 'MEDIUM',
        ]);

        $this->assertSame(PartnerDebtInvariantChecker::STATUS_DRIFT, $result['invariant_status']);
        $this->assertTrue($result['drift_detected']);
    }

    public function test_invoice_allocation_missing_evidence_is_insufficient(): void
    {
        $result = $this->checkWithAudit([
            'primary_classification' => 'INVOICE_RECEIPT_ALLOCATION_EVIDENCE_MISSING',
            'classification_flags' => ['INVOICE_RECEIPT_ALLOCATION_EVIDENCE_MISSING'],
            'risk_level' => 'MEDIUM',
        ]);

        $this->assertSame(PartnerDebtInvariantChecker::STATUS_INSUFFICIENT, $result['invariant_status']);
        $this->assertFalse($result['drift_detected']);
    }

    public function test_supplier_allocation_conflict_is_material_drift(): void
    {
        $result = $this->checkWithAudit([
            'primary_classification' => 'PURCHASE_PAYMENT_ALLOCATION_MISMATCH',
            'classification_flags' => ['PURCHASE_PAYMENT_ALLOCATION_MISMATCH'],
            'risk_level' => 'MEDIUM',
        ]);

        $this->assertSame(PartnerDebtInvariantChecker::STATUS_DRIFT, $result['invariant_status']);
        $this->assertTrue($result['drift_detected']);
    }

    public function test_supplier_allocation_missing_evidence_is_insufficient(): void
    {
        $result = $this->checkWithAudit([
            'primary_classification' => 'PURCHASE_PAYMENT_ALLOCATION_EVIDENCE_MISSING',
            'classification_flags' => ['PURCHASE_PAYMENT_ALLOCATION_EVIDENCE_MISSING'],
            'risk_level' => 'MEDIUM',
        ]);

        $this->assertSame(PartnerDebtInvariantChecker::STATUS_INSUFFICIENT, $result['invariant_status']);
        $this->assertFalse($result['drift_detected']);
    }

    public function test_cancel_reversal_missing_is_material_drift(): void
    {
        $result = $this->checkWithAudit([
            'primary_classification' => 'CANCEL_REVERSAL_MISSING',
            'classification_flags' => ['CANCEL_REVERSAL_MISSING'],
            'risk_level' => 'CRITICAL',
        ]);

        $this->assertSame(PartnerDebtInvariantChecker::STATUS_DRIFT, $result['invariant_status']);
        $this->assertTrue($result['drift_detected']);
    }

    public function test_audit_error_is_a_check_error_not_material_drift(): void
    {
        $result = $this->checkWithAudit([
            'primary_classification' => 'AUDIT_ERROR',
            'classification_flags' => ['AUDIT_ERROR'],
            'risk_level' => 'CRITICAL',
            'audit_error' => 'Synthetic failure',
        ]);

        $this->assertSame(PartnerDebtInvariantChecker::STATUS_ERROR, $result['invariant_status']);
        $this->assertFalse($result['drift_detected']);
    }

    public function test_supplier_stored_vs_ledger_is_material_drift(): void
    {
        $result = $this->checkWithAudit([
            'role' => 'supplier_only',
            'primary_classification' => 'SUPPLIER_STORED_VS_LEDGER',
            'classification_flags' => ['SUPPLIER_STORED_VS_LEDGER'],
            'risk_level' => 'HIGH',
            'supplier_stored_vs_ledger' => 1_500_000,
        ], [
            'is_customer' => false,
            'is_supplier' => true,
            'supplier_debt_amount' => 1_500_000,
        ]);

        $this->assertSame(PartnerDebtInvariantChecker::STATUS_DRIFT, $result['invariant_status']);
        $this->assertSame(1_500_000.0, $result['difference']);
    }

    public function test_supplier_only_without_mismatch_is_ok(): void
    {
        $result = $this->checkWithAudit([
            'role' => 'supplier_only',
        ], [
            'is_customer' => false,
            'is_supplier' => true,
        ]);

        $this->assertSame(PartnerDebtInvariantChecker::STATUS_OK, $result['invariant_status']);
    }

    public function test_dual_role_without_mismatch_is_ok_and_keeps_oriented_net(): void
    {
        $result = $this->checkWithAudit([
            'role' => 'dual_role',
        ], [
            'is_customer' => true,
            'is_supplier' => true,
            'debt_amount' => 500_000,
            'supplier_debt_amount' => 200_000,
        ]);

        $this->assertSame(PartnerDebtInvariantChecker::STATUS_OK, $result['invariant_status']);
        $this->assertSame(300_000.0, $result['net_display_balance']);
    }

    private function checkWithAudit(array $auditOverrides, array $partnerOverrides = []): array
    {
        $partner = new Customer;
        $partner->forceFill(array_merge([
            'id' => 1,
            'code' => 'PARTNER-001',
            'name' => 'Partner',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
        ], $partnerOverrides));
        $audit = Mockery::mock(PartnerDebtParityAuditService::class);
        $audit->shouldReceive('audit')->once()->with($partner)->andReturn(array_merge([
            'role' => 'customer_only',
            'primary_classification' => 'OK',
            'classification_flags' => ['OK'],
            'risk_level' => 'OK',
            'audit_error' => null,
        ], $auditOverrides));

        return (new PartnerDebtInvariantChecker(
            app(CanonicalPartnerDebtService::class),
            $audit,
        ))->check($partner);
    }
}
