<?php

namespace Tests\Feature\Supplier;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * HOTFIX 24.17D — Surface purchase discount in the debt Excel.
 *
 * Two layers of discount can live on a purchase:
 *  - `purchase_items.discount` (line-level)  → flows into the per-line
 *     row's "Giảm giá" column. Already handled since 24.17B.
 *  - `purchases.discount` (document-level) → was silently dropped by
 *     the export. This suite pins the new behaviour: a synthetic
 *     "Giảm giá hóa đơn" detail row is appended beneath the line items
 *     and the document-row Ghi nợ value is left untouched (the ledger
 *     remains the source of truth for debt amounts).
 */
class HOTFIX2417DSupplierDebtExcelPurchaseDiscountTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 2417D',
            'email'    => 'admin-2417d-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function supplier(string $name = 'NCC 2417D'): Customer
    {
        return Customer::create([
            'code'                 => 'NCC-2417D-' . uniqid(),
            'name'                 => $name,
            'phone'                => '09' . random_int(10000000, 99999999),
            'debt_amount'          => 0,
            'supplier_debt_amount' => 0,
            'is_customer'          => false,
            'is_supplier'          => true,
        ]);
    }

    private function purchase(Customer $sup, array $opts = []): Purchase
    {
        $p = Purchase::create([
            'code'          => $opts['code'] ?? ('PN-2417D-' . uniqid()),
            'supplier_id'   => $sup->id,
            'user_id'       => null,
            'total_amount'  => $opts['total_amount'] ?? 1_000_000,
            'discount'      => $opts['discount'] ?? 0,
            'paid_amount'   => $opts['paid_amount'] ?? 0,
            'debt_amount'   => $opts['debt_amount'] ?? 1_000_000,
            'status'        => 'completed',
            'purchase_date' => $opts['when'] ?? Carbon::now(),
        ]);
        if (!empty($opts['when'])) {
            $p->created_at = $opts['when'];
            $p->updated_at = $opts['when'];
            $p->save();
        }
        return $p;
    }

    private function downloadWorkbook(int $supplierId, string $query, User $actor): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $res = $this->actingAs($actor)->get("/api/suppliers/{$supplierId}/export-debt?{$query}");
        $res->assertOk();
        $body = $res->streamedContent() ?: $res->getContent();
        $tmp  = tempnam(sys_get_temp_dir(), 'cnct-d-') . '.xlsx';
        file_put_contents($tmp, $body);
        try {
            return IOFactory::load($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    // ── TC-01 — line-level discount lands in the "Giảm giá" column ──
    public function test_purchase_item_discount_is_exported_to_discount_column(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        // 16 × 4,721,268 - 5,000,000 = 70,540,288
        $qty       = 16;
        $unitPrice = 4_721_268;
        $lineDisc  = 5_000_000;
        $lineTotal = $qty * $unitPrice - $lineDisc;

        $p = $this->purchase($sup, [
            'total_amount' => $lineTotal,
            'debt_amount'  => $lineTotal,
        ]);
        PurchaseItem::create([
            'purchase_id'  => $p->id,
            'product_name' => 'Macbook Air M3',
            'product_code' => 'MBA-M3',
            'quantity'     => $qty,
            'price'        => $unitPrice,
            'discount'     => $lineDisc,
            'subtotal'     => $lineTotal,
        ]);

        $wb    = $this->downloadWorkbook(
            $sup->id,
            'format=xlsx&date_preset=all&include_detail=1&columns[]=quantity&columns[]=unit_price&columns[]=discount&columns[]=line_total',
            $admin
        );
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, true);

        $itemRow = null;
        foreach ($cells as $row) {
            if (($row['C'] ?? '') === 'Macbook Air M3') {
                $itemRow = $row;
                break;
            }
        }
        $this->assertNotNull($itemRow, 'product detail row must appear');
        $this->assertEquals($lineDisc, (int) ($itemRow['G'] ?? 0), 'Giảm giá column must hold the line discount');
        $this->assertEquals($lineTotal, (int) ($itemRow['J'] ?? 0), 'Thành tiền must equal qty*price - discount');
    }

    // ── TC-02 — document-level discount surfaces as a "Giảm giá hóa đơn" row ──
    public function test_purchase_document_level_discount_is_not_lost(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $docDisc = 2_500_000;
        $p = $this->purchase($sup, [
            'total_amount' => 50_000_000,
            'discount'     => $docDisc,
            'debt_amount'  => 47_500_000,
        ]);
        PurchaseItem::create([
            'purchase_id'  => $p->id,
            'product_name' => 'Dell XPS 13',
            'product_code' => 'XPS-13',
            'quantity'     => 1,
            'price'        => 50_000_000,
            'discount'     => 0,
            'subtotal'     => 50_000_000,
        ]);

        $wb    = $this->downloadWorkbook(
            $sup->id,
            'format=xlsx&date_preset=all&include_detail=1&columns[]=quantity&columns[]=unit_price&columns[]=discount&columns[]=line_total',
            $admin
        );
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, true);

        $discountRow = null;
        foreach ($cells as $row) {
            if (($row['C'] ?? '') === 'Giảm giá hóa đơn') {
                $discountRow = $row;
                break;
            }
        }
        $this->assertNotNull($discountRow, '"Giảm giá hóa đơn" detail row must appear when purchases.discount > 0');
        $this->assertEquals($docDisc, (int) ($discountRow['G'] ?? 0), 'Giảm giá column on the synthetic row must equal purchases.discount');
        // Sanity: the synthetic row sits BELOW the line items, not above.
        $itemIdx = null; $discIdx = null;
        foreach ($cells as $idx => $row) {
            if (($row['C'] ?? '') === 'Dell XPS 13') $itemIdx = $idx;
            if (($row['C'] ?? '') === 'Giảm giá hóa đơn') $discIdx = $idx;
        }
        $this->assertNotNull($itemIdx);
        $this->assertNotNull($discIdx);
        $this->assertGreaterThan($itemIdx, $discIdx, 'discount summary row sits below the item rows');
    }

    // ── TC-03 — discount row never touches Ghi nợ / Ghi có ──
    public function test_purchase_discount_does_not_change_debt_amount(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $p = $this->purchase($sup, [
            'total_amount' => 10_000_000,
            'discount'     => 1_000_000,
            'debt_amount'  => 9_000_000,
        ]);
        PurchaseItem::create([
            'purchase_id'  => $p->id,
            'product_name' => 'Linh kiện Z',
            'product_code' => 'LK-Z',
            'quantity'     => 1,
            'price'        => 10_000_000,
            'discount'     => 0,
            'subtotal'     => 10_000_000,
        ]);

        $wb    = $this->downloadWorkbook(
            $sup->id,
            'format=xlsx&date_preset=all&include_detail=1&columns[]=discount',
            $admin
        );
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, true);

        $docRow = null; $discRow = null;
        foreach ($cells as $row) {
            if (($row['B'] ?? '') === $p->code && ($row['C'] ?? '') === 'Nhập hàng') $docRow = $row;
            if (($row['C'] ?? '') === 'Giảm giá hóa đơn') $discRow = $row;
        }
        $this->assertNotNull($docRow, 'purchase document row must appear');
        $this->assertNotNull($discRow, 'discount summary row must appear');
        // Document row Ghi nợ = NET of purchases.discount. HOTFIX 24.17F
        // promoted this from "match ledger gross (10M)" to "match what
        // the detail rows sum to" so the body is internally consistent
        // (item subtotal 10M + discount row -1M = 9M). The legacy
        // ledger still emits a 10M gross supplier_effect; Excel renders
        // the display-net view instead. See HOTFIX 24.17F audit doc.
        $this->assertEquals(9_000_000, (int) ($docRow['K'] ?? 0), 'Ghi nợ on doc row must match net of purchases.discount');
        $this->assertEmpty($docRow['L'] ?? '', 'Ghi có on doc row must remain empty for a purchase');
        // Discount summary row must NOT carry Ghi nợ / Ghi có.
        $this->assertEmpty($discRow['K'] ?? '', 'discount summary row must NOT touch Ghi nợ');
        $this->assertEmpty($discRow['L'] ?? '', 'discount summary row must NOT touch Ghi có');
    }

    // ── TC-04 — purchase with NO discount → no synthetic row, no regression ──
    public function test_purchase_without_discount_has_no_synthetic_row(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $p = $this->purchase($sup, ['total_amount' => 3_000_000, 'debt_amount' => 3_000_000]);
        PurchaseItem::create([
            'purchase_id'  => $p->id,
            'product_name' => 'Sản phẩm thường',
            'product_code' => 'SP-N',
            'quantity'     => 1,
            'price'        => 3_000_000,
            'discount'     => 0,
            'subtotal'     => 3_000_000,
        ]);

        $wb    = $this->downloadWorkbook($sup->id, 'format=xlsx&date_preset=all&include_detail=1', $admin);
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, false);
        $flat  = '';
        foreach ($cells as $row) {
            foreach ($row as $val) $flat .= "\t" . (string) $val;
        }
        $this->assertStringNotContainsString('Giảm giá hóa đơn', $flat, 'no synthetic row when purchases.discount = 0');
    }
}
