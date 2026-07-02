<?php

namespace Tests\Feature\OrderReturn;

use App\Models\CashFlow;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Step 23.2 — Sales Return + Cancel Return business rules.
 *
 * Yêu cầu (BỔ SUNG vào RR-08 + RR-11):
 * - Trả hàng thường: stock tăng + debt giảm + stock_movement IN.
 * - Trả serial: mark in_stock; không tự pick serial; bắt buộc count===qty.
 * - Không cho trả serial không thuộc invoice.
 * - Cancel return: stock giảm lại; serial về sold; debt khôi phục; cancel 2 lần fail.
 * - Cost snapshot: dùng invoice_item.cost_price chứ không lấy product.cost_price hiện tại.
 */
class Step232SalesReturnFlowTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name'     => 'Admin 23.2',
            'email'    => 'admin-232-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
        $this->customer = Customer::create([
            'code'        => 'KH-232-' . uniqid(),
            'name'        => 'KH 23.2',
            'phone'       => '090' . rand(1000000, 9999999),
            'email'       => 'kh-232-' . uniqid() . '@test.local',
            'debt_amount' => 0,
            'total_spent' => 0,
        ]);
    }

    private function makeProduct(bool $hasSerial = false, int $stock = 10, float $cost = 100000): Product
    {
        $cat = Category::firstOrCreate(['name' => 'Cat 23.2']);
        return Product::create([
            'sku'                  => 'P232-' . uniqid(),
            'name'                 => 'Product 23.2',
            'cost_price'           => $cost,
            'retail_price'         => $cost * 2,
            'stock_quantity'       => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active'            => true,
            'has_serial'           => $hasSerial,
            'category_id'          => $cat->id,
        ]);
    }

    private function makeSerial(Product $product, string $status = 'in_stock'): SerialImei
    {
        return SerialImei::create([
            'product_id'    => $product->id,
            'serial_number' => 'SN232-' . uniqid(),
            'status'        => $status,
            'cost_price'    => $product->cost_price,
            'original_cost' => $product->cost_price,
        ]);
    }

    /** Tạo Invoice bán hàng (qua API store) để có cost snapshot và debt. */
    private function sellNormal(Product $product, int $qty, float $price, float $paid): Invoice
    {
        $this->actingAs($this->admin)->post(route('invoices.store'), [
            'customer_id'    => $this->customer->id,
            'subtotal'       => $qty * $price,
            'discount'       => 0,
            'total'          => $qty * $price,
            'customer_paid'  => $paid,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => $qty,
                'price'      => $price,
                'discount'   => 0,
            ]],
        ]);
        return Invoice::where('customer_id', $this->customer->id)->latest('id')->first();
    }

    private function sellSerial(Product $product, array $serials, float $price, float $paid): Invoice
    {
        $qty = count($serials);
        $this->actingAs($this->admin)->post(route('invoices.store'), [
            'customer_id'    => $this->customer->id,
            'subtotal'       => $qty * $price,
            'discount'       => 0,
            'total'          => $qty * $price,
            'customer_paid'  => $paid,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => $qty,
                'price'      => $price,
                'serial_ids' => array_map(fn ($s) => $s->id, $serials),
            ]],
        ]);
        return Invoice::where('customer_id', $this->customer->id)->latest('id')->first();
    }

    /* ═══════════════════════════════════════════════════════════════════
     * TC-23.2-01: Trả hàng thường → stock tăng, debt giảm, movement IN.
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_return_normal_product_should_restore_stock_and_reduce_debt(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $invoice = $this->sellNormal($product, 3, 200000, 0); // ghi nợ 600k
        $product->refresh();
        $this->customer->refresh();
        $this->assertSame(7, (int) $product->stock_quantity);
        $this->assertSame(600000.0, (float) $this->customer->debt_amount);

        $invoiceItem = $invoice->items()->first();

        $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id'       => $invoice->id,
            'customer_id'      => $this->customer->id,
            'subtotal'         => 200000,
            'total'            => 200000,
            'paid_to_customer' => 0,
            'items'            => [[
                'product_id'      => $product->id,
                'invoice_item_id' => $invoiceItem->id,
                'qty'             => 1,
                'price'           => 200000,
            ]],
        ]);

        $product->refresh();
        $this->customer->refresh();
        $this->assertSame(8, (int) $product->stock_quantity, 'Stock phải +1 sau trả hàng.');
        $this->assertSame(400000.0, (float) $this->customer->debt_amount, 'Debt phải giảm 200k.');

        $return = OrderReturn::where('invoice_id', $invoice->id)->latest('id')->first();
        $this->assertNotNull($return);
        $this->assertSame(1, StockMovement::where('product_id', $product->id)
            ->where('type', 'in_invoice_return')
            ->where('ref_code', $return->code)->count());
        // CustomerDebt ledger: phải có row type='return' với amount âm
        $this->assertTrue(CustomerDebt::where('customer_id', $this->customer->id)
            ->where('type', 'return')
            ->where('amount', -200000)->exists());
    }

    /* ═══════════════════════════════════════════════════════════════════
     * TC-23.2-02: Trả hàng serial → mark in_stock, stock tăng.
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_return_serial_product_should_mark_serial_in_stock(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $sA = $this->makeSerial($product);
        $sB = $this->makeSerial($product);
        $product->update(['stock_quantity' => 2, 'inventory_total_cost' => 10000000]);
        $invoice = $this->sellSerial($product, [$sA, $sB], 8000000, 16000000);
        $sA->refresh(); $sB->refresh();
        $this->assertSame('sold', $sA->status);
        $this->assertSame('sold', $sB->status);

        $invoiceItem = $invoice->items()->first();
        $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id'       => $invoice->id,
            'customer_id'      => $this->customer->id,
            'subtotal'         => 8000000,
            'total'            => 8000000,
            'paid_to_customer' => 8000000,
            'items'            => [[
                'product_id'      => $product->id,
                'invoice_item_id' => $invoiceItem->id,
                'qty'             => 1,
                'price'           => 8000000,
                'serial_ids'      => [$sA->id],
            ]],
        ]);

        $sA->refresh(); $sB->refresh();
        $this->assertSame('in_stock', $sA->status, 'Serial A phải về in_stock.');
        $this->assertSame('sold', $sB->status, 'Serial B KHÔNG được đụng vào.');
        $product->refresh();
        $this->assertSame(1, (int) $product->stock_quantity);
    }

    /* ═══════════════════════════════════════════════════════════════════
     * TC-23.2-03: Trả serial KHÔNG thuộc invoice → fail, không đổi gì.
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_return_serial_not_in_invoice_should_fail(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $sA = $this->makeSerial($product);
        $sOther = $this->makeSerial($product); // sẽ bán ở invoice khác
        $product->update(['stock_quantity' => 2, 'inventory_total_cost' => 10000000]);

        $invoiceA = $this->sellSerial($product, [$sA], 8000000, 8000000);

        // Tạo customer khác + bán sOther cho invoice khác
        $cust2 = Customer::create([
            'code' => 'KH-OTH-' . uniqid(), 'name' => 'KH 2',
            'phone' => '091' . rand(1000000, 9999999),
            'email' => 'oth-' . uniqid() . '@test.local',
            'debt_amount' => 0, 'total_spent' => 0,
        ]);
        $this->actingAs($this->admin)->post(route('invoices.store'), [
            'customer_id' => $cust2->id,
            'subtotal' => 8000000, 'discount' => 0, 'total' => 8000000,
            'customer_paid' => 8000000, 'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id, 'quantity' => 1,
                'price' => 8000000, 'serial_ids' => [$sOther->id],
            ]],
        ]);
        $sOther->refresh();
        $this->assertSame('sold', $sOther->status);
        $stockBefore = (int) $product->fresh()->stock_quantity;

        $invoiceItemA = $invoiceA->items()->first();
        // Cố trả sOther (không thuộc invoiceA) → phải bị chặn
        $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id' => $invoiceA->id,
            'customer_id' => $this->customer->id,
            'subtotal' => 8000000, 'total' => 8000000, 'paid_to_customer' => 0,
            'items' => [[
                'product_id' => $product->id,
                'invoice_item_id' => $invoiceItemA->id,
                'qty' => 1, 'price' => 8000000,
                'serial_ids' => [$sOther->id],
            ]],
        ]);

        $sOther->refresh();
        $this->assertSame('sold', $sOther->status, 'Serial không được đụng.');
        $this->assertSame($stockBefore, (int) $product->fresh()->stock_quantity);
        $this->assertFalse(OrderReturn::where('invoice_id', $invoiceA->id)
            ->where('status', '!=', 'Đã hủy')->exists());
    }

    /* ═══════════════════════════════════════════════════════════════════
     * TC-23.2-04: Trả qty > qty đã bán → fail (RR-11 hiện đã chặn, re-verify).
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_return_more_than_sold_qty_should_fail(): void
    {
        $product = $this->makeProduct(false, 5, 100000);
        $invoice = $this->sellNormal($product, 1, 200000, 200000);

        $resp = $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id' => $invoice->id,
            'customer_id' => $this->customer->id,
            'subtotal' => 400000, 'total' => 400000, 'paid_to_customer' => 0,
            'items' => [[
                'product_id' => $product->id, 'qty' => 2, 'price' => 200000,
            ]],
        ]);

        $this->assertFalse(OrderReturn::where('invoice_id', $invoice->id)->exists());
        $this->assertSame(4, (int) $product->fresh()->stock_quantity);
    }

    /* ═══════════════════════════════════════════════════════════════════
     * TC-23.2-05: Hủy phiếu trả hàng thường → stock trừ lại, debt khôi phục.
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_cancel_return_normal_product_should_reverse_stock_and_debt(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $invoice = $this->sellNormal($product, 3, 200000, 0);
        $invoiceItem = $invoice->items()->first();
        $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id' => $invoice->id, 'customer_id' => $this->customer->id,
            'subtotal' => 200000, 'total' => 200000, 'paid_to_customer' => 0,
            'items' => [[
                'product_id' => $product->id,
                'invoice_item_id' => $invoiceItem->id,
                'qty' => 1, 'price' => 200000,
            ]],
        ]);
        $return = OrderReturn::where('invoice_id', $invoice->id)->latest('id')->first();
        $this->customer->refresh();
        $this->assertSame(400000.0, (float) $this->customer->debt_amount);
        $this->assertSame(8, (int) $product->fresh()->stock_quantity);

        $this->actingAs($this->admin)->post(route('returns.cancel', $return));

        $return->refresh();
        $this->assertSame('Đã hủy', $return->status);
        $this->assertSame(7, (int) $product->fresh()->stock_quantity, 'Stock phải -1 sau cancel.');
        $this->customer->refresh();
        $this->assertSame(600000.0, (float) $this->customer->debt_amount, 'Debt phải về 600k.');
    }

    /* ═══════════════════════════════════════════════════════════════════
     * TC-23.2-06: Hủy phiếu trả hàng serial → serial về sold.
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_cancel_return_serial_should_mark_serial_back_to_sold(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $sA = $this->makeSerial($product);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);
        $invoice = $this->sellSerial($product, [$sA], 8000000, 8000000);
        $invoiceItem = $invoice->items()->first();

        $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id' => $invoice->id, 'customer_id' => $this->customer->id,
            'subtotal' => 8000000, 'total' => 8000000, 'paid_to_customer' => 8000000,
            'items' => [[
                'product_id' => $product->id,
                'invoice_item_id' => $invoiceItem->id,
                'qty' => 1, 'price' => 8000000,
                'serial_ids' => [$sA->id],
            ]],
        ]);
        $sA->refresh();
        $this->assertSame('in_stock', $sA->status);
        $return = OrderReturn::where('invoice_id', $invoice->id)->latest('id')->first();

        $this->actingAs($this->admin)->post(route('returns.cancel', $return));

        $sA->refresh();
        $this->assertSame('sold', $sA->status, 'Serial phải về sold sau cancel.');
        $this->assertSame((int) $invoice->id, (int) $sA->invoice_id);
        $this->assertSame(0, (int) $product->fresh()->stock_quantity);
    }

    /* ═══════════════════════════════════════════════════════════════════
     * TC-23.2-07: Hủy 2 lần → lần 2 fail (status check).
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_cancel_return_twice_should_fail(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $invoice = $this->sellNormal($product, 2, 200000, 0);
        $invoiceItem = $invoice->items()->first();
        $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id' => $invoice->id, 'customer_id' => $this->customer->id,
            'subtotal' => 200000, 'total' => 200000, 'paid_to_customer' => 0,
            'items' => [[
                'product_id' => $product->id,
                'invoice_item_id' => $invoiceItem->id,
                'qty' => 1, 'price' => 200000,
            ]],
        ]);
        $return = OrderReturn::where('invoice_id', $invoice->id)->latest('id')->first();

        $this->actingAs($this->admin)->post(route('returns.cancel', $return));
        $stockAfter1 = (int) $product->fresh()->stock_quantity;
        $debtAfter1 = (float) $this->customer->fresh()->debt_amount;

        $resp = $this->actingAs($this->admin)->post(route('returns.cancel', $return));

        // Stock + debt KHÔNG bị double-reverse
        $this->assertSame($stockAfter1, (int) $product->fresh()->stock_quantity,
            'Cancel lần 2 không được đụng stock.');
        $this->assertSame($debtAfter1, (float) $this->customer->fresh()->debt_amount,
            'Cancel lần 2 không được đụng debt.');
    }

    /* ═══════════════════════════════════════════════════════════════════
     * TC-23.2-08: Cost snapshot → return dùng invoice_item.cost_price (lúc bán),
     * KHÔNG dùng product.cost_price hiện tại sau khi đã thay đổi.
     * ═══════════════════════════════════════════════════════════════════ */
    public function test_return_uses_invoice_item_cost_price_not_current_product_cost(): void
    {
        $product = $this->makeProduct(false, 10, 100000); // cost A = 100k
        $invoice = $this->sellNormal($product, 1, 200000, 200000);
        $invoiceItem = $invoice->items()->first();
        $this->assertSame(100000.0, (float) $invoiceItem->cost_price);

        // Đổi cost_price hiện tại của product (giả lập mua thêm hàng giá khác)
        $product->update(['cost_price' => 250000, 'inventory_total_cost' => 9 * 250000]);

        $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id' => $invoice->id, 'customer_id' => $this->customer->id,
            'subtotal' => 200000, 'total' => 200000, 'paid_to_customer' => 200000,
            'items' => [[
                'product_id' => $product->id,
                'invoice_item_id' => $invoiceItem->id,
                'qty' => 1, 'price' => 200000,
            ]],
        ]);

        $return = OrderReturn::where('invoice_id', $invoice->id)->latest('id')->first();
        $returnItem = $return->items()->first();
        $this->assertSame(100000.0, (float) $returnItem->cost_price,
            'return_items.cost_price phải = invoice_item.cost_price (snapshot).');

        $movement = StockMovement::where('product_id', $product->id)
            ->where('type', 'in_invoice_return')
            ->where('ref_code', $return->code)->first();
        $this->assertNotNull($movement);
        $this->assertSame(100000.0, (float) $movement->unit_cost,
            'StockMovement.unit_cost phải = cost_price snapshot (không phải 250k hiện tại).');
    }
}
