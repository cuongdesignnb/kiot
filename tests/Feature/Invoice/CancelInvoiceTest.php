<?php

namespace Tests\Feature\Invoice;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * RR-01: Hủy hóa đơn không được xóa record.
 *
 * File liên quan:  InvoiceController@destroy (app/Http/Controllers/InvoiceController.php:554)
 * Route:           DELETE /invoices/{invoice}  (middleware: auth, permission:invoices.delete)
 *
 * KHÔNG sửa business code — chỉ viết test chứng minh rủi ro.
 *
 * Dùng DatabaseTransactions thay vì RefreshDatabase vì migration có MySQL-specific
 * code (information_schema) không tương thích SQLite :memory:.
 */
class CancelInvoiceTest extends TestCase
{
    use DatabaseTransactions;

    /* ────────── helpers ────────── */

    private function createAdmin(): User
    {
        return User::create([
            'name'     => 'Admin Test RR01',
            'email'    => 'admin-rr01-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function createProduct(int $stock = 10, float $costPrice = 100000): Product
    {
        return Product::create([
            'sku'                  => 'SP-RR01-' . uniqid(),
            'name'                 => 'Sản phẩm test RR01',
            'cost_price'           => $costPrice,
            'retail_price'         => 150000,
            'stock_quantity'       => $stock,
            'inventory_total_cost' => $stock * $costPrice,
            'is_active'            => true,
            'has_serial'           => false,
        ]);
    }

    private function createCustomer(float $debtBefore = 0): Customer
    {
        return Customer::create([
            'code'        => 'KH-RR01-' . uniqid(),
            'name'        => 'Khách test RR01',
            'phone'       => '09' . rand(10000000, 99999999),
            'debt_amount' => $debtBefore,
            'total_spent' => 0,
            'is_customer' => true,
        ]);
    }

    /**
     * Mô phỏng kết quả bán hàng đã xử lý xong (giống InvoiceController@store).
     * Gọi CostingService thật, tạo StockMovement, CashFlow, update customer debt.
     */
    private function simulateSale(Product $product, Customer $customer, int $qty, float $unitPrice, float $customerPaid): Invoice
    {
        $subtotal = $qty * $unitPrice;
        $total    = $subtotal;
        $debt     = $total - $customerPaid;

        $costResult = \App\Services\MovingAvgCostingService::applySale($product, $qty);
        $costAtSale = $costResult['cogs_per_unit'];
        $product->refresh();

        $invoiceCode = 'HD-RR01-' . uniqid();
        $invoice = Invoice::create([
            'code'          => $invoiceCode,
            'subtotal'      => $subtotal,
            'discount'      => 0,
            'total'         => $total,
            'customer_paid' => $customerPaid,
            'customer_id'   => $customer->id,
            'status'        => 'Hoàn thành',
            'sales_channel' => 'Test',
            'created_at'    => now(),
        ]);

        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'product_id'  => $product->id,
            'quantity'    => $qty,
            'price'       => $unitPrice,
            'cost_price'  => $costAtSale,
            'discount'    => 0,
            'subtotal'    => $subtotal,
        ]);

        \App\Services\StockMovementService::record(
            $product,
            \App\Services\StockMovementService::TYPE_OUT_INVOICE,
            $qty,
            $costAtSale,
            $invoice,
            ['ref_code' => $invoice->code, 'note' => 'Bán hàng test RR01']
        );

        if ($debt > 0) {
            $customer->increment('debt_amount', $debt);
        }
        $customer->increment('total_spent', $total);

        if ($customerPaid > 0) {
            CashFlow::create([
                'code'           => 'PT-RR01-' . uniqid(),
                'type'           => 'receipt',
                'amount'         => $customerPaid,
                'time'           => now(),
                'category'       => 'Thu tiền khách trả',
                'target_type'    => 'Khách hàng',
                'target_id'      => $customer->id,
                'target_name'    => $customer->name,
                'reference_type' => 'Invoice',
                'reference_code' => $invoice->code,
                'description'    => 'Test payment RR01',
            ]);
        }

        return $invoice;
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR01-01: Hủy HĐ thanh toán đủ — Invoice phải còn tồn tại
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_TC_RR01_01_cancel_invoice_should_not_delete_record(): void
    {
        $admin    = $this->createAdmin();
        $product  = $this->createProduct(10, 100000);
        $customer = $this->createCustomer(0);

        $invoice   = $this->simulateSale($product, $customer, 2, 150000, 300000);
        $invoiceId = $invoice->id;

        $this->assertDatabaseHas('invoices', ['id' => $invoiceId]);

        $this->actingAs($admin)->delete("/invoices/{$invoiceId}");

        // ═══ ASSERT: Invoice PHẢI còn tồn tại trong DB ═══
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId]);
    }

