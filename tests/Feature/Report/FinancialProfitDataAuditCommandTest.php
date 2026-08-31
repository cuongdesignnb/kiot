<?php

namespace Tests\Feature\Report;

use App\Models\CashFlow;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Paysheet;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FinancialProfitDataAuditCommandTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Carbon $startDate;

    private Carbon $endDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Audit Test',
            'email' => 'admin-audit-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $this->startDate = Carbon::parse('2026-04-01')->startOfDay();
        $this->endDate = Carbon::parse('2026-05-31')->endOfDay();
    }

    /**
     * Test 1: Command runs successfully with dates
     */
    public function test_command_runs_successfully(): void
    {
        $this->artisan('audit:financial-profit-data', [
            '--from' => '2026-04-01',
            '--to' => '2026-05-31',
            '--limit' => '5',
        ])->assertExitCode(0);
    }

    /**
     * Test 2: Command is read-only and does not modify database records
     */
    public function test_command_does_not_modify_database(): void
    {
        // Capture initial database counts
        $invoiceCount = Invoice::count();
        $invoiceItemCount = InvoiceItem::count();
        $productCount = Product::count();
        $cashflowCount = CashFlow::count();
        $paysheetCount = Paysheet::count();

        // Run the command
        $this->artisan('audit:financial-profit-data', [
            '--from' => '2026-04-01',
            '--to' => '2026-05-31',
        ])->assertExitCode(0);

        // Verify counts are unchanged
        $this->assertEquals($invoiceCount, Invoice::count());
        $this->assertEquals($invoiceItemCount, InvoiceItem::count());
        $this->assertEquals($productCount, Product::count());
        $this->assertEquals($cashflowCount, CashFlow::count());
        $this->assertEquals($paysheetCount, Paysheet::count());
    }

    /**
     * Test 3: Command detects missing cost snapshot
     */
    public function test_command_detects_missing_cost_snapshot(): void
    {
        $product = Product::create([
            'sku' => 'SKU-MISSING-SS',
            'name' => 'Product Missing Snapshot',
            'cost_price' => 1000,
            'retail_price' => 2000,
            'stock_quantity' => 10,
        ]);

        $invoice = Invoice::create([
            'code' => 'HD-MISSING-SS',
            'subtotal' => 2000,
            'discount' => 0,
            'total' => 2000,
            'status' => 'Hoàn thành',
            'created_at' => '2026-04-15 10:00:00',
            'transaction_date' => '2026-04-15 10:00:00',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 2000,
            'cost_price' => 0, // Missing snapshot cost price
            'discount' => 0,
            'subtotal' => 2000,
        ]);

        Artisan::call('audit:financial-profit-data', [
            '--from' => '2026-04-01',
            '--to' => '2026-05-31',
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('SKU-MISSING-SS', $output);
        $this->assertStringContainsString('Product Missing Snapshot', $output);
    }

    /**
     * Test 4: Command detects product cost_price > retail_price
     */
    public function test_command_detects_product_cost_price_gt_retail_price(): void
    {
        Product::create([
            'sku' => 'SKU-COST-GT-RETAIL',
            'name' => 'Product Cost Greater Than Retail',
            'cost_price' => 5000,
            'retail_price' => 3000,
            'stock_quantity' => 5,
        ]);

        Artisan::call('audit:financial-profit-data', [
            '--from' => '2026-04-01',
            '--to' => '2026-05-31',
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('SKU-COST-GT-RETAIL', $output);
        $this->assertStringContainsString('Product Cost', $output);
    }

    /**
     * Test 5: Command detects ghost invoice
     */
    public function test_command_detects_ghost_invoice(): void
    {
        Invoice::create([
            'code' => 'HD-GHOST-TEST',
            'subtotal' => 150000,
            'discount' => 0,
            'total' => 150000,
            'status' => 'Hoàn thành',
            'created_at' => '2026-04-20 12:00:00',
            'transaction_date' => '2026-04-20 12:00:00',
        ]);

        Artisan::call('audit:financial-profit-data', [
            '--from' => '2026-04-01',
            '--to' => '2026-05-31',
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('HD-GHOST-TEST', $output);
    }

    public function test_command_does_not_report_invoice_with_items_as_ghost(): void
    {
        $product = Product::create([
            'sku' => 'SKU-NOT-GHOST',
            'name' => 'Product with invoice item',
            'cost_price' => 1000,
            'retail_price' => 2000,
            'stock_quantity' => 1,
        ]);

        $invoice = Invoice::create([
            'code' => 'HD-NOT-GHOST-TEST',
            'subtotal' => 2000,
            'discount' => 0,
            'total' => 2000,
            'status' => 'Hoàn thành',
            'created_at' => '2026-04-21 12:00:00',
            'transaction_date' => '2026-04-21 12:00:00',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 2000,
            'discount' => 0,
            'subtotal' => 2000,
            'cost_price' => 1000,
        ]);

        Artisan::call('audit:financial-profit-data', [
            '--from' => '2026-04-01',
            '--to' => '2026-05-31',
        ]);

        $ghostSection = str(Artisan::output())->between(
            '--- HÓA ĐƠN RÁC (GHOST INVOICES - CÓ TIỀN NHƯNG KHÔNG CÓ CHI TIẾT HÀNG) ---',
            '--- HÓA ĐƠN LỆCH SUBTOTAL VỚI TỔNG CHI TIẾT'
        )->toString();

        $this->assertStringNotContainsString('HD-NOT-GHOST-TEST', $ghostSection);
    }

    public function test_command_uses_stored_line_subtotal_when_auditing_invoice_total(): void
    {
        $product = Product::create([
            'sku' => 'SKU-LINE-DISCOUNT',
            'name' => 'Product with line discount',
            'cost_price' => 1000,
            'retail_price' => 2000,
            'stock_quantity' => 1,
        ]);

        $invoice = Invoice::create([
            'code' => 'HD-LINE-DISCOUNT-TEST',
            'subtotal' => 1900,
            'discount' => 0,
            'total' => 1900,
            'status' => 'Hoàn thành',
            'created_at' => '2026-04-22 12:00:00',
            'transaction_date' => '2026-04-22 12:00:00',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 2000,
            'discount' => 100,
            'subtotal' => 1900,
            'cost_price' => 1000,
        ]);

        Artisan::call('audit:financial-profit-data', [
            '--from' => '2026-04-01',
            '--to' => '2026-05-31',
        ]);

        $subtotalSection = str(Artisan::output())->between(
            '--- HÓA ĐƠN LỆCH SUBTOTAL VỚI TỔNG CHI TIẾT',
            '--- HÀNG QUÀ TẶNG'
        )->toString();

        $this->assertStringNotContainsString('HD-LINE-DISCOUNT-TEST', $subtotalSection);
    }

    /**
     * Test 6: Command detects zero price item with COGS
     */
    public function test_command_detects_zero_price_item_with_cogs(): void
    {
        $product = Product::create([
            'sku' => 'SKU-GIFT-TEST',
            'name' => 'Gift Product with Cost',
            'cost_price' => 500,
            'retail_price' => 0,
            'stock_quantity' => 20,
        ]);

        $invoice = Invoice::create([
            'code' => 'HD-GIFT-TEST',
            'subtotal' => 0,
            'discount' => 0,
            'total' => 0,
            'status' => 'Hoàn thành',
            'created_at' => '2026-05-01 10:00:00',
            'transaction_date' => '2026-05-01 10:00:00',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 0,
            'cost_price' => 500,
            'discount' => 0,
            'subtotal' => 0,
        ]);

        Artisan::call('audit:financial-profit-data', [
            '--from' => '2026-04-01',
            '--to' => '2026-05-31',
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('HD-GIFT-TEST', $output);
        $this->assertStringContainsString('SKU-GIFT-TEST', $output);
    }

    /**
     * Test 7: Command detects loss item
     */
    public function test_command_detects_loss_item(): void
    {
        $product = Product::create([
            'sku' => 'SKU-LOSS-TEST',
            'name' => 'Loss Sale Product',
            'cost_price' => 5000,
            'retail_price' => 4000,
            'stock_quantity' => 10,
        ]);

        $invoice = Invoice::create([
            'code' => 'HD-LOSS-TEST',
            'subtotal' => 4000,
            'discount' => 0,
            'total' => 4000,
            'status' => 'Hoàn thành',
            'created_at' => '2026-05-15 15:00:00',
            'transaction_date' => '2026-05-15 15:00:00',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 4000,
            'cost_price' => 5000,
            'discount' => 0,
            'subtotal' => 4000,
        ]);

        Artisan::call('audit:financial-profit-data', [
            '--from' => '2026-04-01',
            '--to' => '2026-05-31',
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('HD-LOSS-TEST', $output);
        $this->assertStringContainsString('SKU-LOSS-TEST', $output);
    }

    /**
     * Test 8: Command can export CSV when passing --export-csv
     */
    public function test_command_exports_csv_files(): void
    {
        $auditPath = storage_path('app/audit/financial-profit-data');
        if (File::exists($auditPath)) {
            File::deleteDirectory($auditPath);
        }

        $this->artisan('audit:financial-profit-data', [
            '--from' => '2026-04-01',
            '--to' => '2026-05-31',
            '--export-csv' => true,
        ])->assertExitCode(0);

        $this->assertTrue(File::exists($auditPath));

        $directories = File::directories($auditPath);
        $this->assertNotEmpty($directories);

        $latestDir = $directories[0];
        $this->assertTrue(File::exists("{$latestDir}/summary.csv"));
        $this->assertTrue(File::exists("{$latestDir}/top_products_low_margin.csv"));
        $this->assertTrue(File::exists("{$latestDir}/top_invoices_low_margin.csv"));
        $this->assertTrue(File::exists("{$latestDir}/missing_cost_snapshot.csv"));
        $this->assertTrue(File::exists("{$latestDir}/loss_items.csv"));
        $this->assertTrue(File::exists("{$latestDir}/products_cost_gt_retail.csv"));
        $this->assertTrue(File::exists("{$latestDir}/ghost_invoices.csv"));
        $this->assertTrue(File::exists("{$latestDir}/subtotal_mismatch.csv"));
        $this->assertTrue(File::exists("{$latestDir}/zero_price_items.csv"));
        $this->assertTrue(File::exists("{$latestDir}/cashflow_pnl_category_audit.csv"));
        $this->assertTrue(File::exists("{$latestDir}/paysheets_in_period.csv"));

        // Cleanup
        File::deleteDirectory($auditPath);
    }
}
