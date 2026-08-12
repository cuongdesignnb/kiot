<?php

namespace Tests\Feature\Exports;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PartnerDebtExcelParityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dual_role_workbooks_share_primary_documents_have_details_and_opposite_closing(): void
    {
        $actor = User::create([
            'name' => 'Excel parity QA',
            'email' => 'excel-parity-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
        $partner = Customer::create([
            'code' => 'DUAL-EXCEL-'.uniqid(),
            'name' => 'Dual role Excel partner',
            'phone' => '09'.random_int(10000000, 99999999),
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => true,
        ]);
        $product = Product::create([
            'sku' => 'SKU-EXCEL-'.uniqid(),
            'name' => 'Excel parity product',
            'type' => 'standard',
            'cost_price' => 100,
            'retail_price' => 200,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $purchase = Purchase::create([
            'code' => 'PN-EXCEL-'.uniqid(),
            'supplier_id' => $partner->id,
            'total_amount' => 1000,
            'discount' => 100,
            'paid_amount' => 0,
            'debt_amount' => 900,
            'status' => 'completed',
        ]);
        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->sku,
            'quantity' => 1,
            'price' => 1000,
            'discount' => 0,
            'subtotal' => 1000,
        ]);

        $invoice = Invoice::create([
            'code' => 'HD-EXCEL-'.uniqid(),
            'customer_id' => $partner->id,
            'subtotal' => 500,
            'total' => 500,
            'customer_paid' => 0,
            'status' => 'Hoàn thành',
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 500,
            'subtotal' => 500,
            'cost_price' => 100,
        ]);

        $customerWorkbook = $this->workbook(
            "/customers/{$partner->id}/export-debt?format=xlsx&date_preset=all&include_detail=1&columns[]=quantity&columns[]=line_total",
            $actor,
            'customer-debt-parity.xlsx'
        );
        $supplierWorkbook = $this->workbook(
            "/api/suppliers/{$partner->id}/export-debt?format=xlsx&date_preset=all&include_detail=1&columns[]=quantity&columns[]=line_total",
            $actor,
            'supplier-debt-parity.xlsx'
        );

        $customerRows = $customerWorkbook->getSheetByName('CNCT')->toArray(null, true, false, true);
        $supplierRows = $supplierWorkbook->getSheetByName('CNCT')->toArray(null, true, false, true);

        foreach ([$purchase->code, $invoice->code] as $code) {
            $this->assertSame(1, $this->countCode($customerRows, $code));
            $this->assertSame(1, $this->countCode($supplierRows, $code));
        }

        $this->assertGreaterThan(0, $this->countCode($customerRows, $product->sku));
        $this->assertGreaterThan(0, $this->countCode($supplierRows, $product->sku));

        $customerClosing = $this->closing($customerRows);
        $supplierClosing = $this->closing($supplierRows);
        $this->assertEqualsWithDelta(0.0, $customerClosing + $supplierClosing, 0.01);
    }

    private function workbook(string $uri, User $actor, ?string $artifactName = null): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $response = $this->actingAs($actor)->get($uri);
        $response->assertOk();
        $body = $response->streamedContent() ?: $response->getContent();
        $artifactDir = getenv('PARTNER_DEBT_QA_ARTIFACT_DIR') ?: '';
        if ($artifactName !== null && $artifactDir !== '') {
            if (! is_dir($artifactDir)) {
                mkdir($artifactDir, 0777, true);
            }
            file_put_contents($artifactDir.DIRECTORY_SEPARATOR.$artifactName, $body);
        }
        $path = tempnam(sys_get_temp_dir(), 'partner-debt-').'.xlsx';
        file_put_contents($path, $body);
        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }

    private function countCode(array $rows, string $code): int
    {
        return count(array_filter($rows, static fn (array $row): bool => ($row['B'] ?? '') === $code));
    }

    private function closing(array $rows): float
    {
        foreach ($rows as $row) {
            if (($row['I'] ?? '') !== 'Nợ cuối kỳ:') {
                continue;
            }

            return (float) ($row['K'] ?? 0) - (float) ($row['L'] ?? 0);
        }

        self::fail('Nợ cuối kỳ: summary row not found');
    }
}
