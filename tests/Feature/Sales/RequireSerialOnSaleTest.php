<?php

namespace Tests\Feature\Sales;

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
 * Step 23.1: Enforce serial selection trong InvoiceSaleService cho cả Invoice + POS.
 *
 * Yêu cầu:
 * - has_serial=true: count(serial_ids) === quantity bắt buộc, mọi serial in_stock thuộc product.
 * - has_serial=false: không bắt buộc.
 * - Validation chạy TRƯỚC khi tạo Invoice → DB không có Invoice rỗng / orphan serial.
 */
class RequireSerialOnSaleTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name'     => 'Admin 23.1',
            'email'    => 'admin-231-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
        $this->customer = Customer::create([
            'code'        => 'KH-231-' . uniqid(),
            'name'        => 'KH 23.1',
            'phone'       => '090' . rand(1000000, 9999999),
            'email'       => 'kh-231-' . uniqid() . '@test.local',
            'debt_amount' => 0,
            'total_spent' => 0,
        ]);
    }

    private function makeProduct(bool $hasSerial, int $stock, float $cost = 100000): Product
    {
        $category = Category::firstOrCreate(['name' => 'Cat 23.1']);
        return Product::create([
            'sku'                  => 'P231-' . uniqid(),
            'name'                 => 'Product 23.1',
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
            'serial_number' => 'SN231-' . uniqid(),
            'status'        => $status,
            'cost_price'    => $product->cost_price,
            'original_cost' => $product->cost_price,
        ]);
    }

    /** TC-23.1-01: Invoice serial KHÔNG kèm serial_ids → bị chặn, không tạo Invoice. */
    public function test_invoice_store_serial_product_without_serial_ids_should_fail(): void
    {
        $product = $this->makeProduct(true, 1, 5000000);
        $this->makeSerial($product);
        $invoiceCountBefore = Invoice::count();
        $stockBefore = (int) $product->stock_quantity;

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
                // serial_ids bỏ trống cố ý
            ]],
        ]);

        $this->assertSame($invoiceCountBefore, Invoice::count(),
            'Không được tạo Invoice khi serial bắt buộc nhưng không chọn.');
        $product->refresh();
        $this->assertSame($stockBefore, (int) $product->stock_quantity,
            'Stock phải giữ nguyên khi flow bị chặn.');
    }

    /** TC-23.1-02: Invoice serial chọn THIẾU (qty=2 chỉ chọn 1) → bị chặn. */
    public function test_invoice_store_serial_product_with_partial_serial_ids_should_fail(): void
    {
        $product = $this->makeProduct(true, 2, 5000000);
        $product->update(['stock_quantity' => 2, 'inventory_total_cost' => 10000000]);
        $serialA = $this->makeSerial($product);
        $this->makeSerial($product); // còn serial in_stock thứ 2 nhưng user không chọn
        $invoiceCountBefore = Invoice::count();

        $this->actingAs($this->admin)->post(route('invoices.store'), [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 16000000,
            'total'          => 16000000,
            'customer_paid'  => 16000000,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => 2,
                'price'      => 8000000,
                'serial_ids' => [$serialA->id], // chỉ chọn 1/2
            ]],
        ]);

        $this->assertSame($invoiceCountBefore, Invoice::count(),
            'Không được tạo Invoice khi chọn thiếu serial.');
        $serialA->refresh();
        $this->assertSame('in_stock', $serialA->status,
            'Serial chưa được mark sold khi flow bị chặn.');
    }

    /** TC-23.1-03: POS checkout serial KHÔNG kèm serial_ids → 500 + không tạo Invoice. */
    public function test_pos_checkout_serial_product_without_serial_ids_should_fail(): void
    {
        $product = $this->makeProduct(true, 1, 5000000);
        $this->makeSerial($product);
        $invoiceCountBefore = Invoice::count();

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
                // serial_ids bỏ trống
            ]],
        ]);

        $this->assertSame(500, $response->status(),
            'POS phải trả lỗi khi serial bắt buộc bị thiếu.');
        $this->assertFalse($response->json('success'));
        $this->assertSame($invoiceCountBefore, Invoice::count(),
            'POS không được tạo Invoice khi serial thiếu.');
    }

    /** TC-23.1-04: POS sản phẩm thường (has_serial=false) không cần serial_ids → OK. */
    public function test_pos_checkout_normal_product_without_serial_ids_should_succeed(): void
    {
        $product = $this->makeProduct(false, 10, 100000);
        $stockBefore = (int) $product->stock_quantity;

        $response = $this->actingAs($this->admin)->postJson('/api/pos/checkout', [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 600000,
            'discount'       => 0,
            'total'          => 600000,
            'customer_paid'  => 600000,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => 3,
                'price'      => 200000,
            ]],
        ]);

        $response->assertStatus(200);
        $product->refresh();
        $this->assertSame($stockBefore - 3, (int) $product->stock_quantity);
        // Hàng thường: không tạo InvoiceItemSerial nào dính tới product này.
        $this->assertSame(0, InvoiceItemSerial::query()
            ->whereIn('serial_imei_id', SerialImei::where('product_id', $product->id)->pluck('id'))
            ->count());
    }

    /** TC-23.1-05: Invoice/POS hàng serial chọn ĐỦ → tạo Invoice OK, không hồi quy. */
    public function test_pos_checkout_serial_product_with_full_serial_ids_should_succeed(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $serialA = $this->makeSerial($product);
        $serialB = $this->makeSerial($product);
        $product->update(['stock_quantity' => 2, 'inventory_total_cost' => 10000000]);
        $movementsBefore = StockMovement::where('product_id', $product->id)->count();

        $response = $this->actingAs($this->admin)->postJson('/api/pos/checkout', [
            'customer_id'    => $this->customer->id,
            'subtotal'       => 16000000,
            'discount'       => 0,
            'total'          => 16000000,
            'customer_paid'  => 16000000,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => 2,
                'price'      => 8000000,
                'serial_ids' => [$serialA->id, $serialB->id],
            ]],
        ]);

        $response->assertStatus(200);
        $serialA->refresh();
        $serialB->refresh();
        $this->assertSame('sold', $serialA->status);
        $this->assertSame('sold', $serialB->status);
        $product->refresh();
        $this->assertSame(0, (int) $product->stock_quantity);
        $this->assertGreaterThan($movementsBefore,
            StockMovement::where('product_id', $product->id)->count());
    }
}
