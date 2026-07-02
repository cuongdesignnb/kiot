<?php

namespace Tests\Feature\POS;

use App\Models\CashFlow;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\Role;
use App\Models\SerialImei;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class Step246BPosReturnExchangeTest extends TestCase
{
    use DatabaseTransactions;

    private function adminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin246b'], [
            'display_name' => 'Admin',
            'permissions' => ['*'],
            'is_system' => true,
        ]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function userWith(array $perms): User
    {
        $role = Role::create([
            'name' => 'role246b-' . uniqid(),
            'display_name' => 'Test',
            'permissions' => $perms,
            'is_system' => false,
        ]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'KH246B-' . uniqid(),
            'name' => 'KH 246B',
            'phone' => '090' . rand(1000000, 9999999),
            'debt_amount' => 0,
            'total_spent' => 0,
            'is_customer' => true,
        ]);
    }

    private function product(bool $serial = false, int $stock = 10, float $cost = 100000, float $price = 200000): Product
    {
        $cat = Category::firstOrCreate(['name' => 'Cat 246B']);

        return Product::create([
            'sku' => 'P246B-' . uniqid(),
            'name' => 'Product 246B',
            'cost_price' => $cost,
            'retail_price' => $price,
            'stock_quantity' => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active' => true,
            'has_serial' => $serial,
            'category_id' => $cat->id,
        ]);
    }

    private function serial(Product $product, string $status = 'in_stock'): SerialImei
    {
        return SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SN246B-' . uniqid(),
            'status' => $status,
            'cost_price' => $product->cost_price,
            'original_cost' => $product->cost_price,
        ]);
    }

    private function sell(User $admin, Customer $customer, Product $product, int $qty, float $price, float $paid, array $serials = []): Invoice
    {
        $this->actingAs($admin)->postJson('/api/pos/checkout', [
            'customer_id' => $customer->id,
            'subtotal' => $qty * $price,
            'discount' => 0,
            'total' => $qty * $price,
            'customer_paid' => $paid,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => $qty,
                'price' => $price,
                'discount' => 0,
                'serial_ids' => array_map(fn ($s) => $s->id, $serials),
            ]],
        ])->assertOk();

        return Invoice::where('customer_id', $customer->id)->latest('id')->first();
    }

    private function exchangePayload(Invoice $invoice, Product $returnProduct, Product $exchangeProduct, float $returnPrice, float $exchangePrice, array $opts = []): array
    {
        return [
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'branch_id' => $invoice->branch_id,
            'payment_method' => 'cash',
            'note' => 'STEP 24.6B test',
            'return' => [
                'discount' => 0,
                'fee_type' => 'amount',
                'fee_value' => 0,
                'items' => [[
                    'product_id' => $returnProduct->id,
                    'invoice_item_id' => $invoice->items()->first()->id,
                    'qty' => 1,
                    'price' => $returnPrice,
                    'discount' => 0,
                    'serial_ids' => $opts['return_serial_ids'] ?? [],
                ]],
            ],
            'exchange' => [
                'discount' => 0,
                'items' => [[
                    'product_id' => $exchangeProduct->id,
                    'quantity' => 1,
                    'price' => $exchangePrice,
                    'discount' => 0,
                    'serial_ids' => $opts['exchange_serial_ids'] ?? [],
                ]],
            ],
        ];
    }

    public function test_return_exchange_route_requires_permission(): void
    {
        $user = $this->userWith(['pos.use']);

        $this->actingAs($user)->postJson('/api/pos/return-exchange', [])
            ->assertStatus(403);
    }

    public function test_return_exchange_route_exists_and_requires_auth_or_validation(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->postJson('/api/pos/return-exchange', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'POS_RETURN_EXCHANGE_VALIDATION_FAILED')
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_return_exchange_success_response_contains_documents_and_settlement(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 4550000);
        $productB = $this->product(false, 5, 100000, 4550000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 4550000, 4550000);

        $res = $this->actingAs($admin)->postJson('/api/pos/return-exchange',
            $this->exchangePayload($invoice, $productA, $productB, 4550000, 4550000)
        );

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('settlement.return_total', 4550000)
            ->assertJsonPath('settlement.exchange_total', 4550000)
            ->assertJsonPath('settlement.customer_pays', 0)
            ->assertJsonPath('settlement.refund_to_customer', 0)
            ->assertJsonStructure([
                'return' => ['id', 'code'],
                'exchange_invoice' => ['id', 'code'],
                'settlement' => ['return_total', 'exchange_total', 'customer_pays', 'refund_to_customer'],
            ]);
    }

    public function test_return_exchange_validation_error_returns_clear_422(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(false, 5, 100000, 100000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);
        $payload = $this->exchangePayload($invoice, $productA, $productB, 100000, 0);
        $returnsBefore = OrderReturn::count();
        $invoicesBefore = Invoice::count();

        $this->actingAs($admin)->postJson('/api/pos/return-exchange', $payload)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'POS_RETURN_EXCHANGE_VALIDATION_FAILED')
            ->assertJsonValidationErrors('exchange.items');

        $this->assertSame($returnsBefore, OrderReturn::count());
        $this->assertSame($invoicesBefore, Invoice::count());
    }

    public function test_return_exchange_server_error_returns_debug_id(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(false, 5, 100000, 100000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);
        $payload = $this->exchangePayload($invoice, $productA, $productB, 100000, 100000);

        $this->mock(\App\Services\PosReturnExchangeService::class, function ($mock) {
            $mock->shouldReceive('create')->once()->andThrow(new \RuntimeException('forced failure'));
        });

        $this->actingAs($admin)->postJson('/api/pos/return-exchange', $payload)
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'POS_RETURN_EXCHANGE_FAILED')
            ->assertJsonStructure(['debug_id', 'message']);
    }

    public function test_return_exchange_normal_product_customer_pays_difference(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(false, 5, 150000, 150000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);
        $cashBefore = CashFlow::count();

        $res = $this->actingAs($admin)->postJson('/api/pos/return-exchange',
            $this->exchangePayload($invoice, $productA, $productB, 100000, 150000)
        );

        $res->assertOk()->assertJsonPath('settlement.customer_pays', 50000);
        $this->assertSame(5, (int) $productA->fresh()->stock_quantity);
        $this->assertSame(4, (int) $productB->fresh()->stock_quantity);
        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
        $this->assertTrue(CashFlow::where('type', 'receipt')->where('amount', 50000)->exists());
        $this->assertSame($cashBefore + 1, CashFlow::count());
    }

    public function test_return_exchange_normal_product_refunds_difference(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(false, 5, 50000, 50000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);

        $res = $this->actingAs($admin)->postJson('/api/pos/return-exchange',
            $this->exchangePayload($invoice, $productA, $productB, 100000, 50000)
        );

        $res->assertOk()->assertJsonPath('settlement.refund_to_customer', 50000);
        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
        $this->assertTrue(CashFlow::where('type', 'payment')->where('amount', 50000)->exists());
    }

    public function test_return_exchange_equal_value_no_cashflow(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(false, 5, 100000, 100000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);
        $cashBefore = CashFlow::count();

        $res = $this->actingAs($admin)->postJson('/api/pos/return-exchange',
            $this->exchangePayload($invoice, $productA, $productB, 100000, 100000)
        );

        $res->assertOk()->assertJsonPath('settlement.customer_pays', 0);
        $this->assertSame($cashBefore, CashFlow::count());
        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
    }

    public function test_return_exchange_equal_value_does_not_create_negative_customer_debt(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(false, 5, 100000, 100000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);

        $res = $this->actingAs($admin)->postJson('/api/pos/return-exchange',
            $this->exchangePayload($invoice, $productA, $productB, 100000, 100000)
        );

        $res->assertOk()->assertJsonPath('settlement.refund_to_customer', 0);
        $returnCode = $res->json('return.code');
        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(0, CustomerDebt::where('ref_code', $returnCode)->where('type', 'adjustment')->where('amount', '>', 0)->count());
    }

    public function test_return_exchange_cheaper_item_fully_refunded_does_not_leave_credit(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(false, 5, 50000, 50000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);

        $res = $this->actingAs($admin)->postJson('/api/pos/return-exchange',
            $this->exchangePayload($invoice, $productA, $productB, 100000, 50000)
        );

        $res->assertOk()->assertJsonPath('settlement.refund_to_customer', 50000);
        $returnCode = $res->json('return.code');
        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(1, CustomerDebt::where('ref_code', $returnCode)->where('type', 'adjustment')->where('amount', '>', 0)->count());
        $this->assertSame(50000.0, (float) CustomerDebt::where('ref_code', $returnCode)->where('type', 'adjustment')->where('amount', '>', 0)->sum('amount'));
    }

    public function test_return_exchange_more_expensive_paid_in_full_does_not_leave_debt(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(false, 5, 150000, 150000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);

        $this->actingAs($admin)->postJson('/api/pos/return-exchange',
            $this->exchangePayload($invoice, $productA, $productB, 100000, 150000)
        )->assertOk()->assertJsonPath('settlement.customer_pays', 50000);

        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
    }

    public function test_return_exchange_does_not_double_apply_paid_refund_settlement(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(false, 5, 50000, 50000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);

        $res = $this->actingAs($admin)->postJson('/api/pos/return-exchange',
            $this->exchangePayload($invoice, $productA, $productB, 100000, 50000)
        );

        $res->assertOk();
        $returnCode = $res->json('return.code');
        $this->assertSame(1, CustomerDebt::where('ref_code', $returnCode)->where('type', 'adjustment')->where('amount', '>', 0)->count());
        $this->assertSame(50000.0, (float) CustomerDebt::where('ref_code', $returnCode)->where('type', 'adjustment')->where('amount', '>', 0)->sum('amount'));
    }

    public function test_return_exchange_rejects_exchange_item_zero_price(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 4550000);
        $productB = $this->product(false, 5, 100000, 4550000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 4550000, 4550000);
        $payload = $this->exchangePayload($invoice, $productA, $productB, 4550000, 0);
        $returnsBefore = OrderReturn::count();
        $invoicesBefore = Invoice::count();
        $cashBefore = CashFlow::count();
        $stockBeforeA = (int) $productA->fresh()->stock_quantity;
        $stockBeforeB = (int) $productB->fresh()->stock_quantity;

        $this->actingAs($admin)->postJson('/api/pos/return-exchange', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('exchange.items');

        $this->assertSame($returnsBefore, OrderReturn::count());
        $this->assertSame($invoicesBefore, Invoice::count());
        $this->assertSame($cashBefore, CashFlow::count());
        $this->assertSame($stockBeforeA, (int) $productA->fresh()->stock_quantity);
        $this->assertSame($stockBeforeB, (int) $productB->fresh()->stock_quantity);
        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
    }

    public function test_return_exchange_rejects_exchange_discount_greater_than_line_gross(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(false, 5, 100000, 100000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);
        $payload = $this->exchangePayload($invoice, $productA, $productB, 100000, 100000);
        $payload['exchange']['items'][0]['discount'] = 150000;
        $returnsBefore = OrderReturn::count();
        $invoicesBefore = Invoice::count();
        $cashBefore = CashFlow::count();
        $stockBeforeA = (int) $productA->fresh()->stock_quantity;
        $stockBeforeB = (int) $productB->fresh()->stock_quantity;

        $this->actingAs($admin)->postJson('/api/pos/return-exchange', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('exchange.items');

        $this->assertSame($returnsBefore, OrderReturn::count());
        $this->assertSame($invoicesBefore, Invoice::count());
        $this->assertSame($cashBefore, CashFlow::count());
        $this->assertSame($stockBeforeA, (int) $productA->fresh()->stock_quantity);
        $this->assertSame($stockBeforeB, (int) $productB->fresh()->stock_quantity);
        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
    }

    public function test_return_exchange_equal_value_has_zero_refund_and_zero_customer_pays(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 4550000);
        $productB = $this->product(false, 5, 100000, 4550000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 4550000, 4550000);
        $cashBefore = CashFlow::count();

        $res = $this->actingAs($admin)->postJson('/api/pos/return-exchange',
            $this->exchangePayload($invoice, $productA, $productB, 4550000, 4550000)
        );

        $res->assertOk()
            ->assertJsonPath('settlement.refund_to_customer', 0)
            ->assertJsonPath('settlement.customer_pays', 0);
        $this->assertSame($cashBefore, CashFlow::count());
        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
    }

    public function test_return_exchange_rejects_stale_paid_to_customer_when_net_zero(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 4550000);
        $productB = $this->product(false, 5, 100000, 4550000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 4550000, 4550000);
        $payload = $this->exchangePayload($invoice, $productA, $productB, 4550000, 4550000);
        $payload['return']['paid_to_customer'] = 4550000;
        $returnsBefore = OrderReturn::count();
        $invoicesBefore = Invoice::count();
        $cashBefore = CashFlow::count();

        $this->actingAs($admin)->postJson('/api/pos/return-exchange', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('return.paid_to_customer');

        $this->assertSame($returnsBefore, OrderReturn::count());
        $this->assertSame($invoicesBefore, Invoice::count());
        $this->assertSame($cashBefore, CashFlow::count());
    }

    public function test_return_exchange_rejects_stale_customer_paid_when_net_zero(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 4550000);
        $productB = $this->product(false, 5, 100000, 4550000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 4550000, 4550000);
        $payload = $this->exchangePayload($invoice, $productA, $productB, 4550000, 4550000);
        $payload['exchange']['customer_paid'] = 100000;
        $returnsBefore = OrderReturn::count();
        $invoicesBefore = Invoice::count();
        $cashBefore = CashFlow::count();

        $this->actingAs($admin)->postJson('/api/pos/return-exchange', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('exchange.customer_paid');

        $this->assertSame($returnsBefore, OrderReturn::count());
        $this->assertSame($invoicesBefore, Invoice::count());
        $this->assertSame($cashBefore, CashFlow::count());
    }

    public function test_return_exchange_serial_product_restores_return_serial_and_sells_exchange_serial(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(true, 0, 100000, 100000);
        $productB = $this->product(true, 0, 150000, 150000);
        $returnSerial = $this->serial($productA);
        $exchangeSerial = $this->serial($productB);
        $productA->update(['stock_quantity' => 1, 'inventory_total_cost' => 100000]);
        $productB->update(['stock_quantity' => 1, 'inventory_total_cost' => 150000]);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000, [$returnSerial]);

        $res = $this->actingAs($admin)->postJson('/api/pos/return-exchange',
            $this->exchangePayload($invoice, $productA, $productB, 100000, 150000, [
                'return_serial_ids' => [$returnSerial->id],
                'exchange_serial_ids' => [$exchangeSerial->id],
            ])
        );

        $res->assertOk();
        $this->assertSame('in_stock', $returnSerial->fresh()->status);
        $this->assertSame('sold', $exchangeSerial->fresh()->status);
        $this->assertSame((int) $res->json('exchange_invoice.id'), (int) $exchangeSerial->fresh()->invoice_id);
    }

    public function test_return_exchange_rejects_over_return(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(false, 5, 150000, 150000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);
        $payload = $this->exchangePayload($invoice, $productA, $productB, 100000, 150000);
        $payload['return']['items'][0]['qty'] = 2;

        $this->actingAs($admin)->postJson('/api/pos/return-exchange', $payload)
            ->assertStatus(422);
    }

    public function test_return_exchange_rejects_out_of_stock_normal_product(): void
    {
        Setting::set('inventory_allow_oversell', true);
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(false, 0, 150000, 150000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);
        $returnsBefore = OrderReturn::count();
        $invoicesBefore = Invoice::count();

        $this->actingAs($admin)->postJson('/api/pos/return-exchange',
            $this->exchangePayload($invoice, $productA, $productB, 100000, 150000)
        )->assertStatus(422);

        $this->assertSame($returnsBefore, OrderReturn::count());
        $this->assertSame($invoicesBefore, Invoice::count());
        $this->assertSame(0, (int) $productB->fresh()->stock_quantity);
    }

    public function test_return_exchange_rejects_quantity_over_available_stock(): void
    {
        Setting::set('inventory_allow_oversell', true);
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(false, 1, 150000, 150000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);
        $payload = $this->exchangePayload($invoice, $productA, $productB, 100000, 150000);
        $payload['exchange']['items'][0]['quantity'] = 2;
        $returnsBefore = OrderReturn::count();
        $invoicesBefore = Invoice::count();

        $this->actingAs($admin)->postJson('/api/pos/return-exchange', $payload)
            ->assertStatus(422);

        $this->assertSame($returnsBefore, OrderReturn::count());
        $this->assertSame($invoicesBefore, Invoice::count());
        $this->assertSame(1, (int) $productB->fresh()->stock_quantity);
    }

    public function test_return_exchange_rejects_invalid_return_serial(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(true, 0, 100000, 100000);
        $productB = $this->product(false, 5, 150000, 150000);
        $sold = $this->serial($productA);
        $invalid = $this->serial($productA);
        $productA->update(['stock_quantity' => 2, 'inventory_total_cost' => 200000]);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000, [$sold]);

        $this->actingAs($admin)->postJson('/api/pos/return-exchange',
            $this->exchangePayload($invoice, $productA, $productB, 100000, 150000, [
                'return_serial_ids' => [$invalid->id],
            ])
        )->assertStatus(422);
    }

    public function test_return_exchange_rejects_invalid_exchange_serial(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(true, 0, 150000, 150000);
        $badSerial = $this->serial($productB, 'sold');
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);

        $this->actingAs($admin)->postJson('/api/pos/return-exchange',
            $this->exchangePayload($invoice, $productA, $productB, 100000, 150000, [
                'exchange_serial_ids' => [$badSerial->id],
            ])
        )->assertStatus(422);
    }

    public function test_return_exchange_rejects_under_repair_serial(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(true, 0, 150000, 150000);
        $repairingSerial = $this->serial($productB, 'in_stock');
        $repairingSerial->update(['repair_status' => 'repairing']);
        $productB->update(['stock_quantity' => 1, 'inventory_total_cost' => 150000]);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);

        $this->actingAs($admin)->postJson('/api/pos/return-exchange',
            $this->exchangePayload($invoice, $productA, $productB, 100000, 150000, [
                'exchange_serial_ids' => [$repairingSerial->id],
            ])
        )->assertStatus(422);

        $this->assertSame('in_stock', $repairingSerial->fresh()->status);
        $this->assertNull($repairingSerial->fresh()->invoice_id);
    }

    public function test_return_exchange_rejects_returned_to_supplier_serial(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(true, 0, 150000, 150000);
        $returnedSerial = $this->serial($productB, 'in_stock');
        $returnedSerial->update(['purchase_return_id' => 12345]);
        $productB->update(['stock_quantity' => 1, 'inventory_total_cost' => 150000]);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);

        $this->actingAs($admin)->postJson('/api/pos/return-exchange',
            $this->exchangePayload($invoice, $productA, $productB, 100000, 150000, [
                'exchange_serial_ids' => [$returnedSerial->id],
            ])
        )->assertStatus(422);

        $this->assertSame('in_stock', $returnedSerial->fresh()->status);
        $this->assertNull($returnedSerial->fresh()->invoice_id);
    }

    public function test_return_exchange_rolls_back_all_when_exchange_invoice_fails(): void
    {
        Setting::set('inventory_allow_oversell', false);
        $admin = $this->adminUser();
        $customer = $this->customer();
        $productA = $this->product(false, 5, 100000, 100000);
        $productB = $this->product(false, 0, 150000, 150000);
        $invoice = $this->sell($admin, $customer, $productA, 1, 100000, 100000);
        $returnsBefore = OrderReturn::count();
        $invoicesBefore = Invoice::count();
        $stockBefore = (int) $productA->fresh()->stock_quantity;

        $this->actingAs($admin)->postJson('/api/pos/return-exchange',
            $this->exchangePayload($invoice, $productA, $productB, 100000, 150000)
        )->assertStatus(422);

        $this->assertSame($returnsBefore, OrderReturn::count());
        $this->assertSame($invoicesBefore, Invoice::count());
        $this->assertSame($stockBefore, (int) $productA->fresh()->stock_quantity);
        $this->assertSame(0.0, (float) $customer->fresh()->debt_amount);
    }

    public function test_return_only_flow_still_works_after_extract(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $product = $this->product(false, 5, 100000, 100000);
        $invoice = $this->sell($admin, $customer, $product, 1, 100000, 100000);

        $this->actingAs($admin)->post(route('returns.store'), [
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'subtotal' => 100000,
            'total' => 100000,
            'paid_to_customer' => 100000,
            'items' => [[
                'product_id' => $product->id,
                'invoice_item_id' => $invoice->items()->first()->id,
                'qty' => 1,
                'price' => 100000,
            ]],
        ])->assertRedirect();

        $this->assertSame(5, (int) $product->fresh()->stock_quantity);
    }

    public function test_pos_checkout_still_works_after_exchange_changes(): void
    {
        $admin = $this->adminUser();
        $customer = $this->customer();
        $product = $this->product(false, 5, 100000, 100000);

        $this->actingAs($admin)->postJson('/api/pos/checkout', [
            'customer_id' => $customer->id,
            'subtotal' => 100000,
            'discount' => 0,
            'total' => 100000,
            'customer_paid' => 100000,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 100000,
                'discount' => 0,
            ]],
        ])->assertOk();

        $this->assertSame(4, (int) $product->fresh()->stock_quantity);
    }
}
