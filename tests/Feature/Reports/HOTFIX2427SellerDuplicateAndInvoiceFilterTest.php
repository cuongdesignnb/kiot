<?php

namespace Tests\Feature\Reports;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Reports\SellerResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX 24.27 — Prevent duplicate sellers and align invoice filter consistency.
 * UPDATED for HOTFIX 24.28B contract: created_by_name is creator, NOT seller.
 *
 * Key invariants:
 *   - Two employees with same name but different people → disambiguated labels
 *   - Report and invoice list use same SellerResolver → same seller matches same invoices
 *   - Employee with no invoices has no revenue
 *   - seller_name matching one employee → merges
 *   - Profit 8 columns still correct
 *   - Cancelled invoices excluded
 */
class HOTFIX2427SellerDuplicateAndInvoiceFilterTest extends TestCase
{
    use DatabaseTransactions;

    private function adminUser(string $name = 'Admin 2427'): User
    {
        return User::create([
            'name'     => $name,
            'email'    => 'admin-2427-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function regularUser(string $name = 'Staff 2427'): User
    {
        return User::create([
            'name'     => $name,
            'email'    => 'staff-2427-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => 1,
            'status'   => 'active',
        ]);
    }

    private function employee(string $name = 'NV 2427', ?string $code = null, ?int $userId = null): Employee
    {
        return Employee::create([
            'code'      => $code ?? ('NV-2427-' . uniqid()),
            'name'      => $name,
            'is_active' => true,
            'user_id'   => $userId,
        ]);
    }

    private function product(int $cost = 600_000, int $retail = 1_500_000): Product
    {
        return Product::create([
            'sku'                  => 'SKU-2427-' . uniqid(),
            'name'                 => 'SP 2427',
            'cost_price'           => $cost,
            'retail_price'         => $retail,
            'stock_quantity'       => 100,
            'inventory_total_cost' => $cost * 100,
            'has_serial'           => false,
        ]);
    }

    /**
     * HOTFIX 24.28B: invoice helper now accepts seller_name for proper contract.
     */
    private function invoice(
        ?int $createdBy,
        ?string $createdByName,
        Product $product,
        int $qty = 1,
        int $price = 1_000_000,
        int $discount = 0,
        string $status = 'Hoàn thành',
        ?int $costPrice = 600_000,
        ?string $sellerName = null
    ): Invoice {
        $subtotal = $qty * $price;
        $inv = Invoice::create([
            'code'             => 'HD-2427-' . uniqid(),
            'created_by'       => $createdBy,
            'created_by_name'  => $createdByName,
            'seller_name'      => $sellerName,
            'subtotal'         => $subtotal,
            'discount'         => $discount,
            'total'            => $subtotal - $discount,
            'customer_paid'    => $subtotal - $discount,
            'status'           => $status,
            'sales_channel'    => 'Bán trực tiếp',
        ]);
        InvoiceItem::create([
            'invoice_id' => $inv->id,
            'product_id' => $product->id,
            'quantity'   => $qty,
            'price'      => $price,
            'cost_price' => $costPrice,
        ]);
        $inv->created_at = Carbon::now()->startOfDay()->addMinute();
        $inv->save();
        return $inv;
    }

    // ── TC-01 — Employee seller with linked user → one row, employee key ──
    public function test_user_and_employee_same_name_same_person_no_duplicate(): void
    {
        $user = $this->regularUser('Vũ Hồng Nhung');
        $emp  = $this->employee('Vũ Hồng Nhung', 'NV001', $user->id);
        $product = $this->product();

        // Invoice created by user, seller = employee
        $this->invoice($emp->id, $user->name, $product, 1, 2_000_000, sellerName: $emp->name);

        $res = $this->actingAs($user)->get('/reports/employees?concern=profit&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        // Only 1 row for Vũ Hồng Nhung
        $matchingRows = collect($rows)->filter(fn($r) => str_contains($r['name'], 'Vũ Hồng Nhung'));
        $this->assertCount(1, $matchingRows, 'Same person (employee) must appear as one row');

        // Canonical key should be employee:<emp_id>
        $row = $matchingRows->first();
        $this->assertSame("employee:{$emp->id}", $row['id']);

        // Dropdown also no duplicate
        $options = $res->viewData('page')['props']['employees'];
        $nhungOptions = collect($options)->filter(fn($o) => str_contains($o['name'], 'Vũ Hồng Nhung'));
        $this->assertCount(1, $nhungOptions, 'Dropdown must not duplicate same person');
    }

    // ── TC-02 — Two employees same name different people → disambiguated labels ──
    public function test_two_employees_same_name_different_people_disambiguated(): void
    {
        $admin = $this->adminUser();
        $emp1 = $this->employee('Vũ Hồng Nhung', 'NV001');
        $emp2 = $this->employee('Vũ Hồng Nhung', 'NV002');
        $product = $this->product();

        $this->invoice($emp1->id, $admin->name, $product, 1, 1_000_000, sellerName: $emp1->name);
        $this->invoice($emp2->id, $admin->name, $product, 1, 2_000_000, sellerName: $emp2->name);

        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $nhungRows = collect($rows)->filter(fn($r) => str_contains($r['name'], 'Vũ Hồng Nhung'));
        $this->assertCount(2, $nhungRows, 'Two different employees must appear as two rows');

        // Labels must be disambiguated (contain code)
        $names = $nhungRows->pluck('name')->all();
        $this->assertNotEquals($names[0], $names[1], 'Duplicate names must be disambiguated by code');
        $this->assertTrue(
            str_contains($names[0], 'NV001') || str_contains($names[0], 'NV002'),
            'Label must contain employee code for disambiguation'
        );
    }

    // ── TC-03 — Invoice report and invoice list filter use same seller key ──
    public function test_invoice_report_and_invoice_list_use_same_seller_key(): void
    {
        $admin = $this->adminUser();
        $emp = $this->employee('Seller TC03', 'NV-TC03');
        $product = $this->product();
        $inv = $this->invoice($emp->id, $admin->name, $product, 1, 3_000_000, sellerName: $emp->name);

        // Get the seller key from report
        $resReport = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $resReport->assertOk();
        $rows = $resReport->viewData('page')['props']['reportRows'];
        $row = collect($rows)->firstWhere('id', "employee:{$emp->id}");
        $this->assertNotNull($row, 'Seller must appear in report');
        $sellerKey = $row['seller_key'];

        // Filter invoices with same seller key
        $resInvoice = $this->actingAs($admin)->get("/invoices?seller_key={$sellerKey}");
        $resInvoice->assertOk();
        $invoices = $resInvoice->viewData('page')['props']['invoices']['data'];

        $this->assertNotEmpty($invoices, 'Invoice list must return invoices for the seller key');
        $invoiceIds = collect($invoices)->pluck('id')->all();
        $this->assertContains($inv->id, $invoiceIds, 'Same invoice must appear in both report and invoice list');
    }

    // ── TC-04 — Employee with no invoices has no revenue ──
    public function test_employee_with_no_invoices_has_no_revenue(): void
    {
        $admin = $this->adminUser();
        $emp = $this->employee('Ghost Employee', 'NV999');
        $product = $this->product();
        // Create invoice for unknown seller (no employee selected)
        $this->invoice(null, $admin->name, $product, 1, 5_000_000);

        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $ghostRow = collect($rows)->firstWhere('id', "employee:{$emp->id}");
        $this->assertNull($ghostRow, 'Employee with no invoices must not appear in report with revenue');
    }

    // ── TC-05 — created_by = employee id resolves correctly ──
    public function test_created_by_employee_id_resolves_correctly(): void
    {
        $admin = $this->adminUser();
        $emp = $this->employee('Nhân viên Thật', 'NV-REAL');
        $product = $this->product();

        // Invoice with created_by = employee.id (seller = employee)
        $inv = $this->invoice($emp->id, $admin->name, $product, 1, 10_000_000, sellerName: $emp->name);

        $resolver = new SellerResolver();
        $map = $resolver->invoiceSellerMap(Invoice::whereKey($inv->id));

        $sellerKey = $map[$inv->id];
        $this->assertSame("employee:{$emp->id}", $sellerKey,
            'Invoice with created_by = employee id must map to employee key');
    }

    // ── TC-06 — Invoice with no seller goes to unknown ──
    public function test_invoice_with_no_seller_goes_to_unknown(): void
    {
        $admin = $this->adminUser('Admin Persistence');
        $product = $this->product();
        $this->invoice(null, $admin->name, $product, 1, 1_500_000);

        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        // Invoice without seller → unknown bucket (not admin)
        $unknownRow = collect($rows)->firstWhere('id', 'unknown');
        $this->assertNotNull($unknownRow, 'Invoice without seller must go to unknown bucket');
        $this->assertEquals(1_500_000, (int) $unknownRow['revenue']);
    }

    // ── TC-07 — Profit 8 columns still correct ──
    public function test_profit_8_columns_still_correct(): void
    {
        $admin = $this->adminUser();
        $emp = $this->employee('Profit TC-07', 'NV-P07');
        $product = $this->product(cost: 600_000);
        $this->invoice($emp->id, $admin->name, $product, 2, 1_000_000,
            discount: 100_000, costPrice: 600_000, sellerName: $emp->name);

        $res = $this->actingAs($admin)->get('/reports/employees?concern=profit&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];
        $row = collect($rows)->firstWhere('id', "employee:{$emp->id}");
        $this->assertNotNull($row);

        $this->assertArrayHasKey('gross_revenue', $row);
        $this->assertArrayHasKey('invoice_discount', $row);
        $this->assertArrayHasKey('revenue_after_discount', $row);
        $this->assertArrayHasKey('return_value', $row);
        $this->assertArrayHasKey('net_revenue', $row);
        $this->assertArrayHasKey('total_cogs', $row);
        $this->assertArrayHasKey('gross_profit', $row);

        $this->assertEquals(2_000_000, (int) $row['gross_revenue']);
        $this->assertEquals(100_000, (int) $row['invoice_discount']);
        $this->assertEquals(1_900_000, (int) $row['revenue_after_discount']);
        $this->assertEquals(1_200_000, (int) $row['total_cogs']);
        $this->assertEquals(700_000, (int) $row['gross_profit']);
    }

    // ── TC-08 — Cancelled invoice not counted ──
    public function test_cancelled_invoice_not_counted(): void
    {
        $admin = $this->adminUser();
        $emp = $this->employee('Cancel TC-08', 'NV-C08');
        $product = $this->product();
        $this->invoice($emp->id, $admin->name, $product, 1, 5_000_000,
            status: 'Đã hủy', sellerName: $emp->name);
        $this->invoice($emp->id, $admin->name, $product, 1, 2_000_000,
            sellerName: $emp->name);

        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];
        $row = collect($rows)->firstWhere('id', "employee:{$emp->id}");
        $this->assertNotNull($row);
        $this->assertEquals(2_000_000, (int) $row['revenue'], 'Only non-cancelled invoice should count');
    }

    // ── TC-09 — seller_name matching employee resolves to employee key ──
    public function test_seller_name_matching_employee_resolves(): void
    {
        $user = $this->regularUser('Merged Person');
        $emp  = $this->employee('Merged Person', 'NV-MERGE', $user->id);
        $product = $this->product();

        // Invoice with created_by = employee.id
        $inv = $this->invoice($emp->id, $user->name, $product, 1, 1_000_000, sellerName: $emp->name);

        $resolver = new SellerResolver();
        $map = $resolver->invoiceSellerMap(Invoice::whereKey($inv->id));

        $this->assertSame("employee:{$emp->id}", $map[$inv->id],
            'Invoice with created_by = employee id must map to employee key');
    }

    // ── TC-10 — seller_name matching one active employee (no created_by) → merges ──
    public function test_seller_name_matching_one_employee_merges(): void
    {
        $admin = $this->adminUser();
        $emp = $this->employee('Unique Seller', 'NV-UNIQ');
        $product = $this->product();

        // Invoice with seller_name but no created_by
        $inv = $this->invoice(null, $admin->name, $product, 1, 1_000_000,
            sellerName: 'Unique Seller');

        $resolver = new SellerResolver();
        $map = $resolver->invoiceSellerMap(Invoice::whereKey($inv->id));

        $this->assertSame("employee:{$emp->id}", $map[$inv->id],
            'seller_name matching exactly one active employee must merge to employee key');
    }

    // ── TC-11 — seller_name matching multiple employees stays snapshot ──
    public function test_seller_name_matching_multiple_employees_stays_snapshot(): void
    {
        $admin = $this->adminUser();
        $this->employee('Ambiguous Name', 'NV-AMB1');
        $this->employee('Ambiguous Name', 'NV-AMB2');
        $product = $this->product();

        $inv = $this->invoice(null, $admin->name, $product, 1, 1_000_000,
            sellerName: 'Ambiguous Name');

        $resolver = new SellerResolver();
        $map = $resolver->invoiceSellerMap(Invoice::whereKey($inv->id));

        $this->assertSame('snapshot:Ambiguous Name', $map[$inv->id],
            'seller_name matching multiple employees must stay snapshot');
    }

    // ── TC-12 — Invoice filter options contain sellers from SellerResolver ──
    public function test_invoice_filter_options_contain_sellers(): void
    {
        $admin = $this->adminUser('Invoice Filter Admin');
        $emp = $this->employee('Invoice Seller', 'NV-FILT');
        $product = $this->product();
        $this->invoice($emp->id, $admin->name, $product, sellerName: $emp->name);

        $res = $this->actingAs($admin)->get('/invoices');
        $res->assertOk();
        $filterOptions = $res->viewData('page')['props']['filterOptions'];
        $this->assertArrayHasKey('sellers', $filterOptions, 'Invoice page must have sellers in filter options');

        // Employee seller must be in options
        $empOpt = collect($filterOptions['sellers'])->first(fn($s) => $s['name'] === 'Invoice Seller');
        $this->assertNotNull($empOpt, 'Employee seller must appear in seller options');

        // HOTFIX 24.32 evolved contract: super-admin users without a
        // linked employee DO appear in seller options as virtual
        // admin_user:<id>. They must NOT appear with type creator_snapshot,
        // which is the original separation HOTFIX 24.27 was protecting.
        $adminOpt = collect($filterOptions['sellers'])->first(fn($s) => $s['name'] === 'Invoice Filter Admin');
        $this->assertNotNull($adminOpt, 'Super admin appears as virtual seller (24.32)');
        $this->assertSame('admin_user', $adminOpt['type'],
            'Admin must surface as admin_user type, not creator_snapshot');

        // Admin should still also be in creator options (snapshot from created_by_name)
        $this->assertArrayHasKey('creators', $filterOptions);
        $adminCreator = collect($filterOptions['creators'])->first(fn($c) => $c['name'] === 'Invoice Filter Admin');
        $this->assertNotNull($adminCreator, 'Creator must appear in creator options');
    }
}
