<?php

namespace Tests\Feature\Purchase;

use Tests\TestCase;
use App\Models\User;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\SerialImei;
use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * HOTFIX: Serial/IMEI lookup từ màn Trả hàng nhập nhanh.
 *
 * Test cases:
 * 1. Lookup serial in_stock có purchase → trả matches + return_url.
 * 2. Lookup serial sold → blocked_matches, không return_url.
 * 3. Lookup serial returned → blocked_matches.
 * 4. Lookup serial không có purchase_id → blocked_matches.
 * 5. Preselect serial hợp lệ ở màn create → props đúng.
 * 6. Preselect serial thuộc purchase khác → preselectWarning.
 * 7. Lookup with supplier filter.
 */
class HOTFIXPurchaseReturnSerialLookupTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Customer $supplier;
    private Product $product;
    private Purchase $purchase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Serial Lookup',
            'email' => 'serial-lookup-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);

        $this->supplier = Customer::create([
            'code' => 'NCC-SL-' . uniqid(),
            'name' => 'NCC Test Serial Lookup',
            'phone' => '0985' . rand(100000, 999999),
            'is_supplier' => true,
            'is_customer' => false,
            'supplier_debt_amount' => 1000000,
            'total_bought' => 1000000,
        ]);

        $category = Category::firstOrCreate(['name' => 'HOTFIX Serial Lookup']);

        $this->product = Product::create([
            'name' => 'Laptop Test Serial',
            'sku' => 'LAP-SL-' . uniqid(),
            'has_serial' => true,
            'stock_quantity' => 5,
            'cost_price' => 10000000,
            'retail_price' => 15000000,
            'inventory_total_cost' => 50000000,
            'is_active' => true,
            'category_id' => $category->id,
        ]);

        $this->purchase = Purchase::create([
            'code' => 'PNSL' . time() . rand(100, 999),
            'supplier_id' => $this->supplier->id,
            'user_id' => $this->admin->id,
            'total_amount' => 10000000,
            'paid_amount' => 0,
            'debt_amount' => 10000000,
            'status' => 'completed',
            'purchase_date' => now(),
        ]);

        PurchaseItem::create([
            'purchase_id' => $this->purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'product_code' => $this->product->sku,
            'quantity' => 1,
            'price' => 10000000,
            'subtotal' => 10000000,
            'unit_cost_allocated' => 10000000,
        ]);
    }

    // ──────────────────────────────────────────────
    // Case 1: serial in_stock + có purchase → match
    // ──────────────────────────────────────────────
    public function test_serial_lookup_finds_in_stock_serial_with_purchase(): void
    {
        $serial = SerialImei::create([
            'product_id' => $this->product->id,
            'serial_number' => 'SN-LOOKUP-' . uniqid(),
            'status' => 'in_stock',
            'purchase_id' => $this->purchase->id,
            'cost_price' => 10000000,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/purchase-returns/serial-lookup?serial=' . $serial->serial_number);

        $response->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonCount(1, 'matches')
            ->assertJsonPath('matches.0.serial_id', $serial->id)
            ->assertJsonPath('matches.0.serial_number', $serial->serial_number)
            ->assertJsonPath('matches.0.product_id', $this->product->id)
            ->assertJsonPath('matches.0.purchase_id', $this->purchase->id);

        // Must have return_url containing purchase_id and serial_id
        $returnUrl = $response->json('matches.0.return_url');
        $this->assertStringContainsString('/purchase-returns/create', $returnUrl);
        $this->assertStringContainsString('purchase_id=' . $this->purchase->id, $returnUrl);
        $this->assertStringContainsString('serial_id=' . $serial->id, $returnUrl);
    }

    // ──────────────────────────────────────────────
    // Case 2: serial sold → blocked
    // ──────────────────────────────────────────────
    public function test_serial_lookup_does_not_return_sold_serial_as_match(): void
    {
        $serial = SerialImei::create([
            'product_id' => $this->product->id,
            'serial_number' => 'SN-SOLD-' . uniqid(),
            'status' => 'sold',
            'purchase_id' => $this->purchase->id,
            'cost_price' => 10000000,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/purchase-returns/serial-lookup?serial=' . $serial->serial_number);

        $response->assertOk()
            ->assertJsonCount(0, 'matches')
            ->assertJsonCount(1, 'blocked_matches')
            ->assertJsonPath('blocked_matches.0.serial_number', $serial->serial_number);

        // Blocked matches must NOT have return_url
        $this->assertArrayNotHasKey('return_url', $response->json('blocked_matches.0'));
    }

    // ──────────────────────────────────────────────
    // Case 3: serial returned → blocked
    // ──────────────────────────────────────────────
    public function test_serial_lookup_does_not_return_returned_serial_as_match(): void
    {
        $serial = SerialImei::create([
            'product_id' => $this->product->id,
            'serial_number' => 'SN-RETURNED-' . uniqid(),
            'status' => 'returned',
            'purchase_id' => $this->purchase->id,
            'cost_price' => 10000000,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/purchase-returns/serial-lookup?serial=' . $serial->serial_number);

        $response->assertOk()
            ->assertJsonCount(0, 'matches')
            ->assertJsonCount(1, 'blocked_matches');

        $reason = $response->json('blocked_matches.0.reason');
        $this->assertNotEmpty($reason);
    }

    // ──────────────────────────────────────────────
    // Case 4: serial không có purchase_id → blocked
    // ──────────────────────────────────────────────
    public function test_serial_lookup_blocks_serial_without_purchase(): void
    {
        $serial = SerialImei::create([
            'product_id' => $this->product->id,
            'serial_number' => 'SN-NOPURCH-' . uniqid(),
            'status' => 'in_stock',
            'purchase_id' => null,
            'cost_price' => 10000000,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/purchase-returns/serial-lookup?serial=' . $serial->serial_number);

        $response->assertOk()
            ->assertJsonCount(0, 'matches')
            ->assertJsonCount(1, 'blocked_matches')
            ->assertJsonPath('blocked_matches.0.reason', 'Serial này không có phiếu nhập gốc.');
    }

    // ──────────────────────────────────────────────
    // Case 5: preselect serial hợp lệ ở /purchase-returns/create
    // ──────────────────────────────────────────────
    public function test_purchase_return_create_page_accepts_valid_preselect_serial(): void
    {
        $serial = SerialImei::create([
            'product_id' => $this->product->id,
            'serial_number' => 'SN-PRESELECT-' . uniqid(),
            'status' => 'in_stock',
            'purchase_id' => $this->purchase->id,
            'cost_price' => 10000000,
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/purchase-returns/create?purchase_id=' . $this->purchase->id . '&serial_id=' . $serial->id);

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PurchaseReturns/Create')
                ->where('preselectSerialId', $serial->id)
                ->where('preselectProductId', $this->product->id)
                ->where('preselectWarning', null)
            );
    }

    // ──────────────────────────────────────────────
    // Case 6: preselect serial thuộc purchase khác → warning
    // ──────────────────────────────────────────────
    public function test_purchase_return_create_page_rejects_preselect_serial_from_other_purchase(): void
    {
        $otherPurchase = Purchase::create([
            'code' => 'PNSL-OTHER-' . time() . rand(100, 999),
            'supplier_id' => $this->supplier->id,
            'user_id' => $this->admin->id,
            'total_amount' => 5000000,
            'paid_amount' => 0,
            'debt_amount' => 5000000,
            'status' => 'completed',
            'purchase_date' => now(),
        ]);

        PurchaseItem::create([
            'purchase_id' => $otherPurchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'product_code' => $this->product->sku,
            'quantity' => 1,
            'price' => 5000000,
            'subtotal' => 5000000,
            'unit_cost_allocated' => 5000000,
        ]);

        $serial = SerialImei::create([
            'product_id' => $this->product->id,
            'serial_number' => 'SN-OTHERPURCH-' . uniqid(),
            'status' => 'in_stock',
            'purchase_id' => $otherPurchase->id,
            'cost_price' => 5000000,
        ]);

        // Request create with purchase A but serial from purchase B
        $response = $this->actingAs($this->admin)
            ->get('/purchase-returns/create?purchase_id=' . $this->purchase->id . '&serial_id=' . $serial->id);

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PurchaseReturns/Create')
                ->where('preselectSerialId', null)
                ->where('preselectWarning', 'Serial không thuộc phiếu nhập này.')
            );
    }

    // ──────────────────────────────────────────────
    // Case 7: lookup with supplier filter
    // ──────────────────────────────────────────────
    public function test_serial_lookup_filters_by_supplier_id(): void
    {
        $serial = SerialImei::create([
            'product_id' => $this->product->id,
            'serial_number' => 'SN-FILTER-NCC-' . uniqid(),
            'status' => 'in_stock',
            'purchase_id' => $this->purchase->id,
            'cost_price' => 10000000,
        ]);

        // With correct supplier → found
        $response = $this->actingAs($this->admin)
            ->getJson('/purchase-returns/serial-lookup?serial=' . $serial->serial_number . '&supplier_id=' . $this->supplier->id);

        $response->assertOk()
            ->assertJsonCount(1, 'matches');

        // With wrong supplier → not found
        $otherSupplier = Customer::create([
            'code' => 'NCC-OTHER-' . uniqid(),
            'name' => 'NCC Khác',
            'is_supplier' => true,
            'is_customer' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/purchase-returns/serial-lookup?serial=' . $serial->serial_number . '&supplier_id=' . $otherSupplier->id);

        $response->assertOk()
            ->assertJsonCount(0, 'matches')
            ->assertJsonCount(0, 'blocked_matches');
    }
}
