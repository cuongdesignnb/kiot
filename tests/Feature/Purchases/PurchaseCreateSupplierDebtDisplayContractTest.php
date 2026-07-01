<?php

namespace Tests\Feature\Purchases;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseCreateSupplierDebtDisplayContractTest extends TestCase
{
    use DatabaseTransactions;

    public function test_purchase_create_supplier_payload_keeps_raw_debt_and_adds_supplier_screen_balance(): void
    {
        $admin = User::create([
            'name' => 'Admin Purchase Display Contract',
            'email' => 'admin-purchase-display-contract-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $partner = Customer::create([
            'code' => 'NCC-PICKER-CONTRACT-' . uniqid(),
            'name' => 'Purchase Picker Display Contract',
            'phone' => '09' . random_int(10000000, 99999999),
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
        $this->assertSame(205_000.0, (float) $row['supplier_debt_amount']);
        $this->assertSame(205_000.0, (float) $row['customer_receivable_balance']);
        $this->assertSame(205_000.0, (float) $row['supplier_payable_balance']);
        $this->assertSame(0.0, (float) $row['supplier_screen_debt']);
        $this->assertSame(0.0, (float) $row['supplier_oriented_balance']);

        $searchResponse = $this->actingAs($admin)
            ->getJson('/api/suppliers/search?search=' . urlencode($partner->code));
        $searchResponse->assertOk();

        $searchRow = collect($searchResponse->json())->firstWhere('id', $partner->id);
        $this->assertNotNull($searchRow);
        $this->assertSame(205_000.0, (float) $searchRow['supplier_debt_amount']);
        $this->assertSame(0.0, (float) $searchRow['supplier_oriented_balance']);
    }

    public function test_purchase_create_supplier_picker_keeps_raw_payable_for_supplier_only(): void
    {
        $admin = User::create([
            'name' => 'Admin Purchase Supplier Only Contract',
            'email' => 'admin-purchase-supplier-only-contract-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $supplier = Customer::create([
            'code' => 'NCC-PICKER-ONLY-' . uniqid(),
            'name' => 'Purchase Picker Supplier Only',
            'phone' => '09' . random_int(10000000, 99999999),
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
        $this->assertSame(600_000.0, (float) $row['supplier_debt_amount']);
        $this->assertSame(600_000.0, (float) $row['supplier_screen_debt']);
        $this->assertSame(600_000.0, (float) $row['supplier_oriented_balance']);
    }
}
