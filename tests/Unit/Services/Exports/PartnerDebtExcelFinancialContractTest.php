<?php

namespace Tests\Unit\Services\Exports;

use App\Models\Customer;
use App\Services\Exports\CustomerDebtExcelExportService;
use App\Services\Exports\PartnerDebtExportEffectResolver;
use App\Services\Exports\PartnerDebtExportRunningBalanceResolver;
use App\Services\Exports\SupplierDebtExcelExportService;
use Tests\TestCase;

class PartnerDebtExcelFinancialContractTest extends TestCase
{
    public function test_merged_settlement_body_uses_canonical_effect_and_reconciles_summary(): void
    {
        $entry = [
            'event_identity' => 'customer|debt_offsets|1|return|receivable',
            'event_kind' => 'return',
            'reference_type' => 'DebtOffset',
            'reference_id' => 1,
            'type_raw' => 'return',
            'display_merged_settlement' => true,
            'amount' => 900,
            'customer_display_effect' => 250,
            'customer_display_running_balance' => 250,
            'code' => 'RET-MERGED-1',
            'type_label' => 'Trả hàng',
        ];
        $sheet = (new CustomerDebtExcelExportService(
            new Customer(['name' => 'Contract customer', 'code' => 'KH-1']),
            [$entry],
            null,
            null,
            false,
            [],
        ))->build()->getActiveSheet();

        $rows = $sheet->toArray(null, true, false, true);
        $body = $this->rowWithCode($rows, 'RET-MERGED-1');
        $summary = $this->summary($rows, 'Nợ cuối kỳ:');

        self::assertSame(250.0, (float) ($body['K'] ?? 0));
        self::assertSame(0.0, (float) ($body['L'] ?? 0));
        self::assertSame(250.0, (float) ($body['M'] ?? 0));
        self::assertSame(250.0, $summary);
        self::assertSame(250.0, 0.0 + 250.0 - 0.0);
    }

    public function test_production_shaped_payment_effects_use_canonical_debt_not_raw_cash(): void
    {
        $entries = [
            [
                'event_identity' => 'dual|cash_flows|1|payment|payable',
                'event_kind' => 'supplier_payment',
                'reference_type' => 'CashFlow',
                'reference_id' => 1,
                'code' => 'PC-A',
                'type_label' => 'Thanh toán NCC',
                'payment_method' => 'cash',
                'payment_for_code' => 'PN-A',
                'original_cash_flow_amount' => 18400000,
                'customer_display_effect' => -20000,
                'supplier_display_effect' => 20000,
                'customer_display_running_balance' => -20000,
                'supplier_display_running_balance' => 20000,
            ],
            [
                'event_identity' => 'dual|cash_flows|2|payment|payable',
                'event_kind' => 'supplier_payment',
                'reference_type' => 'CashFlow',
                'reference_id' => 2,
                'code' => 'PC-B',
                'type_label' => 'Thanh toán NCC',
                'payment_method' => 'cash',
                'payment_for_code' => 'PN-B',
                'original_cash_flow_amount' => 18600000,
                'customer_display_effect' => -2060000,
                'supplier_display_effect' => 2060000,
                'customer_display_running_balance' => -2080000,
                'supplier_display_running_balance' => 2080000,
            ],
        ];
        $customerRows = (new CustomerDebtExcelExportService(
            new Customer(['name' => 'Dual customer', 'code' => 'DUAL-1']),
            $entries,
            null,
            null,
            false,
            [],
        ))->build()->getActiveSheet()->toArray(null, true, false, true);
        $supplierRows = (new SupplierDebtExcelExportService(
            $entries,
            new Customer(['name' => 'Dual supplier', 'code' => 'DUAL-1']),
            null,
            null,
            false,
            [],
        ))->build()->getActiveSheet()->toArray(null, true, false, true);

        $customerA = $this->rowWithCode($customerRows, 'PC-A');
        $customerB = $this->rowWithCode($customerRows, 'PC-B');
        $supplierA = $this->rowWithCode($supplierRows, 'PC-A');
        $supplierB = $this->rowWithCode($supplierRows, 'PC-B');

        self::assertSame(20000.0, (float) ($customerA['L'] ?? 0));
        self::assertSame(2060000.0, (float) ($customerB['L'] ?? 0));
        self::assertSame(20000.0, (float) ($supplierA['K'] ?? 0));
        self::assertSame(2060000.0, (float) ($supplierB['K'] ?? 0));
        self::assertNotSame(18400000.0, (float) ($customerA['L'] ?? 0));
        self::assertNotSame(18600000.0, (float) ($customerB['L'] ?? 0));
        self::assertSame(0.0, (float) ($customerA['M'] ?? 0) + (float) ($supplierA['M'] ?? 0));
        self::assertSame(0.0, (float) ($customerB['M'] ?? 0) + (float) ($supplierB['M'] ?? 0));
    }

    public function test_canonical_running_balance_missing_fails_closed(): void
    {
        $this->expectExceptionMessage('missing_orientation_running_balance');
        (new PartnerDebtExportRunningBalanceResolver)->resolve([
            'event_identity' => 'customer|invoices|1|sale|receivable',
            'event_kind' => 'customer_sale',
            'reference_type' => 'Invoice',
            'reference_id' => 1,
            'customer_display_effect' => 100,
        ], 'customer');
    }

    public function test_dual_role_event_identity_order_effect_and_running_balance_are_inverse(): void
    {
        $entries = [
            ['event_identity' => 'dual|invoices|1|sale|receivable', 'customer_display_effect' => 100, 'supplier_display_effect' => -100, 'customer_display_running_balance' => 100, 'supplier_display_running_balance' => -100],
            ['event_identity' => 'dual|cash_flows|2|payment|receivable', 'customer_display_effect' => -40, 'supplier_display_effect' => 40, 'customer_display_running_balance' => 60, 'supplier_display_running_balance' => -60],
        ];
        $effects = new PartnerDebtExportEffectResolver;
        $customerIdentities = array_column($entries, 'event_identity');
        $supplierIdentities = array_column($entries, 'event_identity');

        self::assertSame($customerIdentities, $supplierIdentities);
        foreach ($entries as $entry) {
            self::assertEquals(0.0, $effects->resolve($entry, 'customer') + $effects->resolve($entry, 'supplier'));
            self::assertEquals(0.0, $entry['customer_display_running_balance'] + $entry['supplier_display_running_balance']);
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function rowWithCode(array $rows, string $code): array
    {
        foreach ($rows as $row) {
            if (($row['B'] ?? '') === $code) {
                return $row;
            }
        }
        self::fail('Expected workbook row not found: '.$code);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function summary(array $rows, string $label): float
    {
        foreach ($rows as $row) {
            if (($row['I'] ?? '') === $label) {
                return (float) ($row['K'] ?? 0) - (float) ($row['L'] ?? 0);
            }
        }
        self::fail('Summary row not found: '.$label);
    }
}
