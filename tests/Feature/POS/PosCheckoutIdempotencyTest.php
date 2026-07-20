<?php

namespace Tests\Feature\POS;

use App\Models\CashFlow;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PartnerDebtOperation;
use App\Models\PartnerDebtOperationParticipant;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
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
