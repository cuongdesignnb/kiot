<?php

namespace Tests\Feature\Invoices;

use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\Reports\SellerResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX 24.30 — Seller consistency & invoice seller update.
 *
 * Tests cover:
 * 1. Employee linked user → display current user name (Hướng A)
 * 2. Employee without user link → display employee name
 * 3. Snapshot seller not merged to user
 * 4. Unknown seller bucket
 * 5. Invoice page returns full active employee options
 * 6. PATCH seller to different employee
 * 7. PATCH rejected for non-employee keys
 * 8. Cancelled invoice cannot have seller changed
 * 9. Permission enforcement
 * 10. Report reflects seller change
 * 11. Invoice filter reflects seller change
 * 12. Creator snapshot not changed on seller update
 * 13. Duplicate names disambiguated by code
 * 14. Report totals unchanged when only display changes
 */
class HOTFIX2430SellerConsistencyAndInvoiceSellerUpdateTest extends TestCase
{
    use DatabaseTransactions;

    private function adminUser(string $name = 'Admin 2430'): User
    {
        return User::create([
            'name'     => $name,
            'email'    => 'admin-2430-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function staffUser(string $name = 'Staff 2430'): User
    {
        return User::create([
            'name'     => $name,
            'email'    => 'staff-2430-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => $this->getOrCreateRole()->id,
            'status'   => 'active',
        ]);
    }

    private function getOrCreateRole(): Role
    {
        $role = Role::firstOrCreate(
            ['name' => 'staff-2430-test'],
            ['display_name' => 'Staff 2430 Test', 'permissions' => ['invoices.view']]
        );
        // Force reset perms in case a previous run polluted them
        $role->permissions = ['invoices.view'];
        $role->save();
        return $role;
    }

    private function employee(string $name, ?string $code = null, ?int $userId = null, bool $active = true): Employee
    {
        return Employee::create([
            'code'      => $code ?? ('NV-2430-' . uniqid()),
            'name'      => $name,
            'is_active' => $active,
            'user_id'   => $userId,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'sku'                  => 'SKU-2430-' . uniqid(),
            'name'                 => 'SP 2430',
            'cost_price'           => 600_000,
            'retail_price'         => 1_000_000,
            'stock_quantity'       => 100,
            'inventory_total_cost' => 60_000_000,
            'has_serial'           => false,
        ]);
    }

    private function invoice(
        ?int $createdBy,
        string $createdByName,
        Product $product,
        int $total = 1_000_000,
        string $status = 'Hoàn thành',
        ?string $sellerName = null
    ): Invoice {
        $inv = Invoice::create([
            'code'             => 'HD-2430-' . uniqid(),
            'created_by'       => $createdBy,
            'created_by_name'  => $createdByName,
            'seller_name'      => $sellerName,
            'subtotal'         => $total,
            'discount'         => 0,
            'total'            => $total,
            'customer_paid'    => $total,
            'status'           => $status,
            'sales_channel'    => 'Bán trực tiếp',
        ]);
        InvoiceItem::create([
            'invoice_id' => $inv->id,
            'product_id' => $product->id,
            'quantity'   => 1,
            'price'      => $total,
            'cost_price' => 600_000,
        ]);
        $inv->created_at = Carbon::now()->startOfDay()->addMinute();
        $inv->save();
        return $inv;
    }

    // ── Test 1 — Employee linked user uses current user name for display ──
    public function test_employee_linked_user_uses_current_user_name(): void
    {
        $user = $this->adminUser('Admin');
        $emp  = $this->employee('Admin', 'NV001', $user->id);
        $product = $this->product();
        $inv = $this->invoice($emp->id, 'Admin', $product, sellerName: 'Admin');

        // Rename user
        $user->name = 'Trần Văn Tiến';
        $user->save();

        $resolver = new SellerResolver();
        $meta = $resolver->sellerMeta(["employee:{$emp->id}"]);

        $this->assertSame('Trần Văn Tiến', $meta["employee:{$emp->id}"]['name'],
            'Employee linked to user must display current user name');
        $this->assertSame('employee', $meta["employee:{$emp->id}"]['type']);
        $this->assertSame($emp->code, $meta["employee:{$emp->id}"]['code']);
    }

    // ── Test 2 — Employee without linked user keeps employee name ──
    public function test_employee_without_user_keeps_employee_name(): void
    {
        $emp = $this->employee('Admin', 'NV-SOLO');
        $product = $this->product();
        $this->invoice($emp->id, 'Someone', $product, sellerName: $emp->name);

        $resolver = new SellerResolver();
        $meta = $resolver->sellerMeta(["employee:{$emp->id}"]);

        $this->assertSame('Admin', $meta["employee:{$emp->id}"]['name'],
            'Employee without user link must keep employee name');
    }

    // ── Test 3 — Snapshot seller not merged to user ──
    public function test_snapshot_seller_not_merged_to_user(): void
    {
        $this->adminUser('Trần Văn Tiến');
        $product = $this->product();
        $inv = $this->invoice(null, 'Someone', $product, sellerName: 'Admin');

        $resolver = new SellerResolver();
        $map = $resolver->invoiceSellerMap(Invoice::whereKey($inv->id));

        // Should NOT merge to user Trần Văn Tiến
        $this->assertStringStartsWith('snapshot:', $map[$inv->id],
            'Snapshot seller must not merge to user');
        $this->assertStringContainsString('Admin', $map[$inv->id]);
    }

    // ── Test 4 — Unknown seller bucket ──
    public function test_unknown_seller_bucket(): void
    {
        $product = $this->product();
        $inv = $this->invoice(null, 'Creator', $product);

        $resolver = new SellerResolver();
        $map = $resolver->invoiceSellerMap(Invoice::whereKey($inv->id));

        $this->assertSame('unknown', $map[$inv->id]);
    }

    // ── Test 5 — Invoice page returns full active employee seller options ──
    public function test_invoice_page_returns_full_active_employee_options(): void
    {
        $admin = $this->adminUser();
        $emp1 = $this->employee('Seller A', 'NV-A');
        $emp2 = $this->employee('Seller B', 'NV-B');
        $emp3 = $this->employee('Seller C', 'NV-C');
        $inactive = $this->employee('Seller D', 'NV-D', active: false);

        $res = $this->actingAs($admin)->get('/invoices');
        $res->assertOk();

        $options = $res->viewData('page')['props']['filterOptions']['invoiceSellerOptions'];
        $keys = collect($options)->pluck('key')->all();

        $this->assertContains("employee:{$emp1->id}", $keys);
        $this->assertContains("employee:{$emp2->id}", $keys);
        $this->assertContains("employee:{$emp3->id}", $keys);
        $this->assertNotContains("employee:{$inactive->id}", $keys,
            'Inactive employee must not appear in invoice seller options');
    }

    // ── Test 6 — Update seller to different employee ──
    public function test_update_seller_to_different_employee(): void
    {
        $admin = $this->adminUser();
        $empA = $this->employee('Seller A', 'NV-A');
        $empB = $this->employee('Seller B', 'NV-B');
        $product = $this->product();
        $inv = $this->invoice($empA->id, $admin->name, $product, sellerName: $empA->name);

        $res = $this->actingAs($admin)->patchJson("/invoices/{$inv->id}/seller", [
            'seller_key' => "employee:{$empB->id}",
        ]);
        $res->assertOk();

        $inv->refresh();
        $this->assertEquals($empB->id, $inv->created_by);
        $this->assertSame($empB->name, $inv->seller_name);
        // created_by_name must NOT change
        $this->assertSame($admin->name, $inv->created_by_name);
    }

    // ── Test 7 — Reject non-employee seller key ──
    public function test_reject_creator_snapshot_seller_key(): void
    {
        $admin = $this->adminUser();
        $product = $this->product();
        $emp = $this->employee('Seller', 'NV-1');
        $inv = $this->invoice($emp->id, $admin->name, $product, sellerName: $emp->name);

        $res = $this->actingAs($admin)->patchJson("/invoices/{$inv->id}/seller", [
            'seller_key' => 'creator_snapshot:Admin',
        ]);
        $res->assertStatus(422);

        $inv->refresh();
        $this->assertEquals($emp->id, $inv->created_by, 'Invoice must not change');
    }

    // ── Test 8 — Cannot change seller on cancelled invoice ──
    public function test_cannot_change_seller_on_cancelled_invoice(): void
    {
        $admin = $this->adminUser();
        $empA = $this->employee('Seller A', 'NV-A');
        $empB = $this->employee('Seller B', 'NV-B');
        $product = $this->product();
        $inv = $this->invoice($empA->id, $admin->name, $product, status: 'Đã hủy', sellerName: $empA->name);

        $res = $this->actingAs($admin)->patchJson("/invoices/{$inv->id}/seller", [
            'seller_key' => "employee:{$empB->id}",
        ]);
        $res->assertStatus(422);

        $inv->refresh();
        $this->assertEquals($empA->id, $inv->created_by, 'Cancelled invoice seller must not change');
    }

    // ── Test 9 — Staff without permission cannot change seller ──
    public function test_staff_without_permission_cannot_change_seller(): void
    {
        $staff = $this->staffUser('Bình thường');
        $emp = $this->employee('Seller', 'NV-1');
        $empB = $this->employee('Seller B', 'NV-B');
        $product = $this->product();
        $inv = $this->invoice($emp->id, $staff->name, $product, sellerName: $emp->name);

        $res = $this->actingAs($staff)->patchJson("/invoices/{$inv->id}/seller", [
            'seller_key' => "employee:{$empB->id}",
        ]);
        $this->assertTrue(in_array($res->status(), [403, 302]),
            'Staff without invoices.cancel permission must be blocked');
    }

    // ── Test 10 — Report reflects seller change ──
    public function test_report_reflects_seller_change(): void
    {
        $admin = $this->adminUser();
        $empA = $this->employee('Report A', 'NV-RA');
        $empB = $this->employee('Report B', 'NV-RB');
        $product = $this->product();
        $inv = $this->invoice($empA->id, $admin->name, $product, total: 3_000_000, sellerName: $empA->name);

        // Change seller
        $this->actingAs($admin)->patchJson("/invoices/{$inv->id}/seller", [
            'seller_key' => "employee:{$empB->id}",
        ])->assertOk();

        // Report should show empB, not empA
        $res = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $rowA = collect($rows)->firstWhere('id', "employee:{$empA->id}");
        $rowB = collect($rows)->firstWhere('id', "employee:{$empB->id}");

        $this->assertNull($rowA, 'Old seller must not have revenue after change');
        $this->assertNotNull($rowB, 'New seller must appear in report');
        $this->assertEquals(3_000_000, (int) $rowB['revenue']);
    }

    // ── Test 11 — Invoice filter reflects seller change ──
    public function test_invoice_filter_reflects_seller_change(): void
    {
        $admin = $this->adminUser();
        $empA = $this->employee('Filter A', 'NV-FA');
        $empB = $this->employee('Filter B', 'NV-FB');
        $product = $this->product();
        $inv = $this->invoice($empA->id, $admin->name, $product, sellerName: $empA->name);

        $this->actingAs($admin)->patchJson("/invoices/{$inv->id}/seller", [
            'seller_key' => "employee:{$empB->id}",
        ])->assertOk();

        // Filter by new seller
        $resB = $this->actingAs($admin)->get("/invoices?seller_key=employee:{$empB->id}");
        $resB->assertOk();
        $invoicesB = $resB->viewData('page')['props']['invoices']['data'];
        $this->assertNotEmpty($invoicesB);
        $this->assertContains($inv->id, collect($invoicesB)->pluck('id')->all());

        // Filter by old seller
        $resA = $this->actingAs($admin)->get("/invoices?seller_key=employee:{$empA->id}");
        $resA->assertOk();
        $invoicesA = $resA->viewData('page')['props']['invoices']['data'];
        $this->assertNotContains($inv->id, collect($invoicesA)->pluck('id')->all());
    }

    // ── Test 12 — Creator snapshot unchanged after seller update ──
    public function test_creator_snapshot_unchanged_after_seller_update(): void
    {
        $admin = $this->adminUser('Original Creator');
        $emp = $this->employee('Seller', 'NV-1');
        $empB = $this->employee('Seller B', 'NV-B');
        $product = $this->product();
        $inv = $this->invoice($emp->id, 'Original Creator', $product, sellerName: $emp->name);

        $this->actingAs($admin)->patchJson("/invoices/{$inv->id}/seller", [
            'seller_key' => "employee:{$empB->id}",
        ])->assertOk();

        $inv->refresh();
        $this->assertSame('Original Creator', $inv->created_by_name,
            'created_by_name must never change when updating seller');
    }

    // ── Test 13 — Duplicate names label has code ──
    public function test_duplicate_names_disambiguated_by_code(): void
    {
        $this->employee('Nguyễn Văn A', 'NV-001');
        $this->employee('Nguyễn Văn A', 'NV-002');

        $resolver = new SellerResolver();
        $options = $resolver->buildInvoiceSellerOptions();

        $aOptions = collect($options)->filter(fn($o) => str_contains($o['name'], 'Nguyễn Văn A'));
        $this->assertGreaterThanOrEqual(2, $aOptions->count());

        $displayNames = $aOptions->pluck('display_name')->all();
        $this->assertNotEquals($displayNames[0], $displayNames[1],
            'Duplicate names must be disambiguated');
        $this->assertTrue(
            str_contains($displayNames[0], 'NV-001') || str_contains($displayNames[0], 'NV-002'),
            'Display name must contain code for disambiguation'
        );
    }

    // ── Test 14 — Report totals unchanged when only display changes ──
    public function test_report_totals_unchanged_with_display_change(): void
    {
        $user = $this->adminUser('Old Name');
        $emp = $this->employee('Old Name', 'NV-TOTAL', $user->id);
        $product = $this->product();
        $this->invoice($emp->id, 'Someone', $product, total: 5_000_000, sellerName: $emp->name);

        // Get report before rename
        $admin = $this->adminUser('Reporter');
        $res1 = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $res1->assertOk();
        $row1 = collect($res1->viewData('page')['props']['reportRows'])->firstWhere('id', "employee:{$emp->id}");
        $this->assertNotNull($row1);
        $rev1 = (int) $row1['revenue'];

        // Rename user (Hướng A: display changes, data doesn't)
        $user->name = 'New Name';
        $user->save();

        $res2 = $this->actingAs($admin)->get('/reports/employees?concern=sales&view=report');
        $res2->assertOk();
        $row2 = collect($res2->viewData('page')['props']['reportRows'])->firstWhere('id', "employee:{$emp->id}");
        $this->assertNotNull($row2);
        $rev2 = (int) $row2['revenue'];

        $this->assertEquals($rev1, $rev2, 'Revenue must not change when only display name changes');
        $this->assertSame('New Name', $row2['name'], 'Display name must reflect renamed user');
    }
}
