<?php

namespace Tests\Feature\OrderReturn;

use App\Models\Category;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * RR-11: Trả hàng khách không validate trùng số lượng đã trả.
 *
 * Vấn đề: OrderReturnController@store (dòng 96-117) chỉ validate:
 *   - items.*.qty >= 1
 *   - items.*.product_id exists
 * KHÔNG validate:
 *   - Tổng qty trả <= invoice_item.quantity
 *   - Tổng qty trả trước đó + qty trả mới <= invoice_item.quantity
 *   - Invoice status != 'Đã hủy'
 *
 * Kết quả:
 *   - Có thể tạo nhiều phiếu trả cùng invoice, mỗi phiếu trả đầy đủ qty
 *   - Tồn kho tăng gấp đôi, công nợ KH bị âm quá mức
 */
class RR11OrderReturnQtyTest extends TestCase
{
    use DatabaseTransactions;

    private User     $admin;
    private Product  $product;
    private Customer $customer;
    private Invoice  $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin RR11',
            'email'    => 'admin-rr11-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);

        $category = Category::firstOrCreate(['name' => 'Cat RR11']);

        $this->product = Product::create([
            'sku'                  => 'PROD-RR11-' . uniqid(),
            'name'                 => 'Product RR11',
            'cost_price'           => 100000,
            'retail_price'         => 200000,
            'stock_quantity'       => 10,
            'inventory_total_cost' => 10 * 100000,
            'is_active'            => true,
            'has_serial'           => false,
            'category_id'          => $category->id,
        ]);

        $this->customer = Customer::create([
            'code'         => 'KH-RR11-' . uniqid(),
            'name'         => 'KH RR11 ' . uniqid(),
            'phone'        => '090' . rand(1000000, 9999999),
            'email'        => 'kh-rr11-' . uniqid() . '@test.local',
            'debt_amount'  => 0,
            'total_spent'  => 0,
        ]);

        // Tạo Invoice bán 5 sản phẩm
        $this->invoice = Invoice::create([
            'code'             => 'HD-RR11-' . uniqid(),
            'customer_id'      => $this->customer->id,
            'total_amount'     => 5 * 200000,
            'paid_amount'      => 5 * 200000,
            'debt_amount'      => 0,
            'status'           => 'Hoàn thành',
            'payment_method'   => 'cash',
            'created_by_name'  => 'Admin',
        ]);

        InvoiceItem::create([
            'invoice_id'  => $this->invoice->id,
            'product_id'  => $this->product->id,
            'quantity'    => 5,
            'price'       => 200000,
            'cost_price'  => 100000,
            'subtotal'    => 5 * 200000,
        ]);

