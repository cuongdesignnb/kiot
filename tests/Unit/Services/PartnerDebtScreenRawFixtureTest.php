<?php

namespace Tests\Unit\Services;

use App\Models\Customer;
use App\Services\Debt\CanonicalPartnerDebtService;
use App\Services\Debt\PartnerDebtInvariantChecker;
use App\Services\Debt\PartnerDebtParityAuditService;
use App\Support\Debt\PartnerDebtDisplayBalance;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PartnerDebtScreenRawFixtureTest extends TestCase
{
    #[DataProvider('screenRawFixtures')]
    public function test_sanitized_screen_raw_contract(array $fixture): void
    {
        $partner = new Customer;
        $partner->forceFill([
            'id' => $fixture['id'],
            'code' => 'SANITIZED-'.str_pad((string) $fixture['id'], 3, '0', STR_PAD_LEFT),
            'name' => 'Sanitized fixture '.$fixture['id'],
            'debt_amount' => $fixture['customer'],
            'supplier_debt_amount' => $fixture['supplier'],
            'is_customer' => $fixture['is_customer'],
            'is_supplier' => $fixture['is_supplier'],
        ]);
        $aliases = PartnerDebtDisplayBalance::aliases($partner);

        $this->assertSame((float) $fixture['customer'], $aliases['customer_receivable_balance']);
        $this->assertSame((float) $fixture['supplier'], $aliases['supplier_payable_balance']);
        $expectedCustomerScreen = ! $fixture['is_customer']
            ? 0.0
            : ($fixture['is_supplier']
                ? (float) ($fixture['customer'] - $fixture['supplier'])
                : (float) $fixture['customer']);
        $this->assertSame($expectedCustomerScreen, $aliases['customer_screen_debt']);
        $expectedSupplierScreen = $fixture['is_customer'] && $fixture['is_supplier']
            ? (float) ($fixture['supplier'] - $fixture['customer'])
            : (float) $fixture['supplier'];
        $this->assertSame($expectedSupplierScreen, $aliases['supplier_screen_debt']);

        $audit = Mockery::mock(PartnerDebtParityAuditService::class);
        $audit->shouldReceive('audit')->once()->with($partner)->andReturn([
            'role' => $fixture['role'],
            'primary_classification' => $fixture['flag'],
            'classification_flags' => $fixture['flags'] ?? [$fixture['flag']],
            'risk_level' => $fixture['expected_status'] === PartnerDebtInvariantChecker::STATUS_DRIFT ? 'HIGH' : 'LOW',
            'audit_error' => null,
        ]);
        $result = (new PartnerDebtInvariantChecker(
            app(CanonicalPartnerDebtService::class),
            $audit,
        ))->check($partner);

        $this->assertSame($fixture['expected_status'], $result['invariant_status']);
        $this->assertSame(
            $fixture['expected_status'] === PartnerDebtInvariantChecker::STATUS_DRIFT,
            $result['drift_detected'],
        );
    }

    public static function screenRawFixtures(): array
    {
        $ok = PartnerDebtInvariantChecker::STATUS_OK;
        $technical = PartnerDebtInvariantChecker::STATUS_TECHNICAL;
        $insufficient = PartnerDebtInvariantChecker::STATUS_INSUFFICIENT;
        $drift = PartnerDebtInvariantChecker::STATUS_DRIFT;

        return [
            '01 customer only' => [self::fixture(1, 500_000, 0, true, false, 'customer_only', 'OK', $ok)],
            '02 supplier only' => [self::fixture(2, 0, 600_000, false, true, 'supplier_only', 'OK', $ok)],
            '03 dual customer greater' => [self::fixture(3, 900_000, 400_000, true, true, 'dual_role', 'OK', $ok)],
            '04 dual supplier greater' => [self::fixture(4, 400_000, 900_000, true, true, 'dual_role', 'OK', $ok)],
            '05 dual equal' => [self::fixture(5, 700_000, 700_000, true, true, 'dual_role', 'OK', $ok)],
            '06 zero customer nonzero supplier' => [self::fixture(6, 0, 250_000, true, true, 'dual_role', 'OK', $ok)],
            '07 nonzero customer zero supplier' => [self::fixture(7, 250_000, 0, true, true, 'dual_role', 'OK', $ok)],
            '08 negative adjustment' => [self::fixture(8, -300_000, 0, true, false, 'customer_only', 'OK', $ok)],
            '09 virtual display alignment' => [self::fixture(9, 300_000, 0, true, false, 'customer_only', 'VIRTUAL_DISPLAY_ALIGNMENT_ONLY', $technical)],
            '10 technical alias only' => [self::fixture(10, 0, 0, true, false, 'customer_only', 'TARGET_TYPE_ALIAS_SUSPECT', $technical)],
            '11 technical ledger exclusion' => [self::fixture(11, 0, 200_000, false, true, 'supplier_only', 'TECHNICAL_LEDGER_EXCLUDED', $technical)],
            '12 opening balance evidence' => [self::fixture(12, 100_000, 0, true, false, 'customer_only', 'VIRTUAL_OPENING_REQUIRED', $insufficient)],
            '13 document ledger mismatch' => [self::fixture(13, 800_000, 0, true, false, 'customer_only', 'CUSTOMER_DOCUMENT_VS_LEDGER', $drift)],
            '14 cancel reversal missing' => [self::fixture(14, 500_000, 0, true, false, 'customer_only', 'CANCEL_REVERSAL_MISSING', $drift)],
            '15 customer allocation mismatch' => [self::fixture(15, 300_000, 0, true, false, 'customer_only', 'INVOICE_RECEIPT_ALLOCATION_MISMATCH', $drift)],
            '16 supplier allocation mismatch' => [self::fixture(16, 0, 300_000, false, true, 'supplier_only', 'PURCHASE_PAYMENT_ALLOCATION_MISMATCH', $drift)],
            '17 stored document match' => [self::fixture(17, 450_000, 0, true, false, 'customer_only', 'OK', $ok)],
            '18 stored ledger match' => [self::fixture(18, 0, 450_000, false, true, 'supplier_only', 'OK', $ok)],
            '19 multi source divergence' => [self::fixture(19, 1_000_000, 300_000, true, true, 'dual_role', 'CUSTOMER_STORED_VS_DOCUMENT', $drift, ['CUSTOMER_STORED_VS_DOCUMENT', 'SUPPLIER_DOCUMENT_VS_LEDGER'])],
        ];
    }

    private static function fixture(
        int $id,
        int $customer,
        int $supplier,
        bool $isCustomer,
        bool $isSupplier,
        string $role,
        string $flag,
        string $expectedStatus,
        ?array $flags = null,
    ): array {
        return [
            'id' => $id,
            'customer' => $customer,
            'supplier' => $supplier,
            'is_customer' => $isCustomer,
            'is_supplier' => $isSupplier,
            'role' => $role,
            'flag' => $flag,
            'flags' => $flags,
            'expected_status' => $expectedStatus,
        ];
    }
}
