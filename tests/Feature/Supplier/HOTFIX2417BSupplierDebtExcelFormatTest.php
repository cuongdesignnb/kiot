<?php

namespace Tests\Feature\Supplier;

use App\Models\CashFlow;
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
 * HOTFIX 24.17B — Supplier debt export as formatted .xlsx.
 *
 * Pins the KiotViet-style workbook layout returned when the export
 * URL carries `format=xlsx`, while preserving the legacy no-query
 * CSV path (HOTFIX 24.14 / 24.17 contract) and the debt-transactions
 * JSON shape.
 */
class HOTFIX2417BSupplierDebtExcelFormatTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin 2417B',
            'email' => 'admin-2417b-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function supplier(string $name = 'NCC 2417B'): Customer
    {
        return Customer::create([
            'code' => 'NCC-2417B-'.uniqid(),
            'name' => $name,
            'phone' => '09'.random_int(10000000, 99999999),
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => false,
            'is_supplier' => true,
        ]);
    }

    private function purchase(Customer $supplier, int $total, Carbon $when, string $codePrefix = 'PN'): Purchase
    {
        $p = Purchase::create([
            'code' => $codePrefix.'-'.uniqid(),
            'supplier_id' => $supplier->id,
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

        return $p;
    }

    private function downloadWorkbook(int $supplierId, string $query, User $actor): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $res = $this->actingAs($actor)->get("/api/suppliers/{$supplierId}/export-debt?{$query}");
        $res->assertOk();
        $body = $res->streamedContent() ?: $res->getContent();
        $tmp = tempnam(sys_get_temp_dir(), 'cnct-').'.xlsx';
        file_put_contents($tmp, $body);
        try {
            return IOFactory::load($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    // ── TC-01 — xlsx response: Content-Type + filename ──
    public function test_export_xlsx_returns_excel_response(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $this->purchase($sup, 1_000_000, Carbon::now());

        $res = $this->actingAs($admin)->get(
            "/api/suppliers/{$sup->id}/export-debt?format=xlsx&date_preset=all&include_detail=1"
        );
        $res->assertOk();

        $contentType = strtolower($res->headers->get('Content-Type') ?? '');
        $this->assertStringContainsString('spreadsheetml.sheet', $contentType, 'must be xlsx mime');

        $disposition = $res->headers->get('Content-Disposition') ?? '';
        $this->assertStringContainsString('.xlsx', $disposition, 'filename must end with .xlsx');
    }

    // ── TC-02 — workbook has CNCT sheet ──
    public function test_workbook_has_cnct_sheet(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $this->purchase($sup, 500_000, Carbon::now());

        $wb = $this->downloadWorkbook($sup->id, 'format=xlsx&date_preset=all', $admin);
        $this->assertNotNull($wb->getSheetByName('CNCT'), 'sheet `CNCT` must exist');
    }

    // ── TC-03 — workbook carries the report title ──
    public function test_workbook_has_report_title(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $this->purchase($sup, 500_000, Carbon::now());

        $wb = $this->downloadWorkbook($sup->id, 'format=xlsx&date_preset=all', $admin);
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, true, false);

        $flat = '';
        foreach ($cells as $row) {
            foreach ($row as $val) {
                $flat .= ' '.(string) $val;
            }
        }
        $this->assertStringContainsString('CÔNG NỢ CHI TIẾT NHÀ CUNG CẤP', $flat);
    }

    // ── TC-04 — all KiotViet-like headers present ──
    public function test_workbook_has_kiotviet_like_headers(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $this->purchase($sup, 500_000, Carbon::now());

        $wb = $this->downloadWorkbook($sup->id, 'format=xlsx&date_preset=all&include_detail=1&columns[]=cost', $admin);
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, true, false);
        $flat = '';
        foreach ($cells as $row) {
            foreach ($row as $val) {
                $flat .= "\t".(string) $val;
            }
        }

        foreach (['Thời gian', 'Mã', 'Diễn giải', 'ĐVT', 'SL', 'Đơn giá',
            'Giảm giá', 'VAT', 'Thành tiền',
            'Ghi nợ', 'Ghi có'] as $h) {
            $this->assertStringContainsString($h, $flat, "header `{$h}` must appear");
        }
        $this->assertStringNotContainsString('Giá nhập/trả', $flat);
        $this->assertSame('M', $sheet->getHighestColumn());
    }

    // ── TC-05 — purchase entry carries its line items below ──
    public function test_purchase_entry_has_line_items(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $p = $this->purchase($sup, 750_000, Carbon::now());
        PurchaseItem::create([
            'purchase_id' => $p->id,
            'product_name' => 'Linh kiện 2417B',
            'product_code' => 'LK-2417B',
            'quantity' => 3,
            'price' => 250_000,
            'discount' => 0,
            'subtotal' => 750_000,
        ]);

        $wb = $this->downloadWorkbook(
            $sup->id,
            'format=xlsx&date_preset=all&include_detail=1&columns[]=quantity&columns[]=unit_price&columns[]=line_total',
            $admin
        );
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, true, true);

        $purchaseRow = null;
        $detailRow = null;
        foreach ($cells as $idx => $row) {
            if (in_array($p->code, $row, true)) {
                $purchaseRow = $idx;
            }
            if (in_array('Linh kiện 2417B', $row, true)) {
                $detailRow = $idx;
            }
        }
        $this->assertNotNull($purchaseRow, 'purchase code row must appear');
        $this->assertNotNull($detailRow, 'detail line row must appear');
        $this->assertGreaterThan($purchaseRow, $detailRow, 'detail must sit BELOW its document');
    }

    // ── TC-06 — payment entry shows credit amount in Ghi có column ──
    public function test_payment_entry_has_credit_amount(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $this->purchase($sup, 500_000, Carbon::now());

        CashFlow::create([
            'code' => 'PCPN-'.uniqid(),
            'type' => 'payment',
            'amount' => 200_000,
            'target_type' => 'Nha cung cap',
            'target_id' => $sup->id,
            'target_name' => $sup->name,
            'reference_type' => 'SupplierPayment',
            'status' => 'active',
            'description' => 'Test payment',
            'time' => Carbon::now(),
            'created_at' => Carbon::now(),
        ]);

        $wb = $this->downloadWorkbook($sup->id, 'format=xlsx&date_preset=all', $admin);
        $sheet = $wb->getSheetByName('CNCT');
        // Read raw cell values (no number-format applied) so the
        // assertion compares against the stored numeric, not "200,000".
        // toArray signature: ($nullValue, $calculateFormulas, $formatData, $returnCellRef).
        $cells = $sheet->toArray(null, true, false, true);

        $paymentRow = null;
        foreach ($cells as $idx => $row) {
            if (str_starts_with((string) ($row['B'] ?? ''), 'PCPN')) {
                $paymentRow = $row;
                break;
            }
        }
        $this->assertNotNull($paymentRow, 'payment ledger row must appear');
        $this->assertEmpty($paymentRow['J'] ?? '', 'Ghi nợ must be empty for a pure payment');
        $this->assertEquals(200_000, (int) ($paymentRow['K'] ?? 0), 'Ghi có must be the payment amount');
    }

    // ── TC-07 — custom date filter excludes out-of-range entries ──
    public function test_custom_date_filter_excludes_out_of_range_entries(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $pIn = $this->purchase($sup, 100_000, Carbon::create(2026, 5, 5, 9, 0));
        $pOut = $this->purchase($sup, 200_000, Carbon::create(2026, 5, 20, 9, 0));

        $wb = $this->downloadWorkbook(
            $sup->id,
            'format=xlsx&date_preset=custom&date_from=2026-05-01&date_to=2026-05-10',
            $admin
        );
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, true, false);
        $flat = '';
        foreach ($cells as $row) {
            foreach ($row as $val) {
                $flat .= ' '.(string) $val;
            }
        }

        $this->assertStringContainsString($pIn->code, $flat, 'in-window purchase must appear');
        $this->assertStringNotContainsString($pOut->code, $flat, 'out-of-window purchase must NOT appear');
    }

    // ── TC-08 — legacy CSV path is explicit behind mode=legacy ──
    public function test_legacy_csv_with_explicit_mode_still_works(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $p = $this->purchase($sup, 400_000, Carbon::now());

        $res = $this->actingAs($admin)->get("/api/suppliers/{$sup->id}/export-debt?mode=legacy");
        $res->assertOk();
        $body = $res->streamedContent() ?: $res->getContent();

        $this->assertStringContainsString('Mã chứng từ', $body);
        $this->assertStringContainsString('Còn nợ', $body, 'legacy header must survive');
        $this->assertStringContainsString($p->code, $body);
    }

    public function test_default_csv_export_uses_document_timeline_entries(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $sup->supplier_debt_amount = 450_000;
        $sup->save();

        $purchase = $this->purchase($sup, 1_000_000, Carbon::create(2026, 6, 1, 9, 0), 'PN-DOC');

        CashFlow::create([
            'code' => 'PCPN-DOC-'.uniqid(),
            'type' => 'payment',
            'amount' => 400_000,
            'target_type' => 'Nhà cung cấp',
            'target_id' => $sup->id,
            'target_name' => $sup->name,
            'reference_type' => 'Purchase',
            'reference_code' => $purchase->code,
            'status' => 'active',
            'time' => Carbon::create(2026, 6, 1, 10, 0),
            'created_at' => Carbon::create(2026, 6, 1, 10, 0),
        ]);

        \App\Models\PurchaseReturn::create([
            'code' => 'NCCRETURN-DOC-'.uniqid(),
            'supplier_id' => $sup->id,
            'status' => 'completed',
            'total_amount' => 100_000,
            'return_date' => Carbon::create(2026, 6, 1, 11, 0),
            'created_at' => Carbon::create(2026, 6, 1, 11, 0),
        ]);

        $adjustmentCode = 'DCCNC-DOC-'.uniqid();
        SupplierDebtTransaction::create([
            'supplier_id' => $sup->id,
            'code' => $adjustmentCode,
            'type' => 'adjustment',
            'amount' => -50_000,
            'debt_remain' => 550_000,
            'note' => 'Document timeline adjustment',
            'recorded_at' => Carbon::create(2026, 6, 1, 12, 0),
            'created_at' => Carbon::create(2026, 6, 1, 12, 0),
        ]);

        $timeline = $this->actingAs($admin)->getJson("/api/suppliers/{$sup->id}/debt-transactions?mode=document");
        $timeline->assertOk();
        $timelineCodes = collect($timeline->json('entries'))->pluck('code')->filter()->values();

        $res = $this->actingAs($admin)->get("/api/suppliers/{$sup->id}/export-debt?date_preset=all");
        $res->assertOk();
        $body = $res->streamedContent() ?: $res->getContent();

        foreach ([$purchase->code, $adjustmentCode] as $code) {
            $this->assertTrue($timelineCodes->contains($code), "timeline must contain {$code}");
            $this->assertStringContainsString($code, $body, "export must contain {$code}");
        }
        $this->assertStringContainsString('Nợ cần trả nhà cung cấp', $body);
        $this->assertStringContainsString('450000', str_replace([',', '.'], '', $body));
    }

    public function test_dual_role_partner_view_export_contains_customer_invoice_like_timeline(): void
    {
        $admin = $this->admin();
        $partner = $this->supplier('Dual Role Export Partner');
        $partner->is_customer = true;
        $partner->debt_amount = 500_000;
        $partner->supplier_debt_amount = 2_000_000;
        $partner->save();

        $purchase = $this->purchase($partner, 2_000_000, Carbon::create(2026, 6, 2, 9, 0), 'PN-DUAL');

        $invoice = \App\Models\Invoice::create([
            'code' => 'HD-DUAL-EXPORT-'.uniqid(),
            'customer_id' => $partner->id,
            'status' => 'Hoàn thành',
            'total' => 500_000,
            'customer_paid' => 0,
            'transaction_date' => Carbon::create(2026, 6, 2, 10, 0),
            'created_at' => Carbon::create(2026, 6, 2, 10, 0),
        ]);

        $timeline = $this->actingAs($admin)->getJson("/api/suppliers/{$partner->id}/debt-transactions?mode=document&view=partner");
        $timeline->assertOk();
        $this->assertContains($invoice->code, collect($timeline->json('entries'))->pluck('code')->all());

        $res = $this->actingAs($admin)->get("/api/suppliers/{$partner->id}/export-debt?date_preset=all&view=partner");
        $res->assertOk();
        $body = $res->streamedContent() ?: $res->getContent();

        $this->assertStringContainsString($purchase->code, $body);
        $this->assertStringContainsString($invoice->code, $body);
    }

    // ── TC-09 — debt-transactions JSON shape unchanged ──
    public function test_debt_transactions_json_endpoint_unchanged(): void
    {
        $admin = $this->admin();
        $sup = $this->supplier();
        $this->purchase($sup, 600_000, Carbon::now());

        $res = $this->actingAs($admin)->getJson("/api/suppliers/{$sup->id}/debt-transactions");
        $res->assertOk();
        $data = $res->json();
        $this->assertArrayHasKey('entries', $data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertIsArray($data['entries']);
    }
}
