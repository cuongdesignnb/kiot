<?php

namespace Tests\Feature\CustomerDebt;

use App\Http\Controllers\InvoiceController;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RR-06: Customer debt thiếu ledger/service.
 *
 * Bảng customer_debts đã tồn tại từ migration, nhưng:
 *   - Chưa có Model CustomerDebt
 *   - Chưa có CustomerDebtService
 *   - Bảng KHÔNG được populate khi bán hàng/trả hàng/cancel
 *
 * Pattern tham chiếu: SupplierDebtTransaction (đã được dùng trong SupplierController,
 * DebtOffsetService).
 *
 * Test này chứng minh: customers.debt_amount thay đổi đúng nhưng customer_debts
 * không có row → mất audit trail.
 */
class RR06CustomerDebtLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private User     $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin RR06',
            'email'    => 'admin-rr06-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);

        $this->customer = Customer::create([
            'code'        => 'KH-RR06-' . uniqid(),
            'name'        => 'KH RR06 ' . uniqid(),
            'phone'       => '090' . rand(1000000, 9999999),
            'email'       => 'kh-rr06-' . uniqid() . '@test.local',
            'debt_amount' => 0,
            'total_spent' => 0,
        ]);
    }

    private function makeProduct(): Product
    {
        $category = Category::firstOrCreate(['name' => 'Cat RR06']);
        return Product::create([
            'sku'                  => 'PROD-RR06-' . uniqid(),
            'name'                 => 'Product RR06',
            'cost_price'           => 100000,
            'retail_price'         => 200000,
            'stock_quantity'       => 100,
            'inventory_total_cost' => 10000000,
            'is_active'            => true,
            'has_serial'           => false,
            'category_id'          => $category->id,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR06-01: Schema customer_debts tồn tại + Model CustomerDebt phải có
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_customer_debt_schema_and_model_should_exist(): void
    {
        $this->assertTrue(Schema::hasTable('customer_debts'),
            'Bảng customer_debts phải tồn tại (migration đã có)');

        // Model CustomerDebt
        $this->assertTrue(
            class_exists(\App\Models\CustomerDebt::class)
            || class_exists(\App\Models\CustomerDebtTransaction::class),
            'Phải có Model CustomerDebt hoặc CustomerDebtTransaction. '
            . 'Pattern tham chiếu: App\\Models\\SupplierDebtTransaction.'
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR06-02: Bán hàng nợ qua Invoice phải ghi customer_debts row
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_invoice_credit_sale_should_create_customer_debt_transaction(): void
    {
        $product = $this->makeProduct();
        $rowsBefore = DB::table('customer_debts')->where('customer_id', $this->customer->id)->count();

        $this->actingAs($this->admin)->post(route('invoices.store'), [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 1000000,
            'total'          => 1000000,
            'customer_paid'  => 400000, // còn nợ 600k
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => 5,
                'price'      => 200000,
            ]],
        ]);

        $this->customer->refresh();
        $this->assertSame(600000.0, (float) $this->customer->debt_amount,
            'debt_amount đã đúng (do InvoiceSaleService increment)');

        $rowsAfter = DB::table('customer_debts')->where('customer_id', $this->customer->id)->count();
        $this->assertGreaterThan($rowsBefore, $rowsAfter,
            'Bán hàng nợ phải tạo customer_debts row để truy vết. '
            . 'Hiện InvoiceSaleService chỉ increment customers.debt_amount, không ghi ledger.');

        $invoice = Invoice::where('customer_id', $this->customer->id)->latest()->first();
        $debtRow = DB::table('customer_debts')
            ->where('customer_id', $this->customer->id)
            ->where('ref_code', $invoice->code)
            ->first();
        $this->assertNotNull($debtRow,
            "customer_debts phải có row với ref_code = invoice.code ({$invoice->code})");
        $this->assertEquals(600000, (float) $debtRow->amount,
            'amount = 600,000 (debt phát sinh)');
        $this->assertSame('sale', $debtRow->type,
            'type = sale cho bán hàng nợ');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR06-03: POS bán hàng nợ phải ghi customer_debts row
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_pos_credit_sale_should_create_customer_debt_transaction(): void
    {
        $product = $this->makeProduct();
        $rowsBefore = DB::table('customer_debts')->where('customer_id', $this->customer->id)->count();

        $this->actingAs($this->admin)->postJson('/api/pos/checkout', [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 1000000,
            'discount'       => 0,
            'total'          => 1000000,
            'customer_paid'  => 400000,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => 5,
                'price'      => 200000,
                'discount'   => 0,
            ]],
        ]);

        $this->customer->refresh();
        $this->assertSame(600000.0, (float) $this->customer->debt_amount);

        $rowsAfter = DB::table('customer_debts')->where('customer_id', $this->customer->id)->count();
        $this->assertGreaterThan($rowsBefore, $rowsAfter,
            'POS bán nợ phải tạo customer_debts row qua InvoiceSaleService.');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR06-04: Trả hàng khách phải ghi customer_debts decrease row
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_order_return_should_create_customer_debt_decrease_transaction(): void
    {
        // Setup: bán hàng nợ trước
        $product = $this->makeProduct();
        $this->actingAs($this->admin)->post(route('invoices.store'), [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 1000000,
            'total'          => 1000000,
            'customer_paid'  => 400000,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => 5,
                'price'      => 200000,
            ]],
        ]);

        $invoice = Invoice::where('customer_id', $this->customer->id)->latest()->first();
        $invoiceItem = $invoice->items->first();
        $this->customer->refresh();
        $debtBefore = (float) $this->customer->debt_amount;

        $rowsBefore = DB::table('customer_debts')->where('customer_id', $this->customer->id)->count();

        // Trả 1 sản phẩm (200k)
        $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id'      => $invoice->id,
            'customer_id'     => $this->customer->id,
            'subtotal'        => 200000,
            'total'           => 200000,
            'paid_to_customer'=> 0,
            'items'           => [[
                'product_id'      => $product->id,
                'qty'             => 1,
                'price'           => 200000,
                'invoice_item_id' => $invoiceItem->id,
            ]],
        ]);

        $this->customer->refresh();
        $debtAfter = (float) $this->customer->debt_amount;
        $this->assertLessThan($debtBefore, $debtAfter,
            'debt_amount phải giảm sau trả hàng');

        $rowsAfter = DB::table('customer_debts')->where('customer_id', $this->customer->id)->count();
        $this->assertGreaterThan($rowsBefore, $rowsAfter,
            'Trả hàng phải tạo customer_debts row decrease/return type. '
            . 'Hiện OrderReturnController@store chỉ decrement debt_amount, không ghi ledger.');

        $returnRow = DB::table('customer_debts')
            ->where('customer_id', $this->customer->id)
            ->whereIn('type', ['return', 'payment', 'adjustment'])
            ->latest('id')
            ->first();
        $this->assertNotNull($returnRow, 'Phải có row type=return/payment/adjustment');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR06-05: Hủy hóa đơn phải ghi customer_debts reverse row
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_cancel_invoice_should_create_customer_debt_reverse_transaction(): void
    {
        $product = $this->makeProduct();
        $this->actingAs($this->admin)->post(route('invoices.store'), [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 1000000,
            'total'          => 1000000,
            'customer_paid'  => 400000,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => 5,
                'price'      => 200000,
            ]],
        ]);

        $invoice = Invoice::where('customer_id', $this->customer->id)->latest()->first();
        $this->customer->refresh();
        $debtBefore = (float) $this->customer->debt_amount;

        $rowsBefore = DB::table('customer_debts')->where('customer_id', $this->customer->id)->count();

        // Cancel invoice
        $this->actingAs($this->admin)->delete(route('invoices.destroy', $invoice->id));

        $this->customer->refresh();
        $debtAfter = (float) $this->customer->debt_amount;
        $this->assertLessThan($debtBefore, $debtAfter,
            'debt_amount phải giảm khi hủy invoice');

        $rowsAfter = DB::table('customer_debts')->where('customer_id', $this->customer->id)->count();
        $this->assertGreaterThan($rowsBefore, $rowsAfter,
            'Hủy invoice phải tạo customer_debts reverse row. '
            . 'Hiện InvoiceController@cancel chỉ decrement debt_amount, không ghi ledger.');
    }
}
