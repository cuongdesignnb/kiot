<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\CashFlow;
use App\Models\CustomerDebt;
use App\Models\CustomerPaymentDiscount;
use App\Models\CustomerPaymentDiscountAllocation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerDebtVoucherDetailTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Test Voucher',
            'email' => 'admin-voucher-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null, // role_id null represents superadmin/admin in this repo
        ]);

        $this->customer = Customer::create([
            'code' => 'KH-VOUCHER-' . uniqid(),
            'name' => 'Khách hàng test voucher',
            'phone' => '090' . rand(1000000, 9999999),
            'debt_amount' => 100000,
            'is_customer' => true,
        ]);
    }

    /**
     * 1. Mở chi tiết hóa đơn HD trực tiếp
     */
    public function test_can_view_invoice_directly_associated_to_customer(): void
    {
        $invoice = Invoice::create([
            'code' => 'HD' . rand(1000, 9999),
            'customer_id' => $this->customer->id,
            'total' => 500000,
            'customer_paid' => 200000,
            'status' => 'Hoàn thành',
            'subtotal' => 500000,
            'discount' => 0,
            'payment_method' => 'Tiền mặt',
        ]);

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/debt-voucher-detail?code={$invoice->code}");

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('type', 'invoice');
        $resp->assertJsonPath('code', $invoice->code);
        $resp->assertJsonPath('data.total', '500000.00');
        $resp->assertJsonPath('data.customer_paid', '200000.00');
        $resp->assertJsonPath('data.debt_amount', 300000);
    }

    /**
     * 2. Mở chi tiết hóa đơn HD qua ledger (khi invoice.customer_id không trùng nhưng có customer_debts)
     */
    public function test_can_view_invoice_associated_via_ledger(): void
    {
        $otherCustomer = Customer::create([
            'code' => 'KH-OTHER-' . uniqid(),
            'name' => 'Khách hàng khác',
            'phone' => '090' . rand(1000000, 9999999),
            'debt_amount' => 0,
            'is_customer' => true,
        ]);

        $invoice = Invoice::create([
            'code' => 'HD' . rand(1000, 9999),
            'customer_id' => $otherCustomer->id, // not this customer
            'total' => 300000,
            'customer_paid' => 100000,
            'status' => 'Hoàn thành',
            'subtotal' => 300000,
            'discount' => 0,
        ]);

        // Tạo customer_debts liên kết tới customer của chúng ta
        CustomerDebt::create([
            'customer_id' => $this->customer->id,
            'ref_code' => $invoice->code,
            'amount' => 200000,
            'debt_total' => 200000,
            'type' => 'sale',
            'recorded_at' => now(),
        ]);

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/debt-voucher-detail?code={$invoice->code}");

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('type', 'invoice');
        $resp->assertJsonPath('code', $invoice->code);
    }

    /**
     * 3. Không mở hóa đơn không thuộc khách
     */
    public function test_cannot_view_invoice_belonging_to_another_customer(): void
    {
        $otherCustomer = Customer::create([
            'code' => 'KH-OTHER-' . uniqid(),
            'name' => 'Khách hàng khác',
            'phone' => '090' . rand(1000000, 9999999),
            'debt_amount' => 0,
            'is_customer' => true,
        ]);

        $invoice = Invoice::create([
            'code' => 'HD' . rand(1000, 9999),
            'customer_id' => $otherCustomer->id,
            'total' => 300000,
            'customer_paid' => 100000,
            'status' => 'Hoàn thành',
        ]);

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/debt-voucher-detail?code={$invoice->code}");

        $resp->assertStatus(404);
        $resp->assertJsonPath('success', false);
        $resp->assertJsonPath('message', 'Không tìm thấy chứng từ hoặc chứng từ không thuộc khách hàng này.');
    }

    /**
     * 4. Mở phiếu nhập PN
     */
    public function test_can_view_purchase_directly_associated_to_supplier(): void
    {
        $purchase = Purchase::create([
            'code' => 'PN' . rand(1000, 9999),
            'supplier_id' => $this->customer->id, // customer role can also act as supplier
            'total_amount' => 800000,
            'paid_amount' => 300000,
            'debt_amount' => 500005, // test using different number to ensure it parses decimal correctly
            'status' => 'completed',
        ]);

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/debt-voucher-detail?code={$purchase->code}");

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('type', 'purchase');
        $resp->assertJsonPath('code', $purchase->code);
        $resp->assertJsonPath('data.total_amount', '800000.00');
        $resp->assertJsonPath('data.paid_amount', '300000.00');
        $resp->assertJsonPath('data.debt_amount', '500005.00');
    }

    /**
     * 5. Không mở PN không thuộc khách/NCC
     */
    public function test_cannot_view_purchase_belonging_to_another_supplier(): void
    {
        $otherSupplier = Customer::create([
            'code' => 'KH-SUP-' . uniqid(),
            'name' => 'Nhà cung cấp khác',
            'phone' => '090' . rand(1000000, 9999999),
            'debt_amount' => 0,
            'is_supplier' => true,
        ]);

        $purchase = Purchase::create([
            'code' => 'PN' . rand(1000, 9999),
            'supplier_id' => $otherSupplier->id,
            'total_amount' => 800000,
            'paid_amount' => 300000,
            'debt_amount' => 500000,
            'status' => 'completed',
        ]);

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/debt-voucher-detail?code={$purchase->code}");

        $resp->assertStatus(404);
        $resp->assertJsonPath('success', false);
    }

    /**
     * 6. Mở phiếu thu PT/TTHD
     */
    public function test_can_view_cash_flow_belonging_to_customer(): void
    {
        $cashFlow = CashFlow::create([
            'code' => 'PT' . rand(1000, 9999),
            'type' => 'receipt', // valid enum in database
            'amount' => 150000,
            'target_type' => 'Khách hàng',
            'target_id' => $this->customer->id,
            'target_name' => $this->customer->name,
            'status' => 'completed',
        ]);

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/debt-voucher-detail?code={$cashFlow->code}");

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('type', 'cashflow');
        $resp->assertJsonPath('code', $cashFlow->code);
        $resp->assertJsonPath('data.amount', '150000.00');
    }

    /**
     * 7. Không mở cashflow không thuộc khách
     */
    public function test_cannot_view_cash_flow_belonging_to_another_customer(): void
    {
        $otherCustomer = Customer::create([
            'code' => 'KH-OTHER-' . uniqid(),
            'name' => 'Khách hàng khác',
            'phone' => '090' . rand(1000000, 9999999),
            'debt_amount' => 0,
            'is_customer' => true,
        ]);

        $cashFlow = CashFlow::create([
            'code' => 'PT' . rand(1000, 9999),
            'type' => 'receipt', // valid enum in database
            'amount' => 150000,
            'target_type' => 'Khách hàng',
            'target_id' => $otherCustomer->id,
            'target_name' => $otherCustomer->name,
            'status' => 'completed',
        ]);

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/debt-voucher-detail?code={$cashFlow->code}");

        $resp->assertStatus(404);
        $resp->assertJsonPath('success', false);
    }

    /**
     * 8. Mở CKTT
     */
    public function test_can_view_payment_discount_and_allocations(): void
    {
        $discount = CustomerPaymentDiscount::create([
            'code' => 'CKTT' . rand(1000, 9999),
            'customer_id' => $this->customer->id,
            'amount' => 50000,
            'discount_at' => now(),
            'allocate_to_invoices' => true,
            'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'code' => 'HD' . rand(1000, 9999),
            'customer_id' => $this->customer->id,
            'total' => 200000,
            'customer_paid' => 100000,
            'status' => 'Hoàn thành',
        ]);

        CustomerPaymentDiscountAllocation::create([
            'customer_payment_discount_id' => $discount->id,
            'customer_id' => $this->customer->id,
            'invoice_id' => $invoice->id,
            'amount' => 50000,
        ]);

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/debt-voucher-detail?code={$discount->code}");

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('type', 'payment_discount');
        $resp->assertJsonPath('code', $discount->code);
        $resp->assertJsonPath('data.amount', '50000.00');
        $resp->assertJsonCount(1, 'data.allocations');
        $resp->assertJsonPath('data.allocations.0.invoice_code', $invoice->code);
        $resp->assertJsonPath('data.allocations.0.amount', '50000.00');
    }

    /**
     * 9. Mở ledger MERGE
     */
    public function test_can_view_ledger_merge_records(): void
    {
        $mergeCode = 'MERGE-CUSTOMER-' . rand(1000, 9999);
        CustomerDebt::create([
            'customer_id' => $this->customer->id,
            'ref_code' => $mergeCode,
            'amount' => -100000,
            'debt_total' => 0,
            'type' => 'adjustment',
            'note' => 'Gộp khách hàng',
            'recorded_at' => now(),
        ]);

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/debt-voucher-detail?code={$mergeCode}");

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('type', 'ledger');
        $resp->assertJsonPath('code', $mergeCode);
        $resp->assertJsonPath('data.amount', '-100000.00');
        $resp->assertJsonCount(1, 'data.entries');
        $resp->assertJsonPath('data.entries.0.note', 'Gộp khách hàng');
    }

    /**
     * 10. Không có side effect
     */
    public function test_viewing_voucher_detail_has_no_database_side_effects(): void
    {
        $invoice = Invoice::create([
            'code' => 'HD' . rand(1000, 9999),
            'customer_id' => $this->customer->id,
            'total' => 500000,
            'customer_paid' => 200000,
            'status' => 'Hoàn thành',
        ]);

        $originalDebtAmount = $this->customer->debt_amount;
        $originalCustomerPaid = $invoice->customer_paid;
        $originalDebtCount = CustomerDebt::count();

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/debt-voucher-detail?code={$invoice->code}");
        $resp->assertOk();

        $this->customer->refresh();
        $invoice->refresh();

        $this->assertEquals($originalDebtAmount, $this->customer->debt_amount, 'Customer debt_amount should not change');
        $this->assertEquals($originalCustomerPaid, $invoice->customer_paid, 'Invoice customer_paid should not change');
        $this->assertEquals($originalDebtCount, CustomerDebt::count(), 'No new customer_debts rows should be created');
    }

    /**
     * 11. Test fallback voucher detail for TTHD (title should be 'Thanh toán hóa đơn')
     */
    public function test_tthd_fallback_voucher_detail(): void
    {
        $invoice = Invoice::create([
            'code' => 'HD' . rand(1000, 9999),
            'customer_id' => $this->customer->id,
            'total' => 500000,
            'customer_paid' => 200000,
            'status' => 'Hoàn thành',
            'subtotal' => 500000,
            'discount' => 0,
            'payment_method' => 'Tiền mặt',
        ]);

        $tthdCode = 'TTHD' . substr($invoice->code, 2);

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/debt-voucher-detail?code={$tthdCode}");

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('type', 'cashflow');
        $resp->assertJsonPath('title', 'Thanh toán hóa đơn');
        $resp->assertJsonPath('code', $tthdCode);
        $resp->assertJsonPath('data.amount', 200000.0);
        $resp->assertJsonPath('data.status', 'completed');
        $resp->assertJsonPath('data.created_at', $invoice->created_at ? $invoice->created_at->format('d/m/Y H:i') : '');
    }

    /**
     * 12. Test debt history contains detail_available and is_virtual_payment flags
     */
    public function test_debt_history_contains_detail_available_and_virtual_payment_flags(): void
    {
        // 1. Ledger entry
        CustomerDebt::create([
            'customer_id' => $this->customer->id,
            'ref_code' => 'HD_LEDGER_TEST',
            'amount' => 100000,
            'debt_total' => 100000,
            'type' => 'sale',
            'recorded_at' => now(),
        ]);

        // 2. Legacy Invoice & Legacy Virtual Payment TTHD
        $invoice = Invoice::create([
            'code' => 'HD_LEGACY_TEST',
            'customer_id' => $this->customer->id,
            'total' => 300000,
            'customer_paid' => 150000,
            'status' => 'Hoàn thành',
            'created_at' => now()->subMinutes(5),
        ]);

        // Enable dual role to trigger purchase legacy entries
        $this->customer->update([
            'is_supplier' => true,
        ]);

        // 3. Purchase & Purchase payment (purpay)
        $purchase = Purchase::create([
            'code' => 'PN_LEGACY_TEST',
            'supplier_id' => $this->customer->id,
            'total_amount' => 400000,
            'paid_amount' => 200000,
            'status' => 'completed',
            'created_at' => now()->subMinutes(10),
        ]);

        $resp = $this->actingAs($this->admin)
            ->getJson("/customers/{$this->customer->id}/debt-history");

        $resp->assertOk();
        $entries = collect($resp->json('entries'));

        // Check Ledger Entry
        $ledgerEntry = $entries->firstWhere('code', 'HD_LEDGER_TEST');
        $this->assertNotNull($ledgerEntry);
        $this->assertTrue($ledgerEntry['detail_available']);

        // Check Legacy Invoice
        $invEntry = $entries->firstWhere('code', 'HD_LEGACY_TEST');
        $this->assertNotNull($invEntry);
        $this->assertTrue($invEntry['detail_available']);

        // Check Legacy Virtual TTHD
        $tthdEntry = $entries->firstWhere('code', 'TTHD_LEGACY_TEST');
        $this->assertNotNull($tthdEntry);
        $this->assertTrue($tthdEntry['detail_available']);
        $this->assertTrue($tthdEntry['is_virtual_payment']);

        // Check Legacy Purchase
        $purEntry = $entries->firstWhere('code', 'PN_LEGACY_TEST');
        $this->assertNotNull($purEntry);
        $this->assertTrue($purEntry['detail_available']);

        // Check Legacy Purchase Payment (purpay)
        $purpayEntry = $entries->firstWhere('code', 'TTNH_LEGACY_TEST');
        $this->assertNotNull($purpayEntry);
        $this->assertFalse($purpayEntry['detail_available']);
    }
}
