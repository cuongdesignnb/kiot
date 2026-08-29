<?php

namespace Tests\Feature\Supplier;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * HOTFIX 24.17E — Summary block (Nợ đầu kỳ / Phát sinh trong kỳ /
 * Nợ cuối kỳ) must live under the same J/K columns the body uses:
 *
 *   J = "Ghi nợ"   (debit side)
 *   K = "Ghi có"   (credit side)
 *
 * Before this fix the period row pushed `debit` into column I
 * ("Thành tiền") and left K empty, which read visually like a column
 * shift against every document row beneath it.
 */
class HOTFIX2417ESupplierDebtExcelSummaryAlignmentTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin 2417E',
            'email' => 'admin-2417e-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function supplier(string $name = 'NCC 2417E'): Customer
    {
        return Customer::create([
            'code' => 'NCC-2417E-'.uniqid(),
            'name' => $name,
            'phone' => '09'.random_int(10000000, 99999999),
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => false,
            'is_supplier' => true,
        ]);
    }

    private function purchase(Customer $sup, int $total, Carbon $when): Purchase
    {
        $p = Purchase::create([
            'code' => 'PN-2417E-'.uniqid(),
            'supplier_id' => $sup->id,
            'user_id' => null,
            'total_amount' => $total,
            'paid_amount' => 0,
            'debt_amount' => $total,
            'status' => 'completed',
            'purchase_date' => $when,
        ]);
        $p->created_at = $when;
        $p->updated_at = $when;
        $p->save();
        PurchaseItem::create([
            'purchase_id' => $p->id,
            'product_name' => 'SP 2417E',
            'product_code' => 'SP-E',
            'quantity' => 1,
            'price' => $total,
            'discount' => 0,
            'subtotal' => $total,
        ]);

        return $p;
    }

    private function payment(Customer $sup, int $amount): SupplierDebtTransaction
    {
        return SupplierDebtTransaction::create([
            'supplier_id' => $sup->id,
            'code' => 'PCPN-2417E-'.uniqid(),
            'type' => 'payment',
            'amount' => -$amount,
            'debt_remain' => 0,
            'note' => 'Test payment 2417E',
            'user_id' => null,
        ]);
    }

    private function downloadWorkbook(int $supplierId, string $query, User $actor): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $res = $this->actingAs($actor)->get("/api/suppliers/{$supplierId}/export-debt?{$query}");
        $res->assertOk();
        $body = $res->streamedContent() ?: $res->getContent();
        $tmp = tempnam(sys_get_temp_dir(), 'cnct-e-').'.xlsx';
        file_put_contents($tmp, $body);
        try {
            return IOFactory::load($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Find the row index whose column H matches `$label`. The summary
     * lives across three consecutive rows so we look up each label
     * separately instead of guessing absolute row numbers (those drift
     * as the store-header block grows).
     */
    private function findSummaryRow(array $cells, string $label): ?array
    {
        foreach ($cells as $row) {
            if (($row['H'] ?? '') === $label) {
                return $row;
            }
        }

        return null;
    }

    // ── TC-01 — debit lands in J (Ghi nợ), not I (Thành tiền) ──
    public function test_summary_period_debit_is_under_ghi_no_column(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $this->purchase($sup, 7_000_000, Carbon::now());

        $wb = $this->downloadWorkbook($sup->id, 'format=xlsx&date_preset=all', $admin);
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, true);

        $row = $this->findSummaryRow($cells, 'Phát sinh trong kỳ:');
        $this->assertNotNull($row, '"Phát sinh trong kỳ:" row must exist in the summary block');
        $this->assertEquals(7_000_000, (int) ($row['J'] ?? 0), 'period debit must live in column J (Ghi nợ)');
        $this->assertEmpty($row['I'] ?? '', 'column I (Thành tiền) must stay empty in the summary row');
    }

    // ── TC-02 — credit lands in K (Ghi có) ──
    public function test_summary_period_credit_is_under_ghi_co_column(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $this->purchase($sup, 7_000_000, Carbon::now());
        $this->payment($sup, 2_000_000);

        $wb = $this->downloadWorkbook($sup->id, 'format=xlsx&date_preset=all', $admin);
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, true);

        $row = $this->findSummaryRow($cells, 'Phát sinh trong kỳ:');
        $this->assertNotNull($row);
        $this->assertEquals(7_000_000, (int) ($row['J'] ?? 0), 'period debit in J');
        // The 5M persisted-ledger checkpoint is presentation-only and is
        // folded into opening debt. Only the real 2M payment is a period
        // credit transaction.
        $this->assertEquals(2_000_000, (int) ($row['K'] ?? 0), 'period credit in K contains real payments only');

        $opening = $this->findSummaryRow($cells, 'Nợ đầu kỳ:');
        $this->assertNotNull($opening);
        $this->assertEquals(5_000_000, (int) ($opening['K'] ?? 0), 'hidden checkpoint is absorbed into negative opening debt');
        $this->assertStringNotContainsString('CHECKPOINT-', implode(' ', array_map(
            static fn (array $sheetRow): string => implode(' ', array_filter($sheetRow)),
            $cells,
        )));
    }

    // ── TC-03 — positive opening + closing align with K column ──
    public function test_opening_and_closing_debt_align_with_summary_debt_column(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        // The saved zero target requires a hidden -4M checkpoint. Public
        // presentation absorbs it into opening debt, while the body retains
        // only the real purchase and payment rows.
        $this->purchase($sup, 5_000_000, Carbon::now());
        $this->payment($sup, 1_000_000);

        $wb = $this->downloadWorkbook($sup->id, 'format=xlsx&date_preset=all', $admin);
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, true);

        $opening = $this->findSummaryRow($cells, 'Nợ đầu kỳ:');
        $closing = $this->findSummaryRow($cells, 'Nợ cuối kỳ:');
        $this->assertNotNull($opening);
        $this->assertNotNull($closing);

        $this->assertEmpty($opening['J'] ?? '', 'negative opening must not spill into J');
        $this->assertEquals(4_000_000, (int) ($opening['K'] ?? 0));

        // The persisted ledger checkpoint restores the saved zero balance.
        $this->assertEquals(0, (int) ($closing['J'] ?? 0));
        $this->assertEmpty($closing['K'] ?? '', 'zero closing must NOT spill into K');
    }

    // ── TC-04 — Body still uses J/K correctly (regression for 24.17B) ──
    public function test_body_document_rows_still_use_j_and_k(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $purchase = $this->purchase($sup, 3_000_000, Carbon::now());
        $this->payment($sup, 500_000);

        $wb = $this->downloadWorkbook($sup->id, 'format=xlsx&date_preset=all', $admin);
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, true);

        $purchaseRow = null;
        $paymentRow = null;
        foreach ($cells as $row) {
            if (($row['B'] ?? '') === $purchase->code && ($row['C'] ?? '') === 'Nhập hàng') {
                $purchaseRow = $row;
            }
            if (($row['C'] ?? '') === 'Thanh toán' && str_starts_with((string) ($row['B'] ?? ''), 'PCPN')) {
                $paymentRow = $row;
            }
        }
        $this->assertNotNull($purchaseRow);
        $this->assertNotNull($paymentRow);
        $this->assertEquals(3_000_000, (int) ($purchaseRow['J'] ?? 0), 'purchase debit must remain in J');
        $this->assertEmpty($purchaseRow['K'] ?? '', 'purchase row K must remain empty');
        $this->assertEquals(500_000, (int) ($paymentRow['K'] ?? 0), 'payment credit must remain in K');
        $this->assertEmpty($paymentRow['J'] ?? '', 'payment row J must remain empty');
    }
}
