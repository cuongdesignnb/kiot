<?php

namespace Tests\Feature\Suppliers;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Purchase;
use App\Services\SupplierDebtDocumentTimelineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierPaymentDocumentTimelineTest extends TestCase
{
    use DatabaseTransactions;

    public function test_production_like_multi_purchase_payment_is_one_display_row_with_allocation_metadata(): void
    {
        $supplier = Customer::create([
            'code' => 'NCC177425584137',
            'name' => 'Production-like supplier payment fixture',
            'is_customer' => true,
            'is_supplier' => true,
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'status' => 'active',
        ]);

        $purchases = collect();
        for ($index = 1; $index <= 12; $index++) {
            $purchases->push(Purchase::create([
                'code' => 'PN-DISPLAY-'.$index,
                'supplier_id' => $supplier->id,
                'status' => 'completed',
                'total_amount' => 1_500_000,
                'paid_amount' => 1_500_000,
                'debt_amount' => 0,
                'purchase_date' => now()->subMinutes(2),
            ]));
        }

        $payment = CashFlow::create([
            'code' => 'PCPN260807154515978',
            'type' => 'payment',
            'amount' => 18_400_000,
            'status' => 'active',
            'target_type' => 'Supplier',
            'target_id' => $supplier->id,
            'reference_type' => 'SupplierPayment',
            'time' => now()->subMinute(),
        ]);

        $operationUuid = (string) Str::uuid();
        $operationId = DB::table('partner_debt_operations')->insertGetId([
            'operation_uuid' => $operationUuid,
            'operation_type' => 'supplier_payment_display_fixture',
            'idempotency_key' => 'display-fixture-'.$operationUuid,
            'request_hash' => hash('sha256', $operationUuid),
            'status' => 'pending',
            'initiated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($purchases as $purchase) {
            DB::table('supplier_payment_allocations')->insert([
                'payment_id' => $payment->id,
                'purchase_id' => $purchase->id,
                'supplier_id' => $supplier->id,
                'amount' => 1_500_000,
                'allocation_source' => 'manual',
                'idempotency_key' => 'display-allocation-'.$payment->id.'-'.$purchase->id,
                'operation_id' => $operationId,
                'allocated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $timeline = app(SupplierDebtDocumentTimelineService::class)->build($supplier->fresh());
        $paymentRows = collect($timeline['entries'])->where('event_kind', 'supplier_payment');

        $this->assertCount(1, $paymentRows);
        $row = $paymentRows->first();
        $this->assertSame(-18_400_000.0, (float) $row['display_delta']);
        $this->assertSame(12, (int) $row['allocation_count']);
        $this->assertSame(18_000_000.0, (float) $row['allocation_total']);
        $this->assertSame(400_000.0, (float) $row['unallocated_amount']);
        $this->assertTrue((bool) $row['payment_allocation_mismatch']);
        $this->assertTrue((bool) $row['needs_manual_review']);
        $this->assertSame((int) $payment->id, (int) $row['payment_cash_flow_id']);
        $this->assertCount(13, $row['canonical_event_identities']);
        $this->assertSame(13, (int) $row['canonical_event_count']);
    }
}
