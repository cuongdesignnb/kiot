<?php

namespace Tests\Feature\Console;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\Invoice;
use App\Models\SupplierDebtTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PlanDebtAdjustmentStrategyCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_requires_dry_run(): void
    {
        [$partner, $invoice, $cashflow] = $this->fixtures('requires-dry-run');

        $this->artisan('debt:strategy-debt-adjustment', [
            '--partner-code' => $partner->code,
            '--invoice-code' => $invoice->code,
            '--cashflow-code' => $cashflow->code,
        ])->expectsOutputToContain('Please pass --dry-run')
            ->assertExitCode(1);
    }

    public function test_dry_run_runs(): void
    {
        [$partner, $invoice, $cashflow] = $this->fixtures('runs');

        $this->artisan('debt:strategy-debt-adjustment', [
            '--dry-run' => true,
            '--partner-code' => $partner->code,
            '--invoice-code' => $invoice->code,
            '--cashflow-code' => $cashflow->code,
        ])->assertExitCode(0);
    }

    public function test_exports_json_and_markdown(): void
    {
        [$partner, $invoice, $cashflow, $base] = $this->fixtures('exports');
        $json = $base . DIRECTORY_SEPARATOR . 'strategy.json';
        $md = $base . DIRECTORY_SEPARATOR . 'strategy.md';

        $this->artisan('debt:strategy-debt-adjustment', [
            '--dry-run' => true,
            '--partner-code' => $partner->code,
            '--invoice-code' => $invoice->code,
            '--cashflow-code' => $cashflow->code,
            '--export-json' => $json,
            '--export-md' => $md,
        ])->assertExitCode(0);

        $this->assertFileExists($json);
        $this->assertFileExists($md);
        $this->assertArrayHasKey('strategies', json_decode((string) file_get_contents($json), true));
        $this->assertStringContainsString('DebtAdjustment mapping/ledger strategy', (string) file_get_contents($md));
    }

    public function test_dry_run_does_not_write_db(): void
    {
        [$partner, $invoice, $cashflow] = $this->fixtures('no-write');
        $before = [
            CustomerDebt::count(),
            SupplierDebtTransaction::count(),
            CashFlow::count(),
            DebtOffset::count(),
            Invoice::count(),
        ];

        $this->artisan('debt:strategy-debt-adjustment', [
            '--dry-run' => true,
            '--partner-code' => $partner->code,
            '--invoice-code' => $invoice->code,
            '--cashflow-code' => $cashflow->code,
        ])->assertExitCode(0);

        $this->assertSame($before, [
            CustomerDebt::count(),
            SupplierDebtTransaction::count(),
            CashFlow::count(),
            DebtOffset::count(),
            Invoice::count(),
        ]);
    }

    public function test_mock_anh_bay_case_reports_display_resolved_after_hotfix(): void
    {
        [$partner, $invoice, $cashflow, $base] = $this->fixtures('anh-bay');
        $json = $base . DIRECTORY_SEPARATOR . 'strategy.json';

        $this->artisan('debt:strategy-debt-adjustment', [
            '--dry-run' => true,
            '--partner-code' => $partner->code,
            '--invoice-code' => $invoice->code,
            '--cashflow-code' => $cashflow->code,
            '--export-json' => $json,
        ])->assertExitCode(0);

        $payload = json_decode((string) file_get_contents($json), true);

        $this->assertSame('MANUAL_REVIEW_REQUIRED', $payload['recommended_strategy']['strategy']);
        $this->assertFalse($payload['recommended_strategy']['write_db']);
        $this->assertFalse($payload['recommended_strategy']['code_only_change']);
        $this->assertSame(0.0, (float) $payload['current_state']['stored_customer_debt']);
        $this->assertSame(15000000.0, (float) $payload['current_state']['invoice_outstanding']);
        $this->assertSame(15000000.0, (float) $payload['current_state']['cashflow_amount']);
        $this->assertSame(0, $payload['current_state']['invoice_customer_debt_rows']);
        $this->assertSame(0, $payload['current_state']['cashflow_customer_debt_rows']);
        $this->assertTrue($payload['current_state']['timeline_invoice_entry_found']);
        $this->assertTrue($payload['current_state']['timeline_cashflow_entry_found']);
        $this->assertSame(-15000000.0, (float) $payload['current_state']['timeline_cashflow_effect']);
        $this->assertSame(0.0, (float) $payload['current_state']['timeline_final_balance']);
        $this->assertFalse($payload['current_state']['reconcile_mismatch']);
    }

    public function test_ledger_pair_preview_net_effect_is_zero(): void
    {
        $payload = $this->strategyPayload('ledger-pair');
        $strategy = collect($payload['strategies'])->firstWhere('strategy', 'LEDGER_PAIR_PREVIEW');

        $this->assertSame('true_if_approved_later', $strategy['write_db']);
        $this->assertSame(['customer_debts'], $strategy['tables_affected_if_applied']);
        $this->assertSame(0.0, (float) $strategy['net_effect']);
        $this->assertCount(2, $strategy['operations_preview']);
        $this->assertFalse($strategy['recommended']);
        $this->assertSame('HIGH', $strategy['risk']);
    }

    public function test_linkage_only_preview_is_high_risk_and_not_recommended(): void
    {
        $payload = $this->strategyPayload('linkage-only');
        $strategy = collect($payload['strategies'])->firstWhere('strategy', 'LINKAGE_ONLY_PREVIEW');

        $this->assertSame('true_if_approved_later', $strategy['write_db']);
        $this->assertSame(['cash_flows'], $strategy['tables_affected_if_applied']);
        $this->assertSame('HIGH', $strategy['risk']);
        $this->assertFalse($strategy['recommended']);
        $this->assertSame('update_cashflow_reference', $strategy['operations_preview'][0]['operation']);
    }

    private function strategyPayload(string $name): array
    {
        [$partner, $invoice, $cashflow, $base] = $this->fixtures($name);
        $json = $base . DIRECTORY_SEPARATOR . 'strategy.json';

        $this->artisan('debt:strategy-debt-adjustment', [
            '--dry-run' => true,
            '--partner-code' => $partner->code,
            '--invoice-code' => $invoice->code,
            '--cashflow-code' => $cashflow->code,
            '--export-json' => $json,
        ])->assertExitCode(0);

        return json_decode((string) file_get_contents($json), true);
    }

    private function fixtures(string $name): array
    {
        $suffix = strtoupper(str_replace('-', '', $name)) . '-' . uniqid();

        $partner = Customer::create([
            'code' => 'STRAT-' . $suffix,
            'name' => 'Anh Bay Strategy',
            'phone' => '09' . random_int(10000000, 99999999),
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'code' => 'HD-STRAT-' . $suffix,
            'customer_id' => $partner->id,
            'status' => 'Hoan thanh',
            'created_at' => '2026-03-27 15:09:00',
            'transaction_date' => null,
            'total' => 15000000,
            'customer_paid' => 0,
            'debt_amount' => 15000000,
            'payment_status' => 'unpaid',
            'note' => 'Strategy test invoice',
        ]);

        $cashflow = CashFlow::create([
            'code' => 'PT-STRAT-' . $suffix,
            'type' => 'receipt',
            'target_type' => 'Legacy Customer',
            'target_id' => $partner->id,
            'target_name' => $partner->name,
            'amount' => 15000000,
            'time' => '2026-04-22 15:16:00',
            'created_at' => '2026-04-22 08:16:00',
            'reference_type' => 'DebtAdjustment',
            'reference_code' => null,
            'status' => 'active',
            'payment_method' => 'cash',
            'description' => 'Dieu chinh cong no | 15,000,000 -> 0',
        ]);

        $base = storage_path('app/testing/debt-adjustment-strategy-' . $name . '-' . uniqid());
        @mkdir($base, 0755, true);

        return [$partner, $invoice, $cashflow, $base];
    }
}
