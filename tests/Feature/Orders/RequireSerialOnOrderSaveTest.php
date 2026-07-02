<?php

namespace Tests\Feature\Orders;

use App\Http\Controllers\OrderController;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
/**
 * Step 22.2G: Bắt buộc chọn đủ Serial/IMEI khi tạo/sửa Order với hàng has_serial.
 * Trước đây backend chỉ validate khi serial_ids non-empty → user lưu Order serial
 * không tick serial → vẫn create thành công. Sai contract.
 */
class RequireSerialOnOrderSaveTest extends TestCase
{
    use DatabaseTransactions;

    private User     $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin 22.2G',
            'email'    => 'admin-22-2g-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);

        $this->customer = Customer::create([
            'code'        => 'KH-22-2G-' . uniqid(),
            'name'        => 'KH 22.2G',
            'phone'       => '090' . rand(1000000, 9999999),
            'email'       => 'kh-22-2g-' . uniqid() . '@test.local',
            'debt_amount' => 0,
            'total_spent' => 0,
        ]);
    }

    private function makeSerialProduct(int $stock = 5, float $cost = 1000000): Product
    {
        $category = Category::firstOrCreate(['name' => 'Cat 22.2G']);
        return Product::create([
            'sku'                  => 'P22G-' . uniqid(),
            'name'                 => 'Serial Product 22.2G',
            'cost_price'           => $cost,
            'retail_price'         => $cost * 2,
            'stock_quantity'       => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active'            => true,
            'has_serial'           => true,
            'category_id'          => $category->id,
        ]);
    }

    private function makeSerial(Product $product, string $status = 'in_stock'): SerialImei
    {
        return SerialImei::create([
            'product_id'    => $product->id,
            'serial_number' => 'SN-22G-' . uniqid(),
            'status'        => $status,
            'cost_price'    => $product->cost_price,
        ]);
    }

    private function storePayload(Product $product, int $qty, array $serialIds): array
    {
        return [
            'customer_id'   => $this->customer->id,
            'status'        => 'draft',
            'total_price'   => $qty * (float) $product->retail_price,
            'discount'      => 0,
            'other_fees'    => 0,
            'total_payment' => $qty * (float) $product->retail_price,
            'amount_paid'   => 0,
            'items'         => [[
                'product_id' => $product->id,
                'qty'        => $qty,
                'price'      => (float) $product->retail_price,
                'discount'   => 0,
                'serial_ids' => $serialIds,
            ]],
        ];
    }

    private function callStore(array $payload)
    {
        $request = Request::create('/orders', 'POST', $payload);
        $this->actingAs($this->admin);
        return app(OrderController::class)->store($request);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-22.2G-01: store hàng has_serial, serial_ids = [] → FAIL, không tạo order
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_order_store_serial_product_without_serial_ids_should_fail(): void
    {
        $product = $this->makeSerialProduct();
        $this->makeSerial($product);

        $ordersBefore = Order::count();

        $response = $this->callStore($this->storePayload($product, 1, []));

        // Controller trả về RedirectResponse ->withErrors khi fail.
        $this->assertSame($ordersBefore, Order::count(),
            'Không được tạo Order khi hàng has_serial chưa chọn serial_ids.');

        // Kiểm tra session errors (Laravel redirect with errors).
        $errors = session('errors');
        $this->assertNotNull($errors, 'Phải có session errors khi thiếu serial_ids.');
        $this->assertTrue($errors->has('items'), 'Errors phải có key `items`.');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-22.2G-02: store hàng has_serial qty=2 chọn 1 serial → FAIL
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_order_store_serial_product_with_partial_serial_ids_should_fail(): void
    {
        $product = $this->makeSerialProduct();
        $s1 = $this->makeSerial($product);
        $this->makeSerial($product);

        $ordersBefore = Order::count();
        $this->callStore($this->storePayload($product, 2, [$s1->id]));

        $this->assertSame($ordersBefore, Order::count(),
            'Không được tạo Order khi serial_ids ít hơn qty.');
        $this->assertTrue(session('errors')->has('items'));
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-22.2G-03: store hàng has_serial qty=1, serial_ids=[id] hợp lệ → SUCCESS
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_order_store_serial_product_with_full_serial_ids_should_succeed(): void
    {
        $product = $this->makeSerialProduct();
        $s1 = $this->makeSerial($product);

        $ordersBefore = Order::count();
        $this->callStore($this->storePayload($product, 1, [$s1->id]));

        $this->assertSame($ordersBefore + 1, Order::count(),
            'Order phải được tạo khi serial_ids đủ.');

        $order = Order::latest('id')->first();
        $item = $order->items()->first();
        $this->assertNotNull($item);
        $this->assertSame([$s1->id], (array) $item->serial_ids);

        // Serial chưa bị mark sold (processOrder mới làm việc đó).
        $s1->refresh();
        $this->assertSame('in_stock', $s1->status);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-22.2G-04: update Order draft, set serial_ids = [] cho hàng serial → FAIL
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_order_update_serial_product_without_serial_ids_should_fail(): void
    {
        $product = $this->makeSerialProduct();
        $s1 = $this->makeSerial($product);

        // Tạo order ban đầu hợp lệ.
        $this->callStore($this->storePayload($product, 1, [$s1->id]));
        $order = Order::latest('id')->first();
        $this->assertNotNull($order);

        // Update bỏ serial_ids.
        $payload = [
            'items' => [[
                'product_id' => $product->id,
                'qty'        => 1,
                'price'      => (float) $product->retail_price,
                'discount'   => 0,
                'serial_ids' => [],
            ]],
        ];
        $request = Request::create('/orders/' . $order->id, 'PUT', $payload);
        $this->actingAs($this->admin);
        app(OrderController::class)->update($request, $order);

        // Item cũ phải còn nguyên serial_ids (controller return back với errors trước khi delete+create).
        $order->refresh();
        $items = $order->items()->get();
        $this->assertCount(1, $items, 'Items phải giữ nguyên 1 row khi update fail.');
        $this->assertSame([$s1->id], (array) $items->first()->serial_ids,
            'serial_ids cũ phải được giữ khi update fail (controller return back trước khi xoá items).');

        $errors = session('errors');
        $this->assertNotNull($errors);
        $this->assertTrue($errors->has('items'));
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-22.2G-05: hàng thường (has_serial=false), serial_ids=[] → SUCCESS
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_order_store_normal_product_without_serial_ids_should_succeed(): void
    {
        $category = Category::firstOrCreate(['name' => 'Cat 22.2G']);
        $product = Product::create([
            'sku'                  => 'NORM-22G-' . uniqid(),
            'name'                 => 'Normal Product 22.2G',
            'cost_price'           => 100000,
            'retail_price'         => 200000,
            'stock_quantity'       => 10,
            'inventory_total_cost' => 1000000,
            'is_active'            => true,
            'has_serial'           => false,
            'category_id'          => $category->id,
        ]);

        $ordersBefore = Order::count();
        $this->callStore($this->storePayload($product, 2, []));

        $this->assertSame($ordersBefore + 1, Order::count(),
            'Hàng thường phải tạo Order được dù không có serial_ids.');

        $order = Order::latest('id')->first();
        $item = $order->items()->first();
        $this->assertTrue(empty($item->serial_ids),
            'Hàng thường: serial_ids phải null/empty.');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-22.2G-06 (bổ sung): processOrder hàng thường (has_serial=false)
     *  không cần serial_ids → tạo Invoice OK, stock giảm đúng, không tạo
     *  InvoiceItemSerial.
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_order_process_normal_product_without_serial_ids_should_succeed(): void
    {
        $category = Category::firstOrCreate(['name' => 'Cat 22.2G']);
        $product = Product::create([
            'sku'                  => 'NORM-PROC-22G-' . uniqid(),
            'name'                 => 'Normal Process 22.2G',
            'cost_price'           => 100000,
            'retail_price'         => 200000,
            'stock_quantity'       => 10,
            'inventory_total_cost' => 1000000,
            'is_active'            => true,
            'has_serial'           => false,
            'category_id'          => $category->id,
        ]);

        // Tạo Order qua store (đảm bảo flow giống production).
        $this->callStore($this->storePayload($product, 3, []));
        $order = Order::latest('id')->first();
        $this->assertNotNull($order);
        $orderItem = $order->items()->first();
        $this->assertTrue(empty($orderItem->serial_ids),
            'Hàng thường: serial_ids phải null khi tạo Order.');

        $stockBefore = (int) $product->stock_quantity;

        // Process Order.
        $request = Request::create('/orders/' . $order->id . '/process', 'POST', [
            'amount_paid'    => 600000,
            'payment_method' => 'cash',
        ]);
        $this->actingAs($this->admin);
        app(\App\Http\Controllers\OrderController::class)->processOrder($request, $order);

        // Invoice phải được tạo.
        $invoice = \App\Models\Invoice::where('order_id', $order->id)->latest('id')->first();
        $this->assertNotNull($invoice, 'Invoice phải được tạo khi processOrder hàng thường.');

        // Stock giảm đúng theo qty.
        $product->refresh();
        $this->assertSame($stockBefore - 3, (int) $product->stock_quantity,
            'Stock phải giảm đúng theo qty đã đặt (hàng thường).');

        // KHÔNG tạo InvoiceItemSerial cho hàng thường.
        $serialRows = \App\Models\InvoiceItemSerial::whereIn('invoice_item_id',
            $invoice->items()->pluck('id'))->count();
        $this->assertSame(0, $serialRows,
            'Không được tạo InvoiceItemSerial cho hàng has_serial=false.');
    }
}
