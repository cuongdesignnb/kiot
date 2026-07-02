<?php

namespace Tests\Feature\Orders;

use App\Http\Controllers\OrderController;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItemSerial;
use App\Models\Order;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * RR-13 (mới — phát hiện qua scan Bước 17):
 * OrderController@processOrder (Order → Invoice convert) raw stock decrement,
 * không qua MovingAvgCostingService, không ghi StockMovement, không xử lý Serial.
 *
 * Bug ở dòng 376-377:
 *   $product->stock_quantity -= $orderItem->qty;
 *   $product->save();
 *
 * Pattern giống RR-09 trước fix. InvoiceSaleService đã sẵn sàng.
 *
 * Lưu ý: route `orders.processOrder` chưa đăng ký → test gọi controller method trực tiếp.
 */
class RR13OrderConvertStockTest extends TestCase
{
    use DatabaseTransactions;

    private User     $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin RR13',
            'email'    => 'admin-rr13-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);

        $this->customer = Customer::create([
            'code'        => 'KH-RR13-' . uniqid(),
            'name'        => 'KH RR13 ' . uniqid(),
            'phone'       => '090' . rand(1000000, 9999999),
            'email'       => 'kh-rr13-' . uniqid() . '@test.local',
            'debt_amount' => 0,
            'total_spent' => 0,
        ]);
    }

    private function makeProduct(bool $hasSerial = false, int $stock = 10, float $cost = 100000): Product
    {
        $category = Category::firstOrCreate(['name' => 'Cat RR13']);
        return Product::create([
            'sku'                  => 'PROD-RR13-' . uniqid(),
            'name'                 => 'Product RR13',
            'cost_price'           => $cost,
            'retail_price'         => $cost * 2,
            'stock_quantity'       => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active'            => true,
            'has_serial'           => $hasSerial,
            'category_id'          => $category->id,
        ]);
    }

    private function makeOrder(Product $product, int $qty, float $price): Order
    {
        $order = Order::create([
            'code'             => 'DH-RR13-' . uniqid(),
            'customer_id'      => $this->customer->id,
            'status'           => 'draft',
            'total_price'      => $qty * $price,
            'discount'         => 0,
            'other_fees'       => 0,
            'total_payment'    => $qty * $price,
            'amount_paid'      => 0,
            'created_by_name'  => 'Admin',
            'assigned_to_name' => 'Admin',
            'price_book_name'  => 'Bảng giá chung',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'qty'        => $qty,
            'price'      => $price,
            'discount'   => 0,
            'subtotal'   => $qty * $price,
        ]);
        return $order;
    }

    private function callProcessOrder(Order $order, float $amountPaid = 0, string $paymentMethod = 'cash')
    {
        $request = Request::create('/orders-test', 'POST', [
            'amount_paid'    => $amountPaid,
            'payment_method' => $paymentMethod,
        ]);
        $this->actingAs($this->admin);
        return app(OrderController::class)->processOrder($request, $order);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR13-01: Convert phải giảm cả stock_quantity VÀ inventory_total_cost
     *  Bug hiện tại: chỉ giảm stock_quantity → cost_price inflate.
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_order_convert_should_decrease_stock_and_inventory_total_cost(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $order = $this->makeOrder($product, 3, 200000);

        $this->callProcessOrder($order, 600000, 'cash');

        $product->refresh();

        $this->assertSame(7, (int) $product->stock_quantity,
            'Stock phải giảm còn 7');
        $this->assertSame(700000.0, (float) $product->inventory_total_cost,
            'inventory_total_cost phải giảm theo BQ (1M - 3*100k = 700k). '
            . 'Hiện tại không update → cost_price inflate.');
        $this->assertSame(100000.0, (float) $product->cost_price,
            'cost_price phải giữ 100k. Nếu total_cost không update → ~142,857.');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR13-02: Convert phải ghi StockMovement out_invoice
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_order_convert_should_create_stock_movement(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $movementsBefore = StockMovement::where('product_id', $product->id)->count();
        $order = $this->makeOrder($product, 3, 200000);

        $this->callProcessOrder($order, 600000, 'cash');

        $movementsAfter = StockMovement::where('product_id', $product->id)->count();
        $this->assertGreaterThan($movementsBefore, $movementsAfter,
            'Convert order phải tạo StockMovement. Hiện processOrder không gọi StockMovementService.');

        $movement = StockMovement::where('product_id', $product->id)->latest('id')->first();
        $this->assertNotNull($movement);
        $this->assertSame('out_invoice', $movement->type, 'Movement type phải = out_invoice');
        $this->assertSame(3, (int) $movement->qty);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR13-03: Convert quá tồn phải fail (Setting=disable_oversell)
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_order_convert_should_not_allow_quantity_greater_than_stock(): void
    {
        \App\Models\Setting::query()->updateOrCreate(
            ['key' => 'inventory_allow_oversell'],
            ['value' => '0']
        );

        $product = $this->makeProduct(false, 2, 100000);
        $stockBefore = $product->stock_quantity;
        $order = $this->makeOrder($product, 3, 200000);

        $this->callProcessOrder($order, 600000, 'cash');

        $product->refresh();
        $order->refresh();

        $this->assertSame((int) $stockBefore, (int) $product->stock_quantity,
            'Stock không được thay đổi khi qty > stock');

        $this->assertNotSame('completed', $order->status,
            'Order không được set completed khi processOrder fail');

        $invoice = Invoice::where('order_id', $order->id)->first();
        $this->assertNull($invoice, 'Không được tạo Invoice khi qty > stock');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR13-04: Schema OrderItem không có serial_ids → convert hàng serial
     *  phải FAIL AN TOÀN (không chọn đại serial, không tạo Invoice, không trừ tồn).
     *
     *  Sau Step 18.1B: controller throw exception nếu product has_serial mà
     *  orderItem.serial_ids rỗng. Đây là expected behavior an toàn — Bước 18.1A
     *  ban đầu kỳ vọng "mark sold" nhưng OrderItem schema không hỗ trợ → đổi
     *  expected behavior sang fail-safe. Đây không phải che lỗi: schema thiếu
     *  serial_ids là blocker thực sự, fail-safe đúng hơn là chọn đại.
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_order_convert_serial_without_serial_ids_should_fail_safely(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $serialA = SerialImei::create([
            'product_id'    => $product->id,
            'serial_number' => 'SN-A-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 5000000,
            'original_cost' => 5000000,
        ]);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);

        $movementsBefore = StockMovement::where('product_id', $product->id)->count();
        $order = $this->makeOrder($product, 1, 8000000);

        $this->callProcessOrder($order, 8000000, 'cash');

        $serialA->refresh();
        $product->refresh();
        $order->refresh();

        // Convert phải fail an toàn — không động vào dữ liệu
        $this->assertSame('in_stock', $serialA->status,
            'Serial KHÔNG được mark sold khi order chưa lưu serial_ids');
        $this->assertNull($serialA->invoice_id, 'Serial.invoice_id phải null');
        $this->assertNull($serialA->sold_cost_price, 'Serial.sold_cost_price phải null');

        $this->assertNotSame('completed', $order->status,
            'Order không được set completed khi processOrder fail');

        $invoice = Invoice::where('order_id', $order->id)->first();
        $this->assertNull($invoice, 'Không được tạo Invoice khi serial chưa được chọn');

        $this->assertSame(1, (int) $product->stock_quantity, 'Stock không đổi');
        $this->assertSame(5000000.0, (float) $product->inventory_total_cost, 'total_cost không đổi');

        $this->assertSame(
            $movementsBefore,
            StockMovement::where('product_id', $product->id)->count(),
            'Không tạo StockMovement khi convert fail'
        );

        $this->assertSame(0, InvoiceItemSerial::where('serial_imei_id', $serialA->id)->count(),
            'Không tạo InvoiceItemSerial khi convert fail');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR13-05 (Step 22.1C): Convert hàng serial VỚI serial_ids đã chọn
     *  phải mark đúng serial sang sold, tạo InvoiceItemSerial, ghi
     *  StockMovement out_invoice và set order=completed.
     *
     *  Đây là happy path mới sau khi thêm cột order_items.serial_ids và UI
     *  Selector. Test xác nhận processOrder không tự chọn đại — chỉ dùng đúng
     *  serial_ids người dùng đã lưu trên OrderItem.
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_order_convert_serial_with_serial_ids_should_mark_selected_serial_as_sold(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $serialA = SerialImei::create([
            'product_id'    => $product->id,
            'serial_number' => 'SN-A-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 5000000,
            'original_cost' => 5000000,
        ]);
        $serialB = SerialImei::create([
            'product_id'    => $product->id,
            'serial_number' => 'SN-B-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 5000000,
            'original_cost' => 5000000,
        ]);
        $product->update(['stock_quantity' => 2, 'inventory_total_cost' => 10000000]);

        $movementsBefore = StockMovement::where('product_id', $product->id)->count();
        $order = $this->makeOrder($product, 1, 8000000);

        // Step 22.1C: lưu serial_ids vào OrderItem (mô phỏng UI selector).
        $order->items()->first()->update(['serial_ids' => [$serialA->id]]);

        $this->callProcessOrder($order, 8000000, 'cash');

        $serialA->refresh();
        $serialB->refresh();
        $product->refresh();
        $order->refresh();

        $invoice = Invoice::where('order_id', $order->id)->first();
        $this->assertNotNull($invoice, 'Invoice phải được tạo từ order');

        $this->assertSame('sold', $serialA->status,
            'Serial A (đã chọn) phải mark sold');
        $this->assertSame((int) $invoice->id, (int) $serialA->invoice_id,
            'Serial A.invoice_id phải gán = invoice mới');
        $this->assertNotNull($serialA->sold_cost_price);
        $this->assertSame('in_stock', $serialB->status,
            'Serial B (KHÔNG chọn) phải giữ nguyên in_stock — không được chọn đại');
        $this->assertNull($serialB->invoice_id);

        $invoiceItem = $invoice->items()->first();
        $this->assertNotNull($invoiceItem);
        $iis = InvoiceItemSerial::where('serial_imei_id', $serialA->id)->first();
        $this->assertNotNull($iis, 'Phải tạo InvoiceItemSerial cho serial A');
        $this->assertSame((int) $invoiceItem->id, (int) $iis->invoice_item_id,
            'InvoiceItemSerial.invoice_item_id phải trỏ đúng invoice item');

        $this->assertSame('completed', $order->status, 'Order phải completed');
        $this->assertSame(1, (int) $product->stock_quantity, 'Stock giảm 1 còn 1');

        $movement = StockMovement::where('product_id', $product->id)
            ->where('type', 'out_invoice')
            ->latest('id')
            ->first();
        $this->assertNotNull($movement, 'Phải ghi StockMovement out_invoice');
        $this->assertGreaterThan($movementsBefore,
            StockMovement::where('product_id', $product->id)->count());
    }
}
