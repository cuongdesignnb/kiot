<?php

namespace Tests\Feature\Supplier;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupplierDebtTimelineKiotStandardTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Supplier Kiot Test',
            'email' => 'admin-supplier-kiot-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    public function test_dual_role_supplier_tab_does_not_show_customer_invoice_or_tthd(): void
    {
        $supplier = $this->createSupplier([
            'debt_amount' => 54620000,
            'supplier_debt_amount' => 75000000,
            'is_customer' => true,
        ]);

        Purchase::create([
            'code' => 'PN-KIOT-75000000',
            'supplier_id' => $supplier->id,
            'total_amount' => 75000000,
            'paid_amount' => 0,
            'debt_amount' => 75000000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-05-01 09:00:00'),
        ]);

        Invoice::create([
            'code' => 'HD-KIOT-CUSTOMER-1',
            'customer_id' => $supplier->id,
            'subtotal' => 54620000,
            'discount' => 0,
            'total' => 54620000,
            'customer_paid' => 7200000,
            'status' => 'Hoàn thành',
            'created_at' => Carbon::parse('2026-05-02 09:00:00'),
        ]);

        $data = $this->getSupplierDebtTransactions($supplier);
        $entries = collect($data['entries']);

        $this->assertFalse($entries->contains(fn ($entry) => str_starts_with((string) $entry['code'], 'HD')));
        $this->assertFalse($entries->contains(fn ($entry) => str_starts_with((string) $entry['code'], 'TTHD')));
        $this->assertEquals(75000000, $data['summary']['net']);

        $purchase = $entries->firstWhere('code', 'PN-KIOT-75000000');
        $this->assertNotNull($purchase);
        $this->assertEquals('Nhập hàng', $purchase['type_label']);
        $this->assertEquals(75000000, $purchase['supplier_effect']);
        $this->assertEquals(75000000, $purchase['debt_remain']);
    }

    public function test_supplier_payment_reduces_supplier_payable_debt(): void
    {
        $supplier = $this->createSupplier(['supplier_debt_amount' => 6000000]);

        Purchase::create([
            'code' => 'PN-KIOT-PAYMENT-1',
            'supplier_id' => $supplier->id,
            'total_amount' => 10000000,
            'paid_amount' => 0,
            'debt_amount' => 10000000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-05-01 09:00:00'),
        ]);

        CashFlow::create([
            'code' => 'PCPN-KIOT-4000000',
            'type' => 'payment',
            'amount' => 4000000,
            'time' => Carbon::parse('2026-05-02 09:00:00'),
            'category' => 'Chi trả NCC',
            'target_type' => 'Nhà cung cấp',
            'target_id' => $supplier->id,
            'reference_type' => 'Purchase',
            'reference_code' => 'PN-KIOT-PAYMENT-1',
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);

        $data = $this->getSupplierDebtTransactions($supplier);
        $entries = collect($data['entries']);

        $this->assertEquals(10000000, $entries->firstWhere('code', 'PN-KIOT-PAYMENT-1')['supplier_effect']);
        $this->assertEquals(-4000000, $entries->firstWhere('code', 'PCPN-KIOT-4000000')['supplier_effect']);
        $this->assertEquals(6000000, $data['summary']['net']);
    }

    public function test_purchase_return_reduces_supplier_payable_debt(): void
    {
        $supplier = $this->createSupplier(['supplier_debt_amount' => 8000000]);

        $purchase = Purchase::create([
            'code' => 'PN-KIOT-RETURN-1',
            'supplier_id' => $supplier->id,
            'total_amount' => 10000000,
            'paid_amount' => 0,
            'debt_amount' => 10000000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-05-01 09:00:00'),
        ]);

        PurchaseReturn::create([
            'code' => 'THN-KIOT-RETURN-1',
            'purchase_id' => $purchase->id,
            'supplier_id' => $supplier->id,
            'total_amount' => 2000000,
            'refund_amount' => 0,
            'status' => 'completed',
            'return_date' => Carbon::parse('2026-05-02 09:00:00'),
        ]);

        $data = $this->getSupplierDebtTransactions($supplier);
        $entries = collect($data['entries']);

        $this->assertEquals(10000000, $entries->firstWhere('code', 'PN-KIOT-RETURN-1')['supplier_effect']);
        $this->assertEquals(-2000000, $entries->firstWhere('code', 'THN-KIOT-RETURN-1')['supplier_effect']);
        $this->assertEquals(8000000, $data['summary']['net']);
    }

    public function test_supplier_adjustment_uses_signed_amount(): void
    {
        $supplier = $this->createSupplier(['supplier_debt_amount' => 9000000]);

        Purchase::create([
            'code' => 'PN-KIOT-ADJUST-1',
            'supplier_id' => $supplier->id,
            'total_amount' => 10000000,
            'paid_amount' => 0,
            'debt_amount' => 10000000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2026-05-01 09:00:00'),
        ]);

        SupplierDebtTransaction::create([
            'supplier_id' => $supplier->id,
            'code' => 'CB-KIOT-ADJUST-1',
            'type' => 'adjustment',
            'amount' => -1000000,
            'debt_remain' => 9000000,
            'created_at' => Carbon::parse('2026-05-02 09:00:00'),
        ]);

        $data = $this->getSupplierDebtTransactions($supplier);
        $adjustment = collect($data['entries'])->firstWhere('code', 'CB-KIOT-ADJUST-1');

        $this->assertEquals('Điều chỉnh', $adjustment['type_label']);
        $this->assertEquals(-1000000, $adjustment['supplier_effect']);
        $this->assertEquals(9000000, $data['summary']['net']);
    }

    private function createSupplier(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'NCC-KIOT-' . uniqid(),
            'name' => 'Supplier Kiot Standard',
            'phone' => '0900000000',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'total_spent' => 0,
            'total_bought' => 0,
            'is_customer' => false,
            'is_supplier' => true,
        ], $overrides));
    }

    private function getSupplierDebtTransactions(Customer $supplier): array
    {
        $response = $this->actingAs($this->admin)
            ->getJson("/api/suppliers/{$supplier->id}/debt-transactions");

        $response->assertOk();

        return $response->json();
    }
}