        // Simulate stock after sale: stock was 10, sold 5 → 5 left
        $this->product->update([
            'stock_quantity'       => 5,
            'inventory_total_cost' => 5 * 100000,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR11-01: Không cho trả quá số lượng bán trong một lần
     *
     *  Invoice bán qty=5, trả qty=8 → phải bị reject
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_cannot_return_more_than_invoiced_quantity(): void
    {
        $stockBefore = $this->product->stock_quantity;

        $response = $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id'      => $this->invoice->id,
            'customer_id'     => $this->customer->id,
            'subtotal'        => 8 * 200000,
            'total'           => 8 * 200000,
            'paid_to_customer' => 8 * 200000,
            'items'           => [
                [
                    'product_id' => $this->product->id,
                    'qty'        => 8,
                    'price'      => 200000,
                ],
            ],
        ]);

        // Phải bị reject — không cho trả 8 khi chỉ bán 5
        $returnCreated = OrderReturn::where('invoice_id', $this->invoice->id)->exists();

        $this->product->refresh();

        $this->assertFalse(
            $returnCreated && $this->product->stock_quantity > $stockBefore + 5,
            "Không được cho trả 8 sản phẩm khi hóa đơn chỉ bán 5. "
            . "stock trước={$stockBefore}, stock sau={$this->product->stock_quantity}. "
            . "OrderReturnController KHÔNG validate qty trả vs qty bán."
        );

        // Nếu system cho trả quá → đây là lỗi
        if ($returnCreated) {
            $returnQty = ReturnItem::whereHas('orderReturn', function ($q) {
                $q->where('invoice_id', $this->invoice->id)->where('status', '!=', 'Đã hủy');
            })->where('product_id', $this->product->id)->sum('quantity');

            $this->assertLessThanOrEqual(
                5,
                $returnQty,
                "Tổng qty trả ({$returnQty}) vượt qty bán (5). "
                . "OrderReturnController thiếu validation so sánh qty trả vs invoice_item.quantity."
            );
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR11-02: Không cho trả vượt phần còn lại
     *
     *  Invoice bán qty=5, lần 1 trả 3, lần 2 trả 3 → phải reject lần 2
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_cannot_return_exceeding_remaining_quantity(): void
    {
        // Lần 1: trả 3 → hợp lệ
        $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id'      => $this->invoice->id,
            'customer_id'     => $this->customer->id,
            'subtotal'        => 3 * 200000,
            'total'           => 3 * 200000,
            'paid_to_customer' => 3 * 200000,
            'items'           => [
                [
                    'product_id' => $this->product->id,
                    'qty'        => 3,
                    'price'      => 200000,
                ],
            ],
        ]);

        $return1 = OrderReturn::where('invoice_id', $this->invoice->id)
            ->where('status', '!=', 'Đã hủy')
            ->first();
        $this->assertNotNull($return1, 'Lần trả 1 (qty=3) phải thành công');

        // Lần 2: trả 3 → phải FAIL vì chỉ còn 2
        $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id'      => $this->invoice->id,
            'customer_id'     => $this->customer->id,
            'subtotal'        => 3 * 200000,
            'total'           => 3 * 200000,
            'paid_to_customer' => 3 * 200000,
            'items'           => [
                [
                    'product_id' => $this->product->id,
                    'qty'        => 3,
                    'price'      => 200000,
                ],
            ],
        ]);

        // Kiểm tra tổng qty trả không vượt 5
        $totalReturned = ReturnItem::whereHas('orderReturn', function ($q) {
            $q->where('invoice_id', $this->invoice->id)->where('status', '!=', 'Đã hủy');
        })->where('product_id', $this->product->id)->sum('quantity');

        $this->assertLessThanOrEqual(
            5,
            $totalReturned,
            "Tổng qty trả ({$totalReturned}) vượt qty bán (5). "
            . "Lần 1 trả 3, lần 2 trả 3 → tổng 6 > 5. "
            . "OrderReturnController không kiểm tra qty đã trả trước đó."
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR11-03: Cho trả đúng phần còn lại
     *
     *  Invoice bán qty=5, trả 3, trả tiếp 2 → phải thành công
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_can_return_exact_remaining_quantity(): void
    {
        // Lần 1: trả 3
        $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id'      => $this->invoice->id,
            'customer_id'     => $this->customer->id,
            'subtotal'        => 3 * 200000,
            'total'           => 3 * 200000,
            'paid_to_customer' => 3 * 200000,
            'items'           => [
                [
                    'product_id' => $this->product->id,
                    'qty'        => 3,
                    'price'      => 200000,
                ],
            ],
        ]);

        $this->product->refresh();
        $stockAfterReturn1 = $this->product->stock_quantity;

        // Lần 2: trả 2 (đúng còn lại)
        $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id'      => $this->invoice->id,
            'customer_id'     => $this->customer->id,
            'subtotal'        => 2 * 200000,
            'total'           => 2 * 200000,
            'paid_to_customer' => 2 * 200000,
            'items'           => [
                [
                    'product_id' => $this->product->id,
                    'qty'        => 2,
                    'price'      => 200000,
                ],
            ],
        ]);

        $totalReturned = ReturnItem::whereHas('orderReturn', function ($q) {
            $q->where('invoice_id', $this->invoice->id)->where('status', '!=', 'Đã hủy');
        })->where('product_id', $this->product->id)->sum('quantity');

        // Tổng trả phải = 5 (3+2)
        $this->assertEquals(
            5,
            $totalReturned,
            "Trả 3 + 2 = 5 phải hợp lệ (= qty bán). Thực tế: {$totalReturned}"
        );

        // Stock phải cộng lại 5 → về 10
        $this->product->refresh();
        $this->assertEquals(
            10,
            $this->product->stock_quantity,
            "Stock phải về 10 (5 ban đầu + 5 trả). Thực tế: {$this->product->stock_quantity}"
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR11-04: Không cho trả hàng trên invoice đã hủy
     *
     *  Invoice status = 'Đã hủy' → phải reject phiếu trả
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_cannot_return_on_cancelled_invoice(): void
    {
        // Hủy invoice
        $this->invoice->update(['status' => 'Đã hủy']);

        $stockBefore = $this->product->stock_quantity;

        $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id'      => $this->invoice->id,
            'customer_id'     => $this->customer->id,
            'subtotal'        => 2 * 200000,
            'total'           => 2 * 200000,
            'paid_to_customer' => 2 * 200000,
            'items'           => [
                [
                    'product_id' => $this->product->id,
                    'qty'        => 2,
                    'price'      => 200000,
                ],
            ],
        ]);

        // Không được tạo phiếu trả trên hóa đơn đã hủy
        $returnCreated = OrderReturn::where('invoice_id', $this->invoice->id)
            ->where('status', '!=', 'Đã hủy')
            ->exists();

        $this->product->refresh();

        $this->assertFalse(
            $returnCreated,
            "Không được tạo phiếu trả hàng khi invoice đã bị hủy. "
            . "OrderReturnController không kiểm tra invoice.status."
        );

        $this->assertEquals(
            $stockBefore,
            $this->product->stock_quantity,
            "Stock không được thay đổi khi trả hàng trên invoice đã hủy."
        );
    }
}
