<?php

namespace Tests\Feature\Warranty;

use App\Http\Controllers\OrderController;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\SerialImei;
use App\Models\User;
use App\Models\Warranty;
use App\Services\WarrantyGenerationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * STEP 23.7B — Auto-generate Warranty from POS / Invoice manual / Order process.
 */
class Step237BGenerateWarrantyFromSalesTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name'     => 'Admin 23.7B',
            'email'    => 'admin-237b-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
        $this->customer = Customer::create([
            'code'        => 'KH237B-' . uniqid(),
            'name'        => 'KH 237B',
            'phone'       => '090' . rand(1000000, 9999999),
            'email'       => 'kh-237b-' . uniqid() . '@test.local',
            'debt_amount' => 0,
            'total_spent' => 0,
        ]);
    }

    private function makeProduct(bool $hasSerial, int $stock, float $cost = 1000000): Product
    {
        $cat = Category::firstOrCreate(['name' => 'Cat 23.7B']);
        return Product::create([
            'sku'                  => 'P237B-' . uniqid(),
            'name'                 => 'Product 23.7B',
            'cost_price'           => $cost,
            'retail_price'         => $cost * 2,
            'stock_quantity'       => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active'            => true,
            'has_serial'           => $hasSerial,
            'category_id'          => $cat->id,
        ]);
    }

    private function seedWarrantyMonths(Product $product, int $months): void
    {
        $purchase = Purchase::create([
            'code'         => 'PN-237B-' . uniqid(),
            'supplier_id'  => null,
            'user_id'      => $this->admin->id,
            'total_amount' => 0,
            'paid_amount'  => 0,
            'debt_amount'  => 0,
            'status'       => 'completed',
        ]);
        PurchaseItem::create([
            'purchase_id'     => $purchase->id,
            'product_id'      => $product->id,
            'product_name'    => $product->name,
            'product_code'    => $product->sku,
            'quantity'        => 1,
            'price'           => $product->cost_price,
            'discount'        => 0,
            'subtotal'        => $product->cost_price,
            'warranty_months' => $months,
        ]);
    }

    private function makeSerial(Product $product): SerialImei
    {
        return SerialImei::create([
            'product_id'    => $product->id,
            'serial_number' => 'SN237B-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => $product->cost_price,
            'original_cost' => $product->cost_price,
        ]);
    }

    /* ── 1. POS serial → 1 warranty/serial ── */
    public function test_pos_sale_serial_product_should_create_warranty_per_serial(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $this->seedWarrantyMonths($product, 12);
        $sA = $this->makeSerial($product);
        $sB = $this->makeSerial($product);
        $product->update(['stock_quantity' => 2, 'inventory_total_cost' => 10000000]);

        $resp = $this->actingAs($this->admin)->postJson('/api/pos/checkout', [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 16000000,
            'discount'       => 0,
            'total'          => 16000000,
            'customer_paid'  => 16000000,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 2,
                'price'      => 8000000,
                'serial_ids' => [$sA->id, $sB->id],
            ]],
        ]);
        $resp->assertStatus(200);

        $invoice = Invoice::latest('id')->first();
        $this->assertNotNull($invoice);

        $warranties = Warranty::where('invoice_code', $invoice->code)
            ->where('product_id', $product->id)->get();
        $this->assertCount(2, $warranties, '1 warranty/serial.');
        $this->assertEqualsCanonicalizing(
            [$sA->serial_number, $sB->serial_number],
            $warranties->pluck('serial_imei')->all()
        );
        $this->assertSame(12, (int) $warranties->first()->warranty_period);
        $this->assertSame('KH 237B', $warranties->first()->customer_name);
    }

    /* ── 2. Invoice manual serial → 1 warranty/serial ── */
    public function test_invoice_manual_serial_product_should_create_warranty_per_serial(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $this->seedWarrantyMonths($product, 24);
        $sA = $this->makeSerial($product);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);

        $this->actingAs($this->admin)->post(route('invoices.store'), [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 8000000,
            'total'          => 8000000,
            'customer_paid'  => 8000000,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 8000000,
                'serial_ids' => [$sA->id],
            ]],
        ]);

        $invoice = Invoice::latest('id')->first();
        $this->assertNotNull($invoice);
        $w = Warranty::where('invoice_code', $invoice->code)->first();
        $this->assertNotNull($w);
        $this->assertSame($sA->serial_number, $w->serial_imei);
        $this->assertSame(24, (int) $w->warranty_period);
    }

    /* ── 3. Order process → invoice serial → warranty ── */
    public function test_order_process_serial_product_should_create_warranty_per_serial(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $this->seedWarrantyMonths($product, 6);
        $sA = $this->makeSerial($product);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);

        $order = Order::create([
            'code'             => 'DH-237B-' . uniqid(),
            'customer_id'      => $this->customer->id,
            'status'           => 'draft',
            'total_price'      => 8000000,
            'discount'         => 0,
            'other_fees'       => 0,
            'total_payment'    => 8000000,
            'amount_paid'      => 0,
            'created_by_name'  => 'Admin',
            'assigned_to_name' => 'Admin',
            'price_book_name'  => 'Bảng giá chung',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'qty'        => 1,
            'price'      => 8000000,
            'discount'   => 0,
            'subtotal'   => 8000000,
            'serial_ids' => [$sA->id],
        ]);

        $req = Request::create('/orders-test', 'POST', [
            'amount_paid'    => 8000000,
            'payment_method' => 'cash',
        ]);
        $this->actingAs($this->admin);
        app(OrderController::class)->processOrder($req, $order);

        $invoice = Invoice::where('order_id', $order->id)->latest('id')->first();
        $this->assertNotNull($invoice, 'processOrder phải tạo invoice.');

        $w = Warranty::where('invoice_code', $invoice->code)->first();
        $this->assertNotNull($w, 'Order process serial phải sinh warranty.');
        $this->assertSame($sA->serial_number, $w->serial_imei);
        $this->assertSame(6, (int) $w->warranty_period);
    }

    /* ── 4. Normal product có warranty_months → 1 warranty/item ── */
    public function test_normal_product_with_warranty_months_should_create_one_warranty_per_invoice_item(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $this->seedWarrantyMonths($product, 12);

        $this->actingAs($this->admin)->post(route('invoices.store'), [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 600000,
            'total'          => 600000,
            'customer_paid'  => 600000,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 3,
                'price'      => 200000,
            ]],
        ]);

        $invoice = Invoice::latest('id')->first();
        $this->assertNotNull($invoice);
        $warranties = Warranty::where('invoice_code', $invoice->code)
            ->where('product_id', $product->id)->get();
        $this->assertCount(1, $warranties, 'Hàng thường: 1 warranty/item, không theo qty.');
        $this->assertNull($warranties->first()->serial_imei);
        $this->assertSame(12, (int) $warranties->first()->warranty_period);
    }

    /* ── 5. Sản phẩm không có warranty_months → không tạo warranty ── */
    public function test_product_without_warranty_months_should_not_create_warranty(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        // Không seed warranty_months.

        $this->actingAs($this->admin)->post(route('invoices.store'), [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 200000,
            'total'          => 200000,
            'customer_paid'  => 200000,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 200000,
            ]],
        ]);

        $invoice = Invoice::latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertSame(0, Warranty::where('invoice_code', $invoice->code)->count());
    }

    /* ── 6. Idempotent ── */
    public function test_warranty_generation_is_idempotent(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $this->seedWarrantyMonths($product, 12);
        $sA = $this->makeSerial($product);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);

        $this->actingAs($this->admin)->postJson('/api/pos/checkout', [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 8000000,
            'discount'       => 0,
            'total'          => 8000000,
            'customer_paid'  => 8000000,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 8000000,
                'serial_ids' => [$sA->id],
            ]],
        ])->assertStatus(200);

        $invoice = Invoice::latest('id')->first();
        $countAfterSale = Warranty::where('invoice_code', $invoice->code)->count();
        $this->assertSame(1, $countAfterSale);

        // Gọi lại generateForInvoice 2 lần
        app(WarrantyGenerationService::class)->generateForInvoice($invoice);
        app(WarrantyGenerationService::class)->generateForInvoice($invoice);

        $this->assertSame(1, Warranty::where('invoice_code', $invoice->code)->count(),
            'Idempotent: không tạo trùng.');
    }

    /* ── 7. Rollback safety ── */
    public function test_warranty_generation_rolls_back_if_invoice_transaction_fails(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $this->seedWarrantyMonths($product, 12);
        $sA = $this->makeSerial($product);
        // status sold cố ý → InvoiceSaleService::assertSerialSelectionComplete throw
        $sA->update(['status' => 'sold']);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);

        $invoiceCountBefore = Invoice::count();
        $warrantyCountBefore = Warranty::count();

        $this->actingAs($this->admin)->post(route('invoices.store'), [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 8000000,
            'total'          => 8000000,
            'customer_paid'  => 8000000,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 8000000,
                'serial_ids' => [$sA->id],
            ]],
        ]);

        $this->assertSame($invoiceCountBefore, Invoice::count(),
            'Sale fail → không có invoice rác.');
        $this->assertSame($warrantyCountBefore, Warranty::count(),
            'Sale fail → không có warranty rác.');
    }

    /* ── 8. End date math ── */
    public function test_warranty_end_date_calculated_from_purchase_date(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $this->seedWarrantyMonths($product, 12);

        $this->actingAs($this->admin)->post(route('invoices.store'), [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 200000,
            'total'          => 200000,
            'customer_paid'  => 200000,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 200000,
            ]],
        ]);

        $invoice = Invoice::latest('id')->first();
        $w = Warranty::where('invoice_code', $invoice->code)->first();
        $this->assertNotNull($w);

        $purchase = \Carbon\Carbon::parse($w->purchase_date);
        $end      = \Carbon\Carbon::parse($w->warranty_end_date);
        $this->assertSame(12, (int) round($purchase->diffInMonths($end)),
            'warranty_end_date = purchase_date + 12 tháng.');
    }
}
