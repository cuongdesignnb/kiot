<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class CustomerDebtExcelExportTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin 246I',
            'email' => 'admin-246i-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'KH-246I-' . uniqid(),
            'name' => 'Khach 246I',
            'phone' => '09' . random_int(10000000, 99999999),
            'debt_amount' => 0,
            'total_spent' => 0,
            'total_returns' => 0,
            'is_customer' => true,
            'is_supplier' => false,
        ]);
    }

    private function product(string $name = 'San pham 246I'): Product
    {
        return Product::create([
            'sku' => 'SP-246I-' . uniqid(),
            'name' => $name,
            'type' => 'standard',
            'cost_price' => 100000,
            'retail_price' => 300000,
            'stock_quantity' => 10,
            'is_active' => true,
            'has_serial' => false,
        ]);
    }

    private function invoice(Customer $customer, Product $product, int $total = 300000, ?Carbon $when = null): Invoice
    {
        $when ??= Carbon::now();
        $invoice = Invoice::create([
            'code' => 'HD' . uniqid(),
            'customer_id' => $customer->id,
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'customer_paid' => 0,
            'status' => 'Hoàn thành',
        ]);
        $invoice->created_at = $when;
        $invoice->updated_at = $when;
        $invoice->save();

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $total,
            'discount' => 0,
            'subtotal' => $total,
            'cost_price' => 100000,
        ]);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => $invoice->code,
            'amount' => $total,
            'debt_total' => $total,
            'type' => 'sale',
            'note' => 'Ban hang',
            'recorded_at' => $when,
        ]);

        return $invoice;
    }

    private function workbook(int $customerId, string $query, User $actor): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $response = $this->actingAs($actor)->get("/customers/{$customerId}/export-debt?{$query}");
        $response->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'cust-debt-') . '.xlsx';
        file_put_contents($tmp, $response->streamedContent() ?: $response->getContent());

        try {
            return IOFactory::load($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function flatWorkbookText(\PhpOffice\PhpSpreadsheet\Spreadsheet $workbook): string
    {
        $flat = '';
        foreach ($workbook->getSheetByName('CNCT')->toArray(null, true, true, false) as $row) {
            foreach ($row as $value) {
                $flat .= ' ' . (string) $value;
            }
        }

        return $flat;
    }

    public function test_customer_debt_export_xlsx_returns_excel_response(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $this->invoice($customer, $this->product());

        $response = $this->actingAs($admin)->get("/customers/{$customer->id}/export-debt?format=xlsx&date_preset=all&include_detail=1");
        $response->assertOk();

        $this->assertStringContainsString('spreadsheetml.sheet', strtolower($response->headers->get('Content-Type') ?? ''));
        $this->assertStringContainsString('.xlsx', $response->headers->get('Content-Disposition') ?? '');
    }

    public function test_customer_debt_workbook_has_cnct_sheet(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $this->invoice($customer, $this->product());

        $workbook = $this->workbook($customer->id, 'format=xlsx&date_preset=all', $admin);

        $this->assertNotNull($workbook->getSheetByName('CNCT'));
    }

    public function test_customer_debt_workbook_has_customer_title(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $this->invoice($customer, $this->product());

        $flat = $this->flatWorkbookText($this->workbook($customer->id, 'format=xlsx&date_preset=all', $admin));

        $this->assertStringContainsString('CÔNG NỢ CHI TIẾT KHÁCH HÀNG', $flat);
    }

    public function test_customer_debt_workbook_has_kiotviet_like_headers(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $this->invoice($customer, $this->product());

        $flat = $this->flatWorkbookText($this->workbook($customer->id, 'format=xlsx&date_preset=all&include_detail=1', $admin));

        foreach (['Thời gian', 'Mã', 'Diễn giải', 'ĐVT', 'SL', 'Đơn giá', 'Giảm giá', 'VAT', 'Giá bán/trả', 'Thành tiền', 'Ghi nợ', 'Ghi có'] as $header) {
            $this->assertStringContainsString($header, $flat);
        }
    }

    public function test_invoice_entry_has_line_items_when_include_detail(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $product = $this->product('Laptop detail 246I');
        $invoice = $this->invoice($customer, $product);

        $cells = $this->workbook($customer->id, 'format=xlsx&date_preset=all&include_detail=1&columns[]=quantity&columns[]=unit_price&columns[]=line_total', $admin)
            ->getSheetByName('CNCT')
            ->toArray(null, true, true, true);

        $invoiceRow = null;
        $detailRow = null;
        foreach ($cells as $index => $row) {
            if (in_array($invoice->code, $row, true)) {
                $invoiceRow = $index;
            }
            if (in_array('Laptop detail 246I', $row, true)) {
                $detailRow = $index;
            }
        }

        $this->assertNotNull($invoiceRow);
        $this->assertNotNull($detailRow);
        $this->assertGreaterThan($invoiceRow, $detailRow);
    }

    public function test_payment_entry_has_credit_amount(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $when = Carbon::now();

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'ref_code' => 'PT246I',
            'amount' => -200000,
            'debt_total' => 0,
            'type' => 'payment',
            'note' => 'Thu no',
            'recorded_at' => $when,
        ]);

        $cells = $this->workbook($customer->id, 'format=xlsx&date_preset=all', $admin)
            ->getSheetByName('CNCT')
            ->toArray(null, true, false, true);

        $paymentRow = null;
        foreach ($cells as $row) {
            if (($row['B'] ?? '') === 'PT246I') {
                $paymentRow = $row;
                break;
            }
        }

        $this->assertNotNull($paymentRow);
        $this->assertEmpty($paymentRow['K'] ?? '');
        $this->assertEquals(200000, (int) ($paymentRow['L'] ?? 0));
    }

    public function test_return_entry_has_credit_amount_and_no_auto_settlement_adjustment(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $product = $this->product('Return detail 246I');
        $when = Carbon::now();
        $return = OrderReturn::create([
            'code' => 'TH246I',
            'customer_id' => $customer->id,
            'status' => 'Đã trả',
            'subtotal' => 3000000,
            'discount' => 0,
            'fee' => 0,
            'total' => 3000000,
            'paid_to_customer' => 3000000,
        ]);
        ReturnItem::create([
            'return_id' => $return->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 3000000,
            'discount' => 0,
            'import_price' => 100000,
            'cost_price' => 100000,
        ]);

        CustomerDebt::create([
            'customer_id' => $customer->id,
            'order_return_id' => $return->id,
            'ref_code' => $return->code,
            'amount' => -3000000,
            'debt_total' => -3000000,
            'type' => 'return',
            'note' => 'Giam cong no',
            'recorded_at' => $when,
        ]);
        CustomerDebt::create([
            'customer_id' => $customer->id,
            'order_return_id' => $return->id,
            'ref_code' => $return->code,
            'amount' => 3000000,
            'debt_total' => 0,
            'type' => 'adjustment',
            'note' => 'Tat toan tien da tra khach cho phieu tra TH246I',
            'recorded_at' => $when->copy()->addSecond(),
        ]);

        $cells = $this->workbook($customer->id, 'format=xlsx&date_preset=all&include_detail=1', $admin)
            ->getSheetByName('CNCT')
            ->toArray(null, true, false, true);

        $returnRows = [];
        foreach ($cells as $row) {
            if (($row['B'] ?? '') === 'TH246I') {
                $returnRows[] = $row;
            }
        }

        $this->assertCount(1, $returnRows);
        $this->assertSame('Trả hàng', $returnRows[0]['C']);
        $this->assertEquals(3000000, (int) ($returnRows[0]['L'] ?? 0));
        $this->assertStringNotContainsString('Điều chỉnh', $this->flatWorkbookText($this->workbook($customer->id, 'format=xlsx&date_preset=all', $admin)));
    }

    public function test_custom_date_filter_excludes_out_of_range_entries(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $product = $this->product();
        $in = $this->invoice($customer, $product, 100000, Carbon::create(2026, 5, 5, 9, 0));
        $out = $this->invoice($customer, $product, 200000, Carbon::create(2026, 5, 20, 9, 0));

        $flat = $this->flatWorkbookText($this->workbook(
            $customer->id,
            'format=xlsx&date_preset=custom&date_from=2026-05-01&date_to=2026-05-10',
            $admin
        ));

        $this->assertStringContainsString($in->code, $flat);
        $this->assertStringNotContainsString($out->code, $flat);
    }

    public function test_legacy_csv_without_query_still_works(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $invoice = $this->invoice($customer, $this->product());

        $response = $this->actingAs($admin)->get("/customers/{$customer->id}/export-debt");
        $response->assertOk();
        $body = $response->streamedContent() ?: $response->getContent();

        $this->assertStringContainsString($invoice->code, $body);
        $this->assertStringContainsString('cong_no_kh_', $response->headers->get('Content-Disposition') ?? '');
    }

    public function test_customers_index_no_longer_uses_window_open(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Customers/Index.vue'));

        $this->assertStringNotContainsString('window.open', $source);
    }
}
