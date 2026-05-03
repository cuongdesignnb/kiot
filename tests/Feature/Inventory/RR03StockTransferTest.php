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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RR-03: Chuyển kho không ghi StockMovement, không cập nhật giá vốn.
 *
 * Vấn đề: StockTransferController chỉ dùng increment/decrement trực tiếp
 * trên products.stock_quantity mà:
 *   - KHÔNG tạo StockMovement (thẻ kho thiếu dòng)
 *   - KHÔNG cập nhật inventory_total_cost (giá vốn BQ sai)
 *
 * Dữ liệu test:
 *   - Product A: cost_price = 100.000, stock = 10, inventory_total_cost = 1.000.000
 *   - Branch A (nguồn), Branch B (đích)
 *   - Chuyển 3 sản phẩm từ A → B
 *
 * Kỳ vọng:
 *   - Tạo StockMovement transfer_out (khi gửi)
 *   - Tạo StockMovement transfer_in (khi nhận)
 *   - inventory_total_cost giảm khi xuất
 *   - Tổng tồn hệ thống không đổi (nếu nhận đủ)
 */
class RR03StockTransferTest extends TestCase
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
            'name'     => 'Admin RR03',
            'email'    => 'admin-rr03-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);

        $this->branchA = Branch::firstOrCreate(
            ['name' => 'Kho A Test RR03'],
            ['phone' => '0900000001']
        );

        $this->branchB = Branch::firstOrCreate(
            ['name' => 'Kho B Test RR03'],
            ['phone' => '0900000002']
        );

        $category = Category::firstOrCreate(['name' => 'Cat RR03']);

        $this->product = Product::create([
            'sku'                  => 'SP-RR03-' . uniqid(),
            'name'                 => 'Product RR03 Transfer',
            'cost_price'           => 100000,
            'retail_price'         => 200000,
            'stock_quantity'       => 10,
            'inventory_total_cost' => 10 * 100000, // 1.000.000
            'is_active'            => true,
            'has_serial'           => false,
            'category_id'          => $category->id,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR03-01: Chuyển kho phải tạo StockMovement transfer_out
     *
     *  Khi tạo phiếu chuyển kho status = 'transferring':
     *  - stock_quantity giảm đúng
     *  - Phải có StockMovement type = 'transfer_out'
     *  
     *  Nếu fail: thẻ kho (stock card) thiếu dòng chuyển kho xuất
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_stock_transfer_should_create_transfer_out_movement(): void
    {
        $stockBefore = $this->product->stock_quantity;

        // Tạo phiếu chuyển kho qua route
        $response = $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'note'           => 'Test RR03-01',
            'items'          => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 3,
                    'price'      => 3 * $this->product->cost_price,
                ],
            ],
        ]);

        // Phiếu chuyển kho phải tạo thành công
        $transfer = StockTransfer::where('note', 'Test RR03-01')->first();
        $this->assertNotNull($transfer, 'Phiếu chuyển kho phải được tạo');

        // stock_quantity phải giảm 3
        $this->product->refresh();
        $this->assertEquals(
            $stockBefore - 3,
            $this->product->stock_quantity,
            "stock_quantity phải giảm từ {$stockBefore} xuống " . ($stockBefore - 3)
        );

        // Phải có StockMovement transfer_out
        $movement = StockMovement::where('product_id', $this->product->id)
            ->where('type', 'transfer_out')
            ->where('ref_type', 'App\\Models\\StockTransfer')
            ->where('ref_id', $transfer->id)
            ->first();

        $this->assertNotNull(
            $movement,
            "Phải có StockMovement type='transfer_out' khi tạo phiếu chuyển kho. "
            . "Hiện tại: KHÔNG có dòng nào trong stock_movements."
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR03-02: Nhận hàng chuyển kho phải tạo StockMovement transfer_in
     *
     *  Khi tạo phiếu chuyển kho status = 'received' (nhận ngay):
     *  - stock_quantity phải giữ nguyên tổng (trừ rồi cộng)
     *  - Phải có StockMovement type = 'transfer_in'
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_stock_transfer_received_should_create_transfer_in_movement(): void
    {
        $stockBefore = $this->product->stock_quantity;

        // Tạo phiếu chuyển kho status = 'received' (tạo + nhận ngay)
        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'received',
            'note'           => 'Test RR03-02',
            'items'          => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 3,
                    'price'      => 3 * $this->product->cost_price,
                ],
            ],
        ]);

        $transfer = StockTransfer::where('note', 'Test RR03-02')->first();
        $this->assertNotNull($transfer, 'Phiếu chuyển kho phải được tạo');

        // Phải có StockMovement transfer_in
        $movementIn = StockMovement::where('product_id', $this->product->id)
            ->where('type', 'transfer_in')
            ->where('ref_type', 'App\\Models\\StockTransfer')
            ->where('ref_id', $transfer->id)
            ->first();

        $this->assertNotNull(
            $movementIn,
            "Phải có StockMovement type='transfer_in' khi nhận hàng chuyển kho. "
            . "Hiện tại: KHÔNG có dòng nào."
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR03-03: Chuyển kho phải cập nhật inventory_total_cost
     *
     *  Khi xuất 3 sản phẩm (cost = 100.000 mỗi):
     *  - inventory_total_cost phải giảm 300.000 (từ 1.000.000 → 700.000)
     *
     *  Nếu fail: inventory_total_cost không đổi trong khi stock_quantity
     *  giảm → cost_price = total_cost / qty bị sai
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_stock_transfer_should_update_inventory_total_cost(): void
    {
        $costBefore = $this->product->inventory_total_cost;

        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'note'           => 'Test RR03-03',
            'items'          => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 3,
                    'price'      => 3 * $this->product->cost_price,
                ],
            ],
        ]);

        $this->product->refresh();

        // inventory_total_cost phải giảm = 3 × 100.000 = 300.000
        $expectedCost = $costBefore - (3 * 100000);

        $this->assertEquals(
            (float) $expectedCost,
            (float) $this->product->inventory_total_cost,
            "inventory_total_cost phải giảm khi chuyển kho xuất. "
            . "Trước: " . number_format($costBefore)
            . ", kỳ vọng: " . number_format($expectedCost)
            . ", thực tế: " . number_format($this->product->inventory_total_cost)
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR03-04: Chuyển + nhận ngay — tổng tồn hệ thống không đổi
     *
     *  Khi status = 'received':
     *  - Trừ 3 (xuất) + cộng 3 (nhận) = tổng không đổi
     *  - inventory_total_cost cũng không đổi (chỉ chuyển, không tạo/mất giá trị)
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_stock_transfer_received_total_stock_should_not_change(): void
    {
        $stockBefore = $this->product->stock_quantity;
        $costBefore = $this->product->inventory_total_cost;

        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'received',
            'note'           => 'Test RR03-04',
            'items'          => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 3,
                    'price'      => 3 * $this->product->cost_price,
                ],
            ],
        ]);

        $this->product->refresh();

        // Tổng tồn phải không đổi (vì cùng 1 product, chuyển kho chỉ giữa 2 branch)
        // Hệ thống hiện tại lưu tồn chung → stock_quantity phải giữ nguyên
        $this->assertEquals(
            $stockBefore,
            $this->product->stock_quantity,
            "Tổng tồn hệ thống phải không đổi khi chuyển kho status=received. "
            . "Trước: {$stockBefore}, sau: {$this->product->stock_quantity}"
        );

        // inventory_total_cost cũng phải không đổi
        $this->assertEquals(
            (float) $costBefore,
            (float) $this->product->inventory_total_cost,
            "Tổng inventory_total_cost phải không đổi khi chuyển kho received (chỉ di chuyển, không tạo/hủy giá trị). "
            . "Trước: " . number_format($costBefore)
            . ", sau: " . number_format($this->product->inventory_total_cost)
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR03-05: Hủy phiếu chuyển kho — idempotent, tồn phục hồi đúng
     *
     *  Hệ thống hiện tại: cancel() chỉ increment/decrement trực tiếp.
     *  Phải đảm bảo:
     *  - Hủy lần 1: stock phục hồi đúng
     *  - Hủy lần 2: không cộng/trừ thêm (guard idempotent)
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_cancel_stock_transfer_should_be_idempotent(): void
    {
        $stockBefore = $this->product->stock_quantity; // 10

        // Tạo phiếu chuyển kho (transferring → trừ tồn)
        $this->actingAs($this->admin)->post(route('stock-transfers.store'), [
            'from_branch_id' => $this->branchA->id,
            'to_branch_id'   => $this->branchB->id,
            'status'         => 'transferring',
            'note'           => 'Test RR03-05',
            'items'          => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 3,
                    'price'      => 3 * $this->product->cost_price,
                ],
            ],
        ]);

        $transfer = StockTransfer::where('note', 'Test RR03-05')->first();
        $this->assertNotNull($transfer);

        $this->product->refresh();
        $this->assertEquals(7, $this->product->stock_quantity);

        // Hủy lần 1 — phải phục hồi tồn
        $controller = app(\App\Http\Controllers\StockTransferController::class);
        $response1 = $controller->cancel($transfer->id);

        $this->product->refresh();
        $this->assertEquals(
            $stockBefore,
            $this->product->stock_quantity,
            "Hủy lần 1: stock phải phục hồi về {$stockBefore}"
        );

        // Hủy lần 2 — không được thay đổi thêm
        $response2 = $controller->cancel($transfer->id);

        $this->product->refresh();
        $this->assertEquals(
            $stockBefore,
            $this->product->stock_quantity,
            "Hủy lần 2: stock phải giữ nguyên {$stockBefore}, không cộng thêm"
        );
    }
}
