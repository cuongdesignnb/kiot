<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\CustomerDebt;
use App\Models\CustomerPaymentDiscount;
use App\Models\CustomerPaymentDiscountAllocation;
use App\Services\CustomerDebtService;
use App\Services\CustomerPaymentDiscountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerPaymentDiscountTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->admin = User::create([
            'name' => 'Admin Test Discount',
            'email' => 'admin-discount-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $this->customer = Customer::create([
            'code' => 'KH-TEST-' . uniqid(),
            'name' => 'Nguyễn Văn Test',
            'phone' => '090' . rand(1000000, 9999999),
            'debt_amount' => 0,
            'is_customer' => true,
        ]);

        // Ghi nhận nợ ban đầu vào ledger
        app(CustomerDebtService::class)->recordSale(
            $this->customer->id,
            340000,
            null,
            'Nợ ban đầu'
        );
    }

    public function test_create_discount_without_allocation(): void
    {
        $payload = [
            'amount' => 100000,
            'allocate_to_invoices' => false,
            'note' => 'Chiết khấu không phân bổ',
        ];

        $resp = $this->actingAs($this->admin)
            ->postJson("/customers/{$this->customer->id}/payment-discounts", $payload);

        $resp->assertOk();
        $resp->assertJsonPath('success', true);

        // Verify discount table record
        $this->assertDatabaseHas('customer_payment_discounts', [
            'customer_id' => $this->customer->id,
            'amount' => 100000,
            'allocate_to_invoices' => false,
            'status' => 'active',
        ]);

        // Verify customer debt is updated
        $this->customer->refresh();
        $this->assertEquals(240000, (float) $this->customer->debt_amount);

        // Verify ledger record
        $this->assertDatabaseHas('customer_debts', [
            'customer_id' => $this->customer->id,
            'amount' => -100000,
            'type' => 'adjustment',
        ]);

        // Verify no allocations
        $discount = CustomerPaymentDiscount::where('customer_id', $this->customer->id)->first();
        $this->assertCount(0, $discount->allocations);
    }

    public function test_create_discount_with_invoice_allocation(): void
    {
        // Tạo hóa đơn nợ cho khách
        $invoice = Invoice::create([
            'code' => 'HD-TEST-' . uniqid(),
            'customer_id' => $this->customer->id,
            'total' => 440000,
            'customer_paid' => 100000,
            'status' => 'Hoàn thành',
        ]);

        $payload = [
            'amount' => 100000,
            'allocate_to_invoices' => true,
            'allocations' => [
                [
                    'invoice_id' => $invoice->id,
                    'amount' => 100000,
                ]
            ],
            'note' => 'Phân bổ vào hóa đơn',
        ];

        $resp = $this->actingAs($this->admin)
            ->postJson("/customers/{$this->customer->id}/payment-discounts", $payload);

        $resp->assertOk();
        $resp->assertJsonPath('success', true);

        // Verify allocation record
        $this->assertDatabaseHas('customer_payment_discount_allocations', [
            'invoice_id' => $invoice->id,
            'amount' => 100000,
        ]);

        // Verify customer debt
        $this->customer->refresh();
        $this->assertEquals(240000, (float) $this->customer->debt_amount);

        // Verify outstandingInvoices has deducted the discount
        $out = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/outstanding-invoices");
        $out->assertOk();

        // 440k total - 100k paid - 100k discount = 240k remaining
        $out->assertJsonFragment([
            'id' => $invoice->id,
            'remaining' => 240000,
        ]);

        // Verify invoice customer_paid is not modified in DB
        $invoice->refresh();
        $this->assertEquals(100000, (float) $invoice->customer_paid);
    }

    public function test_cannot_discount_exceeding_debt_amount(): void
    {
        $payload = [
            'amount' => 500000, // vượt quá 340000
            'allocate_to_invoices' => false,
        ];

        $resp = $this->actingAs($this->admin)
            ->postJson("/customers/{$this->customer->id}/payment-discounts", $payload);

        $resp->assertStatus(422);

        $this->customer->refresh();
        $this->assertEquals(340000, (float) $this->customer->debt_amount);
    }

    public function test_cannot_allocate_exceeding_receivable(): void
    {
        $invoice = Invoice::create([
            'code' => 'HD-TEST-' . uniqid(),
            'customer_id' => $this->customer->id,
            'total' => 150000,
            'customer_paid' => 100000, // remaining = 50000
            'status' => 'Hoàn thành',
        ]);

        $payload = [
            'amount' => 100000,
            'allocate_to_invoices' => true,
            'allocations' => [
                [
                    'invoice_id' => $invoice->id,
                    'amount' => 100000, // vượt quá 50000
                ]
            ],
        ];

        $resp = $this->actingAs($this->admin)
            ->postJson("/customers/{$this->customer->id}/payment-discounts", $payload);

        $resp->assertStatus(422);
    }

    public function test_cannot_allocate_to_cancelled_invoice(): void
    {
        $invoice = Invoice::create([
            'code' => 'HD-TEST-' . uniqid(),
            'customer_id' => $this->customer->id,
            'total' => 200000,
            'customer_paid' => 50000,
            'status' => 'Đã hủy',
        ]);

        $payload = [
            'amount' => 50000,
            'allocate_to_invoices' => true,
            'allocations' => [
                [
                    'invoice_id' => $invoice->id,
                    'amount' => 50000,
                ]
            ],
        ];

        $resp = $this->actingAs($this->admin)
            ->postJson("/customers/{$this->customer->id}/payment-discounts", $payload);

        $resp->assertStatus(422);
    }

    public function test_debt_payment_does_not_overcollect_discounted_amounts(): void
    {
        $invoice = Invoice::create([
            'code' => 'HD-TEST-' . uniqid(),
            'customer_id' => $this->customer->id,
            'total' => 440000,
            'customer_paid' => 100000, // remaining = 340000
            'status' => 'Hoàn thành',
        ]);

        // Tạo chiết khấu 100000 phân bổ vào hóa đơn
        app(CustomerPaymentDiscountService::class)->create($this->customer, [
            'amount' => 100000,
            'allocate_to_invoices' => true,
            'allocations' => [
                [
                    'invoice_id' => $invoice->id,
                    'amount' => 100000,
                ]
            ],
        ]);

        // Thử thu nợ auto 300000. Do chỉ còn 240000 thực tế phải thu, payment chỉ được thu tối đa 240000.
        $resp = $this->actingAs($this->admin)
            ->postJson("/customers/{$this->customer->id}/debt-payment", [
                'mode' => 'auto',
                'amount' => 300000,
            ]);

        $resp->assertOk();

        // Kiểm tra customer_paid của hóa đơn chỉ tăng thêm 240k (tổng cộng 340k)
        $invoice->refresh();
        $this->assertEquals(340000, (float) $invoice->customer_paid);
    }

    public function test_cancel_payment_discount(): void
    {
        // 1. Tạo chiết khấu
        $discount = app(CustomerPaymentDiscountService::class)->create($this->customer, [
            'amount' => 100000,
            'allocate_to_invoices' => false,
        ]);

        $this->customer->refresh();
        $this->assertEquals(240000, (float) $this->customer->debt_amount);

        // 2. Hủy chiết khấu
        $resp = $this->actingAs($this->admin)
            ->postJson("/customers/{$this->customer->id}/payment-discounts/{$discount->id}/cancel", [
                'reason' => 'Hủy nhầm',
            ]);

        $resp->assertOk();

        // 3. Verify status and ledger
        $discount->refresh();
        $this->assertEquals('cancelled', $discount->status);
        $this->assertNotNull($discount->cancelled_at);

        $this->customer->refresh();
        $this->assertEquals(340000, (float) $this->customer->debt_amount);

        // Verify ledger positive adjustment
        $this->assertDatabaseHas('customer_debts', [
            'customer_id' => $this->customer->id,
            'amount' => 100000,
            'type' => 'adjustment',
        ]);
    }
}
