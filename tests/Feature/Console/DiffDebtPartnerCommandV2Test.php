<?php

namespace Tests\Feature\Console;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\SupplierDebtTransaction;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DiffDebtPartnerCommandV2Test extends TestCase
{
    use DatabaseTransactions;

    public function test_exports_evidence_matching_v2_payload(): void
    {
        [$customer, $inspectJson, $planJson, $base] = $this->fixtures('v2-export', 'B_DOCUMENTS_NO_LEDGER');
        $json = $base . DIRECTORY_SEPARATOR . 'diff-v2.json';
        $md = $base . DIRECTORY_SEPARATOR . 'diff-v2.md';

        $this->artisan('debt:diff-partner', [
            '--dry-run' => true,
            '--code' => $customer->code,
            '--inspect-json' => $inspectJson,
            '--plan-json' => $planJson,
            '--export-json' => $json,
            '--export-md' => $md,
        ])->assertExitCode(0);

        $payload = json_decode((string) file_get_contents($json), true);

        $this->assertSame('v2', $payload['evidence_matching_version']);
        $this->assertArrayHasKey('possible_matches', $payload);
        $this->assertArrayHasKey('candidate_preview', $payload);
        $this->assertArrayHasKey('matching_summary', $payload);
        $this->assertTrue($payload['candidate_preview']['candidate_ready']);
        $this->assertNotEmpty($payload['candidate_preview']['write_operations_preview']);
        $this->assertStringContainsString('Evidence matching version: `v2`', (string) file_get_contents($md));
    }

    public function test_v2_matching_matrix_has_required_fields(): void
    {
        [$customer, $inspectJson, $planJson, $base] = $this->fixtures('v2-fields', 'C_LEDGER_DOCUMENT_MISMATCH');
        $json = $base . DIRECTORY_SEPARATOR . 'diff-v2.json';

        $this->artisan('debt:diff-partner', [
            '--dry-run' => true,
            '--code' => $customer->code,
            '--inspect-json' => $inspectJson,
            '--plan-json' => $planJson,
            '--export-json' => $json,
        ])->assertExitCode(0);

        $row = json_decode((string) file_get_contents($json), true)['matching_matrix'][0];

        foreach ([
            'source_type',
            'source_code',
            'source_base_code',
            'source_date',
            'source_status',
            'source_amount',
            'expected_effect',
            'candidate_ledger_codes',
            'candidate_cashflow_codes',
            'best_match_type',
            'best_match_score',
            'best_match_confidence',
            'matched_ledger_id',
            'matched_ledger_code',
            'matched_ledger_amount',
            'matched_cashflow_id',
            'matched_cashflow_code',
            'matched_cashflow_amount',
            'match_status',
            'issue',
            'severity',
            'can_auto_candidate',
        ] as $field) {
            $this->assertArrayHasKey($field, $row);
        }
    }

    public function test_v2_diff_does_not_write_debt_or_cashflow_rows(): void
    {
        [$customer, $inspectJson, $planJson] = $this->fixtures('v2-no-write', 'B_DOCUMENTS_NO_LEDGER');
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

    private function fixtures(string $name, string $group): array
    {
        $customer = Customer::create([
            'code' => 'DIFF-V2-' . strtoupper($name) . '-' . uniqid(),
            'name' => 'Diff V2 Partner',
            'phone' => '09' . random_int(10000000, 99999999),
            'debt_amount' => 1200000,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'active',
        ]);

        $base = storage_path('app/testing/debt-diff-v2-' . $name . '-' . uniqid());
        @mkdir($base, 0755, true);
        $inspectJson = $base . DIRECTORY_SEPARATOR . 'inspect.json';
        $planJson = $base . DIRECTORY_SEPARATOR . 'plan.json';

        file_put_contents($inspectJson, json_encode($this->inspectionPayload($customer), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        file_put_contents($planJson, json_encode($this->planPayload($customer, $group), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [$customer, $inspectJson, $planJson, $base];
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
            'raw' => [
                'customer_debts' => [],
                'supplier_debt_transactions' => [],
                'invoices' => [[
                    'id' => 210,
                    'code' => 'HD-V2-001',
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
                    'id' => 310,
                    'code' => 'PT-V2-001',
                    'amount' => 300000,
                    'time' => '2026-01-02 09:05:00',
                    'reference_type' => 'Invoice',
                    'reference_code' => 'HD-V2-001',
                    'status' => 'active',
                ]],
                'debt_offsets' => [],
            ],
            'timelines' => [],
        ];
    }

    private function planPayload(Customer $customer, string $group): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'dry_run' => true,
            'plans' => [[
                'id' => (string) $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'classification' => $group === 'B_DOCUMENTS_NO_LEDGER' ? 'HAS_DOCUMENTS_NO_LEDGER' : 'DOCUMENT_LEDGER_MISMATCH',
                'diagnosis' => $group === 'B_DOCUMENTS_NO_LEDGER' ? 'documents_exist_but_no_ledger' : 'ledger_and_documents_mismatch',
                'fix_group' => $group,
                'authority_candidate' => $group === 'B_DOCUMENTS_NO_LEDGER' ? 'document' : 'manual_review',
                'proposed_write_operations' => [],
            ]],
        ];
    }
}
