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
use PhpOffice\PhpSpreadsheet\Style\Border;
use Tests\TestCase;

/**
 * HOTFIX 24.17F — Excel internal-consistency + footer.
 *
 * Pins three pieces:
 *  1) doc row Ghi nợ for a purchase = sum of its detail Thành tiền
 *     (including the negative "Giảm giá hóa đơn" line). Previously the
 *     ledger pushed supplier_effect = gross total, and the discount
 *     line below subtracted from the detail sum but not from the doc
 *     row — so the two read different totals.
 *  2) Khối tổng hợp "Phát sinh trong kỳ" K cell = sum of all visible
 *     doc-row K cells. Same K column the body uses.
 *  3) Footer carries an export-date line and three signature blocks
 *     ("Nhà cung cấp" / "Người lập biểu" / "TM Công ty") each with a
 *     "(Ký, họ tên)" sub-row.
 */
class HOTFIX2417FSupplierDebtExcelReconcileAndFooterTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 2417F',
            'email'    => 'admin-2417f-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function supplier(string $name = 'NCC 2417F'): Customer
    {
        return Customer::create([
            'code'                 => 'NCC-2417F-' . uniqid(),
            'name'                 => $name,
            'phone'                => '09' . random_int(10000000, 99999999),
            'debt_amount'          => 0,
            'supplier_debt_amount' => 0,
            'is_customer'          => false,
            'is_supplier'          => true,
        ]);
    }

    private function purchaseWith(Customer $sup, array $items, int $docDiscount = 0, ?Carbon $when = null): Purchase
    {
        $when = $when ?? Carbon::now();
        $gross = 0;
        foreach ($items as $i) $gross += (int) $i['subtotal'];

        $p = Purchase::create([
            'code'          => 'PN-2417F-' . uniqid(),
            'supplier_id'   => $sup->id,
            'user_id'       => null,
            'total_amount'  => $gross,
            'discount'      => $docDiscount,
            'paid_amount'   => 0,
            'debt_amount'   => $gross - $docDiscount,
            'status'        => 'completed',
            'purchase_date' => $when,
        ]);
        $p->created_at = $when;
        $p->updated_at = $when;
        $p->save();

        foreach ($items as $i) {
            PurchaseItem::create([
                'purchase_id'  => $p->id,
                'product_name' => $i['name'],
                'product_code' => $i['code'] ?? 'CODE-' . uniqid(),
                'quantity'     => $i['qty'],
                'price'        => $i['price'],
                'discount'     => $i['discount'] ?? 0,
                'subtotal'     => $i['subtotal'],
            ]);
        }
        return $p;
    }

    private function payment(Customer $sup, int $amount): SupplierDebtTransaction
    {
        return SupplierDebtTransaction::create([
            'supplier_id' => $sup->id,
            'code'        => 'PCPN-2417F-' . uniqid(),
            'type'        => 'payment',
            'amount'      => -$amount,
            'debt_remain' => 0,
            'note'        => 'Test payment 2417F',
            'user_id'     => null,
        ]);
    }

    private function downloadWorkbook(int $supplierId, string $query, User $actor): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $res = $this->actingAs($actor)->get("/api/suppliers/{$supplierId}/export-debt?{$query}");
        $res->assertOk();
        $body = $res->streamedContent() ?: $res->getContent();
        $tmp  = tempnam(sys_get_temp_dir(), 'cnct-f-') . '.xlsx';
        file_put_contents($tmp, $body);
        try {
            return IOFactory::load($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * The summary block lives across three consecutive rows whose
     * column I cells carry the Vietnamese labels. Look them up by
     * label rather than by absolute row to stay robust against
     * future store-header growth.
     */
    private function findSummaryRow(array $cells, string $label): ?array
    {
        foreach ($cells as $row) {
            if (($row['I'] ?? '') === $label) return $row;
        }
        return null;
    }

    // ── TC-01 — doc row K == sum of detail Thành tiền with doc discount ──
    public function test_purchase_doc_row_matches_detail_sum_when_document_discount_exists(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();

        // Three items + a doc-level discount of 11 (mirrors the 11đ
        // off-by reported in the bug screenshot).
        $items = [
            ['code' => 'A', 'name' => 'SP A', 'qty' => 2, 'price' => 5_000_000, 'discount' => 0,       'subtotal' => 10_000_000],
            ['code' => 'B', 'name' => 'SP B', 'qty' => 1, 'price' => 7_500_000, 'discount' => 0,       'subtotal' => 7_500_000],
            ['code' => 'C', 'name' => 'SP C', 'qty' => 3, 'price' => 4_000_000, 'discount' => 200_000, 'subtotal' => 11_800_000],
        ];
        $docDiscount = 11;
        $p = $this->purchaseWith($sup, $items, $docDiscount);

        $wb    = $this->downloadWorkbook(
            $sup->id,
            'format=xlsx&date_preset=all&include_detail=1&columns[]=quantity&columns[]=unit_price&columns[]=discount&columns[]=line_total',
            $admin
        );
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, true);

        // Find the doc row + collect detail rows below it until the
        // next document boundary (here: until EOF).
        $docRow = null; $docIdx = null;
        foreach ($cells as $idx => $row) {
            if (($row['B'] ?? '') === $p->code && ($row['C'] ?? '') === 'Nhập hàng') {
                $docRow = $row; $docIdx = $idx; break;
            }
        }
        $this->assertNotNull($docRow, 'purchase doc row must appear');

        $detailSum = 0;
        $names     = ['SP A', 'SP B', 'SP C', 'Giảm giá hóa đơn'];
        foreach ($cells as $idx => $row) {
            if ($idx <= $docIdx) continue;
            if (in_array($row['C'] ?? '', $names, true)) {
                $detailSum += (int) ($row['J'] ?? 0);
            }
        }

        $expectedNet = 29_300_000 - $docDiscount; // 29,299,989
        $this->assertEquals($expectedNet, (int) ($docRow['K'] ?? 0),
            'doc row K must equal Σ items.subtotal − purchases.discount');
        $this->assertEquals($expectedNet, $detailSum,
            'sum of detail Thành tiền (incl. negative Giảm giá hóa đơn) must equal doc row K');
    }

    // ── TC-02 — summary periodDebit equals Σ of visible doc K cells ──
    public function test_summary_period_debit_matches_visible_document_debits(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $p1 = $this->purchaseWith($sup, [
            ['name' => 'X', 'qty' => 1, 'price' => 4_000_000, 'subtotal' => 4_000_000],
        ], docDiscount: 0);
        $p2 = $this->purchaseWith($sup, [
            ['name' => 'Y', 'qty' => 1, 'price' => 6_000_000, 'subtotal' => 6_000_000],
        ], docDiscount: 500_000);
        $this->payment($sup, 1_000_000);

        $wb    = $this->downloadWorkbook($sup->id, 'format=xlsx&date_preset=all&include_detail=1', $admin);
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, true);

        $sumDebit = 0;
        foreach ($cells as $row) {
            $code = (string) ($row['B'] ?? '');
            $type = (string) ($row['C'] ?? '');
            // Only doc rows — synthetic discount + per-item lines have empty B or non-doc C.
            if ($code === '' || in_array($type, ['', 'Giảm giá hóa đơn'], true)) continue;
            // Identify "real" doc rows by non-empty C and recognised type labels.
            if (!in_array($type, ['Nhập hàng', 'Thanh toán', 'Trả hàng', 'Điều chỉnh', 'Chiết khấu TT'], true)) continue;
            $sumDebit += (int) ($row['K'] ?? 0);
        }
        $summary = $this->findSummaryRow($cells, 'Phát sinh trong kỳ:');
        $this->assertNotNull($summary);
        $this->assertEquals($sumDebit, (int) ($summary['K'] ?? 0),
            'summary periodDebit must equal Σ visible doc K cells');
        // Sanity: expected = 4M (p1) + (6M - 500k) (p2 net) = 9,500,000.
        $this->assertEquals(9_500_000, (int) ($summary['K'] ?? 0));
    }

    // ── TC-03 — discount row never touches K/L (regression for 24.17D/F) ──
    public function test_discount_row_does_not_touch_debit_credit_columns(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $this->purchaseWith($sup, [
            ['name' => 'Z', 'qty' => 1, 'price' => 10_000_000, 'subtotal' => 10_000_000],
        ], docDiscount: 1_000_000);

        $wb    = $this->downloadWorkbook(
            $sup->id,
            'format=xlsx&date_preset=all&include_detail=1&columns[]=discount&columns[]=line_total',
            $admin
        );
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, true);

        $discRow = null;
        foreach ($cells as $row) {
            if (($row['C'] ?? '') === 'Giảm giá hóa đơn') { $discRow = $row; break; }
        }
        $this->assertNotNull($discRow);
        $this->assertEquals(1_000_000, (int) ($discRow['G'] ?? 0), 'Giảm giá column on discount row');
        $this->assertEquals(-1_000_000, (int) ($discRow['J'] ?? 0), 'Thành tiền on discount row is negative');
        $this->assertEmpty($discRow['K'] ?? '', 'K must stay empty on discount row');
        $this->assertEmpty($discRow['L'] ?? '', 'L must stay empty on discount row');
    }

    // ── TC-04 — outer border medium present + inner heavy borders removed ──
    public function test_table_has_outer_border_and_lighter_inner_borders(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $this->purchaseWith($sup, [
            ['name' => 'W', 'qty' => 1, 'price' => 1_000_000, 'subtotal' => 1_000_000],
        ]);

        $wb    = $this->downloadWorkbook($sup->id, 'format=xlsx&date_preset=all&include_detail=1', $admin);
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, true);

        // Find header row by locating the cell with text "Thời gian".
        $headerRowIdx = null;
        foreach ($cells as $idx => $row) {
            if (($row['A'] ?? '') === 'Thời gian') { $headerRowIdx = $idx; break; }
        }
        $this->assertNotNull($headerRowIdx, 'must find header row by "Thời gian" label');

        $topLeft = $sheet->getStyle('A' . $headerRowIdx);
        $this->assertSame(Border::BORDER_MEDIUM, $topLeft->getBorders()->getTop()->getBorderStyle(),
            'outer top border must be medium');
        $this->assertSame(Border::BORDER_MEDIUM, $topLeft->getBorders()->getLeft()->getBorderStyle(),
            'outer left border must be medium');

        // Pick a body cell that is NOT on the outer left edge (column C
        // of the doc row) — its left border must NOT be medium any
        // more (used to be BORDER_THIN every cell).
        $bodyRowIdx = $headerRowIdx + 1;
        $innerStyle = $sheet->getStyle('C' . $bodyRowIdx)->getBorders();
        $this->assertNotSame(Border::BORDER_MEDIUM, $innerStyle->getLeft()->getBorderStyle(),
            'inner vertical borders must NOT be medium');
    }

    // ── TC-05 — footer carries date + 3 signature blocks ──
    public function test_footer_contains_export_date_and_signature_blocks(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $this->purchaseWith($sup, [
            ['name' => 'V', 'qty' => 1, 'price' => 2_000_000, 'subtotal' => 2_000_000],
        ]);

        $wb    = $this->downloadWorkbook($sup->id, 'format=xlsx&date_preset=all&include_detail=1', $admin);
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, false);
        $flat = '';
        foreach ($cells as $row) {
            foreach ($row as $val) $flat .= "\t" . (string) $val;
        }

        $now = Carbon::now();
        $expectedDate = sprintf('Ngày %d tháng %d năm %d', $now->day, $now->month, $now->year);
        $this->assertStringContainsString($expectedDate, $flat, 'footer export date must appear');
        $this->assertStringContainsString('Nhà cung cấp', $flat);
        $this->assertStringContainsString('Người lập biểu', $flat);
        $this->assertStringContainsString('TM Công ty', $flat);
        $this->assertStringContainsString('(Ký, họ tên)', $flat);
    }

    // ── TC-06 — purchase WITHOUT discount → no synthetic row (regression 24.17D) ──
    public function test_purchase_without_discount_has_no_synthetic_row(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $this->purchaseWith($sup, [
            ['name' => 'U', 'qty' => 1, 'price' => 3_000_000, 'subtotal' => 3_000_000],
        ]);

        $wb    = $this->downloadWorkbook($sup->id, 'format=xlsx&date_preset=all&include_detail=1', $admin);
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, false);
        $flat = '';
        foreach ($cells as $row) {
            foreach ($row as $val) $flat .= "\t" . (string) $val;
        }
        $this->assertStringNotContainsString('Giảm giá hóa đơn', $flat);
    }
}
