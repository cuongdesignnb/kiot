<?php

namespace Tests\Feature\Products;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use App\Models\Role;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Category;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\OrderReturn;
use App\Models\ReturnItem;
use App\Models\StockTake;
use App\Models\StockTransfer;
use App\Models\Damage;
use App\Models\SerialImei;
use App\Services\DocumentLinkResolver;

/**
 * STEP 24.7 — Stock card "Mở phiếu" must resolve to the right source voucher
 * for every doc_type. Tests the DocumentLinkResolver service directly and
 * via the /products/document-detail endpoint.
 */
class Step247StockCardDocumentResolverTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 247',
            'email'    => 'admin-247-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null, // null role_id => isAdmin() = true
        ]);
    }

    private function userWith(array $perms): User
    {
        $role = Role::create([
            'name'         => 'r247-' . uniqid(),
            'display_name' => 'Test 247',
            'permissions'  => $perms,
            'is_system'    => false,
        ]);
        return User::create([
            'name'     => 'User 247 ' . uniqid(),
            'email'    => 'u247-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => $role->id,
        ]);
    }

    private function makeProduct(): Product
    {
        $cat = Category::firstOrCreate(['name' => 'Cat 247']);
        return Product::create([
            'sku'                  => 'P247-' . uniqid(),
            'name'                 => 'Product 247',
            'cost_price'           => 100000,
            'retail_price'         => 200000,
            'stock_quantity'       => 10,
            'inventory_total_cost' => 1000000,
            'is_active'            => true,
            'has_serial'           => false,
            'category_id'          => $cat->id,
        ]);
    }

    // ────────── Resolver unit tests ──────────

    public function test_resolver_returns_invoice_show_url(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $cust = Customer::create(['code' => 'KH-' . uniqid(), 'name' => 'KH', 'is_customer' => true]);
        $invoice = Invoice::create([
            'code'       => 'HD-247-' . uniqid(),
            'customer_id'=> $cust->id,
            'subtotal'   => 0,
            'total'      => 0,
            'status'     => 'Hoàn thành',
        ]);

        $out = app(DocumentLinkResolver::class)->resolve('invoice', (int) $invoice->id);
        $this->assertTrue($out['can_open']);
        $this->assertSame($invoice->code, $out['code']);
        $this->assertStringContainsString('/invoices/' . $invoice->id . '/show', $out['open_url']);
        $this->assertStringNotContainsString('/print', $out['open_url']);
        $this->assertStringContainsString('/print', $out['print_url']);
    }

    public function test_resolver_returns_purchase_show_url(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $supplier = Customer::create([
            'code' => 'NCC-' . uniqid(),
            'name' => 'NCC',
            'is_supplier' => true,
        ]);
        $purchase = Purchase::create([
            'code'         => 'PN-247-' . uniqid(),
            'supplier_id'  => $supplier->id,
            'total_amount' => 0,
            'status'       => 'Hoàn thành',
        ]);

        $out = app(DocumentLinkResolver::class)->resolve('purchase', (int) $purchase->id);
        $this->assertTrue($out['can_open']);
        $this->assertStringContainsString('/purchases/' . $purchase->id, $out['open_url']);
        $this->assertStringNotContainsString('/print', $out['open_url']);
    }

    public function test_resolver_returns_return_show_url(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $cust = Customer::create(['code' => 'KH-' . uniqid(), 'name' => 'KH', 'is_customer' => true]);
        $orderReturn = OrderReturn::create([
            'code'       => 'TH-247-' . uniqid(),
            'customer_id'=> $cust->id,
            'subtotal'   => 0,
            'total'      => 0,
            'status'     => 'Đã trả',
        ]);

        $out = app(DocumentLinkResolver::class)->resolve('return', (int) $orderReturn->id);
        $this->assertTrue($out['can_open']);
        $this->assertStringContainsString('/returns/' . $orderReturn->id . '/show', $out['open_url']);
    }

    public function test_resolver_returns_stock_take_show_url(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $branch = Branch::create(['name' => 'Br247-' . uniqid()]);
        $st = StockTake::create([
            'code'      => 'KK-247-' . uniqid(),
            'branch_id' => $branch->id,
            'status'    => 'balanced',
        ]);
        $out = app(DocumentLinkResolver::class)->resolve('stock_take', (int) $st->id);
        $this->assertTrue($out['can_open']);
        $this->assertStringContainsString('/stock-takes/' . $st->id, $out['open_url']);
    }

    public function test_resolver_returns_stock_transfer_show_url(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $b1 = Branch::create(['name' => 'B247a-' . uniqid()]);
        $b2 = Branch::create(['name' => 'B247b-' . uniqid()]);
        $tr = StockTransfer::create([
            'code'           => 'CHK-247-' . uniqid(),
            'from_branch_id' => $b1->id,
            'to_branch_id'   => $b2->id,
            'status'         => 'transferring',
        ]);
        $out = app(DocumentLinkResolver::class)->resolve('transfer', (int) $tr->id);
        $this->assertTrue($out['can_open']);
        $this->assertStringContainsString('/stock-transfers/' . $tr->id . '/show', $out['open_url']);
    }

    public function test_resolver_returns_damage_show_url(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $branch = Branch::create(['name' => 'BD247-' . uniqid()]);
        $damage = Damage::create([
            'code'      => 'XH-247-' . uniqid(),
            'branch_id' => $branch->id,
            'status'    => 'completed',
        ]);
        $out = app(DocumentLinkResolver::class)->resolve('damage', (int) $damage->id);
        $this->assertTrue($out['can_open']);
        $this->assertStringContainsString('/damages/' . $damage->id . '/show', $out['open_url']);
    }

    public function test_resolver_unknown_doc_type_returns_can_open_false(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $out = app(DocumentLinkResolver::class)->resolve('unknown_thing', 1);
        $this->assertFalse($out['can_open']);
        $this->assertNotEmpty($out['missing_reason']);
        $this->assertNull($out['open_url']);
    }

    public function test_resolver_missing_doc_returns_can_open_false(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $out = app(DocumentLinkResolver::class)->resolve('invoice', 999999999);
        $this->assertFalse($out['can_open']);
        $this->assertStringContainsString('Không tìm thấy', $out['missing_reason']);
        $this->assertNull($out['open_url']);
    }

    public function test_resolver_hides_open_url_when_user_lacks_permission(): void
    {
        $user = $this->userWith(['products.view']); // no invoices.view
        $this->actingAs($user);

        $cust = Customer::create(['code' => 'KH-' . uniqid(), 'name' => 'KH', 'is_customer' => true]);
        $invoice = Invoice::create([
            'code'       => 'HD-247p-' . uniqid(),
            'customer_id'=> $cust->id,
            'subtotal'   => 0,
            'total'      => 0,
            'status'     => 'Hoàn thành',
        ]);

        $out = app(DocumentLinkResolver::class)->resolve('invoice', (int) $invoice->id);
        $this->assertFalse($out['can_open']);
        $this->assertNull($out['open_url'], 'URL must not leak to users without permission');
        $this->assertNull($out['print_url']);
        $this->assertStringContainsString('quyền', $out['missing_reason']);
    }

    // ────────── Endpoint integration ──────────

    public function test_document_detail_endpoint_includes_source_document_for_invoice(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $product = $this->makeProduct();
        $cust = Customer::create(['code' => 'KH-' . uniqid(), 'name' => 'KH', 'is_customer' => true]);
        $invoice = Invoice::create([
            'code'       => 'HD-247e-' . uniqid(),
            'customer_id'=> $cust->id,
            'subtotal'   => 200000,
            'total'      => 200000,
            'status'     => 'Hoàn thành',
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity'   => 1,
            'price'      => 200000,
            'subtotal'   => 200000,
        ]);

        $res = $this->getJson('/products/document-detail?type=invoice&id=' . $invoice->id);
        $res->assertOk();
        $res->assertJsonPath('source_document.can_open', true);
        $res->assertJsonPath('source_document.code', $invoice->code);
        $this->assertStringContainsString('/invoices/' . $invoice->id, $res->json('source_document.open_url'));
    }


    public function testDocumentDetailInvoiceIncludesItemSerialsAndSerialCount(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $product = $this->makeProduct();
        $product->update(['has_serial' => true]);
        $cust = Customer::create(['code' => 'KH-' . uniqid(), 'name' => 'KH', 'is_customer' => true]);
        $invoice = Invoice::create([
            'code'       => 'HD-247s-' . uniqid(),
            'customer_id'=> $cust->id,
            'subtotal'   => 200000,
            'total'      => 200000,
            'status'     => 'Hoàn thành',
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity'   => 2,
            'price'      => 100000,
            'subtotal'   => 200000,
        ]);

        $serial = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'IMEI-247-' . uniqid(),
            'status' => 'sold',
            'invoice_id' => $invoice->id,
            'cost_price' => 100000,
        ]);

        InvoiceItemSerial::create([
            'invoice_item_id' => $item->id,
            'serial_imei_id' => $serial->id,
            'serial_number' => $serial->serial_number,
            'cost_price' => 100000,
        ]);

        $res = $this->getJson('/products/document-detail?type=invoice&id=' . $invoice->id);
        $res->assertOk();
        $res->assertJsonPath('items.0.serials.0.serial_number', $serial->serial_number);
        $res->assertJsonPath('items.0.serial_count', 1);
        $res->assertJsonPath('items.0.serials.0.legacy', false);
    }

    public function testDocumentDetailInvoiceFallsBackToLegacySerialStringWhenSnapshotMissing(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('invoice_items', 'serial')) {
            $this->markTestSkipped('Current test DB schema has no invoice_items.serial column for legacy fallback verification.');
        }

        $admin = $this->admin();
        $this->actingAs($admin);

        $product = $this->makeProduct();
        $product->update(['has_serial' => true]);
        $cust = Customer::create(['code' => 'KH-' . uniqid(), 'name' => 'KH', 'is_customer' => true]);
        $invoice = Invoice::create([
            'code'       => 'HD-247l-' . uniqid(),
            'customer_id'=> $cust->id,
            'subtotal'   => 200000,
            'total'      => 200000,
            'status'     => 'Hoàn thành',
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity'   => 2,
            'price'      => 100000,
            'subtotal'   => 200000,
            'serial'     => 'SER-LEGACY-1, SER-LEGACY-2',
        ]);

        $res = $this->getJson('/products/document-detail?type=invoice&id=' . $invoice->id);
        $res->assertOk();
        $res->assertJsonPath('items.0.serial_count', 2);
        $res->assertJsonPath('items.0.serials.0.serial_number', 'SER-LEGACY-1');
        $res->assertJsonPath('items.0.serials.1.serial_number', 'SER-LEGACY-2');
        $res->assertJsonPath('items.0.serials.0.legacy', true);
    }

    public function test_inventory_card_emits_doc_type_and_doc_id(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $product = $this->makeProduct();
        $cust = Customer::create(['code' => 'KH-' . uniqid(), 'name' => 'KH', 'is_customer' => true]);
        $invoice = Invoice::create([
            'code'       => 'HD-247c-' . uniqid(),
            'customer_id'=> $cust->id,
            'subtotal'   => 100000,
            'total'      => 100000,
            'status'     => 'Hoàn thành',
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity'   => 1,
            'price'      => 100000,
            'subtotal'   => 100000,
        ]);

        $res = $this->getJson('/products/' . $product->id . '/inventory-card');
        $res->assertOk();
        $rows = $res->json();
        $this->assertIsArray($rows);
        $matched = collect($rows)->firstWhere('code', $invoice->code);
        $this->assertNotNull($matched, 'Inventory card must include the invoice row.');
        $this->assertSame('invoice', $matched['doc_type']);
        $this->assertSame((int) $invoice->id, (int) $matched['doc_id']);
    }

    public function test_document_detail_does_not_mutate_stock(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $product = $this->makeProduct();
        $stockBefore = (int) $product->fresh()->stock_quantity;

        $cust = Customer::create(['code' => 'KH-' . uniqid(), 'name' => 'KH', 'is_customer' => true]);
        $invoice = Invoice::create([
            'code'       => 'HD-247m-' . uniqid(),
            'customer_id'=> $cust->id,
            'subtotal'   => 100000,
            'total'      => 100000,
            'status'     => 'Hoàn thành',
        ]);

        // Hit the endpoint multiple times.
        $this->getJson('/products/document-detail?type=invoice&id=' . $invoice->id)->assertOk();
        $this->getJson('/products/document-detail?type=invoice&id=' . $invoice->id)->assertOk();
        $this->getJson('/products/document-detail?type=invoice&id=' . $invoice->id)->assertOk();

        $this->assertSame($stockBefore, (int) $product->fresh()->stock_quantity);
    }

    public function test_show_routes_redirect_to_index_with_search(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $b1 = Branch::create(['name' => 'BR247-' . uniqid()]);
        $b2 = Branch::create(['name' => 'BR247-' . uniqid()]);
        $tr = StockTransfer::create([
            'code'           => 'CHK-247z-' . uniqid(),
            'from_branch_id' => $b1->id,
            'to_branch_id'   => $b2->id,
            'status'         => 'transferring',
        ]);
        $this->get(route('stock-transfers.show', $tr))
            ->assertRedirect(route('stock-transfers.index', ['search' => $tr->code]));

        $damage = Damage::create([
            'code'      => 'XH-247z-' . uniqid(),
            'branch_id' => $b1->id,
            'status'    => 'completed',
        ]);
        $this->get(route('damages.show', $damage))
            ->assertRedirect(route('damages.index', ['search' => $damage->code]));
    }
}
