<?php

namespace Tests\Feature\Debt;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DualRolePartnerDebtNoteExportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dual_role_notes_are_exported_without_mutating_financial_state_or_public_timeline(): void
    {
        $admin = User::create([
            'name' => 'Admin dual-role note export',
            'email' => 'dual-role-note-export-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);
        $partner = Customer::create([
            'code' => 'DT-NOTE-'.uniqid(),
            'name' => 'Đối tác hai vai trò ghi chú',
            'is_customer' => true,
            'is_supplier' => true,
            'status' => 'active',
            'debt_amount' => 100_000,
            'supplier_debt_amount' => 60_000,
            'total_spent' => 100_000,
            'total_bought' => 60_000,
            'total_returns' => 0,
        ]);
        $invoice = Invoice::create([
            'code' => 'HD-DUAL-NOTE-'.uniqid(),
            'customer_id' => $partner->id,
            'subtotal' => 100_000,
            'total' => 100_000,
            'customer_paid' => 0,
            'status' => 'Hoàn thành',
            'note' => 'Ghi chú hóa đơn đối tác hai vai trò',
            'transaction_date' => now()->subDay(),
        ]);
        $purchase = Purchase::create([
            'code' => 'PN-DUAL-NOTE-'.uniqid(),
            'supplier_id' => $partner->id,
            'total_amount' => 60_000,
            'paid_amount' => 0,
            'debt_amount' => 60_000,
            'status' => 'completed',
            'note' => 'Ghi chú phiếu nhập đối tác hai vai trò',
            'purchase_date' => now()->subHours(12),
        ]);

        $beforePartner = $partner->fresh()->only([
            'debt_amount',
            'supplier_debt_amount',
            'total_spent',
            'total_bought',
            'total_returns',
        ]);
        $beforeInvoice = $invoice->fresh()->only(['total', 'customer_paid', 'note']);
        $beforePurchase = $purchase->fresh()->only(['total_amount', 'paid_amount', 'debt_amount', 'note']);

        $customerTimeline = $this->actingAs($admin)
            ->getJson("/customers/{$partner->id}/debt-history?per_page=100&page=1")
            ->assertOk()
            ->json('entries');
        $supplierTimeline = $this->actingAs($admin)
            ->getJson("/api/suppliers/{$partner->id}/debt-transactions?per_page=100&page=1")
            ->assertOk()
            ->json('entries');

        foreach (array_merge($customerTimeline, $supplierTimeline) as $entry) {
            $this->assertArrayNotHasKey('note', $entry);
            $this->assertArrayNotHasKey('description', $entry);
            $this->assertFalse(str_starts_with((string) ($entry['code'] ?? ''), 'CHECKPOINT-'));
        }

        $customerExport = $this->actingAs($admin)->get(
            "/customers/{$partner->id}/export-debt?format=csv&date_preset=all&include_detail=0",
        );
        $customerExport->assertOk();
        $customerCsv = $customerExport->streamedContent() ?: $customerExport->getContent();

        $supplierExport = $this->actingAs($admin)->get(
            "/api/suppliers/{$partner->id}/export-debt?format=csv&date_preset=all&include_detail=0",
        );
        $supplierExport->assertOk();
        $supplierCsv = $supplierExport->streamedContent() ?: $supplierExport->getContent();

        foreach ([$customerCsv, $supplierCsv] as $csv) {
            $this->assertStringContainsString('Ghi chú', $csv);
            $this->assertStringContainsString('Ghi chú hóa đơn đối tác hai vai trò', $csv);
            $this->assertStringContainsString('Ghi chú phiếu nhập đối tác hai vai trò', $csv);
            $this->assertStringNotContainsString('CHECKPOINT-', $csv);
        }

        $this->assertSame($beforePartner, $partner->fresh()->only(array_keys($beforePartner)));
        $this->assertSame($beforeInvoice, $invoice->fresh()->only(array_keys($beforeInvoice)));
        $this->assertSame($beforePurchase, $purchase->fresh()->only(array_keys($beforePurchase)));
    }
}
