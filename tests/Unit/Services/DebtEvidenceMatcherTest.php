<?php

namespace Tests\Unit\Services;

use App\Services\DebtEvidenceMatcher;
use PHPUnit\Framework\TestCase;

class DebtEvidenceMatcherTest extends TestCase
{
    public function test_purchase_payment_code_normalizes_to_purchase_base_code(): void
    {
        $result = $this->matcher()->match($this->inspection([
            'purchases' => [[
                'id' => 10,
                'code' => 'PN20260315085945',
                'purchase_date' => '2026-03-15 08:59:45',
                'status' => 'completed',
                'total_amount' => 1000000,
                'outstanding' => 0,
            ]],
            'cash_flows' => [[
                'id' => 20,
                'code' => 'PCPN20260315085945',
                'reference_code' => 'PCPN20260315085945',
                'amount' => 1000000,
                'time' => '2026-03-15 09:00:00',
                'status' => 'active',
            ]],
        ]), $this->plan('B_DOCUMENTS_NO_LEDGER'));

        $this->assertNotEmpty($result['possible_matches']);
        $this->assertSame('PN20260315085945', $result['document_rows'][0]['normalized_code']['base_code']);
        $this->assertTrue($this->hasPossibleMatch($result['possible_matches'], 'PCPN20260315085945', 'normalized_base_code'));
    }

    public function test_zero_effect_document_is_not_high_missing_ledger_issue(): void
    {
        $result = $this->matcher()->match($this->inspection([
            'invoices' => [[
                'id' => 11,
                'code' => 'HD-ZERO-001',
                'transaction_date' => '2026-01-01 10:00:00',
                'status' => 'completed',
                'total' => 500000,
                'outstanding' => 0,
            ]],
        ]), $this->plan('C_LEDGER_DOCUMENT_MISMATCH'));

        $this->assertSame('ZERO_EFFECT_DOCUMENT', $result['matching_matrix'][0]['match_status']);
        $this->assertSame([], $result['detected_issues']);
    }

    public function test_merge_ledger_requires_manual_authority(): void
    {
        $result = $this->matcher()->match($this->inspection([
            'customer_debts' => [[
                'id' => 30,
                'code' => 'MERGE-CUSTOMER-141',
                'amount' => 2500000,
                'recorded_at' => '2026-01-02 09:00:00',
            ]],
        ]), $this->plan('C_LEDGER_DOCUMENT_MISMATCH'));

        $this->assertSame('MANUAL_LEDGER_REQUIRES_AUTHORITY', $result['matching_matrix'][0]['match_status']);
        $this->assertFalse($result['candidate_preview']['candidate_ready']);
    }

    public function test_amount_date_near_is_possible_match_not_auto_candidate(): void
    {
        $result = $this->matcher()->match($this->inspection([
            'invoices' => [[
                'id' => 40,
                'code' => 'HD-AMOUNT-DATE',
                'transaction_date' => '2026-02-01 10:00:00',
                'status' => 'completed',
                'total' => 700000,
                'outstanding' => 700000,
            ]],
            'customer_debts' => [[
                'id' => 41,
                'code' => 'OTHER-CODE',
                'amount' => 700000,
                'recorded_at' => '2026-02-03 10:00:00',
            ]],
        ]), $this->plan('B_DOCUMENTS_NO_LEDGER'));

        $row = $result['matching_matrix'][0];

        $this->assertSame('MATCHED_AMOUNT_DATE', $row['match_status']);
        $this->assertSame('amount_date_near', $row['best_match_type']);
        $this->assertFalse($row['can_auto_candidate']);
    }

    public function test_group_c_never_builds_write_preview(): void
    {
        $result = $this->matcher()->match($this->inspection([
            'invoices' => [[
                'id' => 50,
                'code' => 'HD-GROUP-C',
                'transaction_date' => '2026-02-01 10:00:00',
                'status' => 'completed',
                'total' => 700000,
                'outstanding' => 700000,
            ]],
        ]), $this->plan('C_LEDGER_DOCUMENT_MISMATCH'));

        $this->assertFalse($result['candidate_preview']['candidate_ready']);
        $this->assertSame([], $result['candidate_preview']['write_operations_preview']);
    }

    public function test_group_b_builds_preview_for_missing_ledger_document(): void
    {
        $result = $this->matcher()->match($this->inspection([
            'invoices' => [[
                'id' => 60,
                'code' => 'HD-GROUP-B',
                'transaction_date' => '2026-02-01 10:00:00',
                'status' => 'completed',
                'total' => 900000,
                'outstanding' => 900000,
            ]],
        ]), $this->plan('B_DOCUMENTS_NO_LEDGER'));

        $this->assertTrue($result['candidate_preview']['candidate_ready']);
        $this->assertCount(1, $result['candidate_preview']['write_operations_preview']);
        $this->assertSame(60, $result['candidate_preview']['write_operations_preview'][0]['source_document_id']);
    }

    public function test_debt_adjustment_cashflow_blocks_invoice_ledger_preview(): void
    {
        $result = $this->matcher()->match($this->inspection([
            'invoices' => [[
                'id' => 57,
                'code' => 'HD177598589311',
                'created_at' => '2026-03-27 15:09:00',
                'status' => 'completed',
                'total' => 15000000,
                'customer_paid' => 0,
                'outstanding' => 15000000,
            ]],
            'cash_flows' => [[
                'id' => 290,
                'code' => 'PT26042215161822',
                'type' => 'receipt',
                'amount' => 15000000,
                'time' => '2026-04-22 15:16:00',
                'reference_type' => 'DebtAdjustment',
                'reference_code' => null,
                'note' => 'Dieu chinh cong no | 15,000,000 -> 0',
            ]],
        ]), $this->plan('B_DOCUMENTS_NO_LEDGER'));

        $this->assertSame('POSSIBLE_SETTLEMENT_PAIR', $result['matching_matrix'][0]['match_status']);
        $this->assertFalse($result['candidate_preview']['candidate_ready']);
        $this->assertSame('possible settlement cashflow exists; manual decision required', $result['candidate_preview']['blocked_reason']);
        $this->assertSame([], $result['candidate_preview']['write_operations_preview']);
    }

    private function matcher(): DebtEvidenceMatcher
    {
        return new DebtEvidenceMatcher();
    }

    private function hasPossibleMatch(array $matches, string $candidate, string $strategy): bool
    {
        foreach ($matches as $match) {
            if (($match['candidate'] ?? '') === $candidate && in_array($strategy, $match['strategies'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    private function plan(string $group): array
    {
        return [
            'code' => 'TEST-PARTNER',
            'fix_group' => $group,
        ];
    }

    private function inspection(array $raw): array
    {
        return [
            'partner' => [
                'id' => 1,
                'code' => 'TEST-PARTNER',
                'is_customer' => true,
                'is_supplier' => false,
            ],
            'stored_balances' => [
                'customer_receivable' => 0,
                'supplier_payable' => 0,
            ],
            'raw' => array_merge([
                'customer_debts' => [],
                'supplier_debt_transactions' => [],
                'invoices' => [],
                'order_returns' => [],
                'purchases' => [],
                'purchase_returns' => [],
                'cash_flows' => [],
                'debt_offsets' => [],
            ], $raw),
        ];
    }
}
