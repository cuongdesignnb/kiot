<?php

namespace Tests\Feature\Supplier;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HOTFIX 24.14 — Supplier expanded-row tab export.
 *
 * The Vue error "Cannot read properties of undefined (reading 'open')"
 * was a frontend-only bug: inline `@click="window.open(...)"` resolved
 * `window` to undefined inside the Vue 3 template scope. This suite
 * pins the backend export contract that the new (now script-defined)
 * handler hits, so the FE fix doesn't accidentally point at the wrong
 * endpoint or break the JSON loaders that feed the same tabs.
 */
class HOTFIX2414SupplierTabExportTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::create([
            'name'     => 'Admin 2414',
            'email'    => 'admin-2414-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
    }

    private function supplier(string $name = 'NCC 2414'): Customer
    {
        return Customer::create([
            'code'                 => 'NCC-2414-' . uniqid(),
            'name'                 => $name,
            'phone'                => '09' . random_int(10000000, 99999999),
            'debt_amount'          => 0,
            'supplier_debt_amount' => 0,
            'is_customer'          => false,
            'is_supplier'          => true,
        ]);
    }

    private function purchase(Customer $supplier, int $total, string $codePrefix = 'PN'): Purchase
    {
        return Purchase::create([
            'code'          => $codePrefix . '-' . uniqid(),
            'supplier_id'   => $supplier->id,
            'user_id'       => null,
            'total_amount'  => $total,
            'paid_amount'   => 0,
            'debt_amount'   => $total,
            'status'        => 'completed',
            'purchase_date' => Carbon::now(),
        ]);
    }

    public function test_supplier_purchase_history_export_downloads_csv(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $p     = $this->purchase($sup, 1000000);

        $res = $this->actingAs($admin)->get("/api/suppliers/{$sup->id}/export-purchases");

        $res->assertOk();
        $contentType = $res->headers->get('Content-Type');
        $this->assertNotNull($contentType);
        $this->assertStringContainsString('text/csv', strtolower($contentType));

        $body = $res->streamedContent() ?: $res->getContent();
        $this->assertStringContainsString('Mã phiếu nhập', $body);
        $this->assertStringContainsString('Tổng tiền', $body);
        $this->assertStringContainsString($p->code, $body);
    }

    public function test_supplier_debt_export_downloads_csv(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $p     = $this->purchase($sup, 500000);

        $res = $this->actingAs($admin)->get("/api/suppliers/{$sup->id}/export-debt");

        $res->assertOk();
        $contentType = $res->headers->get('Content-Type');
        $this->assertNotNull($contentType);
        $this->assertStringContainsString('text/csv', strtolower($contentType));

        $body = $res->streamedContent() ?: $res->getContent();
        $this->assertStringContainsString('Mã chứng từ', $body);
        $this->assertStringContainsString('Còn nợ', $body);
        // Purchase entry should appear in the debt ledger CSV.
        $this->assertStringContainsString($p->code, $body);
    }

    public function test_supplier_tab_export_does_not_include_other_supplier_data(): void
    {
        $admin = $this->admin();
        $a     = $this->supplier('NCC A 2414');
        $b     = $this->supplier('NCC B 2414');

        $pa = $this->purchase($a, 1000000, 'PN-A');
        $pb = $this->purchase($b, 2000000, 'PN-B');

        $res = $this->actingAs($admin)->get("/api/suppliers/{$a->id}/export-purchases");
        $res->assertOk();
        $body = $res->streamedContent() ?: $res->getContent();

        $this->assertStringContainsString($pa->code, $body);
        $this->assertStringNotContainsString(
            $pb->code,
            $body,
            'export for supplier A must not leak supplier B purchases'
        );
    }

    public function test_supplier_purchase_history_json_still_works(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $p     = $this->purchase($sup, 800000);

        $res = $this->actingAs($admin)
            ->getJson("/api/suppliers/{$sup->id}/purchase-history");

        $res->assertOk();
        $data = $res->json();
        $this->assertIsArray($data);
        $codes = collect($data)->pluck('code')->all();
        $this->assertContains($p->code, $codes);
    }

    public function test_supplier_debt_transactions_json_still_works(): void
    {
        $admin = $this->admin();
        $sup   = $this->supplier();
        $this->purchase($sup, 1500000);

        $res = $this->actingAs($admin)
            ->getJson("/api/suppliers/{$sup->id}/debt-transactions");

        $res->assertOk();
        $data = $res->json();
        $this->assertArrayHasKey('entries', $data);
        $this->assertIsArray($data['entries']);
    }

    public function test_supplier_tab_export_missing_supplier_does_not_500(): void
    {
        $admin = $this->admin();

        // The export wrappers call the JSON method internally; non-existent
        // supplier should not blow up — the underlying queries return empty
        // collections and we still get a valid (header-only) CSV back.
        $resP = $this->actingAs($admin)->get('/api/suppliers/999999/export-purchases');
        $this->assertLessThan(500, $resP->getStatusCode(), 'export-purchases must not 500 on missing supplier');

        $resD = $this->actingAs($admin)->get('/api/suppliers/999999/export-debt');
        $this->assertLessThan(500, $resD->getStatusCode(), 'export-debt must not 500 on missing supplier');
    }
}
