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

    public function test_payment_discount_invoices_direct_source(): void
    {
        $invoice = Invoice::create([
            'code' => 'HD-DIRECT-' . uniqid(),
            'customer_id' => $this->customer->id,
            'total' => 500000,
            'customer_paid' => 100000,
            'status' => 'Hoàn thành',
        ]);

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/payment-discount-invoices");

        $resp->assertOk();
        $resp->assertJsonFragment([
            'id' => $invoice->id,
            'code' => $invoice->code,
            'total' => 500000.0,
            'customer_paid' => 100000.0,
            'remaining' => 400000.0,
            'source' => 'direct_invoice',
        ]);
    }

    public function test_payment_discount_invoices_ledger_source(): void
    {
        $otherCustomer = Customer::create([
            'code' => 'KH-OTHER-' . uniqid(),
            'name' => 'Other Customer',
            'phone' => '0901111111',
            'debt_amount' => 0,
            'is_customer' => true,
        ]);

        $invoice = Invoice::create([
            'code' => 'HD-LEDGER-' . uniqid(),
            'customer_id' => $otherCustomer->id,
            'total' => 300000,
            'customer_paid' => 50000,
            'status' => 'Hoàn thành',
        ]);

        CustomerDebt::create([
            'customer_id' => $this->customer->id,
            'ref_code' => $invoice->code,
            'amount' => 250000,
            'debt_total' => 250000,
            'type' => 'sale',
            'recorded_at' => now(),
        ]);

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/payment-discount-invoices");

        $resp->assertOk();
        $resp->assertJsonFragment([
            'id' => $invoice->id,
            'code' => $invoice->code,
            'total' => 300000.0,
            'customer_paid' => 50000.0,
            'remaining' => 250000.0,
            'source' => 'ledger_invoice',
        ]);
    }

    public function test_payment_discount_invoices_excludes_purchase_from_ledger(): void
    {
        $purchase = \App\Models\Purchase::create([
            'code' => 'PN-TEST-' . uniqid(),
            'supplier_id' => $this->customer->id,
            'total_amount' => 200000,
            'paid_amount' => 0,
            'status' => 'completed',
        ]);

        CustomerDebt::create([
            'customer_id' => $this->customer->id,
            'ref_code' => $purchase->code,
            'amount' => 200000,
            'debt_total' => 200000,
            'type' => 'sale',
            'recorded_at' => now(),
        ]);

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/payment-discount-invoices");

        $resp->assertOk();
        $resp->assertJsonMissing(['code' => $purchase->code]);
    }

    public function test_payment_discount_invoices_excludes_cancelled_invoices(): void
    {
        $invoice = Invoice::create([
            'code' => 'HD-CANCEL-' . uniqid(),
            'customer_id' => $this->customer->id,
            'total' => 300000,
            'customer_paid' => 0,
            'status' => 'Đã hủy',
        ]);

        CustomerDebt::create([
            'customer_id' => $this->customer->id,
            'ref_code' => $invoice->code,
            'amount' => 300000,
            'debt_total' => 300000,
            'type' => 'sale',
            'recorded_at' => now(),
        ]);

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/payment-discount-invoices");

        $resp->assertOk();
        $resp->assertJsonMissing(['id' => $invoice->id]);
    }

    public function test_payment_discount_invoices_deduplicates_preferring_direct(): void
    {
        $invoice = Invoice::create([
            'code' => 'HD-DEDUP-' . uniqid(),
            'customer_id' => $this->customer->id,
            'total' => 300000,
            'customer_paid' => 100000,
            'status' => 'Hoàn thành',
        ]);

        CustomerDebt::create([
            'customer_id' => $this->customer->id,
            'ref_code' => $invoice->code,
            'amount' => 200000,
            'debt_total' => 200000,
            'type' => 'sale',
            'recorded_at' => now(),
        ]);

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/payment-discount-invoices");

        $resp->assertOk();
        $data = $resp->json()['invoices'];

        // Count how many times invoice appears
        $occurrences = array_filter($data, fn($item) => $item['id'] === $invoice->id);
        $this->assertCount(1, $occurrences);

        $first = reset($occurrences);
        $this->assertEquals('direct_invoice', $first['source']);
    }

    public function test_payment_discount_allocation_to_ledger_invoice(): void
    {
        $otherCustomer = Customer::create([
            'code' => 'KH-OTHER-' . uniqid(),
            'name' => 'Other Customer',
            'phone' => '0901111112',
            'debt_amount' => 0,
            'is_customer' => true,
        ]);

        $invoice = Invoice::create([
            'code' => 'HD-ALLOC-' . uniqid(),
            'customer_id' => $otherCustomer->id,
            'total' => 300000,
            'customer_paid' => 50000,
            'status' => 'Hoàn thành',
        ]);

        CustomerDebt::create([
            'customer_id' => $this->customer->id,
            'ref_code' => $invoice->code,
            'amount' => 250000,
            'debt_total' => 250000,
            'type' => 'sale',
            'recorded_at' => now(),
        ]);

        // Phải cập nhật debt_amount của customer để có thể tạo CKTT
        $this->customer->update(['debt_amount' => 250000]);

        $payload = [
            'amount' => 100000,
            'allocate_to_invoices' => true,
            'allocations' => [
                [
                    'invoice_id' => $invoice->id,
                    'amount' => 100000,
                ]
            ],
            'note' => 'Phân bổ cho hóa đơn ledger',
        ];

        $resp = $this->actingAs($this->admin)
            ->postJson("/customers/{$this->customer->id}/payment-discounts", $payload);

        $resp->assertOk();
        $resp->assertJsonPath('success', true);

        // Assert allocation table has record
        $this->assertDatabaseHas('customer_payment_discount_allocations', [
            'invoice_id' => $invoice->id,
            'amount' => 100000,
        ]);

        // Assert customer debt decreased
        $this->customer->refresh();
        $this->assertEquals(150000, (float) $this->customer->debt_amount);

        // Assert invoice customer_paid unchanged
        $invoice->refresh();
        $this->assertEquals(50000, (float) $invoice->customer_paid);

        // Assert no cash flow created
        $this->assertDatabaseMissing('cash_flows', [
            'target_id' => $this->customer->id,
            'reference_type' => 'CustomerPaymentDiscount',
        ]);
    }

    public function test_manual_debt_payment_allocation_to_ledger_invoice_after_cktt(): void
    {
        $otherCustomer = Customer::create([
            'code' => 'KH-OTHER-' . uniqid(),
            'name' => 'Other Customer',
            'phone' => '0901111113',
            'debt_amount' => 0,
            'is_customer' => true,
        ]);

        $invoice = Invoice::create([
            'code' => 'HD-MANUAL-' . uniqid(),
            'customer_id' => $otherCustomer->id,
            'total' => 300000,
            'customer_paid' => 50000,
            'status' => 'Hoàn thành',
        ]);

        CustomerDebt::create([
            'customer_id' => $this->customer->id,
            'ref_code' => $invoice->code,
            'amount' => 250000,
            'debt_total' => 250000,
            'type' => 'sale',
            'recorded_at' => now(),
        ]);

        $this->customer->update(['debt_amount' => 250000]);

        // Phân bổ CKTT 100000
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

        // Thu nợ manual 200000. Do remaining là 150000, chỉ được thu 150000
        $resp = $this->actingAs($this->admin)
            ->postJson("/customers/{$this->customer->id}/debt-payment", [
                'mode' => 'manual',
                'allocations' => [
                    [
                        'invoice_id' => $invoice->id,
                        'amount' => 200000,
                    ]
                ],
            ]);

        $resp->assertOk();

        // Invoice customer_paid tăng thêm 150000 (từ 50000 thành 200000)
        $invoice->refresh();
        $this->assertEquals(200000, (float) $invoice->customer_paid);
    }

    public function test_auto_debt_payment_rejection_when_no_receivable_invoices(): void
    {
        // Khách hàng có nợ nhưng không có hóa đơn nào (direct hoặc ledger)
        $this->customer->update(['debt_amount' => 100000]);

        $resp = $this->actingAs($this->admin)
            ->postJson("/customers/{$this->customer->id}/debt-payment", [
                'mode' => 'auto',
                'amount' => 50000,
            ]);

        $resp->assertStatus(422);

        // Verify no cash flow created
        $this->assertDatabaseMissing('cash_flows', [
            'target_id' => $this->customer->id,
            'category' => 'Thu nợ khách hàng',
        ]);

        // Verify debt unchanged
        $this->customer->refresh();
        $this->assertEquals(100000, (float) $this->customer->debt_amount);
    }
}
