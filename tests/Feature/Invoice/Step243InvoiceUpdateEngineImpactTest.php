<?php

namespace Tests\Feature\Invoice;

use App\Models\ActivityLog;
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
use App\Models\Warranty;
use App\Services\InvoiceUpdateService;
use App\Services\MovingAvgCostingService;
use App\Services\StockMovementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Step243InvoiceUpdateEngineImpactTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(array $perms = ['*']): User
    {
        $name = 'test-admin-' . uniqid();
        $role = Role::create(['name' => $name, 'display_name' => $name, 'permissions' => $perms]);
        return User::create([
            'name' => 'Admin243', 'email' => 'a243-' . uniqid() . '@test.local',
            'password' => bcrypt('pw'), 'role_id' => $role->id,
        ]);
    }

    private function product(int $stock = 10, float $cost = 100000): Product
    {
        return Product::create([
            'sku' => 'SP243-' . uniqid(), 'name' => 'SP Test 243',
            'cost_price' => $cost, 'retail_price' => 150000,
            'stock_quantity' => $stock, 'inventory_total_cost' => $stock * $cost,
            'is_active' => true, 'has_serial' => false,
        ]);
    }

    private function serialProduct(int $count = 3, float $cost = 200000): array
    {
        $p = Product::create([
            'sku' => 'SER243-' . uniqid(), 'name' => 'Serial SP 243',
            'cost_price' => $cost, 'retail_price' => 300000,
            'stock_quantity' => $count, 'inventory_total_cost' => $count * $cost,
            'is_active' => true, 'has_serial' => true,
        ]);
        $serials = [];
        for ($i = 0; $i < $count; $i++) {
            $serials[] = SerialImei::create([
                'product_id' => $p->id, 'serial_number' => 'IMEI243-' . uniqid(),
                'status' => 'in_stock', 'cost_price' => $cost,
            ]);
        }
        return [$p, $serials];
    }

    private function customer(float $debt = 0): Customer
    {
        return Customer::create([
            'code' => 'KH243-' . uniqid(), 'name' => 'KH Test 243',
            'phone' => '09' . rand(10000000, 99999999),
            'debt_amount' => $debt, 'total_spent' => 0, 'is_customer' => true,
        ]);
    }

    private function createInvoice(Product $product, Customer $customer, int $qty, float $price, float $paid, ?Carbon $txDate = null): Invoice
    {
        $total = $qty * $price;
        $costResult = MovingAvgCostingService::applySale($product, $qty);
        $costAtSale = $costResult['cogs_per_unit'];
        $product->refresh();

        $code = 'HD243-' . uniqid();
        $now = now();
        $invoice = Invoice::create([
            'code' => $code, 'subtotal' => $total, 'discount' => 0,
            'total' => $total, 'customer_paid' => $paid,
            'customer_id' => $customer->id, 'status' => 'Hoàn thành',
            'sales_channel' => 'Test', 'price_book_name' => 'Giá bán lẻ',
            'transaction_date' => $txDate ?? $now,
            'lock_started_at' => $now, 'created_at' => $now,
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
                'code' => 'PT243-' . uniqid(), 'type' => 'receipt', 'amount' => $paid,
                'time' => $txDate ?? $now, 'category' => 'Thu tiền khách trả',
                'target_type' => 'Khách hàng', 'target_id' => $customer->id,
                'target_name' => $customer->name,
                'reference_type' => 'Invoice', 'reference_code' => $code,
                'description' => 'Test', 'payment_method' => 'cash',
            ]);
        }

        StockMovementService::record($product, StockMovementService::TYPE_OUT_INVOICE, $qty, $costAtSale, $invoice, ['ref_code' => $code]);

        return $invoice;
    }

    // === TC 1: New invoice has transaction_date and lock_started_at ===
    public function test_new_invoice_has_transaction_date_and_lock_started_at(): void
    {
        $admin = $this->admin();
        $product = $this->product(10);
        $customer = $this->customer();

        $lastMonth = now()->subMonth()->startOfDay();
        $this->actingAs($admin);

        $invoice = app(\App\Services\InvoiceSaleService::class)->createSale([
            'customer_id' => $customer->id, 'subtotal' => 150000, 'total' => 150000,
            'discount' => 0, 'customer_paid' => 150000, 'sales_channel' => 'Test',
            'price_book_name' => 'Giá bán lẻ',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 150000, 'discount' => 0]],
        ], [
            'transaction_date' => $lastMonth->format('Y-m-d'),
            'allow_oversell' => true,
        ]);

        $this->assertNotNull($invoice);
        $this->assertNotNull($invoice->transaction_date);
        $this->assertNotNull($invoice->lock_started_at);
        $this->assertTrue(Carbon::parse($invoice->lock_started_at)->diffInMinutes(now()) < 5);
        $this->assertEquals($lastMonth->toDateString(), Carbon::parse($invoice->transaction_date)->toDateString());
    }

    // === TC 2: Legacy invoice without lock_started_at falls back to created_at ===
    public function test_legacy_invoice_without_lock_started_at_falls_back_to_created_at(): void
    {
        $product = $this->product(10);
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 1, 150000, 150000);

        $invoice->update(['lock_started_at' => null, 'created_at' => now()->subHours(48)]);
        $invoice->refresh();

        $lockRef = $invoice->lock_started_at ?? $invoice->created_at;
        $diffHours = Carbon::parse($lockRef)->diffInHours(now());
        $this->assertGreaterThan(24, $diffHours);
    }

    // === TC 3: Change plan detects date-only ===
    public function test_change_plan_detects_date_only(): void
    {
        $product = $this->product(10);
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 2, 150000, 300000);

        $plan = app(InvoiceUpdateService::class)->buildChangePlan($invoice, [
            'transaction_date' => now()->subDays(5)->format('Y-m-d'),
            'customer_id' => $customer->id,
            'subtotal' => 300000, 'discount' => 0, 'total' => 300000,
            'customer_paid' => 300000,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'price' => 150000, 'discount' => 0, 'note' => '']],
        ]);

        $this->assertTrue($plan['date_changed']);
        $this->assertTrue($plan['only_date_changed']);
        $this->assertFalse($plan['content_changed']);
    }

    // === TC 4: Change plan detects quantity change ===
    public function test_change_plan_detects_quantity_change_as_content_update(): void
    {
        $product = $this->product(10);
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 2, 150000, 300000);

        $plan = app(InvoiceUpdateService::class)->buildChangePlan($invoice, [
            'customer_id' => $customer->id,
            'subtotal' => 450000, 'discount' => 0, 'total' => 450000,
            'customer_paid' => 450000,
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'price' => 150000, 'discount' => 0, 'note' => '']],
        ]);

        $this->assertTrue($plan['content_changed']);
        $this->assertTrue($plan['items_changed']);
    }

    // === TC 5: Change plan detects amount change ===
    public function test_change_plan_detects_amount_change_as_content_update(): void
    {
        $product = $this->product(10);
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 2, 150000, 200000);

        $plan = app(InvoiceUpdateService::class)->buildChangePlan($invoice, [
            'customer_id' => $customer->id,
            'subtotal' => 400000, 'discount' => 0, 'total' => 400000,
            'customer_paid' => 300000,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'price' => 200000, 'discount' => 0, 'note' => '']],
        ]);

        $this->assertTrue($plan['content_changed']);
        $this->assertTrue($plan['financial_changed']);
    }

    // === TC 6: Date-only update does NOT mutate stock/cost/debt/serial ===
    public function test_date_only_update_does_not_mutate_stock_cost_debt_serial(): void
    {
        $product = $this->product(10, 100000);
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 2, 150000, 200000);

        $product->refresh(); $customer->refresh();
        $snapStock = (int) $product->stock_quantity;
        $snapCost = (float) $product->inventory_total_cost;
        $snapDebt = (float) $customer->debt_amount;
        $snapSpent = (float) $customer->total_spent;

        $newDate = now()->subDays(10);
        $user = $this->admin(['*']);
        $context = [
            'user' => $user,
            'transaction_date_change_reason' => 'Backdate test reason',
        ];
        $payload = [
            'transaction_date' => $newDate->format('Y-m-d'),
            'customer_id' => $customer->id,
            'subtotal' => 300000, 'discount' => 0, 'total' => 300000,
            'customer_paid' => 200000,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'price' => 150000, 'discount' => 0, 'note' => '', 'serial_ids' => []]],
        ];

        app(InvoiceUpdateService::class)->updateInvoice($invoice, $payload, $context);

        $product->refresh(); $customer->refresh();
        $this->assertEquals($snapStock, (int) $product->stock_quantity);
        $this->assertEquals($snapCost, (float) $product->inventory_total_cost);
        $this->assertEquals($snapDebt, (float) $customer->debt_amount);
        $this->assertEquals($snapSpent, (float) $customer->total_spent);
    }

    // === TC 7: Date-only update updates reporting dates ===
    public function test_date_only_update_updates_reporting_dates_only(): void
    {
        $product = $this->product(10);
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 1, 150000, 150000);
        $smBefore = StockMovement::where('ref_id', $invoice->id)->count();

        $newDate = now()->subDays(15);
        $user = $this->admin(['*']);
        app(InvoiceUpdateService::class)->updateInvoice($invoice, [
            'transaction_date' => $newDate->format('Y-m-d'),
            'customer_id' => $customer->id,
            'subtotal' => 150000, 'discount' => 0, 'total' => 150000, 'customer_paid' => 150000,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 150000, 'discount' => 0, 'note' => '', 'serial_ids' => []]],
        ], ['user' => $user, 'transaction_date_change_reason' => 'Backdate reporting test']);

        $invoice->refresh();
        $this->assertEquals($newDate->startOfDay()->toDateString(), Carbon::parse($invoice->transaction_date)->toDateString());

        // No new stock movements created
        $smAfter = StockMovement::where('ref_id', $invoice->id)->count();
        $this->assertEquals($smBefore, $smAfter);
    }

    // === TC 9: Quantity increase reverses old and applies new correctly ===
    public function test_quantity_increase_reverses_old_sale_and_applies_new_sale_correctly(): void
    {
        $product = $this->product(10, 100000);
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 1, 150000, 150000);

        $product->refresh();
        $stockAfterSale = (int) $product->stock_quantity; // 9

        $user = $this->admin(['*']);
        app(InvoiceUpdateService::class)->updateInvoice($invoice, [
            'customer_id' => $customer->id,
            'subtotal' => 300000, 'discount' => 0, 'total' => 300000, 'customer_paid' => 300000,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'price' => 150000, 'discount' => 0, 'note' => '', 'serial_ids' => []]],
        ], ['user' => $user]);

        $product->refresh();
        // Was 10, sold 2 net = 8
        $this->assertEquals(8, (int) $product->stock_quantity);
    }

    // === TC 10: Quantity decrease ===
    public function test_quantity_decrease_reverses_old_sale_and_applies_new_sale_correctly(): void
    {
        $product = $this->product(10, 100000);
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 3, 150000, 450000);

        $user = $this->admin(['*']);
        app(InvoiceUpdateService::class)->updateInvoice($invoice, [
            'customer_id' => $customer->id,
            'subtotal' => 150000, 'discount' => 0, 'total' => 150000, 'customer_paid' => 150000,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 150000, 'discount' => 0, 'note' => '', 'serial_ids' => []]],
        ], ['user' => $user]);

        $product->refresh();
        $this->assertEquals(9, (int) $product->stock_quantity);

        $customer->refresh();
        $this->assertEquals(150000, (float) $customer->total_spent);
    }

    // === TC 11: Price change does not change net stock ===
    public function test_price_change_does_not_change_net_stock_but_updates_revenue_and_debt(): void
    {
        $product = $this->product(10, 100000);
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 2, 150000, 200000);

        $product->refresh();
        $stockBefore = (int) $product->stock_quantity;

        $user = $this->admin(['*']);
        app(InvoiceUpdateService::class)->updateInvoice($invoice, [
            'customer_id' => $customer->id,
            'subtotal' => 400000, 'discount' => 0, 'total' => 400000, 'customer_paid' => 300000,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'price' => 200000, 'discount' => 0, 'note' => '', 'serial_ids' => []]],
        ], ['user' => $user]);

        $product->refresh();
        $this->assertEquals($stockBefore, (int) $product->stock_quantity);

        $customer->refresh();
        $this->assertEquals(400000, (float) $customer->total_spent);
    }

    // === TC 13: Customer change moves debt ===
    public function test_customer_change_moves_debt_from_old_customer_to_new_customer(): void
    {
        $product = $this->product(10, 100000);
        $oldCust = $this->customer();
        $newCust = $this->customer();
        $invoice = $this->createInvoice($product, $oldCust, 2, 150000, 200000);

        $oldCust->refresh();
        $oldDebtBefore = (float) $oldCust->debt_amount;
        $oldSpentBefore = (float) $oldCust->total_spent;

        $user = $this->admin(['*']);
        app(InvoiceUpdateService::class)->updateInvoice($invoice, [
            'customer_id' => $newCust->id,
            'subtotal' => 300000, 'discount' => 0, 'total' => 300000, 'customer_paid' => 200000,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'price' => 150000, 'discount' => 0, 'note' => '', 'serial_ids' => []]],
        ], ['user' => $user]);

        $oldCust->refresh(); $newCust->refresh();
        $this->assertEquals(0, (float) $oldCust->debt_amount);
        $this->assertEquals(0, (float) $oldCust->total_spent);
        $this->assertEquals(100000, (float) $newCust->debt_amount);
        $this->assertEquals(300000, (float) $newCust->total_spent);
    }

    // === TC 16: Content update with insufficient stock fails without mutation ===
    public function test_content_update_with_insufficient_stock_fails_without_mutation(): void
    {
        Setting::set('inventory_allow_oversell', false);
        $product = $this->product(5, 100000);
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 2, 150000, 300000);

        $product->refresh();
        $stockBefore = (int) $product->stock_quantity; // 3
        $customer->refresh();
        $debtBefore = (float) $customer->debt_amount;

        $user = $this->admin(['*']);
        try {
            app(InvoiceUpdateService::class)->updateInvoice($invoice, [
                'customer_id' => $customer->id,
                'subtotal' => 1500000, 'discount' => 0, 'total' => 1500000, 'customer_paid' => 1500000,
                'items' => [['product_id' => $product->id, 'quantity' => 10, 'price' => 150000, 'discount' => 0, 'note' => '', 'serial_ids' => []]],
            ], ['user' => $user]);
            $this->fail('Should have thrown exception');
        } catch (\Exception $e) {
            $this->assertStringContainsString('tồn kho', $e->getMessage());
        }

        // Verify rollback
        $product->refresh(); $customer->refresh();
        $this->assertEquals($stockBefore, (int) $product->stock_quantity);
        $this->assertEquals($debtBefore, (float) $customer->debt_amount);
    }

    // === TC 17: Old invoice content update requires override permission and reason ===
    public function test_old_invoice_content_update_requires_override_permission_and_reason(): void
    {
        $product = $this->product(10);
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 1, 150000, 150000);
        $invoice->update(['lock_started_at' => now()->subHours(48)]);

        $payload = [
            'customer_id' => $customer->id,
            'subtotal' => 300000, 'discount' => 0, 'total' => 300000, 'customer_paid' => 300000,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'price' => 150000, 'discount' => 0, 'note' => '', 'serial_ids' => []]],
        ];

        // User without permission
        $userNoPerms = $this->admin(['invoices.view']);
        try {
            app(InvoiceUpdateService::class)->updateInvoice($invoice, $payload, ['user' => $userNoPerms]);
            $this->fail('Should fail without override permission');
        } catch (\Exception $e) {
            $this->assertStringContainsString('override', $e->getMessage());
        }

        // User with permission but no reason
        $userWithPerm = $this->admin(['invoices.override_time_lock']);
        try {
            app(InvoiceUpdateService::class)->updateInvoice($invoice, $payload, ['user' => $userWithPerm]);
            $this->fail('Should fail without reason');
        } catch (\Exception $e) {
            $this->assertStringContainsString('lý do', $e->getMessage());
        }

        // User with permission + reason: pass
        $invoice = app(InvoiceUpdateService::class)->updateInvoice($invoice, $payload, [
            'user' => $userWithPerm,
            'time_lock_override_reason' => 'Khách hàng yêu cầu sửa hóa đơn',
        ]);
        $this->assertNotNull($invoice);
    }

    // === TC 18: E-invoice block prevents update even with override ===
    public function test_einvoice_block_prevents_date_and_content_update_even_with_override(): void
    {
        if (!Schema::hasColumn('invoices', 'einvoice_code')) {
            $this->markTestSkipped('einvoice_code column not in test DB yet.');
        }
        Setting::set('block_edit_cancel_einvoice', true);
        $product = $this->product(10);
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 1, 150000, 150000);
        $invoice->update(['einvoice_code' => 'EINV-001']);

        $user = $this->admin(['*']);
        try {
            app(InvoiceUpdateService::class)->updateInvoice($invoice, [
                'transaction_date' => now()->subDays(5)->format('Y-m-d'),
                'customer_id' => $customer->id,
                'subtotal' => 150000, 'discount' => 0, 'total' => 150000, 'customer_paid' => 150000,
                'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 150000, 'discount' => 0, 'note' => '', 'serial_ids' => []]],
            ], ['user' => $user, 'transaction_date_change_reason' => 'Test einvoice block']);
            $this->fail('Should be blocked');
        } catch (\Exception $e) {
            $this->assertStringContainsString('điện tử', $e->getMessage());
        }
    }

    // === TC 20: Cancel old invoice override preserves invariants ===
    public function test_cancel_old_invoice_override_preserves_cancel_invariants(): void
    {
        $product = $this->product(10, 100000);
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 2, 150000, 200000);
        $invoice->update(['lock_started_at' => now()->subHours(48)]);

        $product->refresh();
        $stockAfterSale = (int) $product->stock_quantity;

        $admin = $this->admin(['*']);
        $response = $this->actingAs($admin)->delete("/invoices/{$invoice->id}", [
            'time_lock_override_reason' => 'Manager approved cancel for old invoice',
        ]);

        $invoice->refresh(); $product->refresh(); $customer->refresh();

        $this->assertEquals('Đã hủy', $invoice->status);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoice->id]);
        $this->assertEquals(10, (int) $product->stock_quantity);
        $this->assertEquals(0, (float) $customer->debt_amount);
        $this->assertEquals(0, (float) $customer->total_spent);

        $cfExists = CashFlow::withTrashed()
            ->where('reference_code', $invoice->code)->exists();
        $this->assertTrue($cfExists);
    }

    // === TC 21: Existing CancelInvoiceTest invariants still pass ===
    public function test_cancel_invoice_existing_rr01_invariants_still_pass(): void
    {
        $admin = $this->admin(['*']);
        $product = $this->product(10, 100000);
        $customer = $this->customer();
        $invoice = $this->createInvoice($product, $customer, 2, 150000, 300000);

        $this->actingAs($admin)->delete("/invoices/{$invoice->id}");

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $invoice->refresh();
        $this->assertEquals('Đã hủy', $invoice->status);
        $product->refresh();
        $this->assertEquals(10, (int) $product->stock_quantity);
    }
}
