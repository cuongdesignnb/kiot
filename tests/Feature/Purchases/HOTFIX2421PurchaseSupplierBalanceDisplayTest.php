<?php

namespace Tests\Feature\Purchases;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX 24.21 (brief labels this "24.19"; renamed to avoid colliding
 * with the existing 24.19 hide-inactive-suppliers chain).
 *
 * The Nhập hàng forms (Create / Edit / Show-inline-edit) now display:
 *   - the supplier's current debt/credit before this purchase,
 *   - "Tiền thừa" when the operator pays more than the invoice asks for
 *     (previously the surplus was clamped at 0 in the UI and silently
 *     disappeared into supplier_debt_amount as a negative number),
 *   - the projected supplier balance after this purchase saves.
 *
 * All three computeds depend on `supplier_debt_amount` being present
 * on the supplier objects passed to the page. This suite pins that
 * contract end-to-end and confirms the backend formula stayed exactly
 * the same (overpayment still flows through as a negative debt_amount).
 */
class HOTFIX2421PurchaseSupplierBalanceDisplayTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 2421',
            'email'    => 'admin-2421-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function supplier(int $debt = 0, string $name = 'NCC 2421'): Customer
    {
        return Customer::create([
            'code'                 => 'NCC-2421-' . uniqid(),
            'name'                 => $name,
            'phone'                => '09' . random_int(10000000, 99999999),
            'is_customer'          => false,
            'is_supplier'          => true,
            'status'               => 'active',
            'debt_amount'          => 0,
            'supplier_debt_amount' => $debt,
            'total_bought'         => 0,
        ]);
    }

    private function product(string $name = 'SP 2421', int $cost = 100_000, int $retail = 200_000): Product
    {
        return Product::create([
            'sku'                  => 'SKU-2421-' . uniqid(),
            'name'                 => $name,
            'cost_price'           => $cost,
            'retail_price'         => $retail,
            'stock_quantity'       => 100,
            'inventory_total_cost' => $cost * 100,
            'has_serial'           => false,
        ]);
    }

    // ── TC-01 — /purchases/create suppliers prop carries supplier_debt_amount ──
    public function test_purchase_create_suppliers_include_supplier_debt_amount(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier(1_500_000, 'Has Debt 2421');

        $res = $this->actingAs($admin)->get('/purchases/create');
        $res->assertOk();

        // Inertia HTML carries props as JSON in `data-page` — search the raw
        // body for the supplier code and the numeric debt to confirm the
        // field reached the frontend.
        $body = $res->getContent();
        $this->assertStringContainsString($sup->code, $body, 'supplier code reached the FE');
        $this->assertStringContainsString('supplier_debt_amount', $body,
            'serialised props must mention supplier_debt_amount key');
        $this->assertStringContainsString('1500000', $body,
            'numeric supplier_debt_amount must reach the FE so the form can render "Nợ cũ NCC"');
    }

    // ── TC-02 — /api/suppliers/search response carries supplier_debt_amount ──
    public function test_supplier_search_response_includes_supplier_debt_amount(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier(2_000_000, 'Search Debt 2421');

        $res = $this->actingAs($admin)->getJson('/api/suppliers/search?search=Search%20Debt%202421');
        $res->assertOk();
        $rows = $res->json();
        $this->assertNotEmpty($rows, 'search must return the supplier');
        $row = collect($rows)->firstWhere('code', $sup->code);
        $this->assertNotNull($row, 'matching row must be present');
        $this->assertArrayHasKey('supplier_debt_amount', $row,
            'response shape must include supplier_debt_amount for the FE balance card');
        $this->assertEquals(2_000_000, (int) $row['supplier_debt_amount']);
    }

    // ── TC-03 — backend still allows overpayment (negative debt_amount), unchanged ──
    public function test_purchase_store_keeps_negative_debt_amount_when_overpaid(): void
    {
        $admin   = $this->admin();
        $sup     = $this->supplier(0);
        $product = $this->product();

        $payload = [
            'supplier_id'   => $sup->id,
            'discount'      => 0,
            'paid_amount'   => 1_200_000,                       // overpay by 200,000
            'note'          => 'Test overpay 2421',
            'status'        => 'completed',
            'purchase_date' => now()->toDateTimeString(),
            'items'         => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 1_000_000,
                'discount'   => 0,
                'subtotal'   => 1_000_000,
            ]],
            'payment_method' => 'cash',
        ];

        $res = $this->actingAs($admin)->postJson('/purchases', $payload);
        $this->assertLessThan(500, $res->getStatusCode(), 'overpayment must not 500');
        // Whether 201 / 200 / 302 depends on the form-response shape — we
        // care about the DB side-effect, not the HTTP code.

        $purchase = Purchase::where('supplier_id', $sup->id)
            ->orderByDesc('id')->first();
        $this->assertNotNull($purchase, 'purchase row must be created');
        $this->assertEquals(-200_000, (int) $purchase->debt_amount,
            'legacy formula keeps overpayment as a negative debt_amount — unchanged');

        // Supplier balance walks the same direction the formula always did.
        $sup->refresh();
        $this->assertEquals(-200_000, (int) $sup->supplier_debt_amount,
            'NCC ends up with credit balance of 200k — formula intact');
    }

    // ── TC-04 — formula still walks supplier_debt_amount linearly on update ──
    public function test_purchase_update_supplier_debt_walks_linearly_when_paid_changes(): void
    {
        $admin   = $this->admin();
        $sup     = $this->supplier(0);
        $product = $this->product();

        // Initial create: total 1M, paid 0 → debt 1M.
        $createRes = $this->actingAs($admin)->postJson('/purchases', [
            'supplier_id'   => $sup->id,
            'discount'      => 0,
            'paid_amount'   => 0,
            'status'        => 'completed',
            'purchase_date' => now()->toDateTimeString(),
            'items'         => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 1_000_000,
                'discount'   => 0,
                'subtotal'   => 1_000_000,
            ]],
            'payment_method' => 'cash',
        ]);
        $this->assertLessThan(500, $createRes->getStatusCode());

        $purchase = Purchase::where('supplier_id', $sup->id)->orderByDesc('id')->first();
        $this->assertNotNull($purchase);
        $sup->refresh();
        $this->assertEquals(1_000_000, (int) $sup->supplier_debt_amount, 'after create: NCC nợ 1M');

        // Update paid_amount up to 1.5M → row becomes overpaid by 500k.
        $updateRes = $this->actingAs($admin)->putJson("/purchases/{$purchase->id}", [
            'note'              => $purchase->note,
            'purchase_date'     => $purchase->purchase_date,
            'discount'          => 0,
            'paid_amount'       => 1_500_000,
            'payment_method'    => 'cash',
            'bank_account_info' => null,
            'employee_id'       => null,
        ]);
        $this->assertLessThan(500, $updateRes->getStatusCode(),
            'updating paid_amount past total must not 500');

        $sup->refresh();
        $this->assertEquals(-500_000, (int) $sup->supplier_debt_amount,
            'supplier ends with 500k credit — formula stayed linear, no clamp');
    }
}
