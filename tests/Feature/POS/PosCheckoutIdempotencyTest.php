<?php

namespace Tests\Feature\POS;

use App\Models\CashFlow;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\PartnerDebtOperation;
use App\Models\PartnerDebtOperationParticipant;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Debt\PartnerDebtDisplayBalance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PosCheckoutIdempotencyTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['name' => 'pos-idempotency-test-admin'], [
            'display_name' => 'POS Idempotency Test Admin',
            'permissions' => ['*'],
            'is_system' => true,
        ]);
        $this->admin = User::factory()->create(['role_id' => $role->id]);
        $this->customer = Customer::create([
            'code' => 'KH-IDEM-'.uniqid(),
            'name' => 'Khách POS Idempotency',
            'phone' => '090'.random_int(1000000, 9999999),
            'is_customer' => true,
            'debt_amount' => 0,
            'total_spent' => 0,
        ]);
        $category = Category::firstOrCreate(['name' => 'POS Idempotency Test']);
        $this->product = Product::create([
            'sku' => 'SP-IDEM-'.uniqid(),
            'name' => 'Sản phẩm POS Idempotency',
            'cost_price' => 100000,
            'retail_price' => 200000,
            'stock_quantity' => 10,
            'inventory_total_cost' => 1000000,
            'is_active' => true,
            'has_serial' => false,
            'category_id' => $category->id,
        ]);
    }

    public function test_exact_retry_returns_same_invoice_without_duplicate_side_effects(): void
    {
        $key = 'pos-checkout-exact-retry-'.uniqid();
        $payload = $this->checkoutPayload();
        $before = $this->sideEffectCounts();

        $first = $this->postCheckout($payload, $key);
        $first->assertOk()->assertJsonPath('success', true);
        $afterFirst = $this->sideEffectCounts();

        $second = $this->postCheckout($payload, $key);
        $second->assertOk()->assertJsonPath('success', true);

        $this->assertSame($first->json('invoice_code'), $second->json('invoice_code'));
        $this->assertSame($afterFirst, $this->sideEffectCounts());
        $this->assertSame($before['invoices'] + 1, $afterFirst['invoices']);
        $this->assertSame($before['invoice_items'] + 1, $afterFirst['invoice_items']);
        $this->assertSame($before['cash_flows'] + 1, $afterFirst['cash_flows']);
        $this->assertSame($before['stock_movements'] + 1, $afterFirst['stock_movements']);
        $this->assertSame($before['operations'] + 1, $afterFirst['operations']);
        $this->assertSame($before['participants'] + 1, $afterFirst['participants']);
        $this->assertSame(9, (int) $this->product->fresh()->stock_quantity);
    }

    public function test_same_key_with_changed_payload_returns_structured_conflict_without_side_effects(): void
    {
        $key = 'pos-checkout-conflict-'.uniqid();
        $firstPayload = $this->checkoutPayload();
        $changedPayload = $this->checkoutPayload([
            'subtotal' => 300000,
            'total' => 300000,
            'customer_paid' => 300000,
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'price' => 300000,
                'discount' => 0,
                'serial_ids' => [],
            ]],
        ]);

        $this->postCheckout($firstPayload, $key)->assertOk();
        $afterFirst = $this->sideEffectCounts();

        $conflict = $this->postCheckout($changedPayload, $key);

        $conflict->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'POS_IDEMPOTENCY_PAYLOAD_MISMATCH')
            ->assertJsonMissingPath('errors.idempotency_key');
        $this->assertSame($afterFirst, $this->sideEffectCounts());
        $this->assertSame(9, (int) $this->product->fresh()->stock_quantity);
    }

    public function test_changed_payload_with_new_key_creates_a_new_transaction_once(): void
    {
        $firstPayload = $this->checkoutPayload();
        $changedPayload = $this->checkoutPayload([
            'subtotal' => 300000,
            'total' => 300000,
            'customer_paid' => 300000,
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'price' => 300000,
                'discount' => 0,
                'serial_ids' => [],
            ]],
        ]);
        $before = $this->sideEffectCounts();

        $this->postCheckout($firstPayload, 'pos-checkout-first-'.uniqid())->assertOk();
        $this->postCheckout($changedPayload, 'pos-checkout-second-'.uniqid())->assertOk();
        $after = $this->sideEffectCounts();

        $this->assertSame($before['invoices'] + 2, $after['invoices']);
        $this->assertSame($before['invoice_items'] + 2, $after['invoice_items']);
        $this->assertSame($before['cash_flows'] + 2, $after['cash_flows']);
        $this->assertSame($before['stock_movements'] + 2, $after['stock_movements']);
        $this->assertSame($before['operations'] + 2, $after['operations']);
        $this->assertSame($before['participants'] + 2, $after['participants']);
        $this->assertSame(8, (int) $this->product->fresh()->stock_quantity);
    }

    public function test_derived_unit_price_and_rounding_discount_persist_the_exact_pos_line_total(): void
    {
        $payload = $this->checkoutPayload([
            'subtotal' => 100000,
            'total' => 100000,
            'customer_paid' => 100000,
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 3,
                'price' => 33334,
                'discount' => 2,
                'serial_ids' => [],
            ]],
        ]);

        $response = $this->postCheckout($payload, 'pos-line-total-'.uniqid());

        $response->assertOk()->assertJsonPath('success', true);
        $invoice = Invoice::where('code', $response->json('invoice_code'))->firstOrFail();
        $line = $invoice->items()->sole();

        $this->assertSame(100000.0, (float) $invoice->subtotal);
        $this->assertSame(100000.0, (float) $invoice->total);
        $this->assertSame(3, (int) $line->quantity);
        $this->assertSame(33334.0, (float) $line->price);
        $this->assertSame(2.0, (float) $line->discount);
        $this->assertSame(100000.0, (float) $line->subtotal);
        $this->assertSame(7, (int) $this->product->fresh()->stock_quantity);
    }

    public function test_quick_order_preserves_the_derived_line_total_and_rejects_invalid_discount_before_mutation(): void
    {
        $valid = $this->actingAs($this->admin)->postJson('/api/pos/quick-order', [
            'customer_id' => $this->customer->id,
            'subtotal' => 100000,
            'discount' => 0,
            'total' => 100000,
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 3,
                'price' => 33334,
                'discount' => 2,
            ]],
        ]);

        $valid->assertOk()->assertJsonPath('success', true);
        $order = Order::where('code', $valid->json('order_code'))->firstOrFail();
        $line = $order->items()->sole();

        $this->assertSame(33334.0, (float) $line->price);
        $this->assertSame(2.0, (float) $line->discount);
        $this->assertSame(100000.0, (float) $line->subtotal);
        $this->assertSame(10, (int) $this->product->fresh()->stock_quantity);

        $orderCount = Order::count();
        $invalid = $this->actingAs($this->admin)->postJson('/api/pos/quick-order', [
            'customer_id' => $this->customer->id,
            'subtotal' => 0,
            'discount' => 0,
            'total' => 0,
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'price' => 100000,
                'discount' => 100001,
            ]],
        ]);

        $invalid->assertStatus(422)
            ->assertJsonPath('message', 'Giảm giá dòng không được vượt thành tiền trước giảm giá.')
            ->assertJsonValidationErrors('items.0.discount');
        $this->assertSame($orderCount, Order::count());
        $this->assertSame(10, (int) $this->product->fresh()->stock_quantity);
    }

    public function test_pos_customer_picker_lazily_uses_canonical_customer_debt_for_dual_role_partner(): void
    {
        $dualRole = Customer::create([
            'code' => 'KH-POS-DUAL-DEBT-'.uniqid(),
            'name' => 'Tuấn Béo POS canonical debt',
            'phone' => '091'.random_int(1000000, 9999999),
            'is_customer' => true,
            'is_supplier' => true,
            'status' => 'active',
            'debt_amount' => 205000,
            'supplier_debt_amount' => 205000,
            'total_spent' => 0,
            'total_bought' => 0,
        ]);
        $snapshotColumns = [
            'debt_amount', 'supplier_debt_amount', 'is_customer', 'is_supplier',
            'status', 'merged_into_id', 'updated_at',
        ];
        $before = Customer::query()
            ->whereKey($dualRole->id)
            ->firstOrFail($snapshotColumns)
            ->toJson();

        $search = $this->actingAs($this->admin)
            ->getJson('/api/pos/customers?search='.urlencode($dualRole->code));

        $search->assertOk();
        $row = collect($search->json())->firstWhere('id', $dualRole->id);
        $this->assertNotNull($row);
        $this->assertSame(
            ['id', 'code', 'name', 'phone', 'customer_group'],
            array_keys($row)
        );
        $this->assertArrayNotHasKey('debt_amount', $row);
        $this->assertArrayNotHasKey('customer_screen_debt', $row);

        $display = $this->actingAs($this->admin)
            ->getJson("/api/pos/customers/{$dualRole->id}/debt-display");

        $display->assertOk()
            ->assertJsonPath('id', $dualRole->id)
            ->assertJsonPath('is_dual_role_partner', true)
            ->assertJsonPath('debt_display_contract', 'net_balance');
        $this->assertSame(0.0, (float) $display->json('customer_screen_debt'));
        $this->assertSame(
            PartnerDebtDisplayBalance::customerScreen($dualRole->fresh()),
            (float) $display->json('customer_screen_debt')
        );
        $after = Customer::query()
            ->whereKey($dualRole->id)
            ->firstOrFail($snapshotColumns)
            ->toJson();
        $this->assertSame($before, $after);
    }

    public function test_pos_customer_debt_endpoint_rejects_non_customer_inactive_and_merged_partners_without_mutation(): void
    {
        $supplierOnly = Customer::create([
            'code' => 'NCC-POS-DEBT-'.uniqid(),
            'name' => 'Supplier only POS debt',
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
        ]);
        $inactive = Customer::create([
            'code' => 'KH-POS-INACTIVE-'.uniqid(),
            'name' => 'Inactive POS debt',
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'inactive',
        ]);
        $merged = Customer::create([
            'code' => 'KH-POS-MERGED-'.uniqid(),
            'name' => 'Merged POS debt',
            'is_customer' => true,
            'is_supplier' => false,
            'status' => 'inactive',
            'merged_into_id' => $this->customer->id,
            'merged_at' => now(),
        ]);
        $ids = [$supplierOnly->id, $inactive->id, $merged->id];
        $before = Customer::query()
            ->whereKey($ids)
            ->get(['id', 'is_customer', 'is_supplier', 'status', 'merged_into_id', 'debt_amount', 'supplier_debt_amount', 'updated_at'])
            ->keyBy('id')
            ->toArray();

        foreach ($ids as $id) {
            $this->actingAs($this->admin)
                ->getJson("/api/pos/customers/{$id}/debt-display")
                ->assertNotFound();
        }

        $after = Customer::query()
            ->whereKey($ids)
            ->get(['id', 'is_customer', 'is_supplier', 'status', 'merged_into_id', 'debt_amount', 'supplier_debt_amount', 'updated_at'])
            ->keyBy('id')
            ->toArray();
        $this->assertSame($before, $after);
    }

    private function checkoutPayload(array $overrides = []): array
    {
        return array_replace([
            'customer_id' => $this->customer->id,
            'subtotal' => 200000,
            'discount' => 0,
            'total' => 200000,
            'customer_paid' => 200000,
            'sale_time' => '2026-07-20 10:00:00',
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'price' => 200000,
                'discount' => 0,
                'serial_ids' => [],
            ]],
        ], $overrides);
    }

    private function postCheckout(array $payload, string $key)
    {
        return $this->actingAs($this->admin)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/pos/checkout', $payload);
    }

    private function sideEffectCounts(): array
    {
        $operationIds = PartnerDebtOperation::query()
            ->where('operation_type', 'debt.mutation.invoice_sale_create')
            ->pluck('id');

        return [
            'invoices' => Invoice::where('customer_id', $this->customer->id)->count(),
            'invoice_items' => InvoiceItem::whereIn(
                'invoice_id',
                Invoice::where('customer_id', $this->customer->id)->pluck('id')
            )->count(),
            'cash_flows' => CashFlow::where('target_id', $this->customer->id)->count(),
            'stock_movements' => StockMovement::where('product_id', $this->product->id)->count(),
            'operations' => $operationIds->count(),
            'participants' => PartnerDebtOperationParticipant::whereIn('operation_id', $operationIds)->count(),
        ];
    }
}
