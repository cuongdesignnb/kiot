<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX 24.22 — EmployeeReport must include sellers properly.
 * UPDATED for HOTFIX 24.28B contract:
 *   - created_by_name is creator (NOT seller)
 *   - Invoices without seller go to 'unknown' bucket
 *   - Admin creator does NOT auto-become seller
 */
class HOTFIX2422EmployeeReportIncludesAdminTest extends TestCase
{
    use DatabaseTransactions;

    private function adminUser(string $name = 'Admin 2422'): User
    {
        return User::create([
            'name'     => $name,
            'email'    => 'admin-2422-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
    }

    private function employeeUser(string $name, User $user): Employee
    {
        return Employee::create([
            'code'      => 'NV-2422-' . uniqid(),
            'name'      => $name,
            'user_id'   => $user->id,
            'is_active' => true,
        ]);
    }

    private function plainEmployee(string $name = 'Nhân viên A'): Employee
    {
        return Employee::create([
            'code'      => 'NV-A-2422-' . uniqid(),
            'name'      => $name,
            'is_active' => true,
        ]);
    }

    private function product(int $cost = 1_000_000, int $retail = 1_500_000): Product
    {
        return Product::create([
            'sku'                  => 'SKU-2422-' . uniqid(),
            'name'                 => 'Sản phẩm 2422',
            'cost_price'           => $cost,
            'retail_price'         => $retail,
            'stock_quantity'       => 100,
            'inventory_total_cost' => $cost * 100,
            'has_serial'           => false,
        ]);
    }

    /**
     * HOTFIX 24.28B: added sellerName parameter for proper contract.
     */
    private function invoice(
        ?int $createdBy,
        string $createdByName,
        Product $product,
        int $qty = 1,
        int $price = 1_500_000,
        ?string $sellerName = null
    ): Invoice {
        $inv = Invoice::create([
            'code'             => 'HD-2422-' . uniqid(),
            'created_by'       => $createdBy,
            'created_by_name'  => $createdByName,
            'seller_name'      => $sellerName,
            'subtotal'         => $qty * $price,
            'discount'         => 0,
            'total'            => $qty * $price,
            'customer_paid'    => $qty * $price,
            'status'           => 'Hoàn thành',
            'sales_channel'    => 'Bán trực tiếp',
        ]);
        InvoiceItem::create([
            'invoice_id' => $inv->id,
            'product_id' => $product->id,
            'quantity'   => $qty,
            'price'      => $price,
            'cost_price' => 1_000_000,
        ]);
        $inv->created_at = Carbon::now()->startOfDay()->addMinute();
        $inv->save();
        return $inv;
    }

    // ── TC-01 — invoice without seller goes to unknown, not admin ──
    public function test_invoice_without_seller_goes_to_unknown(): void
    {
        $admin   = $this->adminUser();
        $product = $this->product();
        $this->invoice(null, $admin->name, $product, 2, 1_500_000);

        $res = $this->actingAs($admin)->get('/reports/employees?concern=profit');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        // No admin row (admin is creator, not seller)
        $adminRow = collect($rows)->firstWhere('id', "user:{$admin->id}");
        $this->assertNull($adminRow, 'Admin creator must NOT appear as seller');

        // Unknown bucket must exist
        $unknownRow = collect($rows)->firstWhere('id', 'unknown');
        $this->assertNotNull($unknownRow, 'Unknown seller bucket must exist');
        $this->assertEquals(3_000_000, (int) $unknownRow['revenue']);
    }

    // ── TC-02 — employee seller appears correctly ──
    public function test_employee_seller_appears_correctly(): void
    {
        $admin   = $this->adminUser();
        $emp     = $this->plainEmployee('Người bán TC02');
        $product = $this->product();
        $this->invoice($emp->id, $admin->name, $product, 1, 5_000_000,
            sellerName: $emp->name);

        $res  = $this->actingAs($admin)->get('/reports/employees?concern=sales');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $empRow = collect($rows)->firstWhere('id', "employee:{$emp->id}");
        $this->assertNotNull($empRow);
        $this->assertEquals(5_000_000, (int) $empRow['revenue']);
    }

    // ── TC-03 — items report counts seller's quantity ──
    public function test_items_report_counts_seller_quantity(): void
    {
        $admin   = $this->adminUser();
        $emp     = $this->plainEmployee('Items TC03');
        $product = $this->product();
        $this->invoice($emp->id, $admin->name, $product, 4, 1_000_000,
            sellerName: $emp->name);

        $res  = $this->actingAs($admin)->get('/reports/employees?concern=items');
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $empRow = collect($rows)->firstWhere('id', "employee:{$emp->id}");
        $this->assertNotNull($empRow);
        $this->assertSame(4, (int) $empRow['returns'], 'items report uses `returns` for quantity');
        $this->assertEquals(4_000_000, (int) $empRow['revenue']);
    }

    // ── TC-04 — seller filter exposes employees, unknown; no admin-as-seller ──
    public function test_seller_filter_does_not_include_admin_creator(): void
    {
        $admin    = $this->adminUser();
        $employee = $this->plainEmployee('Người bán B');
        $product  = $this->product();
        $this->invoice(null, $admin->name, $product);
        $this->invoice($employee->id, $employee->name, $product,
            sellerName: $employee->name);

        $res = $this->actingAs($admin)->get('/reports/employees');
        $res->assertOk();
        $options = $res->viewData('page')['props']['employees'];

        // Admin must NOT be in seller filter (only creator)
        $adminOpt = collect($options)->firstWhere('id', "user:{$admin->id}");
        $this->assertNull($adminOpt, 'Admin creator must NOT appear in seller filter');

        // Employee must be in seller filter
        $employeeOption = collect($options)->firstWhere('id', "employee:{$employee->id}");
        $this->assertNotNull($employeeOption, 'plain employees stay in the list');
        $this->assertSame('employee', $employeeOption['type']);

        // Unknown bucket must be in seller filter (for the no-seller invoice)
        $unknownOpt = collect($options)->first(fn($o) => $o['key'] === 'unknown');
        $this->assertNotNull($unknownOpt, 'Unknown seller must appear in filter');
    }

    // ── TC-05 — filtering by employee returns only that employee's invoices ──
    public function test_filtering_by_employee_returns_only_employee_invoices(): void
    {
        $admin    = $this->adminUser();
        $employee = $this->plainEmployee('Người bán C');
        $product  = $this->product();
        $this->invoice(null, $admin->name, $product, 1, 1_000_000);
        $this->invoice($employee->id, $employee->name, $product, 1, 9_000_000,
            sellerName: $employee->name);

        $res  = $this->actingAs($admin)->get("/reports/employees?concern=profit&employee_id=employee:{$employee->id}");
        $res->assertOk();
        $rows = $res->viewData('page')['props']['reportRows'];

        $this->assertCount(1, $rows, 'filter must constrain rows to the employee only');
        $this->assertSame("employee:{$employee->id}", $rows[0]['id']);
    }

    // ── TC-06 — employee-with-user is not duplicated in the filter list ──
    public function test_employee_with_user_does_not_duplicate_in_filter(): void
    {
        $admin    = $this->adminUser();
        $linked   = User::create([
            'name'     => 'Linked Seller',
            'email'    => 'linked-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
            'status'   => 'active',
        ]);
        $employee = $this->employeeUser('Linked Seller', $linked);
        $product  = $this->product();
        $this->invoice($employee->id, $employee->name, $product,
            sellerName: $employee->name);

        $res     = $this->actingAs($admin)->get('/reports/employees');
        $res->assertOk();
        $options = $res->viewData('page')['props']['employees'];

        $matches = collect($options)->where('name', 'Linked Seller');
        $this->assertCount(1, $matches, 'linked employee+user must show up exactly once');
    }
}
