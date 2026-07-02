<?php

namespace Tests\Feature\OrderReturn;

use App\Http\Controllers\OrderReturnController;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\SerialImei;
use App\Models\User;
use App\Services\MovingAvgCostingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * RR-08: Hủy phiếu trả hàng KH rollback Serial/IMEI không chính xác.
 *
 * Bug ở OrderReturnController@cancel dòng 397-407:
 *   SerialImei::where('product_id', $item->product_id)
 *       ->where('status', 'in_stock')
 *       ->whereNull('invoice_id')
 *       ->limit($item->quantity)
 *       ->update([
 *           'status' => 'sold',
 *           'sold_at' => now(),
 *           'invoice_id' => $return->invoice_id,
 *       ]);
 *
 * Vấn đề: query lấy bất kỳ serial in_stock nào của product, không phân biệt
 * serial đã được trả hay serial khác chưa từng thuộc invoice. ReturnItem
 * không lưu serial_ids → không có cách biết serial nào cần rollback.
 *
 * Lưu ý: route returns.cancel chưa đăng ký (P1 backlog từ RR-11).
 * Test gọi controller method trực tiếp.
 */
class RR08OrderReturnSerialRollbackTest extends TestCase
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
            'name'     => 'Admin RR08',
            'email'    => 'admin-rr08-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);

        $category = Category::firstOrCreate(['name' => 'Cat RR08']);

        // Sản phẩm has_serial: ban đầu trong kho 0 (sẽ được nhập serial sau)
        $this->product = Product::create([
            'sku'                  => 'PROD-RR08-' . uniqid(),
            'name'                 => 'Product RR08 Serial',
            'cost_price'           => 5000000,
            'retail_price'         => 8000000,
            'stock_quantity'       => 0,
            'inventory_total_cost' => 0,
            'is_active'            => true,
            'has_serial'           => true,
            'category_id'          => $category->id,
        ]);

        $this->customer = Customer::create([
            'code'        => 'KH-RR08-' . uniqid(),
            'name'        => 'KH RR08 ' . uniqid(),
            'phone'       => '090' . rand(1000000, 9999999),
            'email'       => 'kh-rr08-' . uniqid() . '@test.local',
            'debt_amount' => 0,
            'total_spent' => 0,
        ]);
    }

    /**
     * Setup: Tạo Serial B (in_stock, không thuộc invoice) trước,
     * rồi Serial A (sold, thuộc invoice). Giả lập trả Serial A.
     *
     * Trả về [serialA, serialB, return].
     */
    private function setupReturnedScenario(): array
    {
        // 1) Tạo Serial B trước — id sẽ nhỏ hơn — in_stock, chưa từng thuộc invoice
        $serialB = SerialImei::create([
            'product_id'    => $this->product->id,
            'serial_number' => 'SN-B-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 5000000,
            'original_cost' => 5000000,
        ]);
        MovingAvgCostingService::applyPurchase($this->product, 1, 5000000);

        // 2) Tạo Serial A — id lớn hơn B
        $serialA = SerialImei::create([
            'product_id'    => $this->product->id,
            'serial_number' => 'SN-A-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 5000000,
            'original_cost' => 5000000,
        ]);
        MovingAvgCostingService::applyPurchase($this->product, 1, 5000000);

        // 3) Tạo invoice + invoice_item bán Serial A
        $invoice = Invoice::create([
            'code'             => 'HD-RR08-' . uniqid(),
            'customer_id'      => $this->customer->id,
            'total_amount'     => 8000000,
            'paid_amount'      => 8000000,
            'debt_amount'      => 0,
            'status'           => 'Hoàn thành',
            'payment_method'   => 'cash',
            'created_by_name'  => 'Admin',
        ]);

        $invoiceItem = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $this->product->id,
            'quantity'   => 1,
            'price'      => 8000000,
            'cost_price' => 5000000,
            'subtotal'   => 8000000,
        ]);

        InvoiceItemSerial::create([
            'invoice_item_id' => $invoiceItem->id,
            'serial_imei_id'  => $serialA->id,
            'serial_number'   => $serialA->serial_number,
            'cost_price'      => 5000000,
        ]);

        // Đánh dấu Serial A đã bán
        $serialA->update([
            'status'          => 'sold',
            'sold_at'         => now(),
            'invoice_id'      => $invoice->id,
            'sold_cost_price' => 5000000,
        ]);
        MovingAvgCostingService::applySale($this->product, 1);
        $this->product->refresh();
        $this->product->recomputeFromSerials();

        $this->invoice = $invoice;

        // 4) Tạo phiếu trả qua route store (Serial A được trả)
        $this->actingAs($this->admin)->post(route('returns.store'), [
            'invoice_id'       => $invoice->id,
            'customer_id'      => $this->customer->id,
            'subtotal'         => 8000000,
            'total'            => 8000000,
            'paid_to_customer' => 8000000,
            'items'            => [[
                'product_id'      => $this->product->id,
                'qty'             => 1,
                'price'           => 8000000,
                'invoice_item_id' => $invoiceItem->id,
                'serial_ids'      => [$serialA->id],
            ]],
        ]);

        $return = OrderReturn::where('invoice_id', $invoice->id)
            ->where('status', '!=', 'Đã hủy')
            ->first();

        return [$serialA->fresh(), $serialB->fresh(), $return];
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR08-01: Hủy phiếu trả hàng phải rollback ĐÚNG serial đã trả
     *  Sau cancel:
     *    - Serial A: invoice_id = invoice.id, status = 'sold'
     *    - Serial B: invoice_id = null, status = 'in_stock' (KHÔNG bị động)
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_cancel_order_return_should_restore_exact_returned_serial(): void
    {
        [$serialA, $serialB, $return] = $this->setupReturnedScenario();

        // Sanity: trước cancel cả A và B đều in_stock, invoice_id null
        $this->assertSame('in_stock', $serialA->status);
        $this->assertNull($serialA->invoice_id);
        $this->assertSame('in_stock', $serialB->status);
        $this->assertNull($serialB->invoice_id);

        // Action: cancel — gọi controller trực tiếp (route chưa đăng ký)
        $this->actingAs($this->admin);
        app(OrderReturnController::class)->cancel($return);

        $serialA->refresh();
        $serialB->refresh();

        $this->assertSame($this->invoice->id, $serialA->invoice_id,
            'Serial A (đã trả) phải được rollback về invoice gốc');
        $this->assertSame('sold', $serialA->status,
            'Serial A status phải = sold sau rollback');
        $this->assertNull($serialB->invoice_id,
            'Serial B (chưa từng thuộc invoice) KHÔNG được bị gán invoice_id');
        $this->assertSame('in_stock', $serialB->status,
            'Serial B status phải giữ in_stock');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR08-02: Cancel chỉ được gán đúng 1 serial (Serial A) vào invoice
     *  Không gán nhầm sang serial khác.
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_cancel_order_return_should_not_pick_another_available_serial(): void
    {
        [$serialA, $serialB, $return] = $this->setupReturnedScenario();

        $this->actingAs($this->admin);
        app(OrderReturnController::class)->cancel($return);

        // Số serial có invoice_id = invoice.id sau cancel = 1 (chỉ Serial A)
        $countSold = SerialImei::where('product_id', $this->product->id)
            ->where('invoice_id', $this->invoice->id)
            ->count();
        $this->assertSame(1, $countSold,
            'Đúng 1 serial (Serial A) được rollback về invoice.id, không phải Serial B');

        // Cụ thể serial bị gán invoice_id = invoice.id phải là Serial A
        $linkedSerial = SerialImei::where('product_id', $this->product->id)
            ->where('invoice_id', $this->invoice->id)
            ->first();
        $this->assertNotNull($linkedSerial);
        $this->assertSame($serialA->id, $linkedSerial->id,
            'Serial được gán phải là Serial A (đã trả), không phải Serial B');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR08-03: Hủy lặp idempotent
     *  Cancel lần 2: trạng thái serial không thay đổi thêm.
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_cancel_order_return_should_be_idempotent_for_serials(): void
    {
        [$serialA, $serialB, $return] = $this->setupReturnedScenario();

        $this->actingAs($this->admin);
        app(OrderReturnController::class)->cancel($return);

        $aSnapshot = SerialImei::find($serialA->id)->only(['status', 'invoice_id', 'sold_cost_price']);
        $bSnapshot = SerialImei::find($serialB->id)->only(['status', 'invoice_id', 'sold_cost_price']);

        // Cancel lần 2
        app(OrderReturnController::class)->cancel($return->fresh());

        $aAfter = SerialImei::find($serialA->id)->only(['status', 'invoice_id', 'sold_cost_price']);
        $bAfter = SerialImei::find($serialB->id)->only(['status', 'invoice_id', 'sold_cost_price']);

        $this->assertSame($aSnapshot, $aAfter, 'Cancel lần 2 không đổi Serial A');
        $this->assertSame($bSnapshot, $bAfter, 'Cancel lần 2 không đổi Serial B');
        $this->assertSame('Đã hủy', $return->fresh()->status);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR08-04: Schema return_items phải lưu được serial reference đã trả
     *  (cột serial_ids JSON, hoặc bảng return_item_serials, hoặc serial.return_id).
     *  Hiện tại không có → test FAIL chứng minh limitation schema.
     * ═══════════════════════════════════════════════════════════════════════ */
    public function test_return_items_schema_should_persist_returned_serial_reference(): void
    {
        $hasSerialIdsColumn = Schema::hasColumn('return_items', 'serial_ids');
        $hasReturnItemSerialsTable = Schema::hasTable('return_item_serials');
        $hasSerialReturnId = Schema::hasColumn('serial_imeis', 'return_id')
            || Schema::hasColumn('serial_imeis', 'order_return_id');

        $this->assertTrue(
            $hasSerialIdsColumn || $hasReturnItemSerialsTable || $hasSerialReturnId,
            'Schema cần lưu serial reference đã trả: '
            . 'return_items.serial_ids, hoặc bảng return_item_serials, hoặc serial_imeis.return_id. '
            . 'Hiện tại không có cột/bảng nào → cancel không thể rollback đúng serial.'
        );
    }
}
