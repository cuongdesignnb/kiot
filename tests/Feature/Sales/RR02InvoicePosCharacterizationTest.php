<?php

namespace Tests\Feature\Sales;

use App\Models\CashFlow;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItemSerial;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * RR-02: Characterization tests cho InvoiceController@store vs PosController@checkout.
 *
 * Mục tiêu: khóa behavior hiện tại trước khi tách InvoiceSaleService.
 * Không sửa business code. Test phải pass cả TRƯỚC và SAU refactor để đảm bảo
 * không phá tồn/giá vốn/movement/serial/CashFlow/debt.
 *
 * Bug rõ rệt đã xác định ở POS:
 *   - Tạo InvoiceItemSerial với invoice_item_id=0 rồi update sau (race-prone).
 *   Test P02 assert sau commit không còn record nào với invoice_item_id=0.
 */
class RR02InvoicePosCharacterizationTest extends TestCase
{
    use DatabaseTransactions;

    private User     $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin RR02',
            'email'    => 'admin-rr02-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);

        $this->customer = Customer::create([
            'code'        => 'KH-RR02-' . uniqid(),
            'name'        => 'KH RR02 ' . uniqid(),
            'phone'       => '090' . rand(1000000, 9999999),
            'email'       => 'kh-rr02-' . uniqid() . '@test.local',
            'debt_amount' => 0,
            'total_spent' => 0,
        ]);
    }

    private function makeProduct(bool $hasSerial = false, int $stock = 10, float $cost = 100000): Product
    {
        $category = Category::firstOrCreate(['name' => 'Cat RR02']);

        return Product::create([
            'sku'                  => 'PROD-RR02-' . uniqid(),
            'name'                 => 'Product RR02',
            'cost_price'           => $cost,
            'retail_price'         => $cost * 2,
            'stock_quantity'       => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active'            => true,
            'has_serial'           => $hasSerial,
            'category_id'          => $category->id,
        ]);
    }

    private function makeSerial(Product $product, string $status = 'in_stock'): SerialImei
    {
        return SerialImei::create([
            'product_id'    => $product->id,
            'serial_number' => 'SN-' . uniqid(),
            'status'        => $status,
            'cost_price'    => $product->cost_price,
            'original_cost' => $product->cost_price,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR02-I01: Invoice sản phẩm thường
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_invoice_sale_normal_product_creates_expected_inventory_and_movement(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $movementsBefore = StockMovement::where('product_id', $product->id)->count();

        $this->actingAs($this->admin)->post(route('invoices.store'), [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 3 * 200000,
            'discount'       => 0,
            'total'          => 3 * 200000,
            'customer_paid'  => 3 * 200000,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => 3,
                'price'      => 200000,
                'discount'   => 0,
            ]],
        ]);

        $invoice = Invoice::where('customer_id', $this->customer->id)->latest()->first();
        $this->assertNotNull($invoice, 'Invoice phải được tạo');
        $this->assertSame('Hoàn thành', $invoice->status);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame(3, (int) $invoice->items->first()->quantity);

        $product->refresh();
        $this->assertSame(7, (int) $product->stock_quantity);
        $this->assertSame(700000.0, (float) $product->inventory_total_cost);
        $this->assertSame(100000.0, (float) $product->cost_price);

        $this->assertGreaterThan($movementsBefore, StockMovement::where('product_id', $product->id)->count());
        $movement = StockMovement::where('product_id', $product->id)->latest('id')->first();
        $this->assertSame('out_invoice', $movement->type);
        $this->assertSame(3, (int) $movement->qty);

        $this->assertTrue(
            CashFlow::where('reference_code', $invoice->code)
                ->where('type', 'receipt')
                ->where('amount', 600000)
                ->exists(),
            'CashFlow receipt phải được tạo khi customer_paid > 0'
        );

        $this->customer->refresh();
        $this->assertSame(0.0, (float) $this->customer->debt_amount, 'Paid full: debt = 0');
        $this->assertSame(600000.0, (float) $this->customer->total_spent);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR02-I02: Invoice serial — InvoiceItemSerial.invoice_item_id != 0
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_invoice_sale_serial_creates_valid_invoice_item_serial(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $serialA = $this->makeSerial($product);
        // Set stock = 1 để khớp serial in_stock
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);

        $this->actingAs($this->admin)->post(route('invoices.store'), [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 8000000,
            'total'          => 8000000,
            'customer_paid'  => 8000000,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 8000000,
                'serial_ids' => [$serialA->id],
            ]],
        ]);

        $invoice = Invoice::where('customer_id', $this->customer->id)->latest()->first();
        $this->assertNotNull($invoice);
        $invoiceItem = $invoice->items->first();
        $this->assertNotNull($invoiceItem);

        $serialA->refresh();
        $this->assertSame('sold', $serialA->status);
        $this->assertSame($invoice->id, (int) $serialA->invoice_id);

        $iisRecord = InvoiceItemSerial::where('serial_imei_id', $serialA->id)->first();
        $this->assertNotNull($iisRecord);
        $this->assertNotSame(0, (int) $iisRecord->invoice_item_id, 'invoice_item_id phải != 0');
        $this->assertSame((int) $invoiceItem->id, (int) $iisRecord->invoice_item_id);
        $this->assertSame(5000000, (int) $iisRecord->cost_price);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR02-P01: POS sản phẩm thường
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_pos_sale_normal_product_creates_expected_inventory_and_movement(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $movementsBefore = StockMovement::where('product_id', $product->id)->count();
        $invoiceCountBefore = Invoice::count();

        $response = $this->actingAs($this->admin)->postJson('/api/pos/checkout', [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 3 * 200000,
            'discount'       => 0,
            'total'          => 3 * 200000,
            'customer_paid'  => 3 * 200000,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => 3,
                'price'      => 200000,
                'discount'   => 0,
            ]],
        ]);

        $response->assertStatus(200);
        $this->assertGreaterThan($invoiceCountBefore, Invoice::count(), 'POS phải tạo Invoice');

        $invoice = Invoice::where('customer_id', $this->customer->id)->latest()->first();
        $this->assertNotNull($invoice);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame(3, (int) $invoice->items->first()->quantity);

        $product->refresh();
        $this->assertSame(7, (int) $product->stock_quantity);
        $this->assertSame(700000.0, (float) $product->inventory_total_cost);

        $this->assertGreaterThan($movementsBefore, StockMovement::where('product_id', $product->id)->count());
        $movement = StockMovement::where('product_id', $product->id)->latest('id')->first();
        $this->assertSame('out_invoice', $movement->type);
        $this->assertSame(3, (int) $movement->qty);

        $this->assertTrue(
            CashFlow::where('reference_code', $invoice->code)
                ->where('type', 'receipt')
                ->where('amount', 600000)
                ->exists()
        );

        $this->customer->refresh();
        $this->assertSame(0.0, (float) $this->customer->debt_amount);
        $this->assertSame(600000.0, (float) $this->customer->total_spent);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR02-P02: POS serial — không còn InvoiceItemSerial với invoice_item_id=0
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_pos_sale_serial_creates_valid_invoice_item_serial_without_zero_invoice_item_id(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $serialA = $this->makeSerial($product);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);

        $response = $this->actingAs($this->admin)->postJson('/api/pos/checkout', [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 8000000,
            'discount'       => 0,
            'total'          => 8000000,
            'customer_paid'  => 8000000,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 8000000,
                'discount'   => 0,
                'serial_ids' => [$serialA->id],
            ]],
        ]);

        $response->assertStatus(200);

        $invoice = Invoice::where('customer_id', $this->customer->id)->latest()->first();
        $this->assertNotNull($invoice);
        $invoiceItem = $invoice->items->first();
        $this->assertNotNull($invoiceItem);

        $serialA->refresh();
        $this->assertSame('sold', $serialA->status);
        $this->assertSame($invoice->id, (int) $serialA->invoice_id);

        $iisRecord = InvoiceItemSerial::where('serial_imei_id', $serialA->id)->first();
        $this->assertNotNull($iisRecord);
        $this->assertNotSame(0, (int) $iisRecord->invoice_item_id,
            'POS phải update invoice_item_id sau khi tạo invoiceItem; sau commit phải != 0');
        $this->assertSame((int) $invoiceItem->id, (int) $iisRecord->invoice_item_id);

        $this->assertSame(0, InvoiceItemSerial::where('invoice_item_id', 0)->count(),
            'Sau commit phải không còn InvoiceItemSerial nào với invoice_item_id=0 trong DB');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR02-C01: Invoice và POS cùng payload → cùng inventory effect
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_invoice_and_pos_sale_have_equivalent_inventory_effects_for_same_payload(): void
    {
        $productInvoice = $this->makeProduct(false, 10, 100000);
        $productPos     = $this->makeProduct(false, 10, 100000);

        // Invoice
        $this->actingAs($this->admin)->post(route('invoices.store'), [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 3 * 200000,
            'discount'       => 0,
            'total'          => 3 * 200000,
            'customer_paid'  => 3 * 200000,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $productInvoice->id,
                'quantity'   => 3,
                'price'      => 200000,
            ]],
        ]);

        // POS
        $this->actingAs($this->admin)->postJson('/api/pos/checkout', [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 3 * 200000,
            'discount'       => 0,
            'total'          => 3 * 200000,
            'customer_paid'  => 3 * 200000,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $productPos->id,
                'quantity'   => 3,
                'price'      => 200000,
                'discount'   => 0,
            ]],
        ]);

        $productInvoice->refresh();
        $productPos->refresh();

        $this->assertSame(
            (int) $productInvoice->stock_quantity,
            (int) $productPos->stock_quantity,
            'stock_quantity phải tương đương'
        );
        $this->assertSame(
            (float) $productInvoice->inventory_total_cost,
            (float) $productPos->inventory_total_cost,
            'inventory_total_cost phải tương đương'
        );
        $this->assertSame(
            (float) $productInvoice->cost_price,
            (float) $productPos->cost_price,
            'cost_price phải tương đương'
        );

        $invMovement = StockMovement::where('product_id', $productInvoice->id)->latest('id')->first();
        $posMovement = StockMovement::where('product_id', $productPos->id)->latest('id')->first();
        $this->assertSame($invMovement->type, $posMovement->type, 'Movement type phải tương đương');
        $this->assertSame((int) $invMovement->qty, (int) $posMovement->qty, 'Movement qty phải tương đương');
    }
}
