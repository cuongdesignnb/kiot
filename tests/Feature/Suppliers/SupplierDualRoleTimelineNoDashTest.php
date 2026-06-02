<?php

namespace Tests\Feature\Suppliers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SupplierDualRoleTimelineNoDashTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dual_role_financial_entries_have_display_running_balance_on_both_orientations(): void
    {
        $admin = User::create([
            'name' => 'Admin Dual Role Timeline No Dash',
            'email' => 'admin-dual-role-no-dash-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $partner = Customer::create([
            'code' => 'KH-NCC-NODASH-' . uniqid(),
            'name' => 'Anh Thanh Style No Dash',
            'debt_amount' => 47_400_000,
            'supplier_debt_amount' => 75_000_000,
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        CustomerDebt::create([
            'customer_id' => $partner->id,
            'ref_code' => 'MERGE-NODASH',
            'type' => 'adjustment',
            'amount' => 47_420_000,
            'debt_total' => 47_420_000,
            'note' => 'Gộp công nợ',
            'recorded_at' => Carbon::parse('2025-11-18 09:00:00'),
        ]);

        CustomerDebt::create([
            'customer_id' => $partner->id,
            'ref_code' => 'CKTT-NODASH',
            'type' => 'payment',
            'amount' => -20_000,
            'debt_total' => 47_400_000,
            'note' => 'Chiết khấu thanh toán',
            'recorded_at' => Carbon::parse('2025-11-19 09:00:00'),
        ]);

        Invoice::create([
            'code' => 'HD-NODASH',
            'customer_id' => $partner->id,
            'subtotal' => 7_200_000,
            'discount' => 0,
            'total' => 7_200_000,
            'customer_paid' => 7_200_000,
            'status' => 'completed',
            'transaction_date' => Carbon::parse('2025-11-20 09:00:00'),
            'created_at' => Carbon::parse('2025-11-20 09:00:00'),
        ]);

        OrderReturn::create([
            'code' => 'TH-NODASH',
            'customer_id' => $partner->id,
            'status' => 'completed',
            'subtotal' => 2_000_000,
            'discount' => 0,
            'fee' => 0,
            'total' => 2_000_000,
            'paid_to_customer' => 0,
            'created_at' => Carbon::parse('2025-11-21 09:00:00'),
        ]);

        Purchase::create([
            'code' => 'PN-NODASH',
            'supplier_id' => $partner->id,
            'total_amount' => 75_000_000,
            'paid_amount' => 0,
            'debt_amount' => 75_000_000,
            'status' => 'completed',
            'purchase_date' => Carbon::parse('2025-11-22 09:00:00'),
            'created_at' => Carbon::parse('2025-11-22 09:00:00'),
        ]);

        CashFlow::create([
            'code' => 'PCPN-NODASH',
            'type' => 'payment',
            'amount' => 10_000_000,
            'time' => Carbon::parse('2025-11-23 09:00:00'),
            'target_type' => 'Nhà cung cấp',
            'target_id' => $partner->id,
            'reference_type' => 'Purchase',
            'reference_code' => 'PN-NODASH',
            'payment_method' => 'cash',
            'status' => 'completed',
            'created_at' => Carbon::parse('2025-11-23 09:00:00'),
        ]);

        $customerEntries = collect($this->actingAs($admin)
            ->getJson("/customers/{$partner->id}/debt-history?per_page=100&page=1")
            ->assertOk()
            ->json('entries'));

        foreach ($customerEntries as $entry) {
            $this->assertNotEquals('Đã hạch toán', $entry['badge_label'] ?? null);
            if ($this->isFinancialTimelineEntry($entry)) {
                $this->assertArrayHasKey('customer_display_effect', $entry);
                $this->assertNotNull($entry['customer_display_running_balance'], $entry['code'] ?? $entry['id']);
            }
        }

        $customerLast = $customerEntries
            ->sortBy(fn ($entry) => (string) ($entry['time'] ?? $entry['created_at'] ?? ''))
            ->last();
        $this->assertEquals(-27_600_000, $customerLast['customer_display_running_balance']);

        $supplierResponse = $this->actingAs($admin)
            ->getJson("/api/suppliers/{$partner->id}/debt-transactions?view=partner&per_page=100&page=1")
            ->assertOk();
        $supplierEntries = collect($supplierResponse->json('entries'));

        foreach ($supplierEntries as $entry) {
            $this->assertNotEquals('Đã hạch toán', $entry['badge_label'] ?? null);
            if ($this->isFinancialTimelineEntry($entry)) {
                $this->assertArrayHasKey('supplier_display_effect', $entry);
                $this->assertNotNull($entry['supplier_display_running_balance'], $entry['code'] ?? $entry['id']);
            }
        }

        $supplierLast = $supplierEntries
            ->sortBy(fn ($entry) => (string) ($entry['time'] ?? $entry['created_at'] ?? ''))
            ->last();
        $this->assertEquals(27_600_000, $supplierLast['supplier_display_running_balance']);
        $this->assertEquals(27_600_000, $supplierResponse->json('summary.display_balance_final'));
    }

    private function isFinancialTimelineEntry(array $entry): bool
    {
        return in_array((string) ($entry['event_kind'] ?? ''), [
            'customer_sale',
            'invoice_payment',
            'sales_return',
            'customer_payment',
            'payment_discount',
            'supplier_purchase',
            'supplier_mirror_purchase',
            'supplier_payment',
            'supplier_mirror_payment',
            'purchase_return',
            'supplier_mirror_return',
            'opening_balance',
            'virtual_opening_balance',
        ], true);
    }
}
