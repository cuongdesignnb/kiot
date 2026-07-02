<?php

namespace Tests\Feature\Warranty;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Warranty;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Step 23.7 — Warranty flow read-only & validation.
 */
class Step237WarrantyFlowTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name'     => 'Admin 23.7',
            'email'    => 'admin-237-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function makeProduct(): Product
    {
        $cat = Category::firstOrCreate(['name' => 'Cat 23.7']);
        return Product::create([
            'sku'            => 'P237-' . uniqid(),
            'name'           => 'Product 23.7',
            'cost_price'     => 100000,
            'retail_price'   => 200000,
            'stock_quantity' => 0,
            'is_active'      => true,
            'has_serial'     => false,
            'category_id'    => $cat->id,
        ]);
    }

    private function makeWarranty(?string $serial = 'SN-237-A'): Warranty
    {
        return Warranty::create([
            'invoice_code'      => 'INV-237-' . uniqid(),
            'product_id'        => $this->makeProduct()->id,
            'customer_name'     => 'Test Customer',
            'serial_imei'       => $serial,
            'warranty_period'   => 12,
            'purchase_date'     => now()->subMonth(),
            'warranty_end_date' => now()->addMonths(11),
        ]);
    }

    /* ── A. Index read-only ── */

    public function test_warranty_index_does_not_seed_dummy_data(): void
    {
        $this->assertSame(0, Warranty::count(), 'Bảng warranties phải rỗng trước khi GET index.');

        $resp = $this->actingAs($this->admin)->get(route('warranties.index'));
        $resp->assertOk();

        $this->assertSame(0, Warranty::count(), 'Index KHÔNG được tự seed dummy.');
        $this->assertSame(0, Warranty::where('customer_name', 'Anh Khải')->count());
        $this->assertSame(0, Warranty::where('invoice_code', 'HD008229.01')->count());
    }

    public function test_warranty_index_search_is_read_only(): void
    {
        $w = $this->makeWarranty();
        $before = Warranty::count();

        $this->actingAs($this->admin)
            ->get(route('warranties.index', ['search' => $w->customer_name]))
            ->assertOk();
        $this->actingAs($this->admin)
            ->get(route('warranties.index', ['status' => 'valid', 'sort_by' => 'invoice_code', 'sort_direction' => 'asc']))
            ->assertOk();
        $this->actingAs($this->admin)
            ->get(route('warranties.index', ['status' => 'expired', 'time_filter' => 'this_month']))
            ->assertOk();

        $this->assertSame($before, Warranty::count(), 'Search/filter/sort không được mutate DB.');
    }

    public function test_warranty_legacy_record_without_serial_still_displays(): void
    {
        $this->makeWarranty(null);

        $this->actingAs($this->admin)
            ->get(route('warranties.index'))
            ->assertOk();
    }

    /* ── B. Update validation ── */

    public function test_warranty_update_allows_maintenance_fields(): void
    {
        $w = $this->makeWarranty();

        $this->actingAs($this->admin)->put(route('warranties.update', $w->id), [
            'maintenance_note' => 'Đã bảo trì lần 1',
            'has_reminder_off' => true,
        ]);

        $w->refresh();
        $this->assertSame('Đã bảo trì lần 1', $w->maintenance_note);
        $this->assertTrue((bool) $w->has_reminder_off);
    }

    public function test_warranty_update_validates_warranty_period_non_negative(): void
    {
        $w = $this->makeWarranty();

        $resp = $this->actingAs($this->admin)
            ->from(route('warranties.index'))
            ->put(route('warranties.update', $w->id), [
                'warranty_period' => -1,
            ]);

        $resp->assertSessionHasErrors('warranty_period');
        $this->assertSame(12, (int) $w->fresh()->warranty_period, 'warranty_period không được đổi.');
    }

    public function test_warranty_update_validates_warranty_end_date(): void
    {
        $w = $this->makeWarranty();

        $resp = $this->actingAs($this->admin)
            ->from(route('warranties.index'))
            ->put(route('warranties.update', $w->id), [
                'warranty_end_date' => 'not-a-date',
            ]);

        $resp->assertSessionHasErrors('warranty_end_date');
    }

    public function test_warranty_update_does_not_change_protected_fields(): void
    {
        $w = $this->makeWarranty();
        $origInvoice  = $w->invoice_code;
        $origProduct  = $w->product_id;
        $origCustomer = $w->customer_name;

        $this->actingAs($this->admin)->put(route('warranties.update', $w->id), [
            'invoice_code'  => 'HACKED',
            'product_id'    => 999999,
            'customer_name' => 'Hacker',
            'purchase_date' => '2000-01-01',
            'maintenance_note' => 'ok',
        ]);

        $w->refresh();
        $this->assertSame($origInvoice, $w->invoice_code, 'invoice_code không được sửa qua route update.');
        $this->assertSame($origProduct, $w->product_id);
        $this->assertSame($origCustomer, $w->customer_name);
        $this->assertSame('ok', $w->maintenance_note);
    }

    /* ── C. Export read-only ── */

    public function test_warranty_export_is_read_only(): void
    {
        $this->makeWarranty();
        $before = Warranty::count();

        $this->actingAs($this->admin)
            ->get(route('warranties.export'))
            ->assertOk();

        $this->assertSame($before, Warranty::count(), 'Export không được mutate DB.');
    }

    /* ── D. Sales → warranty generation hiện trạng ── */

    public function test_invoice_sale_current_warranty_generation_behavior(): void
    {
        // Step 23.7 audit: Hiện chưa có module sinh warranty từ bán hàng (không có Warranty::create
        // nào ngoài auto-seed đã xóa). Test này khóa hiện trạng để tránh vô tình thêm logic
        // backfill ngầm trong tương lai mà không qua command dry-run.
        $countBefore = Warranty::count();
        $this->assertSame(0, $countBefore, 'Test khởi đầu phải rỗng (DatabaseTransactions).');

        // Không gọi InvoiceSaleService/Pos vì ngoài scope 23.7. Chỉ đảm bảo chưa có hook tự sinh.
        $callsToWarrantyCreate = [
            // Pattern grep đã xác nhận chỉ còn 0 occurrence sau khi xóa auto-seed.
            'app/Services/InvoiceSaleService.php',
            'app/Http/Controllers/PosController.php',
            'app/Http/Controllers/OrderController.php',
            'app/Http/Controllers/InvoiceController.php',
        ];

        foreach ($callsToWarrantyCreate as $rel) {
            $abs = base_path($rel);
            if (! file_exists($abs)) {
                continue;
            }
            $body = file_get_contents($abs);
            $this->assertDoesNotMatchRegularExpression(
                '/Warranty\s*::\s*create|new\s+Warranty\s*\(/',
                $body,
                "$rel: phát hiện sinh Warranty từ sales — cần audit ở STEP 23.7B."
            );
        }

        $this->assertSame($countBefore, Warranty::count());
    }
}
