<?php

namespace Tests\Feature\POS;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItemSerial;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\Role;
use App\Models\SerialImei;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * STEP 24.6 — POS Quick Return.
 *
 * Verifies the two read-only support endpoints (returnable invoices search +
 * returnable items per invoice) plus the integration with the existing
 * OrderReturnController@store: the modal must NOT bypass any of the rules
 * (RR-08 / RR-11 / Step 23.2 serial-belongs-to-invoice / cancelled-invoice
 * guard / time-limit guard).
 */
class Step246PosQuickReturnTest extends TestCase
{
    use DatabaseTransactions;

    private function adminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin246'], [
            'display_name' => 'Admin',
            'permissions'  => ['*'],
            'is_system'    => true,
        ]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    private function userWith(array $perms): User
    {
        $role = Role::create([
            'name'         => 'role246-' . uniqid(),
            'display_name' => 'Test',
            'permissions'  => $perms,
            'is_system'    => false,
        ]);
        return User::factory()->create(['role_id' => $role->id]);
    }

    private function makeProduct(bool $hasSerial = false, int $stock = 10, float $cost = 100000): Product
    {
        $cat = Category::firstOrCreate(['name' => 'Cat 24.6']);
        return Product::create([
            'sku'                  => 'P246-' . uniqid(),
            'name'                 => 'Product 24.6',
            'cost_price'           => $cost,
            'retail_price'         => $cost * 2,
            'stock_quantity'       => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active'            => true,
            'has_serial'           => $hasSerial,
            'category_id'          => $cat->id,
        ]);
    }

    private function makeSerial(Product $product, string $status = 'in_stock'): SerialImei
    {
        return SerialImei::create([
            'product_id'    => $product->id,
            'serial_number' => 'SN246-' . uniqid(),
            'status'        => $status,
            'cost_price'    => $product->cost_price,
            'original_cost' => $product->cost_price,
        ]);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'code'        => 'KH246-' . uniqid(),
            'name'        => 'KH 24.6 ' . uniqid(),
            'phone'       => '0903' . rand(100000, 999999),
            'email'       => 'kh246-' . uniqid() . '@test.local',
            'debt_amount' => 0,
            'total_spent' => 0,
            'is_customer' => true,
        ]);
    }

    /** Sell a normal product through the real Invoice store endpoint. */
    private function sellNormal(User $admin, Customer $customer, Product $product, int $qty, float $price, float $paid): Invoice
    {
        $this->actingAs($admin)->post(route('invoices.store'), [
            'customer_id'    => $customer->id,
            'subtotal'       => $qty * $price,
            'discount'       => 0,
            'total'          => $qty * $price,
            'customer_paid'  => $paid,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => $qty,
                'price'      => $price,
                'discount'   => 0,
            ]],
        ]);
        return Invoice::where('customer_id', $customer->id)->latest('id')->first();
    }

    private function sellSerial(User $admin, Customer $customer, Product $product, array $serials, float $price, float $paid): Invoice
    {
        $qty = count($serials);
        $this->actingAs($admin)->post(route('invoices.store'), [
            'customer_id'    => $customer->id,
            'subtotal'       => $qty * $price,
            'discount'       => 0,
            'total'          => $qty * $price,
            'customer_paid'  => $paid,
            'payment_method' => 'cash',
            'items'          => [[
                'product_id' => $product->id,
                'quantity'   => $qty,
                'price'      => $price,
                'serial_ids' => array_map(fn ($s) => $s->id, $serials),
            ]],
        ]);
        return Invoice::where('customer_id', $customer->id)->latest('id')->first();
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-01: search endpoint requires returns.create
    // ─────────────────────────────────────────────────────────────────
    public function test_returnable_invoices_search_requires_returns_create_permission(): void
    {
        $user = $this->userWith(['returns.view']); // no returns.create
        $this->actingAs($user);
        $this->getJson('/api/pos/returnable-invoices?search=HD')->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-02: search by invoice code / customer name / phone
    // ─────────────────────────────────────────────────────────────────
    public function test_returnable_invoices_search_by_code_customer_phone(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(false, 20, 100000);
        $invoice = $this->sellNormal($admin, $customer, $product, 1, 200000, 200000);

        $this->actingAs($admin);

        $byCode = $this->getJson('/api/pos/returnable-invoices?search=' . $invoice->code);
        $byCode->assertOk();
        $this->assertContains($invoice->id, collect($byCode->json())->pluck('id')->all());

        $byName = $this->getJson('/api/pos/returnable-invoices?search=' . urlencode($customer->name));
        $this->assertContains($invoice->id, collect($byName->json())->pluck('id')->all());

        $byPhone = $this->getJson('/api/pos/returnable-invoices?search=' . $customer->phone);
        $this->assertContains($invoice->id, collect($byPhone->json())->pluck('id')->all());
    }

    public function test_returnable_invoices_search_by_product_sku(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(false, 20, 100000);
        $invoice = $this->sellNormal($admin, $customer, $product, 1, 200000, 200000);

        $res = $this->actingAs($admin)->getJson('/api/pos/returnable-invoices?search=' . urlencode($product->sku));

        $res->assertOk();
        $this->assertContains($invoice->id, collect($res->json())->pluck('id')->all());
    }

    public function test_returnable_invoices_search_by_serial_from_invoice_item_serial_link(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(true, 0, 5000000);
        $serial = $this->makeSerial($product);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);
        $invoice = $this->sellSerial($admin, $customer, $product, [$serial], 8000000, 8000000);

        $res = $this->actingAs($admin)
            ->getJson('/api/pos/returnable-invoices?search=' . urlencode($serial->serial_number));

        $res->assertOk();
        $this->assertContains($invoice->id, collect($res->json())->pluck('id')->all());
    }

    public function test_returnable_invoices_search_by_serial_invoice_id_fallback(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(true, 0, 5000000);
        $serial = $this->makeSerial($product);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);
        $invoice = $this->sellSerial($admin, $customer, $product, [$serial], 8000000, 8000000);
        InvoiceItemSerial::where('serial_imei_id', $serial->id)->delete();

        $this->assertSame((int) $invoice->id, (int) $serial->fresh()->invoice_id);

        $res = $this->actingAs($admin)
            ->getJson('/api/pos/returnable-invoices?search=' . urlencode($serial->serial_number));

        $res->assertOk();
        $this->assertContains($invoice->id, collect($res->json())->pluck('id')->all());
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-03: returnable items show remaining_qty after a partial return
    // ─────────────────────────────────────────────────────────────────
    public function test_returnable_items_show_remaining_qty_after_partial_return(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(false, 20, 100000);
        $invoice = $this->sellNormal($admin, $customer, $product, 3, 200000, 600000);
        $invoiceItem = $invoice->items()->first();

        // Return 1 of 3 first.
        $this->actingAs($admin)->post(route('returns.store'), [
            'invoice_id'       => $invoice->id,
            'customer_id'      => $customer->id,
            'subtotal'         => 200000,
            'total'            => 200000,
            'paid_to_customer' => 0,
            'items'            => [[
                'product_id'      => $product->id,
                'invoice_item_id' => $invoiceItem->id,
                'qty'             => 1,
                'price'           => 200000,
            ]],
        ])->assertSessionDoesntHaveErrors();

        $res = $this->actingAs($admin)->getJson("/api/pos/invoices/{$invoice->id}/returnable-items");
        $res->assertOk();
        $line = collect($res->json('items'))->firstWhere('product_id', $product->id);
        $this->assertNotNull($line);
        $this->assertEquals(3, (int) $line['sold_qty']);
        $this->assertEquals(1, (int) $line['already_returned_qty']);
        $this->assertEquals(2, (int) $line['remaining_qty']);
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-04: cancelled invoice — endpoint refuses
    // ─────────────────────────────────────────────────────────────────
    public function test_returnable_items_refuses_cancelled_invoice(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(false, 20, 100000);
        $invoice = $this->sellNormal($admin, $customer, $product, 1, 200000, 200000);

        $invoice->update(['status' => 'Đã hủy']);

        $this->actingAs($admin)
            ->getJson("/api/pos/invoices/{$invoice->id}/returnable-items")
            ->assertStatus(422);
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-05: full happy path — POST /returns with payload from POS modal
    // ─────────────────────────────────────────────────────────────────
    public function test_quick_return_normal_product_creates_return_and_restores_stock(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(false, 20, 100000);
        $invoice = $this->sellNormal($admin, $customer, $product, 3, 200000, 600000);
        $invoiceItem = $invoice->items()->first();

        $product->refresh();
        $stockBefore = (int) $product->stock_quantity;

        $payload = [
            'invoice_id'       => $invoice->id,
            'customer_id'      => $customer->id,
            'branch_id'        => $invoice->branch_id,
            'subtotal'         => 200000,
            'discount'         => 0,
            'fee'              => 0,
            'total'            => 200000,
            'paid_to_customer' => 200000,
            'note'             => 'Quick return TC-05',
            'items'            => [[
                'product_id'      => $product->id,
                'qty'             => 1,
                'price'           => 200000,
                'discount'        => 0,
                'invoice_item_id' => $invoiceItem->id,
                'serial_ids'      => [],
            ]],
        ];

        $this->actingAs($admin)->post(route('returns.store'), $payload)->assertSessionDoesntHaveErrors();

        $product->refresh();
        $this->assertEquals($stockBefore + 1, (int) $product->stock_quantity);

        $this->assertDatabaseHas('returns', [
            'invoice_id' => $invoice->id,
            'total'      => 200000,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-06: cannot return more than remaining qty
    // ─────────────────────────────────────────────────────────────────
    public function test_quick_return_cannot_exceed_remaining_qty(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(false, 20, 100000);
        $invoice = $this->sellNormal($admin, $customer, $product, 1, 200000, 200000);
        $invoiceItem = $invoice->items()->first();

        $countBefore = OrderReturn::count();

        $this->actingAs($admin)->post(route('returns.store'), [
            'invoice_id'       => $invoice->id,
            'customer_id'      => $customer->id,
            'subtotal'         => 400000,
            'total'            => 400000,
            'paid_to_customer' => 0,
            'items'            => [[
                'product_id'      => $product->id,
                'qty'             => 2, // sold only 1
                'price'           => 200000,
                'invoice_item_id' => $invoiceItem->id,
            ]],
        ])->assertSessionHasErrors('items');

        $this->assertEquals($countBefore, OrderReturn::count());
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-07: serial product — count(serial_ids) must equal qty
    // ─────────────────────────────────────────────────────────────────
    public function test_quick_return_serial_count_must_match_qty(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(true, 0, 5000000);
        $sA = $this->makeSerial($product);
        $sB = $this->makeSerial($product);
        $product->update(['stock_quantity' => 2, 'inventory_total_cost' => 10000000]);
        $invoice = $this->sellSerial($admin, $customer, $product, [$sA, $sB], 8000000, 16000000);
        $invoiceItem = $invoice->items()->first();

        $countBefore = OrderReturn::count();

        $this->actingAs($admin)->post(route('returns.store'), [
            'invoice_id'       => $invoice->id,
            'customer_id'      => $customer->id,
            'subtotal'         => 16000000,
            'total'            => 16000000,
            'paid_to_customer' => 0,
            'items'            => [[
                'product_id'      => $product->id,
                'qty'             => 2,             // says 2
                'price'           => 8000000,
                'invoice_item_id' => $invoiceItem->id,
                'serial_ids'      => [$sA->id],     // but only sends 1
            ]],
        ])->assertSessionHasErrors();

        $this->assertEquals($countBefore, OrderReturn::count());
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-08: serial must belong to the invoice being returned
    // ─────────────────────────────────────────────────────────────────
    public function test_quick_return_serial_must_belong_to_invoice(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();

        $product = $this->makeProduct(true, 0, 5000000);
        $sA = $this->makeSerial($product);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);
        $invoiceA = $this->sellSerial($admin, $customer, $product, [$sA], 8000000, 8000000);

        // Different invoice with its own serial.
        $product2 = $this->makeProduct(true, 0, 5000000);
        $sX = $this->makeSerial($product2);
        $product2->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);
        $invoiceB = $this->sellSerial($admin, $customer, $product2, [$sX], 8000000, 8000000);

        $invoiceItemA = $invoiceA->items()->first();
        $countBefore = OrderReturn::count();

        // Try to return on invoice A but pass a serial that belongs to invoice B.
        $this->actingAs($admin)->post(route('returns.store'), [
            'invoice_id'       => $invoiceA->id,
            'customer_id'      => $customer->id,
            'subtotal'         => 8000000,
            'total'            => 8000000,
            'paid_to_customer' => 0,
            'items'            => [[
                'product_id'      => $product->id,
                'qty'             => 1,
                'price'           => 8000000,
                'invoice_item_id' => $invoiceItemA->id,
                'serial_ids'      => [$sX->id],
            ]],
        ])->assertSessionHasErrors();

        $this->assertEquals($countBefore, OrderReturn::count());
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-09: serial happy path — serial flips to in_stock
    // ─────────────────────────────────────────────────────────────────
    public function test_quick_return_serial_success_marks_serial_in_stock(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(true, 0, 5000000);
        $sA = $this->makeSerial($product);
        $product->update(['stock_quantity' => 1, 'inventory_total_cost' => 5000000]);
        $invoice = $this->sellSerial($admin, $customer, $product, [$sA], 8000000, 8000000);
        $invoiceItem = $invoice->items()->first();

        $sA->refresh();
        $this->assertEquals('sold', $sA->status);

        $this->actingAs($admin)->post(route('returns.store'), [
            'invoice_id'       => $invoice->id,
            'customer_id'      => $customer->id,
            'subtotal'         => 8000000,
            'total'            => 8000000,
            'paid_to_customer' => 8000000,
            'items'            => [[
                'product_id'      => $product->id,
                'qty'             => 1,
                'price'           => 8000000,
                'invoice_item_id' => $invoiceItem->id,
                'serial_ids'      => [$sA->id],
            ]],
        ])->assertSessionDoesntHaveErrors();

        $sA->refresh();
        $this->assertEquals('in_stock', $sA->status);
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-10: returnable-invoices excludes cancelled invoices
    // ─────────────────────────────────────────────────────────────────
    public function test_returnable_invoices_excludes_cancelled(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(false, 5, 100000);
        $invoice = $this->sellNormal($admin, $customer, $product, 1, 200000, 200000);
        $invoice->update(['status' => 'Đã hủy']);

        $this->actingAs($admin);
        $res = $this->getJson('/api/pos/returnable-invoices?search=' . $invoice->code);
        $res->assertOk();
        $ids = collect($res->json())->pluck('id')->all();
        $this->assertNotContains($invoice->id, $ids);
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-11: serials list is filtered to the target invoice only
    // ─────────────────────────────────────────────────────────────────
    public function test_returnable_items_only_lists_serials_for_that_invoice(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(true, 0, 5000000);
        $sA = $this->makeSerial($product);
        $sB = $this->makeSerial($product); // separate sale
        $product->update(['stock_quantity' => 2, 'inventory_total_cost' => 10000000]);

        $invoiceA = $this->sellSerial($admin, $customer, $product, [$sA], 8000000, 8000000);
        $invoiceB = $this->sellSerial($admin, $customer, $product, [$sB], 8000000, 8000000);

        $this->actingAs($admin);
        $res = $this->getJson("/api/pos/invoices/{$invoiceA->id}/returnable-items");
        $res->assertOk();

        $line = collect($res->json('items'))->firstWhere('product_id', $product->id);
        $this->assertNotNull($line);
        $serialIds = collect($line['serials'])->pluck('id')->all();
        $this->assertContains($sA->id, $serialIds);
        $this->assertNotContains($sB->id, $serialIds, 'Serial of a different invoice must not appear.');
    }

    // ─────────────────────────────────────────────────────────────────
    // TC-12: routes are wired — endpoints exist and are not 404
    // ─────────────────────────────────────────────────────────────────
    public function test_pos_quick_return_routes_are_registered(): void
    {
        $admin = $this->adminUser();
        $this->actingAs($admin);
        // Even with empty search, the search endpoint must respond 200 (not 404).
        $this->getJson('/api/pos/returnable-invoices')->assertStatus(200);
    }
}
