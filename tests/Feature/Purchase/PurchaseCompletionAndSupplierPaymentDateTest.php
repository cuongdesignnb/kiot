<?php

namespace Tests\Feature\Purchase;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseCompletionAndSupplierPaymentDateTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_completed_purchase_is_persisted_as_completed(): void
    {
        $admin = $this->admin();
        $supplier = $this->supplier();
        $product = $this->product();

        $response = $this->actingAs($admin)->postJson('/purchases', [
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'purchase_date' => '2026-08-18 08:30:00',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 100000,
                'discount' => 0,
            ]],
            'paid_amount' => 0,
            'payment_method' => 'cash',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('purchases', [
            'supplier_id' => $supplier->id,
            'status' => 'completed',
        ]);
    }

    public function test_invalid_purchase_status_is_rejected(): void
    {
        $admin = $this->admin();
        $supplier = $this->supplier();
        $product = $this->product();

        $this->actingAs($admin)
            ->postJson('/purchases', [
                'supplier_id' => $supplier->id,
                'status' => 'finished',
                'purchase_date' => '2026-08-18 08:30:00',
                'items' => [[
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => 100000,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_repeating_the_same_purchase_submission_does_not_duplicate_the_purchase(): void
    {
        $admin = $this->admin();
        $supplier = $this->supplier();
        $product = $this->product();
        $payload = [
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'purchase_date' => '2026-08-18 08:30:00',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 100000,
                'discount' => 0,
            ]],
            'paid_amount' => 0,
            'payment_method' => 'cash',
        ];
        $headers = ['Idempotency-Key' => 'purchase-complete-'.uniqid()];

        $this->actingAs($admin)->postJson('/purchases', $payload, $headers)->assertRedirect();
        $this->actingAs($admin)->postJson('/purchases', $payload, $headers)->assertRedirect();

        $this->assertSame(1, Purchase::where('supplier_id', $supplier->id)->count());
    }

    public function test_backdated_supplier_payment_accepts_vietnamese_display_date_without_allocating_future_purchase(): void
    {
        Carbon::setTestNow('2026-08-18 16:00:37');
        $admin = $this->admin();
        $supplier = $this->supplier(['supplier_debt_amount' => 100000]);
        $purchase = Purchase::create([
            'code' => 'PN-DATE-GUARD-'.uniqid(),
            'supplier_id' => $supplier->id,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'debt_amount' => 100000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-07-30 15:23:00'),
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/suppliers/{$supplier->id}/payment", [
                'amount' => 50000,
                'date' => '30/07/2026 14:09',
            ], [
                'Idempotency-Key' => 'supplier-payment-date-'.uniqid(),
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(100000.0, (float) $purchase->fresh()->debt_amount);
        $this->assertSame(50000.0, (float) $supplier->fresh()->supplier_debt_amount);
        $this->assertSame(1, CashFlow::where('reference_type', 'SupplierPayment')->where('target_id', $supplier->id)->count());
        $this->assertSame(1, SupplierDebtTransaction::where('supplier_id', $supplier->id)->where('type', 'payment')->count());
        $this->assertSame(0, DB::table('supplier_payment_allocations')->where('supplier_id', $supplier->id)->count());
        $cashFlow = CashFlow::where('target_id', $supplier->id)
            ->where('reference_type', 'SupplierPayment')
            ->firstOrFail();
        $this->assertSame('2026-07-30 14:09:00', Carbon::parse($cashFlow->time)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-18 16:00:37', Carbon::parse($cashFlow->created_at)->format('Y-m-d H:i:s'));
        $ledger = SupplierDebtTransaction::where('supplier_id', $supplier->id)
            ->where('type', 'payment')
            ->firstOrFail();
        $this->assertSame('2026-08-18 16:00:37', Carbon::parse($ledger->created_at)->format('Y-m-d H:i:s'));

        $timelineResponse = $this->actingAs($admin)
            ->getJson("/api/suppliers/{$supplier->id}/debt-transactions?page=1&per_page=100");
        $timelineResponse->assertOk();
        $entries = collect($timelineResponse->json('entries'));
        $paymentCode = $response->json('payment.payment_code');
        $paymentEntry = $entries->firstWhere('code', $paymentCode);
        $this->assertNotNull($paymentEntry);
        $this->assertSame('2026-07-30 14:09:00', Carbon::parse($paymentEntry['business_time'])->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-18 16:00:37', Carbon::parse($paymentEntry['created_at'])->format('Y-m-d H:i:s'));

        $purchaseIndex = $entries->search(fn (array $entry): bool => ($entry['code'] ?? null) === $purchase->code);
        $paymentIndex = $entries->search(fn (array $entry): bool => ($entry['code'] ?? null) === $paymentCode);
        $this->assertIsInt($purchaseIndex);
        $this->assertIsInt($paymentIndex);
        $this->assertTrue($purchaseIndex < $paymentIndex);
    }

    public function test_malformed_supplier_payment_date_returns_vietnamese_422_without_mutation(): void
    {
        $admin = $this->admin();
        $supplier = $this->supplier(['supplier_debt_amount' => 100000]);
        $purchase = Purchase::create([
            'code' => 'PN-DATE-MALFORMED-'.uniqid(),
            'supplier_id' => $supplier->id,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'debt_amount' => 100000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-08-18 08:30:00'),
        ]);

        $before = $this->financialSnapshot($supplier, $purchase);
        $response = $this->actingAs($admin)
            ->postJson("/api/suppliers/{$supplier->id}/payment", [
                'amount' => 50000,
                'date' => '30/99/2026 14:09',
            ], [
                'Idempotency-Key' => 'supplier-payment-malformed-'.uniqid(),
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('code', 'SUPPLIER_PAYMENT_DATE_INVALID')
            ->assertJsonValidationErrors('date')
            ->assertJsonPath('errors.date.0', 'Ngày thanh toán không hợp lệ. Vui lòng nhập dd/MM/yyyy HH:mm.');

        $this->assertSame($before, $this->financialSnapshot($supplier->fresh(), $purchase->fresh()));
    }

    public function test_supplier_payment_on_or_after_purchase_date_is_recorded(): void
    {
        $admin = $this->admin();
        $supplier = $this->supplier(['supplier_debt_amount' => 100000]);
        $purchase = Purchase::create([
            'code' => 'PN-DATE-VALID-'.uniqid(),
            'supplier_id' => $supplier->id,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'debt_amount' => 100000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-08-18 08:30:00'),
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/suppliers/{$supplier->id}/payment", [
                'amount' => 50000,
                'date' => '2026-08-18 09:00:00',
            ], [
                'Idempotency-Key' => 'supplier-payment-valid-'.uniqid(),
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertSame(50000.0, (float) $purchase->fresh()->debt_amount);
        $this->assertSame(50000.0, (float) $supplier->fresh()->supplier_debt_amount);
        $this->assertSame(1, CashFlow::where('reference_type', 'SupplierPayment')->where('target_id', $supplier->id)->count());
        $this->assertSame(1, SupplierDebtTransaction::where('supplier_id', $supplier->id)->where('type', 'payment')->count());
    }

    public function test_supplier_payment_in_locked_period_returns_422_without_mutation(): void
    {
        $admin = $this->admin();
        $supplier = $this->supplier(['supplier_debt_amount' => 100000]);
        $purchase = Purchase::create([
            'code' => 'PN-LOCK-DATE-'.uniqid(),
            'supplier_id' => $supplier->id,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'debt_amount' => 100000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-08-18 08:30:00'),
        ]);
        Setting::set('lock_date', '2026-08-18', 'system', 'string');

        $before = $this->financialSnapshot($supplier, $purchase);
        $response = $this->actingAs($admin)
            ->postJson("/api/suppliers/{$supplier->id}/payment", [
                'amount' => 50000,
                'date' => '2026-08-18 09:00:00',
            ], [
                'Idempotency-Key' => 'supplier-payment-lock-'.uniqid(),
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('code', 'SUPPLIER_PAYMENT_DATE_INVALID')
            ->assertJsonValidationErrors('date');
        $this->assertSame($before, $this->financialSnapshot($supplier->fresh(), $purchase->fresh()));
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Purchase payment test admin',
            'email' => 'purchase-payment-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function supplier(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'NCC-DATE-'.uniqid(),
            'name' => 'Supplier payment date test',
            'phone' => '09'.random_int(10000000, 99999999),
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'total_bought' => 0,
        ], $overrides));
    }

    private function product(): Product
    {
        return Product::create([
            'sku' => 'SKU-DATE-'.uniqid(),
            'name' => 'Purchase completion test product',
            'cost_price' => 100000,
            'retail_price' => 150000,
            'stock_quantity' => 0,
            'inventory_total_cost' => 0,
            'has_serial' => false,
            'is_active' => true,
        ]);
    }

    private function financialSnapshot(Customer $supplier, Purchase $purchase): array
    {
        return [
            'supplier' => [
                'supplier_debt_amount' => (float) $supplier->fresh()->supplier_debt_amount,
            ],
            'purchase' => [
                'paid_amount' => (float) $purchase->fresh()->paid_amount,
                'debt_amount' => (float) $purchase->fresh()->debt_amount,
            ],
            'cash_flows' => CashFlow::where('target_id', $supplier->id)->where('reference_type', 'SupplierPayment')->count(),
            'supplier_debt_transactions' => SupplierDebtTransaction::where('supplier_id', $supplier->id)->where('type', 'payment')->count(),
            'allocations' => DB::table('supplier_payment_allocations')->where('supplier_id', $supplier->id)->count(),
        ];
    }
}
