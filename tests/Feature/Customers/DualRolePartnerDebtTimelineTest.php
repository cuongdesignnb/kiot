<?php

namespace Tests\Feature\Customers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DualRolePartnerDebtTimelineTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Dual Role Customer Test',
            'email' => 'admin-dual-cust-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    /**
     * Case 1 — Mirror giống ảnh KiotViet
     */
    public function test_mirror_kiotviet_dual_role_behavior(): void
    {
        // Dữ liệu giả lập đối tác Long pin
        $partner = Customer::create([
            'code' => 'LongPinKHNCC',
            'name' => 'Long pin',
            'debt_amount' => 0,
            'supplier_debt_amount' => 22850000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        // Tạo chuỗi ledger NCC.
        // Cần tổng cộng các giao dịch để closing_balance = 22,850,000.
        // Hiệu số các giao dịch:
        // PN003209 (+860k) - PCPN003209 (-980k) + PN003210 (+3380k) + PN003211 (+850k) - PCPN003211 (-980k) + PN003212 (+900k) - CB00306 (-10m)
        // Tổng cộng các giao dịch trên = -5,970,000.
        // Vậy cần một giao dịch khởi đầu (ví dụ PN000000) = 28,820,000.
        
        $baseTime = Carbon::now()->subDays(10);

        Purchase::create([
            'code' => 'PN000000',
            'supplier_id' => $partner->id,
            'total_amount' => 28820000,
            'paid_amount' => 0,
            'status' => 'completed',
            'purchase_date' => $baseTime->copy()->addMinutes(1),
        ]);

        Purchase::create([
            'code' => 'PN003209',
            'supplier_id' => $partner->id,
            'total_amount' => 860000,
            'paid_amount' => 0,
            'status' => 'completed',
            'purchase_date' => $baseTime->copy()->addMinutes(2),
        ]);

        CashFlow::create([
            'code' => 'PCPN003209',
            'type' => 'payment',
            'amount' => 980000,
            'time' => $baseTime->copy()->addMinutes(3),
            'target_type' => 'Nhà cung cấp',
            'target_id' => $partner->id,
            'reference_type' => 'Purchase',
            'reference_code' => 'PN003209',
            'status' => 'completed',
        ]);

        Purchase::create([
            'code' => 'PN003210',
            'supplier_id' => $partner->id,
            'total_amount' => 3380000,
            'paid_amount' => 0,
            'status' => 'completed',
            'purchase_date' => $baseTime->copy()->addMinutes(4),
        ]);

        Purchase::create([
            'code' => 'PN003211',
            'supplier_id' => $partner->id,
            'total_amount' => 850000,
            'paid_amount' => 0,
            'status' => 'completed',
            'purchase_date' => $baseTime->copy()->addMinutes(5),
        ]);

        CashFlow::create([
            'code' => 'PCPN003211',
            'type' => 'payment',
            'amount' => 980000,
            'time' => $baseTime->copy()->addMinutes(6),
            'target_type' => 'Nhà cung cấp',
            'target_id' => $partner->id,
            'reference_type' => 'Purchase',
            'reference_code' => 'PN003211',
            'status' => 'completed',
        ]);

        Purchase::create([
            'code' => 'PN003212',
            'supplier_id' => $partner->id,
            'total_amount' => 900000,
            'paid_amount' => 0,
            'status' => 'completed',
            'purchase_date' => $baseTime->copy()->addMinutes(7),
        ]);

        SupplierDebtTransaction::create([
            'supplier_id' => $partner->id,
            'code' => 'CB00306',
            'type' => 'adjustment',
            'amount' => -10000000,
            'created_at' => $baseTime->copy()->addMinutes(8),
        ]);

        // 1) Test customer net ledger history endpoint
        $response = $this->actingAs($this->admin)->getJson("/customers/{$partner->id}/debt-history");
        $response->assertOk();

        $data = $response->json();
        $entries = collect($data['entries']);

        // Assert entries are present and correct signs for Customer (Mirrored)
        $pn3212 = $entries->firstWhere('code', 'PN003212');
        $this->assertNotNull($pn3212);
        $this->assertEquals(-900000, $pn3212['customer_effect']);
        $this->assertEquals(900000, $pn3212['supplier_effect']);

        $pcpn3211 = $entries->firstWhere('code', 'PCPN003211');
        $this->assertNotNull($pcpn3211);
        $this->assertEquals(980000, $pcpn3211['customer_effect']);
        $this->assertEquals(-980000, $pcpn3211['supplier_effect']);

        $cb306 = $entries->firstWhere('code', 'CB00306');
        $this->assertNotNull($cb306);
        $this->assertEquals(10000000, $cb306['customer_effect']);
        $this->assertEquals(-10000000, $cb306['supplier_effect']);

        // Net balance calculations: closing customer net should be -22,850,000 when receivable = 0
        $this->assertEquals(-22850000, $data['summary']['net_debt_amount']);
        // Verify closing running balance in the timeline (reconcile.computed_balance) matches the net debt
        $this->assertEquals(-22850000, $data['reconcile']['computed_balance']);
    }

    /**
     * Case 2 — Có cả hóa đơn bán hàng và nhập hàng
     */
    public function test_dual_role_both_sales_and_purchases(): void
    {
        $partner = Customer::create([
            'code' => 'DualBoth',
            'name' => 'Both Sales and Purchases',
            'debt_amount' => 3250000,
            'supplier_debt_amount' => 22850000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        // Bán hàng +3,250,000 (Customer side)
        // Chúng ta ghi vào CustomerDebt để phản ánh trên ledger thật
        CustomerDebt::create([
            'customer_id' => $partner->id,
            'ref_code' => 'HD0001',
            'type' => 'sale',
            'amount' => 3250000,
            'debt_total' => 3250000,
            'recorded_at' => Carbon::now()->subDays(5),
        ]);

        // Nhập hàng NCC còn payable 22,850,000
        Purchase::create([
            'code' => 'PN000001',
            'supplier_id' => $partner->id,
            'total_amount' => 22850000,
            'paid_amount' => 0,
            'status' => 'completed',
            'purchase_date' => Carbon::now()->subDays(4),
        ]);

        $response = $this->actingAs($this->admin)->getJson("/customers/{$partner->id}/debt-history");
        $response->assertOk();

        $data = $response->json();
        $this->assertEquals(3250000, $data['summary']['customer_debt_amount']);
        $this->assertEquals(22850000, $data['summary']['supplier_debt_amount']);
        // Customer net debt = debt_amount - supplier_debt_amount = 3.25m - 22.85m = -19.6m
        $this->assertEquals(3250000 - 22850000, $data['summary']['net_debt_amount']);
    }

    /**
     * Case 5 — GET không ghi DB
     */
    public function test_get_endpoints_do_not_write_to_db(): void
    {
        $partner = Customer::create([
            'code' => 'NoWriteDB',
            'name' => 'GET strictly read only',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        $customerDebtsCount = CustomerDebt::count();
        $supplierTxsCount = SupplierDebtTransaction::count();
        $cashflowsCount = CashFlow::count();
        $debtOffsetsCount = DebtOffset::count();
        $customersCount = Customer::count();

        // Perform GET request
        $response = $this->actingAs($this->admin)->getJson("/customers/{$partner->id}/debt-history");
        $response->assertOk();

        $this->assertEquals($customerDebtsCount, CustomerDebt::count());
        $this->assertEquals($supplierTxsCount, SupplierDebtTransaction::count());
        $this->assertEquals($cashflowsCount, CashFlow::count());
        $this->assertEquals($debtOffsetsCount, DebtOffset::count());
        $this->assertEquals($customersCount, Customer::count());
    }

    /**
     * Case 6 — Cấn bằng thật
     */
    public function test_offset_displays_correctly(): void
    {
        $partner = Customer::create([
            'code' => 'OffsetPartner',
            'name' => 'Offset Partner',
            'debt_amount' => 5000000,
            'supplier_debt_amount' => 5000000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        // Cấn bằng công nợ 5,000,000
        $offset = DebtOffset::create([
            'code' => 'CB000001',
            'customer_id' => $partner->id,
            'amount' => 5000000,
            'receivable_before' => 5000000,
            'payable_before' => 5000000,
            'receivable_after' => 0,
            'payable_after' => 0,
            'is_auto' => false,
            'status' => 'active',
            'note' => 'Cấn trừ nợ Long Pin',
            'user_id' => $this->admin->id,
        ]);

        // Cũng thêm vào Supplier side để NCC có dòng cấn trừ (chúng ta dùng SupplierDebtTransaction type offset)
        SupplierDebtTransaction::create([
            'supplier_id' => $partner->id,
            'code' => 'CB000001',
            'type' => 'offset',
            'amount' => -5000000, // giảm payable
            'note' => 'Cấn trừ nợ Long Pin',
        ]);

        $response = $this->actingAs($this->admin)->getJson("/customers/{$partner->id}/debt-history");
        $response->assertOk();

        $data = $response->json();
        $entries = collect($data['entries']);

        // HOTFIX FOLLOW-UP contract: a CB voucher must appear EXACTLY ONCE
        // on the dual-role customer-net view. The pre-fix behaviour emitted
        // two rows for the same CB (one from customer-side debt_offset
        // source and one from supplier_ledger_mirror) which gave the
        // misleading impression of a double offset. KiotViet shows only one
        // row per voucher with mirrored sign (+amount on customer screen,
        // -amount on supplier screen).
        $cbEntries = $entries->where('code', 'CB000001')->values();
        $this->assertCount(1, $cbEntries,
            'CB voucher must appear exactly once on customer-net view (KiotViet contract)');

        $cb = $cbEntries->first();
        $this->assertSame('supplier_ledger_mirror', $cb['source'],
            'CB reaches the customer-net view via the supplier mirror');
        $this->assertEquals(+5_000_000, $cb['customer_effect'],
            'Mirror inverts supplier_effect (-5M) → customer_effect (+5M) matching KiotViet sign');
        $this->assertEquals(-5_000_000, $cb['supplier_effect']);
        $this->assertTrue($cb['affects_debt_balance']);
    }

    /**
     * Bổ sung test riêng case Anh Thanh Thiên Phú
     */
    public function test_thien_phu_reconciliation_case(): void
    {
        $partner = Customer::create([
            'code' => 'KH177727496998',
            'name' => 'Anh Thanh-Thiên Phú',
            'debt_amount' => 47400000,
            'supplier_debt_amount' => 75000000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        // 1) MERGE-CUSTOMER-141 +47.420.000đ
        CustomerDebt::create([
            'customer_id' => $partner->id,
            'ref_code' => 'MERGE-CUSTOMER-141',
            'amount' => 47420000,
            'debt_total' => 47420000,
            'type' => 'adjustment',
            'note' => 'Gộp công nợ',
            'recorded_at' => Carbon::parse('2026-05-20 09:00:00'),
        ]);

        // 2) CKTT -20.000đ
        CustomerDebt::create([
            'customer_id' => $partner->id,
            'ref_code' => 'CKTT26052510573737',
            'amount' => -20000,
            'debt_total' => 47400000,
            'type' => 'payment',
            'note' => 'Chiết khấu thanh toán',
            'recorded_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);

        // 3) Purchases (supplier side) total 75.000.000đ
        $purchases = [
            ['PN20260523105400', 62100000, '2026-05-23 10:54:00'],
            ['PN20260523143050', 2100000, '2026-05-23 14:30:50'],
            ['PN20260527150940', 5400000, '2026-05-27 15:09:40'],
            ['PN20260527163153', 2700000, '2026-05-27 16:31:53'],
            ['PN20260528090703', 2700000, '2026-05-28 09:07:03'],
        ];

        foreach ($purchases as [$code, $total, $date]) {
            Purchase::create([
                'code' => $code,
                'supplier_id' => $partner->id,
                'total_amount' => $total,
                'paid_amount' => 0,
                'debt_amount' => $total,
                'status' => 'completed',
                'purchase_date' => Carbon::parse($date),
            ]);
        }

        // Test customer net ledger history endpoint
        $response = $this->actingAs($this->admin)->getJson("/customers/{$partner->id}/debt-history");
        $response->assertOk();

        $data = $response->json();
        
        // Assert summaries
        $this->assertEquals(47400000, $data['summary']['customer_debt_amount']);
        $this->assertEquals(75000000, $data['summary']['supplier_debt_amount']);
        $this->assertEquals(-27600000, $data['summary']['net_debt_amount']);
        $this->assertEquals(-27600000, $data['reconcile']['computed_balance']);
        $this->assertFalse($data['reconcile']['has_mismatch']);

        // Assert entries are correct
        $entries = collect($data['entries']);
        
        $merge = $entries->firstWhere('code', 'MERGE-CUSTOMER-141');
        $this->assertNotNull($merge);
        $this->assertEquals('Số dư đầu kỳ / Gộp công nợ', $merge['display_type']);
        $this->assertEquals(47420000, $merge['customer_effect']);
        $this->assertTrue($merge['affects_debt_balance']);

        $cktt = $entries->firstWhere('code', 'CKTT26052510573737');
        $this->assertNotNull($cktt);
        $this->assertEquals('Chiết khấu thanh toán', $cktt['display_type']);
        $this->assertEquals(-20000, $cktt['customer_effect']);
        $this->assertTrue($cktt['affects_debt_balance']);

        // Test supplier ledger endpoint
        $supResponse = $this->actingAs($this->admin)->getJson("/api/suppliers/{$partner->id}/debt-transactions");
        $supResponse->assertOk();
        $supData = $supResponse->json();
        $this->assertEquals(75000000, $supData['summary']['net']);

        // Run the console command dry-run for this customer to output the reconciliation table
        $this->artisan('customers:reconcile-partner-ledger', ['--customer-id' => $partner->id])
            ->assertExitCode(0);
    }
}

