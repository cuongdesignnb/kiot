<?php

namespace Tests\Feature\Repair;

use App\Models\Category;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * RR-07: Sửa chữa trừ tồn linh kiện bằng raw decrement.
 *
 * Vấn đề: TaskService::addPart() dòng 289 dùng $product->decrement() raw:
 *   - KHÔNG tạo StockMovement (thẻ kho thiếu dòng xuất linh kiện)
 *   - KHÔNG cập nhật inventory_total_cost của linh kiện (giá vốn BQ sai)
 *
 * TaskService::removePart() dòng 323 dùng Product::increment() raw:
 *   - KHÔNG tạo StockMovement (thẻ kho thiếu dòng hoàn linh kiện)
 *   - KHÔNG cập nhật inventory_total_cost của linh kiện
 *
 * Lưu ý: RepairService cũ (deprecated) cũng có cùng lỗi nhưng thêm bug
 * dùng 'device_repair_id' thay vì 'task_id'. Test này dùng TaskService.
 */
class RR07RepairPartsTest extends TestCase
{
    use DatabaseTransactions;

    private Product    $partProduct;
    private Product    $deviceProduct;
    private SerialImei $serial;
    private User       $admin;
    private Task       $repair;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name'     => 'Admin RR07',
            'email'    => 'admin-rr07-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);

        $category = Category::firstOrCreate(['name' => 'Cat RR07']);

        // Sản phẩm thiết bị (cần serial để tạo phiếu sửa chữa)
        $this->deviceProduct = Product::create([
            'sku'                  => 'DEVICE-RR07-' . uniqid(),
            'name'                 => 'Device RR07',
            'cost_price'           => 5000000,
            'retail_price'         => 8000000,
            'stock_quantity'       => 1,
            'inventory_total_cost' => 5000000,
            'is_active'            => true,
            'has_serial'           => true,
            'category_id'          => $category->id,
        ]);

        // Serial cho thiết bị
        $this->serial = SerialImei::create([
            'product_id'    => $this->deviceProduct->id,
            'serial_number' => 'SN-RR07-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 5000000,
        ]);

        // Linh kiện (part) — đây là sản phẩm bị trừ tồn khi xuất
        $this->partProduct = Product::create([
            'sku'                  => 'PART-RR07-' . uniqid(),
            'name'                 => 'Part RR07 Test',
            'cost_price'           => 100000,
            'retail_price'         => 200000,
            'stock_quantity'       => 10,
            'inventory_total_cost' => 10 * 100000, // 1.000.000
            'is_active'            => true,
            'has_serial'           => false,
            'category_id'          => $category->id,
        ]);

        // Tạo phiếu sửa chữa via TaskService
        $service = app(TaskService::class);
        $this->repair = $service->createTask([
            'type'              => Task::TYPE_REPAIR,
            'serial_imei_id'    => $this->serial->id,
            'issue_description' => 'Test RR07',
            'created_by'        => $this->admin->id,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR07-01: Xuất linh kiện phải cập nhật inventory_total_cost
     *
     *  Part stock=10, cost=100.000, total_cost=1.000.000
     *  Xuất 3 → stock=7, total_cost phải = 700.000
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_add_part_should_update_part_inventory_total_cost(): void
    {
        $costBefore = (float) $this->partProduct->inventory_total_cost; // 1.000.000

        $service = app(TaskService::class);
        $service->addPart($this->repair, $this->partProduct->id, 3);

        $this->partProduct->refresh();

        // stock_quantity phải giảm
        $this->assertEquals(7, $this->partProduct->stock_quantity);

        // inventory_total_cost phải giảm 3 × 100.000 = 300.000
        $expected = $costBefore - (3 * 100000); // 700.000
        $this->assertEquals(
            $expected,
            (float) $this->partProduct->inventory_total_cost,
            "Linh kiện inventory_total_cost phải giảm khi xuất. "
            . "Trước: " . number_format($costBefore)
            . ", kỳ vọng: " . number_format($expected)
            . ", thực tế: " . number_format($this->partProduct->inventory_total_cost)
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR07-02: Xuất linh kiện phải tạo StockMovement repair_out
     *
     *  Phải có StockMovement type = repair_out cho linh kiện
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_add_part_should_create_repair_out_movement(): void
    {
        $service = app(TaskService::class);
        $service->addPart($this->repair, $this->partProduct->id, 3);

        $movement = StockMovement::where('product_id', $this->partProduct->id)
            ->where('type', 'repair_out')
            ->first();

        $this->assertNotNull(
            $movement,
            "Phải có StockMovement type='repair_out' khi xuất linh kiện sửa chữa. "
            . "Hiện tại: KHÔNG có dòng nào trong stock_movements cho linh kiện."
        );

        if ($movement) {
            $this->assertEquals(3, $movement->qty);
        }
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR07-03: Hoàn linh kiện (removePart) phải cộng lại + movement đảo
     *
     *  Xuất 3 → hoàn 3 → stock phải về 10, total_cost về 1.000.000
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_remove_part_should_restore_stock_and_create_movement(): void
    {
        $service = app(TaskService::class);

        // Xuất 3
        $part = $service->addPart($this->repair, $this->partProduct->id, 3);

        $this->partProduct->refresh();
        $this->assertEquals(7, $this->partProduct->stock_quantity);

        // Hoàn linh kiện
        $service->removePart($part);

        $this->partProduct->refresh();

        // stock phải về 10
        $this->assertEquals(
            10,
            $this->partProduct->stock_quantity,
            "Hoàn linh kiện phải cộng lại stock_quantity"
        );

        // inventory_total_cost phải về 1.000.000
        $this->assertEquals(
            1000000,
            (float) $this->partProduct->inventory_total_cost,
            "Hoàn linh kiện phải phục hồi inventory_total_cost"
        );

        // Phải có StockMovement đảo repair_in
        $movementIn = StockMovement::where('product_id', $this->partProduct->id)
            ->where('type', 'repair_in')
            ->first();

        $this->assertNotNull(
            $movementIn,
            "Hoàn linh kiện phải tạo StockMovement type='repair_in'. "
            . "Hiện tại: KHÔNG có movement đảo."
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR07-04: Không cho xuất linh kiện quá tồn
     *
     *  Part stock=10, xuất 15 → phải throw Exception
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_add_part_should_not_allow_exceeding_stock(): void
    {
        $service = app(TaskService::class);

        $this->expectException(\RuntimeException::class);

        // Xuất 15 khi chỉ có 10 → phải throw
        $service->addPart($this->repair, $this->partProduct->id, 15);

        // Nếu không throw, stock không được đổi
        $this->partProduct->refresh();
        $this->assertEquals(10, $this->partProduct->stock_quantity);
    }
}
