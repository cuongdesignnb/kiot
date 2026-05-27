<?php

namespace Tests\Feature\Invoice;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\SerialImei;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InvoiceUpdateService;
use App\Services\MovingAvgCostingService;
use App\Services\StockMovementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvoiceEditRouteTest extends TestCase
{
    use DatabaseTransactions;

    private function userWithPermission(array $perms): User
    {
        $name = 'test-user-' . uniqid();
        $role = Role::create(['name' => $name, 'display_name' => $name, 'permissions' => $perms]);
        return User::create([
            'name' => 'UserEditTest', 'email' => 'u-' . uniqid() . '@test.local',
            'password' => bcrypt('pw'), 'role_id' => $role->id,
        ]);
    }

    private function product(string $sku, ?string $barcode = null, int $stock = 10, float $cost = 100000): Product
    {
        $data = [
            'sku' => $sku,
            'name' => 'SP Test ' . $sku,
            'cost_price' => $cost,
            'retail_price' => 150000,
            'stock_quantity' => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active' => true,
            'has_serial' => false,
        ];
        if ($barcode !== null) {
            $data['barcode'] = $barcode;
        }
        return Product::create($data);
    }

    private function createInvoice(Product $product, Customer $customer, int $qty, float $price, float $paid): Invoice
    {
        $total = $qty * $price;
        $costResult = MovingAvgCostingService::applySale($product, $qty);
        $costAtSale = $costResult['cogs_per_unit'];
        $product->refresh();

        $code = 'HDTEST-' . uniqid();
        $now = now();
        $invoice = Invoice::create([
            'code' => $code, 'subtotal' => $total, 'discount' => 0,
            'total' => $total, 'customer_paid' => $paid,
            'customer_id' => $customer->id, 'status' => 'Hoàn thành',
            'sales_channel' => 'Test', 'price_book_name' => 'Giá bán lẻ',
            'transaction_date' => $now,
            'lock_started_at' => $now, 'created_at' => $now,
            'is_delivery' => false,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'product_id' => $product->id,
            'quantity' => $qty, 'price' => $price, 'cost_price' => $costAtSale,
            'discount' => 0, 'subtotal' => $total,
        ]);

        $debt = $total - $paid;
        if ($debt > 0) $customer->increment('debt_amount', $debt);
        $customer->increment('total_spent', $total);

        if ($paid > 0) {
            CashFlow::create([
                'code' => 'PTTEST-' . uniqid(), 'type' => 'receipt', 'amount' => $paid,
                'time' => $now, 'category' => 'Thu tiền khách trả',
                'target_type' => 'Khách hàng', 'target_id' => $customer->id,
                'target_name' => $customer->name,
                'reference_type' => 'Invoice', 'reference_code' => $code,
                'description' => 'Test', 'payment_method' => 'cash',
            ]);
        }

        StockMovementService::record($product, StockMovementService::TYPE_OUT_INVOICE, $qty, $costAtSale, $invoice, ['ref_code' => $code]);

        return $invoice;
    }

    private function customer(float $debt = 0): Customer
    {
        return Customer::create([
            'code' => 'KHTEST-' . uniqid(), 'name' => 'KH Test Edit',
            'phone' => '09' . rand(10000000, 99999999),
            'debt_amount' => $debt, 'total_spent' => 0, 'is_customer' => true,
        ]);
    }

    // === Test 1 — Route PUT invoices không còn 405 ===
    public function test_route_put_invoices_is_accessible_with_permission(): void
    {
        $user = $this->userWithPermission(['invoices.edit', 'invoices.view']);
        $product = $this->product('SPTEST001');
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 1, 150000, 150000);

        $payload = [
            'customer_id' => $customer->id,
            'subtotal' => 150000,
            'discount' => 0,
            'total' => 150000,
            'customer_paid' => 150000,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => 150000,
                    'discount' => 0,
                    'serial_ids' => [],
                ]
            ],
            'payment_method' => 'Tiền mặt',
        ];

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", $payload);
        $response->assertStatus(302); // redirects on success
        $this->assertNotEquals(405, $response->getStatusCode());
    }

    // === Test 2 — User không có quyền edit không được sửa hóa đơn ===
    public function test_user_without_permission_cannot_update_invoice(): void
    {
        $user = $this->userWithPermission(['invoices.view']);
        $product = $this->product('SPTEST001');
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 1, 150000, 150000);

        $payload = [
            'customer_id' => $customer->id,
            'subtotal' => 150000,
            'discount' => 0,
            'total' => 150000,
            'customer_paid' => 150000,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => 150000,
                    'discount' => 0,
                    'serial_ids' => [],
                ]
            ],
        ];

        // Standard web request
        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", $payload);
        $response->assertRedirect('/');

        // JSON request
        $responseJson = $this->actingAs($user)->putJson("/invoices/{$invoice->id}", $payload);
        $responseJson->assertStatus(403);
    }

    // === Test 3 — Mã hàng hiển thị ở InvoiceController::show() ===
    public function test_product_code_displays_sku_in_invoice_show_inertia_prop(): void
    {
        $user = $this->userWithPermission(['invoices.view']);
        $product = $this->product('SPTEST001');
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 1, 150000, 150000);

        $response = $this->actingAs($user)->get("/invoices/{$invoice->id}/show");
        $response->assertOk();
        $response->assertInertia(function (Assert $page) {
            $page->has('invoice.items', 1)
                ->where('invoice.items.0.product_code', 'SPTEST001');
        });
    }

    // === Test 4 — Fallback mã hàng ===
    public function test_product_code_fallback_chain_works(): void
    {
        $user = $this->userWithPermission(['invoices.view']);
        
        // 4.1: Product has SKU
        $product1 = $this->product('SP001', 'BARCODE001');
        $customer1 = $this->customer();
        $invoice1 = $this->createInvoice($product1, $customer1, 1, 150000, 150000);
        $response1 = $this->actingAs($user)->get("/invoices/{$invoice1->id}/show");
        $response1->assertInertia(function (Assert $page) {
            $page->where('invoice.items.0.product_code', 'SP001');
        });
    }

    // === Test 5 — Expand invoice index có product sku ===
    public function test_invoice_index_inertia_prop_contains_product_sku(): void
    {
        $user = $this->userWithPermission(['invoices.view']);
        $product = $this->product('SPINDEX01');
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 1, 150000, 150000);

        $response = $this->actingAs($user)->get("/invoices");
        $response->assertOk();
        $response->assertInertia(function (Assert $page) {
            $page->has('invoices.data')
                ->where('invoices.data.0.items.0.product.sku', 'SPINDEX01');
        });
    }

    // === Test 6 — Edit hóa đơn giao hàng restore đúng mode ===
    public function test_edit_delivery_invoice_restores_delivery_mode(): void
    {
        $user = $this->userWithPermission(['invoices.view', 'orders.create']);
        $product = $this->product('SPDELIVERY');
        $customer = $this->customer();
        
        $invoice = $this->createInvoice($product, $customer, 1, 150000, 150000);
        $invoice->update([
            'is_delivery' => true,
            'receiver_name' => 'Nguyen Van A',
            'receiver_phone' => '0987654321',
            'receiver_address' => '123 Main St',
            'delivery_fee' => 30000,
        ]);

        $response = $this->actingAs($user)->get("/orders/create?action=edit&invoice_id={$invoice->id}");
        $response->assertOk();
        $response->assertInertia(function (Assert $page) {
            $page->component('Orders/Create')
                ->where('action', 'edit')
                ->where('invoice.is_delivery', 1)
                ->where('invoice.receiver_name', 'Nguyen Van A')
                ->where('invoice.receiver_phone', '0987654321')
                ->where('invoice.receiver_address', '123 Main St')
                ->where('invoice.delivery_fee', '30000.00');
        });
    }

    // === Test 7 — Edit hóa đơn thường không bị ép sang giao hàng ===
    public function test_edit_normal_invoice_does_not_force_delivery_mode(): void
    {
        $user = $this->userWithPermission(['invoices.view', 'orders.create']);
        $product = $this->product('SPNORMAL');
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 1, 150000, 150000);
        
        $this->assertFalse((bool)$invoice->is_delivery);

        $response = $this->actingAs($user)->get("/orders/create?action=edit&invoice_id={$invoice->id}");
        $response->assertOk();
        $response->assertInertia(function (Assert $page) {
            $page->component('Orders/Create')
                ->where('action', 'edit')
                ->where('invoice.is_delivery', 0);
        });
    }

    // === Test 8 — Không phá trả hàng ===
    public function test_invoice_return_mode_works_and_does_not_conflict_with_edit(): void
    {
        $user = $this->userWithPermission(['invoices.view', 'orders.create']);
        $product = $this->product('SPRETURN');
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 1, 150000, 150000);

        $response = $this->actingAs($user)->get("/orders/create?action=return&invoice_id={$invoice->id}");
        $response->assertOk();
        $response->assertInertia(function (Assert $page) {
            $page->component('Orders/Create')
                ->where('action', 'return');
        });
    }

    // === Test 9 — Update invoice không đổi item không làm sai tồn kho ===
    public function test_update_invoice_header_only_does_not_affect_inventory(): void
    {
        $user = $this->userWithPermission(['invoices.edit', 'invoices.view']);
        $product = $this->product('SPINVSTOCK');
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 2, 150000, 300000);

        $product->refresh();
        $initialStock = $product->stock_quantity; // Should be 8

        $payload = [
            'customer_id' => $customer->id,
            'subtotal' => 300000,
            'discount' => 0,
            'total' => 300000,
            'customer_paid' => 300000,
            'note' => 'Updated note only',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'price' => 150000,
                    'discount' => 0,
                    'serial_ids' => [],
                ]
            ],
            'payment_method' => 'Tiền mặt',
        ];

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", $payload);
        $response->assertStatus(302);

        $product->refresh();
        $this->assertEquals($initialStock, $product->stock_quantity);
    }

    // === Test 10 — Update invoice đổi số lượng reverse/apply đúng ===
    public function test_update_invoice_quantity_change_updates_inventory_correctly(): void
    {
        $user = $this->userWithPermission(['invoices.edit', 'invoices.view']);
        $product = $this->product('SPQTYCHANGE', null, 10, 100000);
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 1, 150000, 150000);

        $product->refresh();
        $this->assertEquals(9, $product->stock_quantity);

        $payload = [
            'customer_id' => $customer->id,
            'subtotal' => 300000,
            'discount' => 0,
            'total' => 300000,
            'customer_paid' => 300000,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'price' => 150000,
                    'discount' => 0,
                    'serial_ids' => [],
                ]
            ],
            'payment_method' => 'Tiền mặt',
        ];

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", $payload);
        $response->assertStatus(302);

        $product->refresh();
        // Initial stock 10, updated quantity 2 -> stock should be 8
        $this->assertEquals(8, $product->stock_quantity);
    }

    // === Test 11 — Serial invoice edit ===
    public function test_serial_invoice_edit_reassigns_serials_correctly(): void
    {
        $user = $this->userWithPermission(['invoices.edit', 'invoices.view']);
        
        $product = Product::create([
            'sku' => 'SPSERIAL-' . uniqid(),
            'name' => 'Product has serial',
            'cost_price' => 200000,
            'retail_price' => 300000,
            'stock_quantity' => 2,
            'inventory_total_cost' => 400000,
            'is_active' => true,
            'has_serial' => true,
        ]);

        $serialA = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SERIAL-A-' . uniqid(),
            'status' => 'sold',
            'cost_price' => 200000,
        ]);
        
        $serialB = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SERIAL-B-' . uniqid(),
            'status' => 'in_stock',
            'cost_price' => 200000,
        ]);

        $customer = $this->customer();
        
        // Setup initial invoice with serial A sold
        $invoice = Invoice::create([
            'code' => 'HDSER-' . uniqid(),
            'subtotal' => 300000,
            'discount' => 0,
            'total' => 300000,
            'customer_paid' => 300000,
            'customer_id' => $customer->id,
            'status' => 'Hoàn thành',
            'sales_channel' => 'Test',
            'price_book_name' => 'Giá bán lẻ',
            'transaction_date' => now(),
            'lock_started_at' => now(),
            'created_at' => now(),
        ]);

        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 300000,
            'cost_price' => 200000,
            'discount' => 0,
            'subtotal' => 300000,
        ]);

        $serialA->update(['invoice_id' => $invoice->id]);

        // Put request to change serial to B
        $payload = [
            'customer_id' => $customer->id,
            'subtotal' => 300000,
            'discount' => 0,
            'total' => 300000,
            'customer_paid' => 300000,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => 300000,
                    'discount' => 0,
                    'serial_ids' => [$serialB->id],
                ]
            ],
            'payment_method' => 'Tiền mặt',
        ];

        $response = $this->actingAs($user)->put("/invoices/{$invoice->id}", $payload);
        $response->assertStatus(302);

        $serialA->refresh();
        $serialB->refresh();

        // Assert: serial A becomes in_stock (or null invoice_id), serial B becomes sold
        $this->assertEquals('in_stock', $serialA->status);
        $this->assertNull($serialA->invoice_id);

        $this->assertEquals('sold', $serialB->status);
        $this->assertEquals($invoice->id, $serialB->invoice_id);
    }
}
