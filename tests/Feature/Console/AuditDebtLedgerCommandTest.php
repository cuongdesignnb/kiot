<?php

namespace Tests\Feature\Console;

use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuditDebtLedgerCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_requires_dry_run(): void
    {
        $this->artisan('debt:audit-ledger')
            ->expectsOutputToContain('Please pass --dry-run')
            ->assertExitCode(1);
    }

    public function test_dry_run_does_not_write_customer_debt_rows(): void
    {
        $customer = $this->customer([
            'debt_amount' => 4300000,
            'is_customer' => true,
            'is_supplier' => false,
        ]);
        $countBefore = CustomerDebt::count();

        $this->artisan('debt:audit-ledger', [
            '--dry-run' => true,
            '--customer-id' => $customer->id,
        ])
            ->assertExitCode(0);

        $this->assertSame($countBefore, CustomerDebt::count());
    }

    public function test_stored_balance_without_history_is_classified_for_manual_review(): void
    {
        $customer = $this->customer([
            'debt_amount' => 4300000,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
        ]);
        $path = storage_path('app/testing/debt-audit-stored-no-history.csv');
        @unlink($path);

        $this->artisan('debt:audit-ledger', [
            '--dry-run' => true,
            '--customer-id' => $customer->id,
            '--export' => $path,
        ])
            ->assertExitCode(0);

        $rows = $this->csvRows($path);
        $this->assertCount(1, $rows);
        $this->assertContains($rows[0]['classification'], [
            'STORED_BALANCE_NO_HISTORY',
            'VIRTUAL_OPENING_REQUIRED',
        ]);
        $this->assertNotSame('', $rows[0]['recommended_action']);
    }

    public function test_fully_paid_invoice_is_ok_without_ledger(): void
    {
        $customer = $this->customer([
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
        ]);
        Invoice::create([
            'code' => 'HD-AUDIT-PAID-' . uniqid(),
            'customer_id' => $customer->id,
            'subtotal' => 12000000,
            'discount' => 0,
            'total' => 12000000,
            'customer_paid' => 12000000,
            'status' => 'completed',
            'transaction_date' => Carbon::parse('2026-06-04 09:00:00'),
            'created_at' => Carbon::parse('2026-06-04 09:00:00'),
            'updated_at' => Carbon::parse('2026-06-04 09:00:00'),
        ]);
        $path = storage_path('app/testing/debt-audit-paid-ok.csv');
        @unlink($path);

        $this->artisan('debt:audit-ledger', [
            '--dry-run' => true,
            '--customer-id' => $customer->id,
            '--export' => $path,
        ])
            ->assertExitCode(0);

        $rows = $this->csvRows($path);
        $this->assertCount(1, $rows);
        $this->assertSame('OK', $rows[0]['classification']);
    }

    public function test_dual_role_orientation_uses_opposite_customer_and_supplier_views(): void
    {
        $partner = $this->customer([
            'debt_amount' => 47400000,
            'supplier_debt_amount' => 75000000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);
        $path = storage_path('app/testing/debt-audit-dual-role.csv');
        @unlink($path);

        $this->artisan('debt:audit-ledger', [
            '--dry-run' => true,
            '--customer-id' => $partner->id,
            '--export' => $path,
        ])
            ->assertExitCode(0);

        $rows = $this->csvRows($path);
        $this->assertCount(1, $rows);
        $this->assertSame('-27600000', $this->normalizeNumber($rows[0]['stored_customer_view']));
        $this->assertSame('27600000', $this->normalizeNumber($rows[0]['stored_supplier_view']));
        $this->assertNotSame('DUAL_ROLE_NET_MISMATCH', $rows[0]['classification']);
    }

    public function test_export_csv_contains_required_headers(): void
    {
        $customer = $this->customer([
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
        ]);
        $path = storage_path('app/testing/debt-audit-headers.csv');
        @unlink($path);

        $this->artisan('debt:audit-ledger', [
            '--dry-run' => true,
            '--customer-id' => $customer->id,
            '--export' => $path,
        ])
            ->assertExitCode(0);

        $handle = fopen($path, 'r');
        $headers = fgetcsv($handle);
        fclose($handle);

        $this->assertSame([
            'id',
            'code',
            'name',
            'phone',
            'is_customer',
            'is_supplier',
            'status',
            'debt_amount',
            'supplier_debt_amount',
            'stored_customer_view',
            'stored_supplier_view',
            'customer_debt_count',
            'customer_debt_sum',
            'supplier_debt_transaction_count',
            'supplier_debt_transaction_sum',
            'invoice_count',
            'invoice_total',
            'invoice_paid_total',
            'invoice_outstanding_total',
            'cashflow_receipt_count',
            'cashflow_receipt_total',
            'order_return_count',
            'order_return_total',
            'order_return_refund_total',
            'purchase_count',
            'purchase_total',
            'purchase_paid_total',
            'purchase_outstanding_total',
            'purchase_return_count',
            'purchase_return_total',
            'purchase_return_refund_total',
            'debt_offset_count',
            'debt_offset_total',
            'customer_display_balance_target',
            'customer_display_balance_final',
            'customer_ledger_mismatch',
            'customer_display_resolved',
            'customer_has_virtual_opening',
            'customer_virtual_opening_balance',
            'customer_reconcile_severity',
            'supplier_display_balance_target',
            'supplier_display_balance_final',
            'supplier_ledger_mismatch',
            'supplier_display_resolved',
            'supplier_has_virtual_opening',
            'supplier_virtual_opening_balance',
            'supplier_reconcile_severity',
            'classification',
            'risk_level',
            'recommended_action',
        ], $headers);
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'AUDIT-DEBT-' . uniqid(),
            'name' => 'Debt Audit Partner',
            'phone' => '09' . random_int(10000000, 99999999),
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ], $overrides));
    }

    private function csvRows(string $path): array
    {
        $handle = fopen($path, 'r');
        $headers = fgetcsv($handle);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($headers, $row);
        }

        fclose($handle);

        return $rows;
    }

    private function normalizeNumber(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
