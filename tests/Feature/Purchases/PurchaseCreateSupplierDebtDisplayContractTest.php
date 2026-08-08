<?php

namespace Tests\Feature\Purchases;

use App\Models\Customer;
use App\Models\User;
use App\Support\Debt\PartnerDebtDisplayBalance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseCreateSupplierDebtDisplayContractTest extends TestCase
{
    use DatabaseTransactions;

    public function test_purchase_create_supplier_payload_is_lightweight_and_debt_endpoint_keeps_canonical_aliases(): void
    {
        $admin = User::create([
            'name' => 'Admin Purchase Display Contract',
            'email' => 'admin-purchase-display-contract-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $partner = Customer::create([
            'code' => 'NCC-PICKER-CONTRACT-'.uniqid(),
            'name' => 'Purchase Picker Display Contract',
            'phone' => '09'.random_int(10000000, 99999999),
            'debt_amount' => 205_000,
            'supplier_debt_amount' => 205_000,
            'is_customer' => true,
            'is_supplier' => true,
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin)->get('/purchases/create');
        $response->assertOk();

        $props = $response->original->getData()['page']['props'] ?? [];
        $row = collect($props['suppliers'] ?? [])->firstWhere('id', $partner->id);

        $this->assertNotNull($row);
        $this->assertSame(
            ['id', 'code', 'name', 'phone', 'is_customer', 'is_supplier'],
            array_keys($row)
        );

        $debtResponse = $this->actingAs($admin)
            ->getJson("/purchases/suppliers/{$partner->id}/debt-display");
        $debtResponse->assertOk();
        $debt = $debtResponse->json();
        $this->assertSame($partner->id, $debt['id']);

        foreach (PartnerDebtDisplayBalance::responseAliases($partner->fresh()) as $key => $value) {
            $this->assertEquals($value, $debt[$key], "canonical alias {$key} must remain unchanged");
        }

        $searchResponse = $this->actingAs($admin)
            ->getJson('/api/suppliers/search?search='.urlencode($partner->code));
        $searchResponse->assertOk();

        $searchRow = collect($searchResponse->json())->firstWhere('id', $partner->id);
        $this->assertNotNull($searchRow);
        $this->assertSame(
            ['id', 'code', 'name', 'phone', 'is_customer', 'is_supplier'],
            array_keys($searchRow)
        );
    }

    public function test_supplier_only_debt_remains_available_from_lazy_endpoint(): void
    {
        $admin = User::create([
            'name' => 'Admin Purchase Supplier Only Contract',
            'email' => 'admin-purchase-supplier-only-contract-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $supplier = Customer::create([
            'code' => 'NCC-PICKER-ONLY-'.uniqid(),
            'name' => 'Purchase Picker Supplier Only',
            'phone' => '09'.random_int(10000000, 99999999),
            'debt_amount' => 0,
            'supplier_debt_amount' => 600_000,
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin)->get('/purchases/create');
        $response->assertOk();

        $props = $response->original->getData()['page']['props'] ?? [];
        $row = collect($props['suppliers'] ?? [])->firstWhere('id', $supplier->id);

        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('supplier_debt_amount', $row);

        $debtResponse = $this->actingAs($admin)
            ->getJson("/purchases/suppliers/{$supplier->id}/debt-display");
        $debtResponse->assertOk();
        $debt = $debtResponse->json();

        $this->assertSame(600_000.0, (float) $debt['debt_stored_projection']['supplier_payable']);
        $this->assertTrue($debt['debt_has_mismatch']);
        $this->assertSame('supplier_payable', $debt['debt_display_contract']);
    }
}
