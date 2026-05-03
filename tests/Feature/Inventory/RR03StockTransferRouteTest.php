<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * RR-03 Route Integration Tests.
 *
 * Kiểm tra route receive/cancel của StockTransfer có tồn tại
 * và gọi đúng nghiệp vụ khi gọi qua HTTP.
 *
 * Phát hiện từ STEP-6.1B: methods receive() và cancel() tồn tại
 * trong StockTransferController nhưng KHÔNG có route đăng ký.
 */
class RR03StockTransferRouteTest extends TestCase
{
    use DatabaseTransactions;

    private Product $product;
    private Branch  $branchA;
    private Branch  $branchB;
    private User    $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin RR03 Route',
            'email'    => 'admin-rr03-route-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);

        $this->branchA = Branch::firstOrCreate(
            ['name' => 'Kho A Route Test'],
            ['phone' => '0900000011']
        );

        $this->branchB = Branch::firstOrCreate(
            ['name' => 'Kho B Route Test'],
            ['phone' => '0900000012']
        );

        $category = Category::firstOrCreate(['name' => 'Cat RR03 Route']);

        $this->product = Product::create([
            'sku'                  => 'SP-RR03-RT-' . uniqid(),
            'name'                 => 'Product RR03 Route Test',
            'cost_price'           => 100000,
            'retail_price'         => 200000,
            'stock_quantity'       => 10,
            'inventory_total_cost' => 10 * 100000,
            'is_active'            => true,
            'has_serial'           => false,
            'category_id'          => $category->id,
        ]);
    }

    /**
     * Helper: tạo phiếu chuyển kho status = transferring (đã xuất kho).
     */
    private function createTransferringTransfer(int $qty = 3): StockTransfer
    {
        $response = $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'note'           => 'Route test ' . uniqid(),
            'items'          => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => $qty,
                    'price'      => $qty * $this->product->cost_price,
                ],
            ],
        ]);

        return StockTransfer::latest('id')->first();
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TEST 1: Route nhận hàng chuyển kho phải tồn tại
     *
     *  Kỳ vọng: POST /stock-transfers/{id}/receive trả về response != 404
     *  Nếu route chưa đăng ký → 404 → FAIL
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_stock_transfer_receive_route_should_exist(): void
    {
        $transfer = $this->createTransferringTransfer(3);
        $this->product->refresh();
        $stockAfterTransferOut = $this->product->stock_quantity; // 7

        // Gọi route nhận hàng
        $response = $this->actingAs($this->admin)
            ->postJson("/stock-transfers/{$transfer->id}/receive");

        // Route phải tồn tại (không phải 404 hoặc 405)
        $this->assertNotEquals(
            404,
            $response->status(),
            "Route POST /stock-transfers/{id}/receive KHÔNG tồn tại (404). "
            . "Method receive() có trong controller nhưng chưa đăng ký route."
        );

        $this->assertNotEquals(
            405,
            $response->status(),
            "Route /stock-transfers/{id}/receive trả 405 Method Not Allowed. "
            . "Route tồn tại nhưng sai HTTP method."
        );

        // Nếu route tồn tại và hoạt động đúng:
        // - Transfer chuyển sang status = received
        $transfer->refresh();
        $this->assertEquals(
            'received',
            $transfer->status,
            "Sau khi gọi receive, transfer phải chuyển status = 'received'. "
            . "Thực tế: '{$transfer->status}'"
        );

        // - stock_quantity phải cộng nhận đúng (7 + 3 = 10)
        $this->product->refresh();
        $this->assertEquals(
            $stockAfterTransferOut + 3,
            $this->product->stock_quantity,
            "Sau receive, stock phải cộng đúng. "
            . "Kỳ vọng: " . ($stockAfterTransferOut + 3)
            . ", thực tế: {$this->product->stock_quantity}"
        );

        // - Phải có StockMovement transfer_in
        $movementIn = StockMovement::where('product_id', $this->product->id)
            ->where('type', 'transfer_in')
            ->where('ref_id', $transfer->id)
            ->first();

        $this->assertNotNull(
            $movementIn,
            "Sau receive qua route, phải có StockMovement type='transfer_in'."
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TEST 2: Route hủy phiếu chuyển kho phải tồn tại
     *
     *  Kỳ vọng: POST /stock-transfers/{id}/cancel trả về response != 404
     *  Nếu route chưa đăng ký → 404 → FAIL
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_stock_transfer_cancel_route_should_exist(): void
    {
        $transfer = $this->createTransferringTransfer(3);
        $stockBefore = 10; // ban đầu

        // Gọi route hủy
        $response = $this->actingAs($this->admin)
            ->postJson("/stock-transfers/{$transfer->id}/cancel");

        // Route phải tồn tại
        $this->assertNotEquals(
            404,
            $response->status(),
            "Route POST /stock-transfers/{id}/cancel KHÔNG tồn tại (404). "
            . "Method cancel() có trong controller nhưng chưa đăng ký route."
        );

        $this->assertNotEquals(
            405,
            $response->status(),
            "Route /stock-transfers/{id}/cancel trả 405 Method Not Allowed."
        );

        // Nếu route tồn tại:
        // - Transfer chuyển sang status = cancelled
        $transfer->refresh();
        $this->assertEquals(
            'cancelled',
            $transfer->status,
            "Sau cancel, transfer phải chuyển status = 'cancelled'. "
            . "Thực tế: '{$transfer->status}'"
        );

        // - stock_quantity phải hoàn về ban đầu
        $this->product->refresh();
        $this->assertEquals(
            $stockBefore,
            $this->product->stock_quantity,
            "Sau cancel, stock phải hoàn về {$stockBefore}. "
            . "Thực tế: {$this->product->stock_quantity}"
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TEST 3: Route hủy phải idempotent — không đảo lặp
     *
     *  Gọi cancel 2 lần → tồn không thay đổi thêm
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_stock_transfer_cancel_route_should_be_idempotent(): void
    {
        $transfer = $this->createTransferringTransfer(3);
        $stockBefore = 10;

        // Cancel lần 1
        $this->actingAs($this->admin)
            ->postJson("/stock-transfers/{$transfer->id}/cancel");

        $this->product->refresh();
        $stockAfterCancel1 = $this->product->stock_quantity;

        // Cancel lần 2
        $this->actingAs($this->admin)
            ->postJson("/stock-transfers/{$transfer->id}/cancel");

        $this->product->refresh();

        $this->assertEquals(
            $stockAfterCancel1,
            $this->product->stock_quantity,
            "Hủy lần 2 qua route không được thay đổi tồn thêm. "
            . "Sau lần 1: {$stockAfterCancel1}, sau lần 2: {$this->product->stock_quantity}"
        );
    }
}
