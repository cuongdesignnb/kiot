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

class AuditDebtCandidatePairCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_requires_dry_run(): void
    {
        [$partner, $invoice, $cashflow] = $this->fixtures('requires-dry-run');

        $this->artisan('debt:audit-candidate-pair', [
            '--partner-code' => $partner->code,
            '--invoice-code' => $invoice->code,
            '--cashflow-code' => $cashflow->code,
        ])->expectsOutputToContain('Please pass --dry-run')
            ->assertExitCode(1);
    }

    public function test_dry_run_runs(): void
    {
        [$partner, $invoice, $cashflow] = $this->fixtures('runs');

        $this->artisan('debt:audit-candidate-pair', [
            '--dry-run' => true,
            '--partner-code' => $partner->code,
            '--invoice-code' => $invoice->code,
            '--cashflow-code' => $cashflow->code,
        ])->assertExitCode(0);
    }

    public function test_exports_json_and_markdown(): void
    {
        [$partner, $invoice, $cashflow, $base] = $this->fixtures('exports');
        $json = $base . DIRECTORY_SEPARATOR . 'pair.json';
        $md = $base . DIRECTORY_SEPARATOR . 'pair.md';

        $this->artisan('debt:audit-candidate-pair', [
            '--dry-run' => true,
            '--partner-code' => $partner->code,
            '--invoice-code' => $invoice->code,
            '--cashflow-code' => $cashflow->code,
            '--export-json' => $json,
            '--export-md' => $md,
        ])->assertExitCode(0);

        $this->assertFileExists($json);
        $this->assertFileExists($md);
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

        $this->artisan('debt:audit-candidate-pair', [
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

    public function test_export_has_required_sections(): void
    {
        [$partner, $invoice, $cashflow, $base] = $this->fixtures('sections');
        $json = $base . DIRECTORY_SEPARATOR . 'pair.json';

        $this->artisan('debt:audit-candidate-pair', [
            '--dry-run' => true,
            '--partner-code' => $partner->code,
            '--invoice-code' => $invoice->code,
            '--cashflow-code' => $cashflow->code,
            '--export-json' => $json,
        ])->assertExitCode(0);

        $payload = json_decode((string) file_get_contents($json), true);

        foreach ([
            'partner',
            'invoice',
            'cashflow',
            'related_customer_debts',
            'related_cashflows',
            'timeline_entries',
            'candidate_decision',
        ] as $section) {
            $this->assertArrayHasKey($section, $payload);
        }
    }

    public function test_debt_adjustment_note_sets_signal(): void
    {
        [$partner, $invoice, $cashflow, $base] = $this->fixtures('signal');
        $json = $base . DIRECTORY_SEPARATOR . 'pair.json';

        $this->artisan('debt:audit-candidate-pair', [
            '--dry-run' => true,
            '--partner-code' => $partner->code,
            '--invoice-code' => $invoice->code,
            '--cashflow-code' => $cashflow->code,
            '--export-json' => $json,
        ])->assertExitCode(0);

        $payload = json_decode((string) file_get_contents($json), true);

        $this->assertTrue($payload['cashflow']['debt_adjustment_signal']);
        $this->assertTrue($payload['signals']['debt_adjustment_signal']);
    }

    public function test_no_write_preview_when_possible_settlement_ambiguity_exists(): void
    {
        [$partner, $invoice, $cashflow, $base] = $this->fixtures('ambiguity');
        $json = $base . DIRECTORY_SEPARATOR . 'pair.json';

        $this->artisan('debt:audit-candidate-pair', [
            '--dry-run' => true,
            '--partner-code' => $partner->code,
            '--invoice-code' => $invoice->code,
            '--cashflow-code' => $cashflow->code,
            '--export-json' => $json,
        ])->assertExitCode(0);

        $decision = json_decode((string) file_get_contents($json), true)['candidate_decision'];

        $this->assertSame('blocked', $decision['candidate_status']);
        $this->assertSame('MANUAL_REVIEW_REQUIRED', $decision['recommended_fix_type']);
        $this->assertSame([], $decision['write_operations_preview']);
        $this->assertTrue($decision['double_count_risk']);
    }

    private function fixtures(string $name): array
    {
        $partner = Customer::create([
            'code' => 'PAIR-' . strtoupper($name) . '-' . uniqid(),
            'name' => 'Anh Bay Pair',
            'phone' => '09' . random_int(10000000, 99999999),
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'code' => 'HD-PAIR-' . strtoupper($name),
            'customer_id' => $partner->id,
            'status' => 'Hoan thanh',
            'created_at' => '2026-03-27 15:09:00',
            'transaction_date' => null,
            'total' => 15000000,
            'customer_paid' => 0,
            'debt_amount' => 15000000,
            'payment_status' => 'unpaid',
            'note' => 'Pair test invoice',
        ]);

        $cashflow = CashFlow::create([
            'code' => 'PT-PAIR-' . strtoupper($name),
            'type' => 'receipt',
            'target_type' => 'Khách hàng',
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

        $base = storage_path('app/testing/debt-pair-' . $name . '-' . uniqid());
        @mkdir($base, 0755, true);

        return [$partner, $invoice, $cashflow, $base];
    }
}
