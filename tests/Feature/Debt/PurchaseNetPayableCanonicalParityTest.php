<?php

namespace Tests\Feature\Debt;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use App\Services\CustomerDebtDocumentTimelineService;
use App\Services\Debt\CanonicalPartnerDebtEventService;
use App\Services\Debt\CanonicalPartnerDebtService;
use App\Services\SupplierDebtDocumentTimelineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PurchaseNetPayableCanonicalParityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_document_discount_matches_the_persisted_supplier_debt_projection(): void
    {
        $supplier = $this->partner([
            'supplier_debt_amount' => 352_000_000,
        ]);
        SupplierDebtTransaction::create([
            'supplier_id' => $supplier->id,
            'code' => 'DCNCC-PRODUCTION-SHAPE-CHECKPOINT',
            'type' => 'adjustment',
            'amount' => -4_050_000,
            'debt_remain' => 950_000,
            'purchase_id' => null,
            'created_at' => now()->subDays(4),
        ]);
        SupplierDebtTransaction::create([
            'supplier_id' => $supplier->id,
            'code' => 'PCPN-PRODUCTION-SHAPE-CHECKPOINT',
            'type' => 'payment',
            'amount' => -950_000,
            'debt_remain' => 0,
            'purchase_id' => null,
            'created_at' => now()->subDays(3),
        ]);
        $discountedPurchase = Purchase::create([
            'code' => 'PN-CANONICAL-DISCOUNT-504M',
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => 504_000_000,
            'discount' => 5_500_000,
            'other_costs_total' => 0,
            'paid_amount' => 150_000_000,
            'debt_amount' => 348_500_000,
            'purchase_date' => now()->subMinute(),
        ]);
        $this->linkedPayment($supplier, $discountedPurchase, 150_000_000);
        Purchase::create([
            'code' => 'PN-CANONICAL-AFTER-DISCOUNT',
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => 3_500_000,
            'discount' => 0,
            'other_costs_total' => 0,
            'paid_amount' => 0,
            'debt_amount' => 3_500_000,
            'purchase_date' => now(),
        ]);

        $canonical = app(CanonicalPartnerDebtService::class)->calculate($supplier->fresh());
        $purchaseEvent = app(CanonicalPartnerDebtEventService::class)
            ->build($supplier->fresh())
            ->firstWhere('source_code', $discountedPurchase->code);
        $checkpoint = app(CanonicalPartnerDebtEventService::class)
            ->build($supplier->fresh())
            ->firstWhere('event_kind', 'persisted_ledger_checkpoint');

        $this->assertSame(5_000_000.0, (float) $checkpoint['supplier_delta']);
        $this->assertSame(498_500_000.0, (float) $purchaseEvent['supplier_delta']);
        $this->assertSame(352_000_000.0, (float) $canonical['supplier_payable']);
        $this->assertSame(0.0, (float) $canonical['differences']['supplier_payable']);
        $this->assertFalse($canonical['has_mismatch']);
    }

    public function test_document_discount_and_other_costs_preserve_dual_role_orientation(): void
    {
        $partner = $this->partner([
            'is_customer' => true,
            'debt_amount' => 250_000,
            'supplier_debt_amount' => 740_000,
        ]);
        Invoice::create([
            'code' => 'HD-DUAL-NET-PAYABLE',
            'customer_id' => $partner->id,
            'status' => 'completed',
            'total' => 250_000,
            'customer_paid' => 0,
            'transaction_date' => now()->subMinutes(2),
        ]);
        $purchase = Purchase::create([
            'code' => 'PN-DUAL-NET-PAYABLE',
            'supplier_id' => $partner->id,
            'status' => 'completed',
            'total_amount' => 1_000_000,
            'discount' => 100_000,
            'other_costs_total' => 40_000,
            'paid_amount' => 200_000,
            'debt_amount' => 740_000,
            'purchase_date' => now()->subMinute(),
        ]);
        $this->linkedPayment($partner, $purchase, 200_000);

        $canonical = app(CanonicalPartnerDebtService::class)->calculate($partner->fresh());
        $customer = app(CustomerDebtDocumentTimelineService::class)->build($partner->fresh());
        $supplier = app(SupplierDebtDocumentTimelineService::class)->build($partner->fresh());

        $this->assertSame(250_000.0, (float) $canonical['customer_receivable']);
        $this->assertSame(740_000.0, (float) $canonical['supplier_payable']);
        $this->assertFalse($canonical['has_mismatch']);
        $this->assertSame(-490_000.0, (float) $customer['raw_final_balance']);
        $this->assertSame(490_000.0, (float) $supplier['raw_final_balance']);
        $this->assertSame($customer['source_identity_hash'], $supplier['source_identity_hash']);
    }

    public function test_cancelled_purchase_reverses_the_exact_net_payable_amount(): void
    {
        $supplier = $this->partner();
        $purchase = Purchase::create([
            'code' => 'PN-CANCEL-NET-PAYABLE',
            'supplier_id' => $supplier->id,
            'status' => 'cancelled',
            'total_amount' => 1_000_000,
            'discount' => 100_000,
            'other_costs_total' => 40_000,
            'paid_amount' => 200_000,
            'debt_amount' => 740_000,
            'purchase_date' => now()->subMinutes(2),
            'cancelled_at' => now(),
        ]);
        $this->linkedPayment($supplier, $purchase, 200_000);

        $events = app(CanonicalPartnerDebtEventService::class)->build($supplier->fresh());
        $purchaseEvent = $events->firstWhere('event_kind', 'purchase');
        $purchaseReversal = $events->firstWhere('event_kind', 'purchase_cancel_reversal');

        $this->assertSame(940_000.0, (float) $purchaseEvent['supplier_delta']);
        $this->assertSame(-940_000.0, (float) $purchaseReversal['supplier_delta']);
        $this->assertSame(
            $purchaseEvent['event_identity'],
            $purchaseReversal['reversal_of_event_identity'],
        );
        $this->assertSame(0.0, (float) $events->sum('supplier_delta'));
    }

    public function test_aligned_discounted_history_does_not_block_the_next_purchase_commit(): void
    {
        $actor = User::create([
            'name' => 'Purchase net payable actor',
            'email' => 'purchase-net-payable-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
        $supplier = $this->partner([
            'supplier_debt_amount' => 352_000_000,
        ]);
        $discountedPurchase = Purchase::create([
            'code' => 'PN-HISTORICAL-DISCOUNT',
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => 504_000_000,
            'discount' => 5_500_000,
            'other_costs_total' => 0,
            'paid_amount' => 150_000_000,
            'debt_amount' => 348_500_000,
            'purchase_date' => now()->subDays(2),
        ]);
        $this->linkedPayment($supplier, $discountedPurchase, 150_000_000);
        Purchase::create([
            'code' => 'PN-HISTORICAL-AFTER-DISCOUNT',
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => 3_500_000,
            'discount' => 0,
            'other_costs_total' => 0,
            'paid_amount' => 0,
            'debt_amount' => 3_500_000,
            'purchase_date' => now()->subDay(),
        ]);
        $product = Product::create([
            'sku' => 'SP-NEXT-PURCHASE-'.uniqid(),
            'name' => 'Next purchase product',
            'cost_price' => 1_000_000,
            'retail_price' => 1_200_000,
            'stock_quantity' => 0,
            'inventory_total_cost' => 0,
            'has_serial' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($actor)->post('/purchases', [
            'code' => 'PN-NEXT-AFTER-DISCOUNT',
            'supplier_id' => $supplier->id,
            'discount' => 0,
            'paid_amount' => 0,
            'status' => 'completed',
            'purchase_date' => now()->toDateTimeString(),
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 5,
                'price' => 1_000_000,
                'discount' => 0,
            ]],
        ]);

        $response->assertRedirect(route('purchases.index'));
        $response->assertSessionMissing('error');
        $this->assertDatabaseHas('purchases', [
            'code' => 'PN-NEXT-AFTER-DISCOUNT',
            'supplier_id' => $supplier->id,
            'debt_amount' => 5_000_000,
        ]);
        $this->assertSame(357_000_000.0, (float) $supplier->fresh()->supplier_debt_amount);
        $this->assertFalse(
            app(CanonicalPartnerDebtService::class)->calculate($supplier->fresh())['has_mismatch'],
        );
    }

    private function partner(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'code' => 'NCC-NET-PAYABLE-'.uniqid(),
            'name' => 'Net payable supplier',
            'is_customer' => false,
            'is_supplier' => true,
            'status' => 'active',
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
        ], $attributes));
    }

    private function linkedPayment(Customer $supplier, Purchase $purchase, float $amount): CashFlow
    {
        return CashFlow::create([
            'code' => 'PC-NET-PAYABLE-'.uniqid(),
            'type' => 'payment',
            'amount' => $amount,
            'status' => 'active',
            'target_type' => 'Nhà cung cấp',
            'target_id' => $supplier->id,
            'target_name' => $supplier->name,
            'reference_type' => 'Purchase',
            'reference_code' => $purchase->code,
            'time' => $purchase->purchase_date,
        ]);
    }
}
