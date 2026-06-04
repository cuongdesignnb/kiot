<?php

namespace Tests\Feature\Console;

use App\Models\CashFlow;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\SupplierDebtTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PlanDebtFixCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_requires_dry_run(): void
    {
        $this->artisan('debt:plan-fix')
            ->expectsOutputToContain('Please pass --dry-run')
            ->assertExitCode(1);
    }

    public function test_dry_run_does_not_write_debt_or_cashflow_rows(): void
    {
        [$csv, $dir, $exports] = $this->fixturePaths('no-write');
        $this->writeInputs($csv, $dir, [
            $this->casePayload('101', 'NCC-PLAN-101', 'DOCUMENT_LEDGER_MISMATCH', 'ledger_and_documents_mismatch'),
        ]);

        $before = [
            CustomerDebt::count(),
            SupplierDebtTransaction::count(),
            CashFlow::count(),
            DebtOffset::count(),
        ];

        $this->artisan('debt:plan-fix', [
            '--dry-run' => true,
            '--csv' => $csv,
            '--inspect-dir' => $dir,
            '--limit' => 1,
            '--export-csv' => $exports['csv'],
            '--export-json' => $exports['json'],
            '--export-md' => $exports['md'],
        ])->assertExitCode(0);

        $this->assertSame($before, [
            CustomerDebt::count(),
            SupplierDebtTransaction::count(),
            CashFlow::count(),
            DebtOffset::count(),
        ]);
    }

    public function test_exports_csv_json_and_markdown(): void
    {
        [$csv, $dir, $exports] = $this->fixturePaths('exports');
        $this->writeInputs($csv, $dir, [
            $this->casePayload('201', 'NCC-PLAN-201', 'VIRTUAL_OPENING_REQUIRED', 'virtual_opening_display_resolved'),
        ]);

        $this->artisan('debt:plan-fix', [
            '--dry-run' => true,
            '--csv' => $csv,
            '--inspect-dir' => $dir,
            '--limit' => 1,
            '--export-csv' => $exports['csv'],
            '--export-json' => $exports['json'],
            '--export-md' => $exports['md'],
        ])->assertExitCode(0);

        $this->assertFileExists($exports['csv']);
        $this->assertFileExists($exports['json']);
        $this->assertFileExists($exports['md']);
    }

    public function test_diagnosis_mapping_to_fix_groups(): void
    {
        [$csv, $dir, $exports] = $this->fixturePaths('mapping');
        $this->writeInputs($csv, $dir, [
            $this->casePayload('301', 'NCC-PLAN-A', 'VIRTUAL_OPENING_REQUIRED', 'virtual_opening_display_resolved'),
            $this->casePayload('302', 'NCC-PLAN-B', 'HAS_DOCUMENTS_NO_LEDGER', 'documents_exist_but_no_ledger'),
            $this->casePayload('303', 'NCC-PLAN-C', 'DOCUMENT_LEDGER_MISMATCH', 'ledger_and_documents_mismatch'),
            $this->casePayload('304', 'KH-PLAN-D', 'CUSTOMER_ONLY_MISMATCH', 'needs_manual_review'),
        ]);

        $this->artisan('debt:plan-fix', [
            '--dry-run' => true,
            '--csv' => $csv,
            '--inspect-dir' => $dir,
            '--limit' => 10,
            '--export-csv' => $exports['csv'],
            '--export-json' => $exports['json'],
            '--export-md' => $exports['md'],
        ])->assertExitCode(0);

        $plans = collect(json_decode(file_get_contents($exports['json']), true)['plans'])
            ->keyBy('code');

        $this->assertSame('A_OPENING_BALANCE_REVIEW', $plans['NCC-PLAN-A']['fix_group']);
        $this->assertSame('virtual_opening_readonly', $plans['NCC-PLAN-A']['authority_candidate']);
        $this->assertSame('B_DOCUMENTS_NO_LEDGER', $plans['NCC-PLAN-B']['fix_group']);
        $this->assertSame('document', $plans['NCC-PLAN-B']['authority_candidate']);
        $this->assertSame('C_LEDGER_DOCUMENT_MISMATCH', $plans['NCC-PLAN-C']['fix_group']);
        $this->assertSame('manual_review', $plans['NCC-PLAN-C']['authority_candidate']);
        $this->assertSame('D_CUSTOMER_ONLY_REVIEW', $plans['KH-PLAN-D']['fix_group']);
    }

    public function test_plan_does_not_propose_executable_write_operations(): void
    {
        [$csv, $dir, $exports] = $this->fixturePaths('no-write-ops');
        $this->writeInputs($csv, $dir, [
            $this->casePayload('401', 'NCC-PLAN-401', 'DOCUMENT_LEDGER_MISMATCH', 'ledger_and_documents_mismatch'),
        ]);

        $this->artisan('debt:plan-fix', [
            '--dry-run' => true,
            '--csv' => $csv,
            '--inspect-dir' => $dir,
            '--limit' => 1,
            '--export-csv' => $exports['csv'],
            '--export-json' => $exports['json'],
            '--export-md' => $exports['md'],
        ])->assertExitCode(0);

        $plan = json_decode(file_get_contents($exports['json']), true)['plans'][0];

        $this->assertSame([], $plan['proposed_write_operations']);
        $this->assertTrue($plan['requires_confirmation_before_fix']);
        $this->assertTrue($plan['rollback_plan_required']);
        $this->assertTrue($plan['manual_review_required']);
    }

    private function fixturePaths(string $name): array
    {
        $base = storage_path('app/testing/debt-plan-' . $name . '-' . uniqid());
        $dir = $base . DIRECTORY_SEPARATOR . 'inspections';
        @mkdir($dir, 0755, true);

        return [
            $base . DIRECTORY_SEPARATOR . 'mismatch.csv',
            $dir,
            [
                'csv' => $base . DIRECTORY_SEPARATOR . 'plan.csv',
                'json' => $base . DIRECTORY_SEPARATOR . 'plan.json',
                'md' => $base . DIRECTORY_SEPARATOR . 'plan.md',
            ],
        ];
    }

    private function writeInputs(string $csv, string $dir, array $cases): void
    {
        @mkdir(dirname($csv), 0755, true);
        $handle = fopen($csv, 'w');
        fputcsv($handle, [
            'id',
            'code',
            'name',
            'phone',
            'classification',
            'risk_level',
            'stored_customer_view',
            'stored_supplier_view',
            'customer_debt_count',
            'supplier_debt_transaction_count',
            'invoice_count',
            'cashflow_receipt_count',
            'order_return_count',
            'purchase_count',
            'purchase_return_count',
            'debt_offset_count',
            'customer_display_resolved',
            'supplier_display_resolved',
            'customer_has_virtual_opening',
            'supplier_has_virtual_opening',
        ]);

        foreach ($cases as $case) {
            fputcsv($handle, [
                $case['id'],
                $case['code'],
                $case['name'],
                $case['phone'],
                $case['classification'],
                $case['risk_level'],
                $case['stored_customer_view'],
                $case['stored_supplier_view'],
                $case['customer_debt_count'],
                $case['supplier_debt_transaction_count'],
                $case['invoice_count'],
                $case['cashflow_receipt_count'],
                $case['order_return_count'],
                $case['purchase_count'],
                $case['purchase_return_count'],
                $case['debt_offset_count'],
                1,
                1,
                $case['customer_has_virtual_opening'] ? 1 : 0,
                $case['supplier_has_virtual_opening'] ? 1 : 0,
            ]);

            file_put_contents(
                $dir . DIRECTORY_SEPARATOR . $case['code'] . '-' . $case['id'] . '.json',
                json_encode($this->inspectionPayload($case), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        fclose($handle);
    }

    private function casePayload(string $id, string $code, string $classification, string $diagnosis): array
    {
        return [
            'id' => $id,
            'code' => $code,
            'name' => 'Plan Partner ' . $id,
            'phone' => '0900000' . $id,
            'classification' => $classification,
            'diagnosis' => $diagnosis,
            'risk_level' => $classification === 'DOCUMENT_LEDGER_MISMATCH' ? 'CRITICAL' : 'HIGH',
            'stored_customer_view' => -1000000,
            'stored_supplier_view' => 1000000,
            'customer_debt_count' => $diagnosis === 'documents_exist_but_no_ledger' ? 0 : 1,
            'supplier_debt_transaction_count' => 0,
            'invoice_count' => 1,
            'cashflow_receipt_count' => 1,
            'order_return_count' => 0,
            'purchase_count' => 0,
            'purchase_return_count' => 0,
            'debt_offset_count' => 0,
            'customer_has_virtual_opening' => $diagnosis === 'virtual_opening_display_resolved',
            'supplier_has_virtual_opening' => false,
        ];
    }

    private function inspectionPayload(array $case): array
    {
        $ledgerCount = (int) $case['customer_debt_count'] + (int) $case['supplier_debt_transaction_count'];
        $documentCount = (int) $case['invoice_count']
            + (int) $case['cashflow_receipt_count']
            + (int) $case['order_return_count']
            + (int) $case['purchase_count']
            + (int) $case['purchase_return_count']
            + (int) $case['debt_offset_count'];

        return [
            'partner' => [
                'id' => $case['id'],
                'code' => $case['code'],
                'name' => $case['name'],
                'phone' => $case['phone'],
            ],
            'stored_balances' => [
                'customer_view' => $case['stored_customer_view'],
                'supplier_view' => $case['stored_supplier_view'],
            ],
            'source_counts' => [
                'ledger_count' => $ledgerCount,
                'document_count' => $documentCount,
                'cash_flow_count' => $case['cashflow_receipt_count'],
            ],
            'computed' => [
                'customer_display_resolved' => true,
                'supplier_display_resolved' => true,
                'customer_has_virtual_opening' => $case['customer_has_virtual_opening'],
                'supplier_has_virtual_opening' => $case['supplier_has_virtual_opening'],
            ],
            'diagnosis' => [
                'primary_cause' => $case['diagnosis'],
                'confidence' => 'high',
                'recommended_action' => 'Manual review before any real fix.',
                'requires_confirmation_before_fix' => true,
            ],
        ];
    }
}
