<?php

namespace Tests\Feature\Console;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\SupplierDebtTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DiffDebtPartnerCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_requires_dry_run(): void
    {
        $customer = $this->customer();

        $this->artisan('debt:diff-partner', [
            '--code' => $customer->code,
        ])->expectsOutputToContain('Please pass --dry-run')
            ->assertExitCode(1);
    }

    public function test_dry_run_runs(): void
    {
        [$customer, $inspectJson, $planJson] = $this->fixtures('runs');

        $this->artisan('debt:diff-partner', [
            '--dry-run' => true,
            '--code' => $customer->code,
            '--inspect-json' => $inspectJson,
            '--plan-json' => $planJson,
        ])->assertExitCode(0);
    }

    public function test_exports_json_and_markdown(): void
    {
        [$customer, $inspectJson, $planJson, $base] = $this->fixtures('exports');
        $json = $base . DIRECTORY_SEPARATOR . 'diff.json';
        $md = $base . DIRECTORY_SEPARATOR . 'diff.md';

        $this->artisan('debt:diff-partner', [
            '--dry-run' => true,
            '--code' => $customer->code,
            '--inspect-json' => $inspectJson,
            '--plan-json' => $planJson,
            '--export-json' => $json,
            '--export-md' => $md,
        ])->assertExitCode(0);

        $this->assertFileExists($json);
        $this->assertFileExists($md);

        $payload = json_decode(file_get_contents($json), true);
        $this->assertArrayHasKey('matching_matrix', $payload);
        $this->assertArrayHasKey('detected_issues', $payload);
    }

    public function test_dry_run_does_not_write_debt_or_cashflow_rows(): void
    {
        [$customer, $inspectJson, $planJson] = $this->fixtures('no-write');
        $before = [
            CustomerDebt::count(),
            SupplierDebtTransaction::count(),
            CashFlow::count(),
            DebtOffset::count(),
        ];

        $this->artisan('debt:diff-partner', [
            '--dry-run' => true,
            '--code' => $customer->code,
            '--inspect-json' => $inspectJson,
            '--plan-json' => $planJson,
        ])->assertExitCode(0);

        $this->assertSame($before, [
            CustomerDebt::count(),
            SupplierDebtTransaction::count(),
            CashFlow::count(),
            DebtOffset::count(),
        ]);
    }

    public function test_matching_matrix_has_required_fields(): void
    {
        [$customer, $inspectJson, $planJson, $base] = $this->fixtures('matrix');
        $json = $base . DIRECTORY_SEPARATOR . 'diff.json';

        $this->artisan('debt:diff-partner', [
            '--dry-run' => true,
            '--code' => $customer->code,
            '--inspect-json' => $inspectJson,
            '--plan-json' => $planJson,
            '--export-json' => $json,
        ])->assertExitCode(0);

        $matrix = json_decode(file_get_contents($json), true)['matching_matrix'];
        $this->assertNotEmpty($matrix);

        foreach ([
            'source_type',
            'source_code',
            'source_date',
            'source_status',
            'source_amount',
            'expected_effect',
            'matched_ledger_id',
            'matched_ledger_code',
            'matched_ledger_amount',
            'matched_cashflow_id',
            'matched_cashflow_code',
            'matched_cashflow_amount',
            'match_status',
            'issue',
        ] as $field) {
            $this->assertArrayHasKey($field, $matrix[0]);
        }
    }

    private function fixtures(string $name): array
    {
        $customer = $this->customer([
            'code' => 'DIFF-' . strtoupper($name) . '-' . uniqid(),
        ]);
        $base = storage_path('app/testing/debt-diff-' . $name . '-' . uniqid());
        @mkdir($base, 0755, true);
        $inspectJson = $base . DIRECTORY_SEPARATOR . 'inspect.json';
        $planJson = $base . DIRECTORY_SEPARATOR . 'plan.json';

        file_put_contents($inspectJson, json_encode($this->inspectionPayload($customer), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        file_put_contents($planJson, json_encode($this->planPayload($customer), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [$customer, $inspectJson, $planJson, $base];
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'DIFF-' . uniqid(),
            'name' => 'Diff Partner',
            'phone' => '09' . random_int(10000000, 99999999),
            'debt_amount' => 1200000,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ], $overrides));
    }

    private function inspectionPayload(Customer $customer): array
    {
        return [
            'partner' => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'is_customer' => true,
                'is_supplier' => false,
                'created_at' => '2026-01-01 00:00:00',
            ],
            'stored_balances' => [
                'customer_receivable' => 1200000,
                'supplier_payable' => 0,
                'customer_view' => 1200000,
                'supplier_view' => -1200000,
            ],
            'source_counts' => [
                'ledger_count' => 1,
                'document_count' => 2,
                'cash_flow_count' => 1,
            ],
            'raw' => [
                'customer_debts' => [[
                    'id' => 11,
                    'code' => 'HD-DIFF-001',
                    'type' => 'invoice',
                    'amount' => 1200000,
                    'balance' => 1200000,
                    'recorded_at' => '2026-01-02 09:00:00',
                    'reference_code' => 'HD-DIFF-001',
                    'status' => null,
                ]],
                'supplier_debt_transactions' => [],
                'invoices' => [[
                    'id' => 21,
                    'code' => 'HD-DIFF-001',
                    'status' => 'completed',
                    'transaction_date' => '2026-01-02 09:00:00',
                    'created_at' => '2026-01-02 09:00:00',
                    'total' => 1500000,
                    'customer_paid' => 300000,
                    'outstanding' => 1200000,
                    'debt_amount' => 1200000,
                ]],
                'order_returns' => [],
                'purchases' => [],
                'purchase_returns' => [],
                'cash_flows' => [[
                    'id' => 31,
                    'code' => 'PT-DIFF-001',
                    'amount' => 300000,
                    'time' => '2026-01-02 09:05:00',
                    'reference_type' => 'Invoice',
                    'reference_code' => 'HD-DIFF-001',
                    'status' => 'active',
                ]],
                'debt_offsets' => [],
            ],
            'timelines' => [
                'customer_net' => [
                    'entries' => [[
                        'code' => 'HD-DIFF-001',
                        'time' => '2026-01-02 09:00:00',
                        'display_effect' => 1200000,
                    ]],
                ],
            ],
            'computed' => [
                'customer_has_virtual_opening' => false,
                'supplier_has_virtual_opening' => false,
            ],
            'diagnosis' => [
                'primary_cause' => 'ledger_and_documents_mismatch',
                'requires_confirmation_before_fix' => true,
            ],
        ];
    }

    private function planPayload(Customer $customer): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'dry_run' => true,
            'plans' => [[
                'id' => (string) $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'classification' => 'DOCUMENT_LEDGER_MISMATCH',
                'diagnosis' => 'ledger_and_documents_mismatch',
                'fix_group' => 'C_LEDGER_DOCUMENT_MISMATCH',
                'authority_candidate' => 'manual_review',
                'proposed_write_operations' => [],
            ]],
        ];
    }
}
