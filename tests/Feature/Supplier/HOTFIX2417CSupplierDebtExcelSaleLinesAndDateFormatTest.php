<?php

namespace Tests\Feature\Supplier;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * HOTFIX 24.17C — Excel debt export must surface sale-line product
 * info, and the modal must speak Vietnamese dd/mm/yyyy without ever
 * letting the backend misread it as US M/D/Y.
 */
class HOTFIX2417CSupplierDebtExcelSaleLinesAndDateFormatTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 2417C',
            'email'    => 'admin-2417c-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function category(): Category
    {
        return Category::firstOrCreate(['name' => 'Cat 2417C']);
    }

    private function dualRolePartner(string $name = 'NCC+KH 2417C'): Customer
    {
        return Customer::create([
            'code'                 => 'NCC-2417C-' . uniqid(),
            'name'                 => $name,
            'phone'                => '09' . random_int(10000000, 99999999),
            'debt_amount'          => 0,
            'supplier_debt_amount' => 0,
            'is_customer'          => true,
            'is_supplier'          => true,
        ]);
    }

    private function product(string $name, string $sku, int $cost = 100_000, int $retail = 200_000): Product
    {
        return Product::create([
            'sku'                  => $sku,
            'name'                 => $name,
            'cost_price'           => $cost,
            'retail_price'         => $retail,
            'stock_quantity'       => 100,
            'inventory_total_cost' => $cost * 100,
            'has_serial'           => false,
            'category_id'          => $this->category()->id,
        ]);
    }

    /**
     * Mirror the minimal Invoice row + items needed for the dual-role
     * branch of SupplierController::debtTransactions().
     */
    private function saleInvoice(Customer $partner, array $lines, ?Carbon $when = null): Invoice
    {
        $total = 0;
        foreach ($lines as $l) $total += ((int) $l['qty']) * ((int) $l['price']);
        $inv = Invoice::create([
            'code'           => 'HD-2417C-' . uniqid(),
            'customer_id'    => $partner->id,
            'subtotal'       => $total,
            'total'          => $total,
            'customer_paid'  => 0,
            'status'         => 'active',
        ]);
        if ($when) {
            $inv->created_at = $when;
            $inv->updated_at = $when;
            $inv->save();
        }
        foreach ($lines as $l) {
            InvoiceItem::create([
                'invoice_id' => $inv->id,
                'product_id' => $l['product_id'],
                'quantity'   => $l['qty'],
                'price'      => $l['price'],
                'cost_price' => $l['cost'] ?? 0,
                'serial'     => $l['serial'] ?? null,
            ]);
        }
        return $inv;
    }

    private function purchase(Customer $sup, int $total, Carbon $when): Purchase
    {
        $p = Purchase::create([
            'code'          => 'PN-' . uniqid(),
            'supplier_id'   => $sup->id,
            'user_id'       => null,
            'total_amount'  => $total,
            'paid_amount'   => 0,
            'debt_amount'   => $total,
            'status'        => 'completed',
            'purchase_date' => $when,
        ]);
        $p->created_at = $when;
        $p->updated_at = $when;
        $p->save();
        return $p;
    }

    private function downloadWorkbook(int $partnerId, string $query, User $actor): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $res = $this->actingAs($actor)->get("/api/suppliers/{$partnerId}/export-debt?{$query}");
        $res->assertOk();
        $body = $res->streamedContent() ?: $res->getContent();
        $tmp  = tempnam(sys_get_temp_dir(), 'cnct-c-') . '.xlsx';
        file_put_contents($tmp, $body);
        try {
            return IOFactory::load($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    // ── TC-01 — sale detail rows show product code + name ──
    public function test_invoice_sale_detail_lines_show_product_code_and_name(): void
    {
        $admin   = $this->admin();
        $partner = $this->dualRolePartner();
        $product = $this->product('Tai nghe Sony WH-1000XM5', 'SKU-WH1000XM5', 5_000_000, 8_000_000);
        $this->saleInvoice($partner, [
            ['product_id' => $product->id, 'qty' => 2, 'price' => 8_000_000, 'cost' => 5_000_000],
        ]);

        $wb    = $this->downloadWorkbook(
            $partner->id,
            'format=xlsx&date_preset=all&include_detail=1&columns[]=quantity&columns[]=unit_price&columns[]=cost&columns[]=line_total',
            $admin
        );
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, false);

        $flat = '';
        foreach ($cells as $row) {
            foreach ($row as $val) $flat .= "\t" . (string) $val;
        }
        $this->assertStringContainsString('SKU-WH1000XM5', $flat, 'product SKU must appear on the detail row');
        $this->assertStringContainsString('Tai nghe Sony WH-1000XM5', $flat, 'product name must appear on the detail row');
        // quantity, price, line_total should all show up
        $this->assertStringContainsString('16000000', $flat, 'line_total (qty*price) must appear');
    }

    // ── TC-02 — multi-item invoice exports every line ──
    public function test_invoice_sale_multiple_items_are_all_exported(): void
    {
        $admin   = $this->admin();
        $partner = $this->dualRolePartner();
        $p1      = $this->product('Bàn phím cơ Keychron K8', 'SKU-K8');
        $p2      = $this->product('Chuột Logitech MX Master 3S', 'SKU-MXM3S');
        $this->saleInvoice($partner, [
            ['product_id' => $p1->id, 'qty' => 1, 'price' => 3_000_000],
            ['product_id' => $p2->id, 'qty' => 1, 'price' => 2_500_000],
        ]);

        $wb    = $this->downloadWorkbook(
            $partner->id,
            'format=xlsx&date_preset=all&include_detail=1&columns[]=quantity&columns[]=unit_price&columns[]=line_total',
            $admin
        );
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, false);
        $flat  = '';
        foreach ($cells as $row) {
            foreach ($row as $val) $flat .= "\t" . (string) $val;
        }
        $this->assertStringContainsString('Bàn phím cơ Keychron K8', $flat);
        $this->assertStringContainsString('Chuột Logitech MX Master 3S', $flat);
    }

    // ── TC-03 — backend accepts dd/mm/yyyy custom date ──
    public function test_custom_date_accepts_vietnamese_dd_mm_yyyy(): void
    {
        $admin   = $this->admin();
        $partner = $this->dualRolePartner();
        $this->purchase($partner, 1_000_000, Carbon::create(2026, 4, 15, 9, 0));

        $res = $this->actingAs($admin)->get(
            "/api/suppliers/{$partner->id}/export-debt?format=xlsx&date_preset=custom&date_from=01/04/2026&date_to=30/04/2026"
        );
        $this->assertSame(200, $res->getStatusCode(), 'dd/mm/yyyy must be accepted as 422-free');
    }

    // ── TC-04 — `01/04/2026` is parsed as 1-April, NOT 4-January ──
    public function test_custom_date_does_not_parse_as_us_format(): void
    {
        $admin   = $this->admin();
        $partner = $this->dualRolePartner();
        // Transaction on 30/04/2026 — must be included by a Vietnamese
        // `01/04/2026 → 30/04/2026` window. If the backend parsed
        // `01/04/2026` as Jan 4 (US), the upper bound `30/04/2026`
        // would still be late April but `from` would be Jan 4, so
        // technically the row would still appear. The discriminator
        // is the inverse: a row dated 02/01/2026 (Jan 2) must NOT
        // appear inside an April-only window.
        $inJan  = $this->purchase($partner, 500_000, Carbon::create(2026, 1, 2, 9, 0));
        $inApr  = $this->purchase($partner, 600_000, Carbon::create(2026, 4, 30, 9, 0));

        $wb    = $this->downloadWorkbook(
            $partner->id,
            'format=xlsx&date_preset=custom&date_from=01/04/2026&date_to=30/04/2026',
            $admin
        );
        $sheet = $wb->getSheetByName('CNCT');
        $cells = $sheet->toArray(null, true, false, false);
        $flat  = '';
        foreach ($cells as $row) {
            foreach ($row as $val) $flat .= "\t" . (string) $val;
        }
        $this->assertStringContainsString($inApr->code, $flat, 'in-window April row must appear');
        $this->assertStringNotContainsString($inJan->code, $flat,
            'a January row must NOT leak in — proves backend reads dd/mm/yyyy, not M/D/Y');
    }

    // ── TC-05 — impossible calendar dates trip 422, not 500 ──
    public function test_invalid_vietnamese_date_returns_422(): void
    {
        $admin   = $this->admin();
        $partner = $this->dualRolePartner();

        $res = $this->actingAs($admin)->get(
            "/api/suppliers/{$partner->id}/export-debt?format=xlsx&date_preset=custom&date_from=31/02/2026&date_to=30/04/2026"
        );
        $this->assertSame(422, $res->getStatusCode(), '31/02 must return 422, not 500');
    }
}
