<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomerNetDebtTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin Net Debt Test',
            'email' => 'admin-net-debt-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
    }

    private function getCustomersProps($response)
    {
        // For Inertia responses, the view data contains 'page' key
        $page = $response->original->getData()['page'] ?? null;
        if ($page && isset($page['props'])) {
            return $page['props'];
        }
        return $response->json();
    }

    /**
     * Test 1 — Khách chỉ mua hàng chưa trả.
     */
    public function test_customer_only_buys_and_does_not_pay(): void
    {
        $customer = Customer::create([
            'code' => 'KH-NET-' . uniqid(),
            'name' => 'Customer A Only Sale',
            'debt_amount' => 10000000,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
        ]);

        $response = $this->actingAs($this->admin)->get('/customers');
        $response->assertOk();

        $props = $this->getCustomersProps($response);
        $data = collect($props['customers']['data'] ?? []);
        $c = $data->firstWhere('id', $customer->id);

        $this->assertNotNull($c);
        $this->assertEquals(10000000, $c['net_debt_amount']);
        $this->assertEquals('customer_owes_store', $c['net_debt_direction']);
        $this->assertEquals('Khách còn nợ', $c['net_debt_label']);
    }

    /**
     * Test 2 — Khách kiêm NCC có nhập hàng chưa trả.
     */
    public function test_dual_role_partner_with_purchase_unpaid(): void
    {
        $customer = Customer::create([
            'code' => 'KH-NET-' . uniqid(),
            'name' => 'Dual Partner A',
            'debt_amount' => 54620000,
            'supplier_debt_amount' => 27020000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        $response = $this->actingAs($this->admin)->get('/customers');
        $response->assertOk();

        $props = $this->getCustomersProps($response);
        $data = collect($props['customers']['data'] ?? []);
        $c = $data->firstWhere('id', $customer->id);

        $this->assertNotNull($c);
        $this->assertEquals(27600000, $c['net_debt_amount']);
    }

    /**
     * Test 3 — Nhập hàng lớn hơn bán hàng (Store owes partner).
     */
    public function test_purchase_exceeds_sale_store_owes_partner(): void
    {
        $customer = Customer::create([
            'code' => 'KH-NET-' . uniqid(),
            'name' => 'Dual Partner B',
            'debt_amount' => 10000000,
            'supplier_debt_amount' => 15000000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        $response = $this->actingAs($this->admin)->get('/customers');
        $response->assertOk();

        $props = $this->getCustomersProps($response);
        $data = collect($props['customers']['data'] ?? []);
        $c = $data->firstWhere('id', $customer->id);

        $this->assertNotNull($c);
        $this->assertEquals(-5000000, $c['net_debt_amount']);
        $this->assertEquals('store_owes_customer_supplier', $c['net_debt_direction']);
        $this->assertEquals('Mình còn nợ lại', $c['net_debt_label']);
    }

    /**
     * Test 4 — Phiếu nhập đã trả tiền một phần.
     */
    public function test_partial_paid_purchase(): void
    {
        $customer = Customer::create([
            'code' => 'KH-NET-' . uniqid(),
            'name' => 'Dual Partner C',
            'debt_amount' => 54620000,
            'supplier_debt_amount' => 20000000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        $response = $this->actingAs($this->admin)->get('/customers');
        $response->assertOk();

        $props = $this->getCustomersProps($response);
        $data = collect($props['customers']['data'] ?? []);
        $c = $data->firstWhere('id', $customer->id);

        $this->assertNotNull($c);
        $this->assertEquals(34620000, $c['net_debt_amount']);
    }

    /**
     * Test 5 — Summary tổng nợ phải thu.
     */
    public function test_net_debt_summary_totals(): void
    {
        Customer::query()->update(['debt_amount' => 0, 'supplier_debt_amount' => 0]);

        Customer::create([
            'code' => 'KH-NET-S1',
            'name' => 'Customer S1',
            'debt_amount' => 10000000,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
        ]);

        Customer::create([
            'code' => 'KH-NET-S2',
            'name' => 'Customer S2',
            'debt_amount' => 10000000,
            'supplier_debt_amount' => 15000000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        Customer::create([
            'code' => 'KH-NET-S3',
            'name' => 'Customer S3',
            'debt_amount' => 5000000,
            'supplier_debt_amount' => 5000000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        $response = $this->actingAs($this->admin)->get('/customers');
        $response->assertOk();

        $props = $this->getCustomersProps($response);
        $summary = $props['summary'] ?? [];
        $this->assertEquals(10000000, $summary['total_debt']);
        $this->assertEquals(5000000, $summary['total_store_owes']);
    }

    /**
     * Test 6 — Filter has_debt.
     */
    public function test_filter_has_debt(): void
    {
        Customer::query()->update(['debt_amount' => 0, 'supplier_debt_amount' => 0]);

        $c1 = Customer::create([
            'code' => 'KH-F1',
            'name' => 'Customer F1',
            'debt_amount' => 10000000,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
        ]);

        $c2 = Customer::create([
            'code' => 'KH-F2',
            'name' => 'Customer F2',
            'debt_amount' => 10000000,
            'supplier_debt_amount' => 15000000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        // Filter has_debt = yes
        $respYes = $this->actingAs($this->admin)->get('/customers?has_debt=yes');
        $respYes->assertOk();
        $propsYes = $this->getCustomersProps($respYes);
        $idsYes = collect($propsYes['customers']['data'] ?? [])->pluck('id')->all();
        $this->assertContains($c1->id, $idsYes);
        $this->assertNotContains($c2->id, $idsYes);

        // Filter has_debt = no
        $respNo = $this->actingAs($this->admin)->get('/customers?has_debt=no');
        $respNo->assertOk();
        $propsNo = $this->getCustomersProps($respNo);
        $idsNo = collect($propsNo['customers']['data'] ?? [])->pluck('id')->all();
        $this->assertNotContains($c1->id, $idsNo);
        $this->assertContains($c2->id, $idsNo);
    }

    /**
     * Test 8 — Không ảnh hưởng khách thường.
     */
    public function test_normal_customer_unaffected(): void
    {
        $customer = Customer::create([
            'code' => 'KH-NET-' . uniqid(),
            'name' => 'Normal Customer',
            'debt_amount' => 500000,
            'supplier_debt_amount' => 0,
            'is_customer' => true,
            'is_supplier' => false,
        ]);

        $response = $this->actingAs($this->admin)->get('/customers');
        $response->assertOk();

        $props = $this->getCustomersProps($response);
        $data = collect($props['customers']['data'] ?? []);
        $c = $data->firstWhere('id', $customer->id);

        $this->assertNotNull($c);
        $this->assertEquals(500000, $c['net_debt_amount']);
    }
}
