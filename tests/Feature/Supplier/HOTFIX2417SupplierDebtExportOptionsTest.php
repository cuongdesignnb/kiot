<?php

namespace Tests\Feature\Supplier;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX 24.17 — Supplier debt export with date filter + column options.
 *
 * The /api/suppliers/{id}/export-debt endpoint now accepts query params
 * (date_preset, date_from, date_to, include_detail, columns[]). With no
 * query the legacy format pinned by HOTFIX 24.14 is preserved. This
 * suite pins the new contract end-to-end:
 *
 *  - legacy no-query path still hits the old headers,
 *  - custom date range filters entries by created_at without
 *    recomputing debt_remain,
 *  - presets (today, all, ...) don't 500,
 *  - bad date input returns 422 (not 500),
 *  - include_detail=1 + columns[] appends the requested detail columns.
 */
class HOTFIX2417SupplierDebtExportOptionsTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 2417',
            'email'    => 'admin-2417-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function supplier(string $name = 'NCC 2417'): Customer
    {
        return Customer::create([
            'code'                 => 'NCC-2417-' . uniqid(),
            'name'                 => $name,
            'phone'                => '09' . random_int(10000000, 99999999),
            'debt_amount'          => 0,
            'supplier_debt_amount' => 0,
            'is_customer'          => false,
            'is_supplier'          => true,
        ]);
    }

    private function purchase(Customer $supplier, int $total, Carbon $when, string $codePrefix = 'PN'): Purchase
    {
        $p = Purchase::create([
            'code'          => $codePrefix . '-' . uniqid(),
            'supplier_id'   => $supplier->id,
            'user_id'       => null,
            'total_amount'  => $total,
            'paid_amount'   => 0,
            'debt_amount'   => $total,
            'status'        => 'completed',
            'purchase_date' => $when,
        ]);
        // Force created_at so the date filter has something predictable to
        // bite — Eloquent sets it to now() otherwise.
        $p->created_at = $when;
        $p->updated_at = $when;
        $p->save();
        return $p;
    }

    // ── TC-01 — no query: legacy format intact (HOTFIX 24.14 contract) ──
    public function test_export_with_no_query_keeps_legacy_format(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $p     = $this->purchase($sup, 500000, Carbon::now());

        $res = $this->actingAs($admin)->get("/api/suppliers/{$sup->id}/export-debt");

        $res->assertOk();
        $body = $res->streamedContent() ?: $res->getContent();
        $this->assertStringContainsString('Mã chứng từ', $body);
        $this->assertStringContainsString('Còn nợ', $body, 'legacy header `Còn nợ` must survive');
        $this->assertStringContainsString($p->code, $body);
    }

    // ── TC-02 — custom range: includes entries inside, excludes outside ──
    public function test_export_custom_range_filters_by_created_at(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();

        $pIn1  = $this->purchase($sup, 100000, Carbon::create(2026, 5, 1, 9, 0));
        $pIn2  = $this->purchase($sup, 200000, Carbon::create(2026, 5, 10, 9, 0));
        $pOut  = $this->purchase($sup, 300000, Carbon::create(2026, 5, 20, 9, 0));

        $res = $this->actingAs($admin)->get(
            "/api/suppliers/{$sup->id}/export-debt?date_preset=custom&date_from=2026-05-01&date_to=2026-05-14"
        );

        $res->assertOk();
        $body = $res->streamedContent() ?: $res->getContent();

        $this->assertStringContainsString($pIn1->code, $body, 'purchase on 2026-05-01 must appear');
        $this->assertStringContainsString($pIn2->code, $body, 'purchase on 2026-05-10 must appear');
        $this->assertStringNotContainsString($pOut->code, $body, 'purchase on 2026-05-20 must be filtered out');

        // New headers must be used when query params are present.
        $this->assertStringContainsString('Thời gian', $body);
        $this->assertStringContainsString('Nợ cần trả nhà cung cấp', $body);
    }

    // ── TC-03 — preset=today doesn't 500 ──
    public function test_export_preset_today_is_ok(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $this->purchase($sup, 50000, Carbon::now());

        $res = $this->actingAs($admin)->get("/api/suppliers/{$sup->id}/export-debt?date_preset=today");

        $res->assertOk();
        $this->assertLessThan(500, $res->getStatusCode());
    }

    // ── TC-04 — preset=all returns full ledger ──
    public function test_export_preset_all_returns_all_entries(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $pOld  = $this->purchase($sup, 100000, Carbon::create(2020, 1, 1, 9, 0));
        $pNew  = $this->purchase($sup, 200000, Carbon::now());

        $res = $this->actingAs($admin)->get("/api/suppliers/{$sup->id}/export-debt?date_preset=all");

        $res->assertOk();
        $body = $res->streamedContent() ?: $res->getContent();
        $this->assertStringContainsString($pOld->code, $body);
        $this->assertStringContainsString($pNew->code, $body);
    }

    // ── TC-05 — date_from > date_to → 422, not 500 ──
    public function test_export_invalid_range_returns_422(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();

        $res = $this->actingAs($admin)->get(
            "/api/suppliers/{$sup->id}/export-debt?date_preset=custom&date_from=2026-06-30&date_to=2026-06-01"
        );

        $this->assertSame(422, $res->getStatusCode(), 'reversed range must 422, not 500');
    }

    // ── TC-06 — include_detail=1 emits detail header columns ──
    public function test_export_include_detail_appends_detail_columns(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $p     = $this->purchase($sup, 500000, Carbon::now());
        PurchaseItem::create([
            'purchase_id'   => $p->id,
            'product_name'  => 'Linh kiện X',
            'product_code'  => 'LK-X',
            'quantity'      => 2,
            'price'         => 250000,
            'discount'      => 0,
            'subtotal'      => 500000,
        ]);

        $res = $this->actingAs($admin)->get(
            "/api/suppliers/{$sup->id}/export-debt?date_preset=all&include_detail=1"
            . "&columns[]=quantity&columns[]=unit_price&columns[]=line_total"
        );

        $res->assertOk();
        $body = $res->streamedContent() ?: $res->getContent();
        $this->assertStringContainsString('Số lượng', $body);
        $this->assertStringContainsString('Đơn giá', $body);
        $this->assertStringContainsString('Thành tiền', $body);
        // Detail row must surface the line subtotal.
        $this->assertStringContainsString('500000', $body);
    }

    // ── TC-07 — columns whitelist: only selected columns are exported ──
    public function test_export_columns_whitelist_only(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $p     = $this->purchase($sup, 300000, Carbon::now());
        PurchaseItem::create([
            'purchase_id'   => $p->id,
            'product_name'  => 'Linh kiện Y',
            'product_code'  => 'LK-Y',
            'quantity'      => 3,
            'price'         => 100000,
            'discount'      => 10000,
            'subtotal'      => 290000,
        ]);

        $res = $this->actingAs($admin)->get(
            "/api/suppliers/{$sup->id}/export-debt?date_preset=all&include_detail=1&columns[]=quantity"
        );

        $res->assertOk();
        $body = $res->streamedContent() ?: $res->getContent();
        $this->assertStringContainsString('Số lượng', $body);
        $this->assertStringNotContainsString('Đơn giá', $body, 'unit_price not selected → must not appear');
        $this->assertStringNotContainsString('Giảm giá', $body, 'discount not selected → must not appear');
        $this->assertStringNotContainsString('Thành tiền', $body, 'line_total not selected → must not appear');
    }

    // ── TC-08 — regression: debt-transactions JSON shape preserved ──
    public function test_debt_transactions_json_endpoint_unchanged(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $this->purchase($sup, 700000, Carbon::now());

        $res = $this->actingAs($admin)->getJson("/api/suppliers/{$sup->id}/debt-transactions");

        $res->assertOk();
        $data = $res->json();
        $this->assertArrayHasKey('entries', $data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertIsArray($data['entries']);
    }
}
