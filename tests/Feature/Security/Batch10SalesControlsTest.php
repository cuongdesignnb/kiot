<?php

namespace Tests\Feature\Security;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoiceSaleService;
use App\Support\Security\CostVisibility;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Batch 10 đợt 4 — Kiot kiểm soát (items 30–32).
 *
 * 30: cost/profit hidden server-side for a role without cost_profit.view;
 *     old invoices locked for a role without invoices.modify_old.
 * 31: edit / cancel need a reason and write a journal row with a snapshot.
 * 32: seller and price > 0 required; purchase cost > 0; below cost allowed
 *     but flagged.
 */
class Batch10SalesControlsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private User $sales;

    private Role $salesRole;

    private Employee $seller;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // The hour-based time lock is a separate, older rule; keep it out of the way.
        Setting::set('order_change_time', 100000, 'order', 'number');
        foreach (['sales_require_seller', 'sales_require_line_price', 'purchase_require_line_cost', 'sales_warn_below_cost'] as $key) {
            Setting::set($key, true, 'sales_controls', 'boolean');
        }
        Setting::set('invoice_old_lock_days', 0, 'sales_controls', 'number');

        $this->admin = User::factory()->create(['role_id' => null]);
        $this->salesRole = Role::create([
            'name' => 'b10-sales-'.uniqid(),
            'display_name' => 'Nhân viên bán (test)',
            'permissions' => [
                'dashboard.view',
                'pos.use',
                'products.view',
                'products.view_cost_price',
                'invoices.view',
                'invoices.create',
                'invoices.edit',
                'invoices.cancel',
                'reports.view',
            ],
            'is_system' => false,
        ]);
        $this->sales = User::factory()->create(['role_id' => $this->salesRole->id]);
        $this->seller = Employee::create([
            'code' => 'NV-B10-'.uniqid(),
            'name' => 'Người bán B10',
            'is_active' => true,
        ]);
        $this->product = Product::create([
            'sku' => 'SP-B10-'.uniqid(),
            'name' => 'Sản phẩm kiểm soát B10',
            'cost_price' => 100000,
            'retail_price' => 150000,
            'stock_quantity' => 20,
            'inventory_total_cost' => 2000000,
            'is_active' => true,
            'has_serial' => false,
        ]);
    }

    // ───────────────────────── Item 30: cost / profit hidden ─────────────────────────

    public function test_restricted_user_does_not_receive_cost_in_product_search_json(): void
    {
        $restricted = $this->actingAs($this->sales)
            ->getJson('/api/products/search?search='.$this->product->sku)
            ->assertOk()
            ->json();
        $row = collect($restricted)->firstWhere('id', $this->product->id);
        $this->assertNotNull($row);
        $this->assertArrayHasKey('cost_price', $row);
        $this->assertNull($row['cost_price']);
        $this->assertNull($row['inventory_total_cost']);
        $this->assertEquals(150000, $row['retail_price']);

        $admin = $this->actingAs($this->admin)
            ->getJson('/api/products/search?search='.$this->product->sku)
            ->assertOk()
            ->json();
        $this->assertEquals(100000, collect($admin)->firstWhere('id', $this->product->id)['cost_price']);
    }

    public function test_restricted_user_does_not_receive_cost_or_profit_in_inertia_props(): void
    {
        $invoice = $this->makeInvoice(150000);

        $this->actingAs($this->sales)
            ->get('/invoices?search='.$invoice->code)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Invoices/Index')
                ->where('invoices.data.0.items.0.product.cost_price', null)
                ->where('invoices.data.0.items.0.cost_price', null)
                ->where('invoices.data.0.items.0.price', fn ($price) => (float) $price === 150000.0));

        $this->actingAs($this->sales)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard/Index')
                ->where('thisMonthProfit', null)
                ->where('totalStockValue', null));

        // Admin still sees the figures.
        $this->actingAs($this->admin)
            ->get('/invoices?search='.$invoice->code)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('invoices.data.0.items.0.product.cost_price', fn ($cost) => (float) $cost === 100000.0));
    }

    public function test_cost_reports_are_forbidden_without_cost_permission(): void
    {
        $this->actingAs($this->sales)->get('/reports/cost-profit')->assertForbidden();
        $this->actingAs($this->sales)->get('/reports/financial-report')->assertForbidden();
        $this->actingAs($this->sales)->get('/reports/cost-analysis')->assertForbidden();
    }

    public function test_restricted_user_cannot_overwrite_product_cost(): void
    {
        $role = Role::create([
            'name' => 'b10-product-editor-'.uniqid(),
            'display_name' => 'Sửa hàng không xem vốn',
            'permissions' => ['products.view', 'products.edit'],
        ]);
        $editor = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($editor)->put("/products/{$this->product->id}", [
            'name' => 'Tên mới B10',
            'cost_price' => 0,
            'retail_price' => 160000,
        ])->assertRedirect();

        $fresh = $this->product->fresh();
        $this->assertSame('Tên mới B10', $fresh->name);
        $this->assertEquals(160000, (float) $fresh->retail_price);
        $this->assertEquals(100000, (float) $fresh->cost_price);
    }

    public function test_cost_visibility_key_matching(): void
    {
        foreach (['cost_price', 'unit_cost', 'gross_profit', 'thisMonthProfit', 'cogs_net', 'profit_margin', 'last_purchase_price', 'totalStockValue', 'stock_value'] as $key) {
            $this->assertTrue(CostVisibility::isSensitiveKey($key), $key);
        }
        foreach (['inventory_costing_method', 'other_costs', 'retail_price', 'price', 'total', 'cost_pending', 'delivery_fee'] as $key) {
            $this->assertFalse(CostVisibility::isSensitiveKey($key), $key);
        }
    }

    // ───────────────────────── Item 30: old invoices ─────────────────────────

    public function test_old_invoice_cannot_be_edited_cancelled_or_reassigned_without_permission(): void
    {
        $invoice = $this->makeInvoice(150000);
        $this->backdateRecording($invoice);

        $this->actingAs($this->sales)
            ->from('/invoices')
            ->put("/invoices/{$invoice->id}", $this->updatePayload($invoice, ['note' => 'Sửa thử']))
            ->assertRedirect('/invoices')
            ->assertSessionHas('error');
        $this->assertNotSame('Sửa thử', $invoice->fresh()->note);

        $this->actingAs($this->sales)
            ->from('/invoices')
            ->delete("/invoices/{$invoice->id}", ['cancel_reason' => 'Khách đổi ý mua'])
            ->assertSessionHas('error');
        $this->assertSame('Hoàn thành', $invoice->fresh()->status);

        $this->actingAs($this->sales)
            ->patchJson("/invoices/{$invoice->id}/seller", ['seller_key' => 'employee:'.$this->seller->id, 'reason' => 'Nhập nhầm người bán'])
            ->assertForbidden();

        // With the permission, the same cancel goes through.
        $this->salesRole->update(['permissions' => [...$this->salesRole->permissions, 'invoices.modify_old']]);
        $this->actingAs($this->sales->fresh())
            ->from('/invoices')
            ->delete("/invoices/{$invoice->id}", ['cancel_reason' => 'Khách đổi ý mua'])
            ->assertRedirect();
        $this->assertSame('Đã hủy', $invoice->fresh()->status);
    }

    public function test_todays_invoice_is_not_old(): void
    {
        $invoice = $this->makeInvoice(150000);

        $this->actingAs($this->sales)
            ->from('/invoices')
            ->put("/invoices/{$invoice->id}", $this->updatePayload($invoice, ['note' => 'Ghi chú mới B10']))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');
        $this->assertSame('Ghi chú mới B10', $invoice->fresh()->note);
    }

    // ───────────────────────── Item 31: journal with reason ─────────────────────────

    public function test_invoice_cancel_requires_reason_and_writes_journal_row(): void
    {
        $invoice = $this->makeInvoice(150000);

        $this->actingAs($this->admin)
            ->from('/invoices')
            ->delete("/invoices/{$invoice->id}", [])
            ->assertSessionHasErrors('cancel_reason');
        $this->assertSame('Hoàn thành', $invoice->fresh()->status);

        $this->actingAs($this->admin)
            ->from('/invoices')
            ->delete("/invoices/{$invoice->id}", ['cancel_reason' => 'Khách trả lại máy'])
            ->assertRedirect();

        $log = ActivityLog::query()
            ->where('action', ActivityLog::ACTION_INVOICE_CANCEL)
            ->where('subject_id', $invoice->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('Khách trả lại máy', $log->reason);
        $this->assertSame(ActivityLog::DOC_INVOICE, $log->document_type);
        $this->assertSame($invoice->code, $log->document_code);
        $this->assertSame('Hoàn thành', $log->properties['before']['status']);
        $this->assertSame('Đã hủy', $log->properties['after']['status']);
        $this->assertNotNull($log->created_at);
    }

    public function test_invoice_edit_requires_reason_and_journal_keeps_before_and_after(): void
    {
        $invoice = $this->makeInvoice(150000);
        $payload = $this->updatePayload($invoice, ['note' => 'Ghi chú sau khi sửa']);
        unset($payload['edit_reason']);

        $this->actingAs($this->admin)
            ->from('/invoices')
            ->put("/invoices/{$invoice->id}", $payload)
            ->assertSessionHasErrors('edit_reason');

        $this->actingAs($this->admin)
            ->from('/invoices')
            ->put("/invoices/{$invoice->id}", $this->updatePayload($invoice, ['note' => 'Ghi chú sau khi sửa']))
            ->assertSessionMissing('error');

        $log = ActivityLog::query()
            ->where('action', ActivityLog::ACTION_INVOICE_UPDATE)
            ->where('subject_id', $invoice->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('Sửa ghi chú hóa đơn', $log->reason);
        $this->assertSame($invoice->code, $log->document_code);
        $this->assertNotSame('Ghi chú sau khi sửa', $log->properties['before']['note']);
        $this->assertSame('Ghi chú sau khi sửa', $log->properties['after']['note']);
    }

    public function test_seller_change_requires_reason(): void
    {
        $invoice = $this->makeInvoice(150000);

        $this->actingAs($this->admin)
            ->patchJson("/invoices/{$invoice->id}/seller", ['seller_key' => 'employee:'.$this->seller->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->actingAs($this->admin)
            ->patchJson("/invoices/{$invoice->id}/seller", ['seller_key' => 'employee:'.$this->seller->id, 'reason' => 'Ghi nhầm người bán'])
            ->assertOk();

        $this->assertTrue(ActivityLog::query()
            ->where('action', ActivityLog::ACTION_INVOICE_SELLER_CHANGE)
            ->where('document_code', $invoice->code)
            ->where('reason', 'Ghi nhầm người bán')
            ->exists());
    }

    public function test_cash_flow_edit_requires_reason_and_is_journaled(): void
    {
        $flow = CashFlow::create([
            'code' => 'PT-B10-'.uniqid(),
            'type' => 'receipt',
            'amount' => 50000,
            'time' => now(),
            'category' => 'Thu khác',
            'target_type' => 'Khác',
            'target_name' => 'Khách vãng lai',
            'payment_method' => 'cash',
            'description' => 'Thu lẻ',
        ]);

        $base = ['amount' => 60000, 'category' => 'Thu khác', 'target_type' => 'Khác', 'target_name' => 'Khách vãng lai', 'payment_method' => 'cash'];
        $this->actingAs($this->admin)->from('/cash-flows')
            ->put("/cash-flows/{$flow->id}", $base)
            ->assertSessionHasErrors('edit_reason');
        $this->assertEquals(50000, (float) $flow->fresh()->amount);

        $this->actingAs($this->admin)->from('/cash-flows')
            ->put("/cash-flows/{$flow->id}", $base + ['edit_reason' => 'Thu thiếu tiền'])
            ->assertSessionHasNoErrors();

        $log = ActivityLog::query()->where('action', ActivityLog::ACTION_CASHFLOW_UPDATE)
            ->where('document_code', $flow->code)->latest('id')->firstOrFail();
        $this->assertSame('Thu thiếu tiền', $log->reason);
        $this->assertEquals(50000, $log->properties['before']['amount']);
        $this->assertEquals(60000, $log->properties['after']['amount']);
    }

    public function test_journal_filter_by_document_code_and_type(): void
    {
        $invoice = $this->makeInvoice(150000);
        $this->actingAs($this->admin)->from('/invoices')
            ->delete("/invoices/{$invoice->id}", ['cancel_reason' => 'Hủy để lọc nhật ký']);

        $rows = $this->actingAs($this->admin)
            ->getJson('/api/activity-logs?control_only=1&document_type=invoice&document_code='.$invoice->code)
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame($invoice->code, $row['document_code']);
        }
        $this->assertSame('Hủy để lọc nhật ký', $rows[0]['reason']);
    }

    // ───────────────────────── Item 32: validation rules ─────────────────────────

    public function test_pos_checkout_requires_seller(): void
    {
        $this->actingAs($this->sales)
            ->postJson('/api/pos/checkout', $this->checkoutPayload(150000, null))
            ->assertStatus(422)
            ->assertJsonValidationErrors('seller_key');
    }

    public function test_pos_checkout_requires_positive_line_price(): void
    {
        $this->actingAs($this->sales)
            ->postJson('/api/pos/checkout', $this->checkoutPayload(0, 'employee:'.$this->seller->id))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.price');
    }

    public function test_seller_rule_can_be_relaxed_in_settings(): void
    {
        Setting::set('sales_require_seller', false, 'sales_controls', 'boolean');

        $this->actingAs($this->sales)
            ->postJson('/api/pos/checkout', $this->checkoutPayload(150000, null))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_below_cost_sale_is_allowed_and_flagged(): void
    {
        $check = $this->actingAs($this->sales)
            ->postJson('/api/sales/below-cost-check', ['items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'price' => 90000,
                'discount' => 0,
            ]]])
            ->assertOk()
            ->assertJsonPath('has_below_cost', true)
            ->json('lines.0');
        $this->assertSame('Giá bán thấp hơn giá vốn', $check['message']);
        $this->assertNull($check['cost_price'], 'A user without cost permission must not get the cost value.');

        $this->actingAs($this->admin)
            ->postJson('/api/sales/below-cost-check', ['items' => [[
                'product_id' => $this->product->id, 'quantity' => 1, 'price' => 90000,
            ]]])
            ->assertJsonPath('lines.0.cost_price', 100000);

        $response = $this->actingAs($this->sales)
            ->postJson('/api/pos/checkout', $this->checkoutPayload(90000, 'employee:'.$this->seller->id))
            ->assertOk()
            ->assertJsonPath('success', true);

        $code = $response->json('invoice_code');
        $log = ActivityLog::query()
            ->where('action', ActivityLog::ACTION_INVOICE_BELOW_COST)
            ->where('document_code', $code)
            ->firstOrFail();
        $this->assertSame(1, $log->properties['below_cost_count']);
        $this->assertEquals(90000, $log->properties['lines'][0]['sale_price']);
    }

    public function test_sale_at_or_above_cost_is_not_flagged(): void
    {
        $code = $this->actingAs($this->sales)
            ->postJson('/api/pos/checkout', $this->checkoutPayload(150000, 'employee:'.$this->seller->id))
            ->assertOk()
            ->json('invoice_code');

        $this->assertFalse(ActivityLog::query()
            ->where('action', ActivityLog::ACTION_INVOICE_BELOW_COST)
            ->where('document_code', $code)
            ->exists());
    }

    public function test_invoice_edit_rejects_zero_line_price(): void
    {
        $invoice = $this->makeInvoice(150000);
        $payload = $this->updatePayload($invoice);
        $payload['items'][0]['price'] = 0;

        $this->actingAs($this->admin)
            ->from('/invoices')
            ->put("/invoices/{$invoice->id}", $payload)
            ->assertSessionHasErrors('items.0.price');
    }

    public function test_purchase_requires_positive_import_cost(): void
    {
        $supplier = Customer::create([
            'code' => 'NCC-B10-'.uniqid(),
            'name' => 'NCC B10',
            'phone' => '09'.random_int(10000000, 99999999),
            'is_supplier' => true,
            'is_customer' => false,
        ]);

        $payload = [
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'purchase_date' => now()->toDateTimeString(),
            'discount' => 0,
            'paid_amount' => 0,
            'payment_method' => 'cash',
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'price' => 0, 'discount' => 0]],
        ];

        $this->actingAs($this->admin)
            ->from('/purchases/create')
            ->post('/purchases', $payload)
            ->assertSessionHasErrors('items.0.price');
        $this->assertFalse(Purchase::query()->where('supplier_id', $supplier->id)->exists());

        $payload['items'][0]['price'] = 95000;
        $this->actingAs($this->admin)->post('/purchases', $payload)->assertSessionHasNoErrors();
        $this->assertTrue(Purchase::query()->where('supplier_id', $supplier->id)->exists());
    }

    // ───────────────────────── Rollout ─────────────────────────

    public function test_migration_grants_the_new_permissions_to_existing_roles(): void
    {
        $role = Role::create([
            'name' => 'b10-existing-'.uniqid(),
            'display_name' => 'Vai trò có sẵn',
            'permissions' => ['invoices.view'],
        ]);

        $migration = require database_path('migrations/2026_11_16_100000_add_sales_controls_permissions_and_audit_columns.php');
        $migration->up();

        $permissions = $role->fresh()->permissions;
        $this->assertContains('cost_profit.view', $permissions);
        $this->assertContains('invoices.modify_old', $permissions);
        $this->assertContains('invoices.view', $permissions);
        $this->assertContains('cost_profit.view', Role::getAllPermissionKeys());
        $this->assertContains('invoices.modify_old', Role::getAllPermissionKeys());
    }

    // ───────────────────────── helpers ─────────────────────────

    private function makeInvoice(float $price): Invoice
    {
        $this->actingAs($this->admin);

        return app(InvoiceSaleService::class)->createSale([
            'customer_id' => null,
            'branch_id' => null,
            'subtotal' => $price,
            'discount' => 0,
            'total' => $price,
            'customer_paid' => $price,
            'payment_method' => 'cash',
            'note' => 'Hóa đơn test B10',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'price' => $price,
                'discount' => 0,
                'serial_ids' => [],
            ]],
        ], [
            'source' => 'pos',
            'code_prefix' => 'HDB10'.uniqid(),
            'default_status' => 'Hoàn thành',
            'seller_id' => $this->seller->id,
            'seller_name' => $this->seller->name,
            'validate_before_purchase_date' => false,
            'validate_stock_setting' => false,
            'allow_oversell' => true,
        ]);
    }

    private function backdateRecording(Invoice $invoice): void
    {
        $yesterday = now()->subDay()->setTime(10, 0);
        DB::table('invoices')->where('id', $invoice->id)->update([
            'created_at' => $yesterday,
            'lock_started_at' => $yesterday,
            'transaction_date' => $yesterday,
        ]);
        $invoice->refresh();
    }

    private function updatePayload(Invoice $invoice, array $overrides = []): array
    {
        $invoice->loadMissing('items');
        $item = $invoice->items->first();

        return array_merge([
            'edit_reason' => 'Sửa ghi chú hóa đơn',
            'customer_id' => $invoice->customer_id,
            'subtotal' => (float) $invoice->subtotal,
            'discount' => (float) $invoice->discount,
            'total' => (float) $invoice->total,
            'customer_paid' => (float) $invoice->customer_paid,
            'note' => $invoice->note,
            'payment_method' => $invoice->payment_method,
            'items' => [[
                'invoice_item_id' => $item->id,
                'product_id' => $item->product_id,
                'quantity' => (int) $item->quantity,
                'price' => (float) $item->price,
                'discount' => (float) $item->discount,
                'serial_ids' => [],
            ]],
        ], $overrides);
    }

    private function checkoutPayload(float $price, ?string $sellerKey): array
    {
        return [
            'subtotal' => $price,
            'discount' => 0,
            'total' => $price,
            'customer_paid' => $price,
            'customer_id' => null,
            'seller_key' => $sellerKey,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'price' => $price,
                'discount' => 0,
                'serial_ids' => [],
            ]],
        ];
    }
}