    public function test_TC_RR01_01_cancel_invoice_should_set_status_cancelled(): void
    {
        $admin    = $this->createAdmin();
        $product  = $this->createProduct(10, 100000);
        $customer = $this->createCustomer(0);

        $invoice   = $this->simulateSale($product, $customer, 2, 150000, 300000);
        $invoiceId = $invoice->id;

        $this->actingAs($admin)->delete("/invoices/{$invoiceId}");

        $invoiceAfter = Invoice::find($invoiceId);

        $this->assertNotNull($invoiceAfter, 'Invoice bị xóa vật lý — không thể kiểm tra status');

        if ($invoiceAfter) {
            $this->assertContains(
                $invoiceAfter->status,
                ['Đã hủy', 'cancelled'],
                "Invoice status phải là 'Đã hủy' hoặc 'cancelled', thực tế: '{$invoiceAfter->status}'"
            );
        }
    }

    public function test_TC_RR01_01_cancel_invoice_should_keep_items(): void
    {
        $admin    = $this->createAdmin();
        $product  = $this->createProduct(10, 100000);
        $customer = $this->createCustomer(0);

        $invoice   = $this->simulateSale($product, $customer, 2, 150000, 300000);
        $invoiceId = $invoice->id;

        $this->actingAs($admin)->delete("/invoices/{$invoiceId}");

        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoiceId]);
    }

    public function test_TC_RR01_01_cancel_invoice_should_restore_stock(): void
    {
        $admin    = $this->createAdmin();
        $product  = $this->createProduct(10, 100000);
        $customer = $this->createCustomer(0);

        $invoice = $this->simulateSale($product, $customer, 2, 150000, 300000);

        $product->refresh();
        $this->assertEquals(8, $product->stock_quantity);

        $this->actingAs($admin)->delete("/invoices/{$invoice->id}");

        $product->refresh();
        $this->assertEquals(10, $product->stock_quantity,
            "Tồn kho phải phục hồi về 10, thực tế: {$product->stock_quantity}");
    }

    public function test_TC_RR01_01_cancel_invoice_should_create_stock_movement(): void
    {
        $admin    = $this->createAdmin();
        $product  = $this->createProduct(10, 100000);
        $customer = $this->createCustomer(0);

        $invoice = $this->simulateSale($product, $customer, 2, 150000, 300000);

        $this->actingAs($admin)->delete("/invoices/{$invoice->id}");

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type'       => 'in_invoice_return',
            'direction'  => 'in',
        ]);
    }

    public function test_TC_RR01_01_cancel_invoice_cashflow_should_not_be_hard_deleted(): void
    {
        $admin    = $this->createAdmin();
        $product  = $this->createProduct(10, 100000);
        $customer = $this->createCustomer(0);

        $invoice = $this->simulateSale($product, $customer, 2, 150000, 300000);
        $invoiceCode = $invoice->code;

        $this->assertDatabaseHas('cash_flows', [
            'reference_type' => 'Invoice',
            'reference_code' => $invoiceCode,
        ]);

        $this->actingAs($admin)->delete("/invoices/{$invoice->id}");

        // CashFlow dùng SoftDeletes → delete() set deleted_at, không hard-delete.
        // Record vẫn tồn tại trong DB (kể cả trashed).
        $cashFlowExists = CashFlow::withTrashed()
            ->where('reference_type', 'Invoice')
            ->where('reference_code', $invoiceCode)
            ->exists();

        $this->assertTrue($cashFlowExists,
            'CashFlow bị hard-delete — mất dấu vết dòng tiền');
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR01-02: Hủy HĐ ghi nợ — Công nợ phải đảo lại
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_TC_RR01_02_cancel_invoice_with_debt_should_restore_customer_debt(): void
    {
        $admin    = $this->createAdmin();
        $product  = $this->createProduct(10, 100000);
        $customer = $this->createCustomer(0);

        $invoice = $this->simulateSale($product, $customer, 2, 150000, 200000);

        $customer->refresh();
        $this->assertEquals(100000, (float) $customer->debt_amount);

        $this->actingAs($admin)->delete("/invoices/{$invoice->id}");

        $customer->refresh();
        $this->assertEquals(0, (float) $customer->debt_amount,
            "Công nợ KH phải về 0, thực tế: {$customer->debt_amount}");
        $this->assertEquals(0, (float) $customer->total_spent,
            "total_spent phải về 0, thực tế: {$customer->total_spent}");
    }

    public function test_TC_RR01_02_cancel_invoice_with_debt_should_keep_invoice(): void
    {
        $admin    = $this->createAdmin();
        $product  = $this->createProduct(10, 100000);
        $customer = $this->createCustomer(0);

        $invoice   = $this->simulateSale($product, $customer, 2, 150000, 200000);
        $invoiceId = $invoice->id;

        $this->actingAs($admin)->delete("/invoices/{$invoiceId}");

        $this->assertDatabaseHas('invoices', ['id' => $invoiceId]);
        $this->assertDatabaseHas('invoice_items', ['invoice_id' => $invoiceId]);
    }

    public function test_TC_RR01_02_cancel_invoice_should_restore_inventory_total_cost(): void
    {
        $admin    = $this->createAdmin();
        $product  = $this->createProduct(10, 100000);
        $customer = $this->createCustomer(0);

        $invoice = $this->simulateSale($product, $customer, 2, 150000, 300000);

        $product->refresh();
        $this->assertEquals(800000, (float) $product->inventory_total_cost);

        $this->actingAs($admin)->delete("/invoices/{$invoice->id}");

        $product->refresh();
        $this->assertEquals(1000000, (float) $product->inventory_total_cost,
            "inventory_total_cost phải phục hồi, thực tế: {$product->inventory_total_cost}");
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  TC-RR01-03: Hủy lặp — Không cộng tồn 2 lần
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_TC_RR01_03_double_cancel_should_not_double_restore_stock(): void
    {
        $admin    = $this->createAdmin();
        $product  = $this->createProduct(10, 100000);
        $customer = $this->createCustomer(0);

        $invoice   = $this->simulateSale($product, $customer, 2, 150000, 300000);
        $invoiceId = $invoice->id;

        // Hủy lần 1
        $this->actingAs($admin)->delete("/invoices/{$invoiceId}");

        $product->refresh();
        $stockAfterFirst = $product->stock_quantity;
        $this->assertEquals(10, $stockAfterFirst);

        // Hủy lần 2
        $this->actingAs($admin)->delete("/invoices/{$invoiceId}");

        $product->refresh();
        $this->assertEquals($stockAfterFirst, $product->stock_quantity,
            "Hủy lặp không được cộng thêm tồn. Trước: {$stockAfterFirst}, Sau: {$product->stock_quantity}");

        // Stock movement đảo chỉ nên có 1
        $smCount = StockMovement::where('product_id', $product->id)
            ->where('type', 'in_invoice_return')
            ->count();
        $this->assertEquals(1, $smCount, "Chỉ nên có 1 SM đảo, thực tế: {$smCount}");
    }
}
