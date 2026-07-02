<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\SerialImei;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX 24.34 — Tab Serial/IMEI must display dismantled serials with
 * the physical "Đã bóc tách" label, never raw `dismantled` or a
 * "Đang sửa" badge inherited from repair_status.
 *
 * Backend contract verified here:
 *   GET /products/{product}/serials?status=ready       → in_stock, not in repair flow
 *   GET /products/{product}/serials?status=repairing   → in_stock + repair_status in {not_started,repairing}
 *   GET /products/{product}/serials?status=dismantled  → status = dismantled (NEVER bundled with repairing)
 *   index() exposes dismantled_count separate from repairing_count and ready_count
 *   POS sellable feed excludes dismantled
 */
class HOTFIX2434ProductSerialDismantledDisplayTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 2434',
            'email'    => 'admin-2434-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function serialProduct(int $stock = 20): Product
    {
        return Product::create([
            'sku'                  => 'SKU-2434-' . uniqid(),
            'name'                 => 'SP 2434',
            'cost_price'           => 500_000,
            'retail_price'         => 1_000_000,
            'stock_quantity'       => $stock,
            'inventory_total_cost' => 500_000 * $stock,
            'has_serial'           => true,
            'is_active'            => true,
        ]);
    }

    private function serial(Product $p, string $status, ?string $repairStatus = null): SerialImei
    {
        return SerialImei::create([
            'product_id'    => $p->id,
            'serial_number' => 'SR-2434-' . uniqid(),
            'status'        => $status,
            'repair_status' => $repairStatus,
            'cost_price'    => 500_000,
        ]);
    }

    // ── Test 1 — filter ready does NOT include dismantled ──
    public function test_filter_ready_excludes_dismantled(): void
    {
        $admin = $this->admin();
        $p = $this->serialProduct();
        $a = $this->serial($p, 'in_stock', null);
        $b = $this->serial($p, 'in_stock', 'ready');
        $c = $this->serial($p, 'dismantled', 'ready'); // ← legacy edge case

        $res = $this->actingAs($admin)->getJson("/products/{$p->id}/serials?status=ready");
        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->all();

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
        $this->assertNotContains($c->id, $ids, 'Dismantled must NOT appear in ready filter');
    }

    // ── Test 2 — filter repairing does NOT include dismantled ──
    public function test_filter_repairing_excludes_dismantled(): void
    {
        $admin = $this->admin();
        $p = $this->serialProduct();
        $a = $this->serial($p, 'in_stock', 'repairing');
        $b = $this->serial($p, 'in_stock', 'not_started');
        $c = $this->serial($p, 'dismantled', 'repairing'); // ← this row caused the screenshot bug

        $res = $this->actingAs($admin)->getJson("/products/{$p->id}/serials?status=repairing");
        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->all();

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
        $this->assertNotContains($c->id, $ids, 'Dismantled with repair_status=repairing must NOT appear in repairing filter');
    }

    // ── Test 3 — filter dismantled returns only dismantled ──
    public function test_filter_dismantled_returns_only_dismantled(): void
    {
        $admin = $this->admin();
        $p = $this->serialProduct();
        $this->serial($p, 'in_stock', null);
        $this->serial($p, 'in_stock', 'repairing');
        $d1 = $this->serial($p, 'dismantled', null);
        $d2 = $this->serial($p, 'dismantled', 'ready');

        $res = $this->actingAs($admin)->getJson("/products/{$p->id}/serials?status=dismantled");
        $res->assertOk();
        $rows = $res->json();
        $statuses = collect($rows)->pluck('status')->unique()->values()->all();

        $this->assertSame(['dismantled'], $statuses);
        $ids = collect($rows)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$d1->id, $d2->id], $ids);
    }

    // ── Test 4 — product index counts dismantled separately ──
    public function test_product_index_counts_dismantled_separately(): void
    {
        $admin = $this->admin();
        $p = $this->serialProduct(19);

        // 10 ready in_stock
        for ($i = 0; $i < 10; $i++) $this->serial($p, 'in_stock', null);
        // 4 in_stock + repair_status=repairing
        for ($i = 0; $i < 4; $i++) $this->serial($p, 'in_stock', 'repairing');
        // 5 dismantled
        for ($i = 0; $i < 5; $i++) $this->serial($p, 'dismantled', 'repairing');

        $res = $this->actingAs($admin)->get('/products?search=' . $p->sku);
        $res->assertOk();

        $row = collect($res->viewData('page')['props']['products']['data'])
            ->firstWhere('id', $p->id);
        $this->assertNotNull($row, 'Product must appear in list');

        $this->assertEquals(14, (int) $row['in_stock_count'], 'in_stock_count = 10 ready + 4 repairing');
        $this->assertEquals(10, (int) $row['ready_count']);
        $this->assertEquals(4, (int) $row['repairing_count']);
        $this->assertEquals(5, (int) $row['dismantled_count'],
            'dismantled_count must be exposed separately');
        $this->assertEquals(19, (int) $row['total_serial_count']);
    }

    // ── Test 5 — POS sellable serial feed excludes dismantled ──
    public function test_pos_sellable_serial_feed_excludes_dismantled(): void
    {
        $admin = $this->admin();
        $p = $this->serialProduct();
        $ok = $this->serial($p, 'in_stock', null);
        $repairing = $this->serial($p, 'in_stock', 'repairing');
        $dismantled = $this->serial($p, 'dismantled', 'repairing');

        $res = $this->actingAs($admin)->getJson("/api/products/{$p->id}/serials");
        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->all();

        $this->assertContains($ok->id, $ids, 'in_stock + no repair → sellable');
        $this->assertNotContains($repairing->id, $ids, 'in_stock + repairing → NOT sellable');
        $this->assertNotContains($dismantled->id, $ids, 'dismantled → NOT sellable');
    }

    // ── Test 6 — status=all still works as escape hatch ──
    public function test_filter_all_returns_every_status(): void
    {
        $admin = $this->admin();
        $p = $this->serialProduct();
        $this->serial($p, 'in_stock', null);
        $this->serial($p, 'dismantled', 'ready');
        $this->serial($p, 'sold', null);

        $res = $this->actingAs($admin)->getJson("/products/{$p->id}/serials?status=all");
        $res->assertOk();
        $statuses = collect($res->json())->pluck('status')->unique()->sort()->values()->all();
        $this->assertEquals(['dismantled', 'in_stock', 'sold'], $statuses);
    }

    // ── Test 7 — dismantled serial response carries status=dismantled so FE can label it ──
    public function test_dismantled_serial_response_keeps_status_dismantled(): void
    {
        $admin = $this->admin();
        $p = $this->serialProduct();
        $d = $this->serial($p, 'dismantled', 'repairing');

        $res = $this->actingAs($admin)->getJson("/products/{$p->id}/serials?status=dismantled");
        $res->assertOk();
        $row = collect($res->json())->firstWhere('id', $d->id);
        $this->assertNotNull($row);
        $this->assertSame('dismantled', $row['status'],
            'API still emits raw status=dismantled — FE serialStatusLabel() maps it to "Đã bóc tách"');
    }
}
