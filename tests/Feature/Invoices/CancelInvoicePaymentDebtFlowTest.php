<?php

namespace Tests\Feature\Invoices;

use App\Models\User;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Invoice;
use App\Models\CashFlow;
use App\Models\CustomerDebt;
use App\Services\CustomerDebtService;
use App\Services\InvoiceSaleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CancelInvoicePaymentDebtFlowTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Test Cancel Invoice',
            'email' => 'admin-cancel-inv-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'status' => 'active',
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'KH-TEST-' . uniqid(),
            'name' => 'Test Customer',
            'phone' => '0987654321',
            'is_customer' => true,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'sku' => 'SP-TEST-' . uniqid(),
            'name' => 'Test Product',
            'cost_price' => 100000,
            'retail_price' => 150000,
            'stock_quantity' => 20,
        ]);
    }

    public function test_cancel_invoice_updates_cashflows_and_preserves_paid_snapshot(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $product = $this->product();

        // 1. Tạo Invoice bán hàng có thanh toán
        $payload = [
            'customer_id' => $customer->id,
            'subtotal' => 300000,
            'discount' => 50000,
            'total' => 250000,
            'customer_paid' => 100000,
            'payment_method' => 'Tiền mặt',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'price' => 150000,
                    'discount' => 25000,
                ]
            ]
        ];

        $context = [
            'source' => 'invoice',
            'code_prefix' => 'HD-TEST',
            'default_status' => 'Hoàn thành',
            'created_by_name' => $admin->name,
            'validate_before_purchase_date' => false,
            'validate_stock_setting' => false,
            'allow_oversell' => true,
        ];

        $invoice = app(InvoiceSaleService::class)->createSale($payload, $context);

        // Kiểm tra sau khi tạo
        $this->assertEquals(250000, $invoice->total);
        $this->assertEquals(100000, $invoice->customer_paid);
        $this->assertEquals('Hoàn thành', $invoice->status);

        // Phải có cashflow liên quan
        $cashflow = CashFlow::where('reference_type', 'Invoice')
            ->where('reference_code', $invoice->code)
            ->first();
        $this->assertNotNull($cashflow);
        $this->assertEquals(100000, $cashflow->amount);
        $this->assertEquals('receipt', $cashflow->type);
        $this->assertNotEquals('cancelled', $cashflow->status);

        // 2. Gọi API DELETE để hủy hóa đơn
        $response = $this->actingAs($admin)->delete("/invoices/{$invoice->id}");
        $response->assertRedirect();

        // Hóa đơn chuyển trạng thái sang Đã hủy, nhưng customer_paid vẫn giữ nguyên snapshot
        $invoice->refresh();
        $this->assertEquals('Đã hủy', $invoice->status);
        $this->assertEquals(100000, $invoice->customer_paid);

        // Cashflow chuyển status sang cancelled
        $cashflow->refresh();
        $this->assertEquals('cancelled', $cashflow->status);
    }

    public function test_payment_history_returns_correct_snapshot_and_effective_paid(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $product = $this->product();

        $payload = [
            'customer_id' => $customer->id,
            'subtotal' => 150000,
            'total' => 150000,
            'customer_paid' => 50000,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => 150000,
                ]
            ]
        ];

        $context = [
            'created_by_name' => $admin->name,
            'validate_before_purchase_date' => false,
            'validate_stock_setting' => false,
            'allow_oversell' => true,
        ];

        $invoice = app(InvoiceSaleService::class)->createSale($payload, $context);

        // Hủy hóa đơn
        $this->actingAs($admin)->delete("/invoices/{$invoice->id}");

        // Gọi API payment-history
        $response = $this->actingAs($admin)->get("/invoices/{$invoice->id}/payment-history");
        $response->assertOk();

        $data = $response->json();
        $this->assertArrayHasKey('invoice', $data);
        $this->assertArrayHasKey('payments', $data);

        $this->assertEquals(50000, $data['invoice']['customer_paid_snapshot']);
        $this->assertEquals(0, $data['invoice']['effective_paid']);

        // Check payment record is returned and flagged as cancelled
        $this->assertNotEmpty($data['payments']);
        $payment = $data['payments'][0];
        $this->assertTrue($payment['is_cancelled']);
        $this->assertEquals('cancelled', $payment['status']);
    }

    public function test_debt_history_maps_cancel_label_and_excludes_cancelled_legacy_invoices(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $product = $this->product();

        $payload = [
            'customer_id' => $customer->id,
            'subtotal' => 200000,
            'total' => 200000,
            'customer_paid' => 50000,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'price' => 200000,
                ]
            ]
        ];

        $context = [
            'created_by_name' => $admin->name,
            'validate_before_purchase_date' => false,
            'validate_stock_setting' => false,
            'allow_oversell' => true,
        ];

        $invoice = app(InvoiceSaleService::class)->createSale($payload, $context);

        // Kiểm tra công nợ ban đầu của khách (200000 - 50000 = 150000)
        $this->assertEquals(150000, $customer->fresh()->debt_amount);

        // Hủy hóa đơn
        $this->actingAs($admin)->delete("/invoices/{$invoice->id}");

        // Sau khi hủy, nợ phải về 0
        $this->assertEquals(0, $customer->fresh()->debt_amount);

        // Lấy lịch sử công nợ
        $response = $this->actingAs($admin)->get("/customers/{$customer->id}/debt-history");
        $response->assertOk();

        $data = $response->json();
        $entries = $data['entries'];

        // Kiểm tra xem dòng ledger đảo công nợ có type="Hủy hóa đơn"
        $reversalLedger = collect($entries)->first(fn($e) => $e['type_raw'] === 'invoice_cancel_reversal');
        $this->assertNotNull($reversalLedger);
        $this->assertEquals('Hủy hóa đơn', $reversalLedger['type']);
        $this->assertEquals(-150000, $reversalLedger['amount']);

        // Vì hóa đơn đã hủy, legacy entries của nó không được hiển thị (đã lọc bỏ)
        $legacyInv = collect($entries)->first(fn($e) => $e['source'] === 'legacy' && $e['code'] === $invoice->code);
        $this->assertNull($legacyInv);
    }

    public function test_outstanding_invoices_and_debt_payment_exclude_cancelled_invoices(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $product = $this->product();

        // 1. Tạo 1 hóa đơn chưa hủy
        $invActive = app(InvoiceSaleService::class)->createSale([
            'customer_id' => $customer->id,
            'subtotal' => 100000,
            'total' => 100000,
            'customer_paid' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 100000]]
        ], [
            'created_by_name' => $admin->name,
            'validate_before_purchase_date' => false,
            'validate_stock_setting' => false,
            'allow_oversell' => true,
        ]);

        // 2. Tạo 1 hóa đơn đã hủy
        $invCancelled = app(InvoiceSaleService::class)->createSale([
            'customer_id' => $customer->id,
            'subtotal' => 200000,
            'total' => 200000,
            'customer_paid' => 0,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 200000]]
        ], [
            'created_by_name' => $admin->name,
            'validate_before_purchase_date' => false,
            'validate_stock_setting' => false,
            'allow_oversell' => true,
        ]);

        $this->actingAs($admin)->delete("/invoices/{$invCancelled->id}");

        // 3. outstandingInvoices chỉ trả về hóa đơn active
        $response = $this->actingAs($admin)->get("/customers/{$customer->id}/outstanding-invoices");
        $response->assertOk();
        $outstandings = $response->json();

        $activeCodes = collect($outstandings)->pluck('code')->all();
        $this->assertContains($invActive->code, $activeCodes);
        $this->assertNotContains($invCancelled->code, $activeCodes);

        // 4. debtPayment manual mode chặn thanh toán cho hóa đơn đã hủy
        $paymentResponse = $this->actingAs($admin)->post("/customers/{$customer->id}/debt-payment", [
            'mode' => 'manual',
            'allocations' => [
                ['invoice_id' => $invCancelled->id, 'amount' => 50000]
            ],
            'note' => 'Trả nợ cho hóa đơn đã hủy'
        ]);

        // Check it throws/returns error
        $paymentResponse->assertStatus(302); // Redirect back with error if web request
        $this->assertEquals('Không thể thu nợ cho hóa đơn đã hủy.', session('error'));
    }
}
