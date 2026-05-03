<?php

namespace Tests\Feature\Serials;

use App\Models\Category;
use App\Models\Product;
use App\Models\SerialImei;
use App\Services\SerialAvailabilityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Step 22.2A — SerialAvailabilityService Contract.
 *
 * Đảm bảo selector Serial/IMEI khả dụng tương thích cả dữ liệu chuẩn lẫn legacy.
 * Service phải:
 *  - Bao gồm serial in_stock chuẩn.
 *  - Bao gồm serial legacy có status NULL chưa bán.
 *  - Loại serial sold/damaged/cancelled/returned/refunded.
 *  - Loại serial repair_status not_started/repairing.
 *  - Loại serial đã có invoice_id/sold_at/purchase_return_id (khi schema có cột).
 */
class SerialAvailabilityServiceTest extends TestCase
{
    use DatabaseTransactions;

    private SerialAvailabilityService $svc;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        SerialAvailabilityService::clearSchemaCache();
        $this->svc = app(SerialAvailabilityService::class);

        $cat = Category::firstOrCreate(['name' => 'Cat-22.2A']);
        $this->product = Product::create([
            'sku'            => 'PROD-22.2A-' . uniqid(),
            'name'           => 'Product 22.2A',
            'cost_price'     => 100000,
            'retail_price'   => 200000,
            'stock_quantity' => 10,
            'is_active'      => true,
            'has_serial'     => true,
            'category_id'    => $cat->id,
        ]);
    }

    private function makeSerial(array $attrs = []): SerialImei
    {
        return SerialImei::create(array_merge([
            'product_id'    => $this->product->id,
            'serial_number' => 'SN-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 100000,
        ], $attrs));
    }

    /* ═════════════ TC-22.2A-01: in_stock chuẩn ═════════════ */
    public function test_available_serials_includes_in_stock_serial(): void
    {
        $s = $this->makeSerial(['status' => 'in_stock']);

        $ids = $this->svc->querySellableForProduct($this->product->id)->pluck('id')->all();

        $this->assertContains($s->id, $ids, 'Serial in_stock phải sellable.');
        $this->assertTrue($this->svc->isSellable($s->refresh(), $this->product->id));
    }

    /* ═════════════ TC-22.2A-02: legacy NULL status, chưa bán ═════════════ */
    public function test_available_serials_includes_legacy_null_status_unsold_serial(): void
    {
        // Schema có thể NOT NULL — chỉ chạy nhánh này khi DB cho phép NULL.
        $col = collect(\DB::select(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'serial_imeis' AND COLUMN_NAME = 'status'"
        ))->first();
        $nullable = $col && strtoupper($col->IS_NULLABLE ?? 'NO') === 'YES';
        if (! $nullable) {
            $this->markTestSkipped('serial_imeis.status NOT NULL — legacy NULL không tồn tại ở schema này (đã được DB bảo vệ).');
        }

        $s = $this->makeSerial();
        \DB::table('serial_imeis')->where('id', $s->id)->update(['status' => null]);

        $ids = $this->svc->querySellableForProduct($this->product->id)->pluck('id')->all();
        $this->assertContains($s->id, $ids,
            'Serial legacy status=NULL chưa bán phải sellable.');

        $reload = SerialImei::find($s->id);
        $this->assertTrue($this->svc->isSellable($reload, $this->product->id));
        $this->assertTrue($this->svc->isLegacyStatus($reload));
    }

    /* ═════════════ TC-22.2A-03: status alias `available`/`ready` (skip nếu ENUM không cho) ═════════════ */
    public function test_available_serials_includes_legacy_alias_status_if_schema_allows(): void
    {
        // Schema thật là ENUM('in_stock','sold','returning','warranty','defective','returned')
        // → 'available'/'ready' không insert được. Test này chỉ chạy nếu ENUM mở rộng.
        try {
            $a = $this->makeSerial();
            \DB::table('serial_imeis')->where('id', $a->id)->update(['status' => 'available']);
        } catch (\Throwable $e) {
            $this->markTestSkipped('ENUM hiện tại không cho phép status="available" — service vẫn tolerant nhưng schema chặn.');
        }

        $ids = $this->svc->querySellableForProduct($this->product->id)->pluck('id')->all();
        $this->assertContains($a->id, $ids);
        $this->assertTrue($this->svc->isLegacyStatus(SerialImei::find($a->id)));
    }

    /* ═════════════ TC-22.2A-04: blocked statuses (theo ENUM thật) ═════════════ */
    public function test_available_serials_excludes_blocked_statuses(): void
    {
        // Theo ENUM('in_stock','sold','returning','warranty','defective','returned').
        $sold      = $this->makeSerial(['status' => 'sold']);
        $returning = $this->makeSerial(['status' => 'returning']);
        $warranty  = $this->makeSerial(['status' => 'warranty']);
        $defective = $this->makeSerial(['status' => 'defective']);
        $returned  = $this->makeSerial(['status' => 'returned']);

        $ids = $this->svc->querySellableForProduct($this->product->id)->pluck('id')->all();

        foreach ([$sold, $returning, $warranty, $defective, $returned] as $s) {
            $this->assertNotContains($s->id, $ids, "Status {$s->status} phải bị loại.");
            $this->assertFalse($this->svc->isSellable($s->refresh(), $this->product->id));
        }
    }

    /* ═════════════ TC-22.2A-05: repair_status repairing/not_started bị loại ═════════════ */
    public function test_available_serials_excludes_repairing_serials(): void
    {
        $r1 = $this->makeSerial(['repair_status' => 'not_started']);
        $r2 = $this->makeSerial(['repair_status' => 'repairing']);
        $ok = $this->makeSerial(['repair_status' => 'ready']);

        $ids = $this->svc->querySellableForProduct($this->product->id)->pluck('id')->all();
        $this->assertNotContains($r1->id, $ids);
        $this->assertNotContains($r2->id, $ids);
        $this->assertContains($ok->id, $ids,
            'repair_status=ready vẫn sellable.');
    }

    /* ═════════════ TC-22.2A-06: findBlockedIds + countSellable ═════════════ */
    public function test_find_blocked_ids_reports_offenders(): void
    {
        $ok   = $this->makeSerial(['status' => 'in_stock']);
        $sold = $this->makeSerial(['status' => 'sold']);

        $blocked = $this->svc->findBlockedIds([$ok->id, $sold->id, 999999], $this->product->id);
        $this->assertContains($sold->id, $blocked);
        $this->assertContains(999999, $blocked, 'ID không tồn tại cũng phải báo blocked.');
        $this->assertNotContains($ok->id, $blocked);

        $this->assertSame(1, $this->svc->countSellable([$ok->id, $sold->id], $this->product->id));
    }

    /* ═════════════ TC-22.2A-07: serial product khác bị loại ═════════════ */
    public function test_serial_of_other_product_is_not_sellable(): void
    {
        $other = Product::create([
            'sku'            => 'PROD-OTHER-' . uniqid(),
            'name'           => 'Other',
            'cost_price'     => 1,
            'retail_price'   => 1,
            'stock_quantity' => 1,
            'is_active'      => true,
            'has_serial'     => true,
            'category_id'    => $this->product->category_id,
        ]);
        $s = SerialImei::create([
            'product_id'    => $other->id,
            'serial_number' => 'SN-OTHER-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 1,
        ]);

        $this->assertFalse($this->svc->isSellable($s, $this->product->id));
    }
}
