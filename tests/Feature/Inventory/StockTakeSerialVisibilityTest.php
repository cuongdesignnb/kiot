<?php

namespace Tests\Feature\Inventory;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\StockTakeItemSerial;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockTakeSerialVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name' => 'StockTake Serial QA',
            'email' => 'stocktake-serial-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function branch(): Branch
    {
        return Branch::create([
            'code' => 'BR-ST-SERIAL-'.uniqid(),
            'name' => 'StockTake Serial Branch',
        ]);
    }

    private function product(bool $hasSerial = true, int $stock = 0): Product
    {
        $category = Category::firstOrCreate(['name' => 'StockTake Serial Category']);

        return Product::create([
            'sku' => 'ST-SERIAL-'.uniqid(),
            'name' => $hasSerial ? 'Serial StockTake Product' : 'Normal StockTake Product',
            'category_id' => $category->id,
            'stock_quantity' => $stock,
            'inventory_total_cost' => $stock * 100000,
            'cost_price' => 100000,
            'retail_price' => 150000,
            'has_serial' => $hasSerial,
            'is_active' => true,
        ]);
    }

    private function serial(Product $product, string $number): SerialImei
    {
        return SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => $number,
            'status' => 'in_stock',
            'cost_price' => 100000,
            'original_cost' => 100000,
        ]);
    }

    private function serialPayload(iterable $serials, array $presentNumbers = [], ?array $unknown = null): array
    {
        $payload = collect($serials)->map(fn (SerialImei $serial) => [
            'serial_imei_id' => $serial->id,
            'serial_number' => $serial->serial_number,
            'actual_present' => in_array($serial->serial_number, $presentNumbers, true),
        ])->values()->all();

        return [
            'serials' => $payload,
            'unknown_serials' => $unknown ?? [],
        ];
    }

    public function test_stocktake_product_serial_endpoint_returns_inventory_serials(): void
    {
        $product = $this->product(true, 2);
        $first = $this->serial($product, 'ST-SN-001');
        $second = $this->serial($product, 'ST-SN-002');
        $this->serial($product, 'ST-SN-SOLD')->update(['status' => 'sold']);

        $response = $this->actingAs($this->admin)->getJson(route('stock-takes.products.serials', $product));

        $response->assertOk()
            ->assertJsonPath('product_id', $product->id)
            ->assertJsonPath('aggregate_stock', 2)
            ->assertJsonPath('serial_stock_count', 2)
            ->assertJsonPath('integrity_match', true)
            ->assertJsonPath('serials.0.serial_number', $first->serial_number)
            ->assertJsonPath('serials.1.serial_number', $second->serial_number)
            ->assertJsonPath('serials.0.cost_price', 100000);
    }

    public function test_stocktake_product_search_resolves_serial_without_loading_serial_lists(): void
    {
        $product = $this->product(true, 1);
        $serial = $this->serial($product, 'ST-SCAN-001');
        $this->product(true, 0);
        $this->product(true, 0);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($this->admin)->getJson('/api/stock-takes/products?search='.$serial->serial_number);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk()
            ->assertJsonPath('0.product_id', $product->id)
            ->assertJsonPath('0.matched_serial_numbers.0', $serial->serial_number);
        $this->assertSame(1, collect($response->json())->count());
        $this->assertNotEmpty($queries);
    }

    public function test_category_product_list_does_not_bulk_load_serials(): void
    {
        foreach (range(1, 3) as $index) {
            $product = $this->product(true, 1);
            $this->serial($product, 'ST-BULK-'.$index);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($this->admin)->getJson('/api/stock-takes/products?limit=500');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertSame(0, collect($queries)->filter(fn (array $query) => str_contains(strtolower($query['query']), 'serial_imeis'))->count());
    }

    public function test_normal_product_stocktake_regression_remains_quantity_based(): void
    {
        $product = $this->product(false, 10);
        $branch = $this->branch();

        $response = $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'branch_id' => $branch->id,
            'status' => 'draft',
            'items' => [[
                'product_id' => $product->id,
                'actual_stock' => 7,
                'checked' => true,
            ]],
        ]);

        $response->assertRedirect();
        $item = StockTake::latest('id')->firstOrFail()->items()->firstOrFail();
        $this->assertSame(7, (int) $item->actual_stock);
        $this->assertSame(-3, (int) $item->diff_qty);
        $this->assertSame(10, (int) $product->fresh()->stock_quantity);
    }

    public function test_serial_exact_match_recomputes_parent_and_balances_without_mutation(): void
    {
        $product = $this->product(true, 3);
        $serials = collect(['ST-EXACT-001', 'ST-EXACT-002', 'ST-EXACT-003'])
            ->map(fn (string $number) => $this->serial($product, $number));
        $branch = $this->branch();

        $response = $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'branch_id' => $branch->id,
            'status' => 'balanced',
            'items' => [[
                'product_id' => $product->id,
                'actual_stock' => 999,
                'checked' => false,
                ...$this->serialPayload($serials, $serials->pluck('serial_number')->all()),
            ]],
        ]);

        $response->assertRedirect();
        $stockTake = StockTake::latest('id')->firstOrFail();
        $item = $stockTake->items()->firstOrFail();
        $this->assertSame('balanced', $stockTake->status);
        $this->assertSame(3, (int) $item->actual_stock);
        $this->assertSame(0, (int) $item->diff_qty);
        $this->assertSame(3, StockTakeItemSerial::where('stock_take_item_id', $item->id)->count());
        $this->assertSame(0, StockMovement::where('ref_id', $stockTake->id)->count());
        $this->assertSame(3, (int) $product->fresh()->stock_quantity);
    }

    public function test_serial_missing_can_be_drafted_but_balance_fails_closed_without_mutation(): void
    {
        $product = $this->product(true, 3);
        $serials = collect(['ST-MISSING-001', 'ST-MISSING-002', 'ST-MISSING-003'])
            ->map(fn (string $number) => $this->serial($product, $number));
        $branch = $this->branch();

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'branch_id' => $branch->id,
            'status' => 'draft',
            'items' => [[
                'product_id' => $product->id,
                ...$this->serialPayload($serials, ['ST-MISSING-001', 'ST-MISSING-003']),
            ]],
        ])->assertRedirect();

        $stockTake = StockTake::latest('id')->firstOrFail();
        $item = $stockTake->items()->firstOrFail();
        $this->assertSame(2, (int) $item->actual_stock);
        $this->assertSame(-1, (int) $item->diff_qty);

        $response = $this->actingAs($this->admin)->post(route('stock-takes.balance', $stockTake));
        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('ST-MISSING-002', (string) $response->json('message'));
        $this->assertSame('draft', $stockTake->fresh()->status);
        $this->assertSame(3, (int) $product->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('ref_id', $stockTake->id)->count());
    }

    public function test_unknown_serial_is_not_created_and_blocks_completion(): void
    {
        $product = $this->product(true, 1);
        $serial = $this->serial($product, 'ST-UNKNOWN-EXPECTED');
        $branch = $this->branch();

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'branch_id' => $branch->id,
            'status' => 'draft',
            'items' => [[
                'product_id' => $product->id,
                ...$this->serialPayload([$serial], [$serial->serial_number], ['UNKNOWN001']),
            ]],
        ])->assertRedirect();

        $stockTake = StockTake::latest('id')->firstOrFail();
        $response = $this->actingAs($this->admin)->post(route('stock-takes.balance', $stockTake));
        $response->assertStatus(422);
        $this->assertStringContainsString('UNKNOWN001', (string) $response->json('message'));
        $this->assertSame(0, SerialImei::where('serial_number', 'UNKNOWN001')->count());
        $this->assertSame(1, (int) $product->fresh()->stock_quantity);
        $this->assertSame(0, StockMovement::where('ref_id', $stockTake->id)->count());
    }

    public function test_serial_aggregate_mismatch_is_reported_and_blocks_completion(): void
    {
        $product = $this->product(true, 5);
        $serials = collect(['ST-MISMATCH-001', 'ST-MISMATCH-002', 'ST-MISMATCH-003', 'ST-MISMATCH-004'])
            ->map(fn (string $number) => $this->serial($product, $number));

        $response = $this->actingAs($this->admin)->getJson(route('stock-takes.products.serials', $product));
        $response->assertJsonPath('aggregate_stock', 5)
            ->assertJsonPath('serial_stock_count', 4)
            ->assertJsonPath('integrity_match', false);

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'draft',
            'items' => [[
                'product_id' => $product->id,
                ...$this->serialPayload($serials, $serials->pluck('serial_number')->all()),
            ]],
        ])->assertRedirect();
        $stockTake = StockTake::latest('id')->firstOrFail();
        $response = $this->actingAs($this->admin)->post(route('stock-takes.balance', $stockTake));
        $response->assertStatus(422);
        $this->assertStringContainsString('đang lệch', (string) $response->json('message'));
        $this->assertSame('draft', $stockTake->fresh()->status);
        $this->assertSame(5, (int) $product->fresh()->stock_quantity);
    }

    public function test_stale_serial_snapshot_is_422_without_mutation(): void
    {
        $product = $this->product(true, 2);
        $serials = collect(['ST-STALE-001', 'ST-STALE-002'])
            ->map(fn (string $number) => $this->serial($product, $number));

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'draft',
            'items' => [[
                'product_id' => $product->id,
                ...$this->serialPayload($serials, $serials->pluck('serial_number')->all()),
            ]],
        ])->assertRedirect();
        $stockTake = StockTake::latest('id')->firstOrFail();
        $serials->last()->update(['status' => 'sold']);

        $response = $this->actingAs($this->admin)->post(route('stock-takes.balance', $stockTake));
        $response->assertStatus(422);
        $this->assertStringContainsString('đã thay đổi', (string) $response->json('message'));
        $this->assertSame('draft', $stockTake->fresh()->status);
        $this->assertSame('sold', $serials->last()->fresh()->status);
        $this->assertSame(0, StockMovement::where('ref_id', $stockTake->id)->count());
    }

    public function test_serial_draft_update_recomputes_parent_from_serial_details(): void
    {
        $product = $this->product(true, 2);
        $serials = collect(['ST-UPDATE-001', 'ST-UPDATE-002'])
            ->map(fn (string $number) => $this->serial($product, $number));

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'draft',
            'items' => [[
                'product_id' => $product->id,
                ...$this->serialPayload($serials, $serials->pluck('serial_number')->all()),
            ]],
        ])->assertRedirect();

        $stockTake = StockTake::latest('id')->firstOrFail();
        $response = $this->actingAs($this->admin)->putJson(route('stock-takes.update', $stockTake), [
            'items' => [[
                'product_id' => $product->id,
                'actual_stock' => 999,
                'checked' => false,
                ...$this->serialPayload($serials, ['ST-UPDATE-001']),
            ]],
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $item = $stockTake->fresh()->items()->firstOrFail();
        $checks = $item->serialChecks()->orderBy('id')->get();

        $this->assertSame(1, (int) $item->actual_stock);
        $this->assertSame(-1, (int) $item->diff_qty);
        $this->assertTrue((bool) $checks[0]->actual_present);
        $this->assertFalse((bool) $checks[1]->actual_present);
        $this->assertSame(2, (int) $product->fresh()->stock_quantity);
    }

    public function test_serial_draft_persistence_retains_verification_state(): void
    {
        $product = $this->product(true, 2);
        $serials = collect(['ST-DRAFT-001', 'ST-DRAFT-002'])
            ->map(fn (string $number) => $this->serial($product, $number));

        $this->actingAs($this->admin)->post(route('stock-takes.store'), [
            'status' => 'draft',
            'items' => [[
                'product_id' => $product->id,
                ...$this->serialPayload($serials, ['ST-DRAFT-001']),
            ]],
        ])->assertRedirect();

        $checks = StockTake::latest('id')->firstOrFail()->items()->firstOrFail()->serialChecks()->orderBy('id')->get();
        $this->assertCount(2, $checks);
        $this->assertTrue((bool) $checks[0]->actual_present);
        $this->assertFalse((bool) $checks[1]->actual_present);
        $this->assertSame(['ST-DRAFT-001', 'ST-DRAFT-002'], $checks->pluck('serial_number_snapshot')->all());
    }
}
