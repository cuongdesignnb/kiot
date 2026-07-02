<?php

namespace Tests\Feature\Reports;

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
 * HOTFIX 24.28B — Seller / Creator data contract tests.
 *
 * Key invariants:
 *   - created_by_name is NEVER used as seller (it's the creator snapshot)
 *   - created_by = seller employee id
 *   - seller_name = seller name snapshot
 *   - Invoices without seller → 'unknown' bucket
 *   - Admin creator does NOT become seller
 *   - Report and invoice filter use same SellerResolver
 */
class HOTFIX2428SellerCreatorContractTest extends TestCase
{
    use DatabaseTransactions;

    private function adminUser(string $name = 'Admin 2428'): User
    {
        return User::create([
            'name'     => $name,
            'email'    => 'admin-2428-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function employee(string $name = 'NV 2428', ?string $code = null): Employee
    {
        return Employee::create([
            'code'      => $code ?? ('NV-2428-' . uniqid()),
            'name'      => $name,
            'is_active' => true,
        ]);
    }

    private function product(int $cost = 600_000): Product
    {
        return Product::create([
            'sku'                  => 'SKU-2428-' . uniqid(),
            'name'                 => 'SP 2428',
            'cost_price'           => $cost,
            'retail_price'         => 1_500_000,
            'stock_quantity'       => 100,
            'inventory_total_cost' => $cost * 100,
            'has_serial'           => false,
        ]);
    }

    /**
     * Create an invoice with explicit seller/creator fields.
     */
    private function invoice(
        ?int $createdBy,
        ?string $createdByName,
        ?string $sellerName,
        Product $product,
        int $qty = 1,
        int $price = 1_000_000,
        int $discount = 0,
        string $status = 'Hoàn thành',
        int $costPrice = 600_000
    ): Invoice {
        $subtotal = $qty * $price;
        $inv = Invoice::create([
            'code'             => 'HD-2428-' . uniqid(),
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

    // ── Test 1: creator không tự thành seller ──
    public function test_creator_does_not_become_seller(): void
    {
        $admin = $this->adminUser('Trần Văn Tiến');
        $product = $this->product();

        // Admin creates invoice but no seller selected
        $inv = $this->invoice(null, 'Trần Văn Tiến', null, $product, 1, 2_000_000);

        $resolver = new SellerResolver();
        $map = $resolver->invoiceSellerMap(Invoice::whereKey($inv->id));

        $this->assertSame('unknown', $map[$inv->id],
            'Invoice without seller must map to unknown, not to creator');

        // Check report
        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $creatorRow = collect($rows)->first(fn($r) => str_contains($r['name'], 'Trần Văn Tiến'));
        $this->assertNull($creatorRow,
            'Creator must NOT appear as a seller in report');

        $unknownRow = collect($rows)->first(fn($r) => $r['id'] === 'unknown');
        $this->assertNotNull($unknownRow,
            'Unknown seller bucket must exist');
        $this->assertEquals(2_000_000, (int) $unknownRow['revenue']);
    }

    // ── Test 2: seller khác creator ──
    public function test_seller_different_from_creator(): void
    {
        $admin = $this->adminUser('Trần Văn Tiến');
        $emp = $this->employee('Vũ Hồng Nhung', 'NV001');
        $product = $this->product();

        // Admin creates invoice, seller = employee
        $inv = $this->invoice($emp->id, 'Trần Văn Tiến', 'Vũ Hồng Nhung', $product, 1, 3_000_000);

        // Report should attribute to employee, not admin
        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $sellerRow = collect($rows)->first(fn($r) => $r['id'] === "employee:{$emp->id}");
        $this->assertNotNull($sellerRow, 'Employee seller must appear in report');
        $this->assertEquals(3_000_000, (int) $sellerRow['revenue']);

        $creatorRow = collect($rows)->first(fn($r) => str_contains($r['name'], 'Trần Văn Tiến'));
        $this->assertNull($creatorRow, 'Creator must NOT appear as seller in report');

        // Invoice filter by seller
        $resInv = $this->actingAs($admin)->get("/invoices?seller_key=employee:{$emp->id}");
        $resInv->assertOk();
        $invoices = $resInv->viewData('page')['props']['invoices']['data'];
        $this->assertNotEmpty($invoices);
        $this->assertContains($inv->id, collect($invoices)->pluck('id')->all());

        // Invoice filter by creator
        $resCreator = $this->actingAs($admin)->get('/invoices?creator_key=creator_snapshot:Trần Văn Tiến');
        $resCreator->assertOk();
        $creatorInvoices = $resCreator->viewData('page')['props']['invoices']['data'];
        $this->assertNotEmpty($creatorInvoices);
        $this->assertContains($inv->id, collect($creatorInvoices)->pluck('id')->all());
    }

    // ── Test 3: đổi tên user không làm creator snapshot thành seller ──
    public function test_rename_user_does_not_make_old_snapshot_a_seller(): void
    {
        $admin = $this->adminUser('Admin');
        $product = $this->product();

        // Create invoice with old name
        $this->invoice(null, 'Admin', null, $product);

        // Rename user
        $admin->name = 'Trần Văn Tiến';
        $admin->save();

        $resolver = new SellerResolver();

        // Creator options should have 'Admin' as snapshot
        $creatorOpts = $resolver->buildCreatorFilterOptions();
        $adminCreator = collect($creatorOpts)->first(fn($o) => $o['name'] === 'Admin');
        $this->assertNotNull($adminCreator, 'Old creator name must appear in creator options');

        // Seller options must NOT have Admin
        $sellerOpts = $resolver->buildSellerFilterOptions();
        $adminSeller = collect($sellerOpts)->first(fn($o) => $o['name'] === 'Admin');
        $this->assertNull($adminSeller, 'Old creator name must NOT appear in seller options');

        // Unknown bucket should exist for the invoice
        $unknownSeller = collect($sellerOpts)->first(fn($o) => $o['key'] === 'unknown');
        $this->assertNotNull($unknownSeller, 'Unknown seller bucket must exist');
    }

    // ── Test 4: seller_name snapshot without employee ──
    public function test_seller_name_snapshot_without_employee(): void
    {
        $admin = $this->adminUser();
        $product = $this->product();

        // Invoice with seller_name but no employee id
        $inv = $this->invoice(null, 'Trần Văn Tiến', 'CTV Nguyễn A', $product, 1, 1_000_000);

        $resolver = new SellerResolver();
        $map = $resolver->invoiceSellerMap(Invoice::whereKey($inv->id));

        $this->assertSame('snapshot:CTV Nguyễn A', $map[$inv->id],
            'Seller name without matching employee must be snapshot key');

        // Report should attribute to snapshot seller
        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $snapshotRow = collect($rows)->first(fn($r) => str_contains($r['name'], 'CTV Nguyễn A'));
        $this->assertNotNull($snapshotRow, 'Snapshot seller must appear in report');
        $this->assertEquals(1_000_000, (int) $snapshotRow['revenue']);

        // Must NOT attribute to creator
        $creatorRow = collect($rows)->first(fn($r) => str_contains($r['name'], 'Trần Văn Tiến'));
        $this->assertNull($creatorRow, 'Creator must NOT appear as seller');
    }

    // ── Test 5: employee-user same name no duplicate ──
    public function test_employee_user_same_name_no_duplicate(): void
    {
        $user = User::create([
            'name' => 'Vũ Hồng Nhung', 'email' => 'vhn-2428-' . uniqid() . '@test.local',
            'password' => bcrypt('p'), 'role_id' => 1, 'status' => 'active',
        ]);
        $emp = $this->employee('Vũ Hồng Nhung', 'NV000016');
        $emp->user_id = $user->id;
        $emp->save();
        $product = $this->product();

        $this->invoice($emp->id, $user->name, $emp->name, $product, 1, 2_000_000);

        $res = $this->actingAs($user)->get('/reports/employees?concern=sales&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $nhungRows = collect($rows)->filter(fn($r) => str_contains($r['name'], 'Vũ Hồng Nhung'));
        $this->assertCount(1, $nhungRows, 'Same person must appear as one row');
        $this->assertSame("employee:{$emp->id}", $nhungRows->first()['id']);

        // Dropdown
        $employees = $res->viewData('page')['props']['employees'];
        $nhungOpts = collect($employees)->filter(fn($o) => str_contains($o['name'], 'Vũ Hồng Nhung'));
        $this->assertCount(1, $nhungOpts, 'No duplicate in dropdown');
    }

    // ── Test 6: two employees same name disambiguated ──
    public function test_two_employees_same_name_disambiguated(): void
    {
        $admin = $this->adminUser();
        $emp1 = $this->employee('Vũ Hồng Nhung', 'NV001');
        $emp2 = $this->employee('Vũ Hồng Nhung', 'NV002');
        $product = $this->product();

        $this->invoice($emp1->id, $admin->name, $emp1->name, $product, 1, 1_000_000);
        $this->invoice($emp2->id, $admin->name, $emp2->name, $product, 1, 2_000_000);

        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $nhungRows = collect($rows)->filter(fn($r) => str_contains($r['name'], 'Vũ Hồng Nhung'));
        $this->assertCount(2, $nhungRows);

        $names = $nhungRows->pluck('name')->all();
        $this->assertNotEquals($names[0], $names[1], 'Display names must be disambiguated');
    }

    // ── Test 7: report and invoice filter match invoice codes ──
    public function test_report_and_invoice_filter_match(): void
    {
        $admin = $this->adminUser();
        $emp = $this->employee('Seller TC7', 'NV-TC7');
        $product = $this->product();

        $inv1 = $this->invoice($emp->id, $admin->name, $emp->name, $product, 1, 1_000_000);
        $inv2 = $this->invoice($emp->id, $admin->name, $emp->name, $product, 1, 2_000_000);
        $inv3 = $this->invoice($emp->id, $admin->name, $emp->name, $product, 1, 3_000_000);

        // Report
        $resReport = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $resReport->assertOk();
        $rows = $resReport->viewData('page')['props']['reportRows'];
        $row = collect($rows)->firstWhere('id', "employee:{$emp->id}");
        $this->assertNotNull($row);
        $this->assertEquals(6_000_000, (int) $row['revenue']);

        // Invoice filter
        $resInv = $this->actingAs($admin)->get("/invoices?seller_key=employee:{$emp->id}");
        $resInv->assertOk();
        $invoices = $resInv->viewData('page')['props']['invoices']['data'];
        $ids = collect($invoices)->pluck('id')->all();
        $this->assertCount(3, $ids);
        $this->assertContains($inv1->id, $ids);
        $this->assertContains($inv2->id, $ids);
        $this->assertContains($inv3->id, $ids);
    }

    // ── Test 8: creator filter separate from seller ──
    public function test_creator_filter_separate_from_seller(): void
    {
        $creatorA = $this->adminUser('Creator A');
        $creatorB = User::create([
            'name' => 'Creator B', 'email' => 'cb-' . uniqid() . '@test.local',
            'password' => bcrypt('p'), 'role_id' => 1, 'status' => 'active',
        ]);
        $emp = $this->employee('Seller X', 'NV-SX');
        $product = $this->product();

        $invA = $this->invoice($emp->id, 'Creator A', $emp->name, $product, 1, 1_000_000);
        $invB = $this->invoice($emp->id, 'Creator B', $emp->name, $product, 1, 2_000_000);

        // Filter by seller → both invoices
        $resSeller = $this->actingAs($creatorA)->get("/invoices?seller_key=employee:{$emp->id}");
        $resSeller->assertOk();
        $sellerInvs = collect($resSeller->viewData('page')['props']['invoices']['data']);
        $this->assertCount(2, $sellerInvs);

        // Filter by creator A → only A's invoice
        $resCreatorA = $this->actingAs($creatorA)->get('/invoices?creator_key=creator_snapshot:Creator A');
        $resCreatorA->assertOk();
        $creatorAInvs = collect($resCreatorA->viewData('page')['props']['invoices']['data']);
        $this->assertCount(1, $creatorAInvs);
        $this->assertEquals($invA->id, $creatorAInvs->first()['id']);

        // Report: seller X should have both invoices' revenue
        $resReport = $this->actingAs($creatorA)->get('/reports/employees?concern=sales&view=report');
        $resReport->assertOk();
        $rows = $resReport->viewData('page')['props']['reportRows'];
        $row = collect($rows)->firstWhere('id', "employee:{$emp->id}");
        $this->assertNotNull($row);
        $this->assertEquals(3_000_000, (int) $row['revenue']);
    }

    // ── Test 9: cancelled invoice not counted ──
    public function test_cancelled_invoice_not_counted(): void
    {
        $admin = $this->adminUser();
        $emp = $this->employee('Seller TC9', 'NV-TC9');
        $product = $this->product();

        $this->invoice($emp->id, $admin->name, $emp->name, $product, 1, 5_000_000, status: 'Đã hủy');
        $this->invoice($emp->id, $admin->name, $emp->name, $product, 1, 2_000_000);

        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];
        $row = collect($rows)->firstWhere('id', "employee:{$emp->id}");
        $this->assertNotNull($row);
        $this->assertEquals(2_000_000, (int) $row['revenue']);
    }

    // ── Test 10: profit report has 8 columns ──
    public function test_profit_report_has_8_columns(): void
    {
        $admin = $this->adminUser();
        $emp = $this->employee('Seller TC10', 'NV-TC10');
        $product = $this->product(cost: 600_000);

        $this->invoice($emp->id, $admin->name, $emp->name, $product, 2, 1_000_000,
            discount: 100_000, costPrice: 600_000);

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

    // ── Test 11: seller_name matching one employee merges ──
    public function test_seller_name_matching_one_employee_merges(): void
    {
        $admin = $this->adminUser();
        $emp = $this->employee('Unique Seller', 'NV-UNIQ');
        $product = $this->product();

        // Invoice with no created_by but seller_name matches employee
        $inv = $this->invoice(null, $admin->name, 'Unique Seller', $product, 1, 1_000_000);

        $resolver = new SellerResolver();
        $map = $resolver->invoiceSellerMap(Invoice::whereKey($inv->id));

        $this->assertSame("employee:{$emp->id}", $map[$inv->id],
            'Seller name matching one active employee must merge to employee key');
    }

    // ── Test 12: invoice filter options contain sellers and creators separately ──
    public function test_filter_options_separate_sellers_and_creators(): void
    {
        $admin = $this->adminUser('Admin Filter Test');
        $emp = $this->employee('Seller Filter', 'NV-FILT');
        $product = $this->product();
        $this->invoice($emp->id, $admin->name, $emp->name, $product);

        $res = $this->actingAs($admin)->get('/invoices');
        $res->assertOk();
        $opts = $res->viewData('page')['props']['filterOptions'];

        // Sellers from SellerResolver
        $this->assertArrayHasKey('sellers', $opts);
        $empOpt = collect($opts['sellers'])->first(fn($s) => $s['name'] === 'Seller Filter');
        $this->assertNotNull($empOpt, 'Employee must appear in seller options');
        // HOTFIX 24.32 evolved contract: super-admin without linked
        // employee surfaces as virtual seller (admin_user). The original
        // 24.28 separation that mattered was "creator_snapshot must not
        // be a seller" — that still holds because admin_user and
        // creator_snapshot are distinct types.
        $adminSeller = collect($opts['sellers'])->first(fn($s) => $s['name'] === 'Admin Filter Test');
        $this->assertNotNull($adminSeller, 'Super admin surfaces as virtual seller (24.32)');
        $this->assertSame('admin_user', $adminSeller['type']);
        $this->assertNotSame('creator_snapshot', $adminSeller['type'],
            'Admin seller must be admin_user, NEVER creator_snapshot');

        // Creators from snapshots
        $this->assertArrayHasKey('creators', $opts);
        $adminCreator = collect($opts['creators'])->first(fn($c) => $c['name'] === 'Admin Filter Test');
        $this->assertNotNull($adminCreator, 'Creator must appear in creator options');
    }
}
