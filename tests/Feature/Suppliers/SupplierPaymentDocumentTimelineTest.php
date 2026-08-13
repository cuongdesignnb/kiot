<?php

namespace Tests\Feature\Suppliers;

use App\Http\Controllers\PurchaseController;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Debt\CanonicalPartnerDebtEventService;
use App\Services\SupplierDebtDocumentTimelineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
        $this->assertSame('SupplierPayment', $row['reference_type']);
        $this->assertSame((int) $payment->id, (int) $row['reference_id']);
        $this->assertSame($payment->code, $row['reference_code']);
        $this->assertSame($payment->code, $row['document_group_parent_code']);
        $this->assertSame('supplier_payment', $row['document_group_type']);
        $this->assertNotSame('PN-DISPLAY-1', $row['parent_document_code']);
        $this->assertSame($purchases->pluck('id')->sort()->values()->all(), collect($row['allocation_purchase_ids'])
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all());
        $this->assertSame($purchases->pluck('code')->sort()->values()->all(), collect($row['allocation_purchase_codes'])
            ->map(fn ($code): string => (string) $code)
            ->sort()
            ->values()
            ->all());
        $this->assertCount(13, $row['canonical_event_identities']);
        $this->assertSame(13, (int) $row['canonical_event_count']);
    }

    public function test_exact_production_allocation_amounts_render_one_fully_allocated_voucher(): void
    {
        $supplier = Customer::create([
            'code' => 'NCC177425584137',
            'name' => 'Exact supplier payment fixture',
            'is_customer' => true,
            'is_supplier' => true,
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'status' => 'active',
        ]);
        $amounts = [
            20_000, 1_300_000, 990_000, 660_000, 370_000, 200_000,
            250_000, 2_100_000, 900_000, 250_000, 100_000, 11_260_000,
        ];
        $purchases = collect();
        foreach ($amounts as $index => $amount) {
            $purchases->push(Purchase::create([
                'code' => 'PN-EXACT-'.$index,
                'supplier_id' => $supplier->id,
                'status' => 'completed',
                'total_amount' => $amount,
                'paid_amount' => $amount,
                'debt_amount' => 0,
                'purchase_date' => now()->subMinutes(2),
            ]));
        }
        $payment = CashFlow::create([
            'code' => 'PCPN-EXACT-18400000',
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
            'operation_type' => 'supplier_payment_exact_fixture',
            'idempotency_key' => 'exact-fixture-'.$operationUuid,
            'request_hash' => hash('sha256', $operationUuid),
            'status' => 'pending',
            'initiated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($purchases as $index => $purchase) {
            DB::table('supplier_payment_allocations')->insert([
                'payment_id' => $payment->id,
                'purchase_id' => $purchase->id,
                'supplier_id' => $supplier->id,
                'amount' => $amounts[$index],
                'allocation_source' => 'manual',
                'idempotency_key' => 'exact-allocation-'.$payment->id.'-'.$purchase->id,
                'operation_id' => $operationId,
                'allocated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $timeline = app(SupplierDebtDocumentTimelineService::class)->build($supplier->fresh());
        $paymentRows = collect($timeline['entries'])->where('event_kind', 'supplier_payment');
        $row = $paymentRows->sole();

        $this->assertCount(1, $paymentRows);
        $this->assertSame(-18_400_000.0, (float) $row['display_delta']);
        $this->assertSame(12, (int) $row['allocation_count']);
        $this->assertSame(18_400_000.0, (float) $row['allocation_total']);
        $this->assertSame(0.0, (float) $row['unallocated_amount']);
        $this->assertFalse((bool) $row['payment_allocation_mismatch']);
        $this->assertFalse((bool) $row['needs_manual_review']);
    }

    public function test_cancelling_one_purchase_reverses_only_that_allocation(): void
    {
        $supplier = Customer::create([
            'code' => 'NCC-CANCEL-ALLOC',
            'name' => 'Cancellation allocation fixture',
            'is_customer' => false,
            'is_supplier' => true,
            'supplier_debt_amount' => 0,
            'status' => 'active',
        ]);
        $purchases = collect();
        foreach ([2_000_000, 3_000_000, 4_000_000] as $index => $amount) {
            $purchases->push(Purchase::create([
                'code' => 'PN-CANCEL-'.($index + 1),
                'supplier_id' => $supplier->id,
                'status' => 'completed',
                'total_amount' => $amount,
                'paid_amount' => $amount,
                'debt_amount' => 0,
                'purchase_date' => now()->subMinutes(3),
            ]));
        }
        $payment = CashFlow::create([
            'code' => 'PCPN-CANCEL-9000000',
            'type' => 'payment',
            'amount' => 9_000_000,
            'status' => 'active',
            'target_type' => 'Supplier',
            'target_id' => $supplier->id,
            'reference_type' => 'SupplierPayment',
            'time' => now()->subMinute(),
        ]);
        $operationUuid = (string) Str::uuid();
        $operationId = DB::table('partner_debt_operations')->insertGetId([
            'operation_uuid' => $operationUuid,
            'operation_type' => 'supplier_payment_cancel_fixture',
            'idempotency_key' => 'cancel-fixture-'.$operationUuid,
            'request_hash' => hash('sha256', $operationUuid),
            'status' => 'pending',
            'initiated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($purchases as $index => $purchase) {
            DB::table('supplier_payment_allocations')->insert([
                'payment_id' => $payment->id,
                'purchase_id' => $purchase->id,
                'supplier_id' => $supplier->id,
                'amount' => [2_000_000, 3_000_000, 4_000_000][$index],
                'allocation_source' => 'manual',
                'idempotency_key' => 'cancel-allocation-'.$payment->id.'-'.$purchase->id,
                'operation_id' => $operationId,
                'allocated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $before = app(CanonicalPartnerDebtEventService::class)->build($supplier->fresh());
        $purchaseB = $purchases[1]->fresh();
        $user = User::create([
            'name' => 'Cancellation allocation test user',
            'email' => 'cancel-allocation-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
        $this->actingAs($user);
        app(PurchaseController::class)->destroy(
            Request::create('/purchases/'.$purchaseB->id, 'DELETE', ['cancel_reason' => 'Cancel purchase B']),
            $purchaseB,
        );

        $after = app(CanonicalPartnerDebtEventService::class)->build($supplier->fresh());
        $this->assertSame(3, $before->filter(fn (array $event): bool => ($event['event_kind'] ?? '') === 'supplier_payment')->count());
        $this->assertSame(3, $after->filter(fn (array $event): bool => ($event['event_kind'] ?? '') === 'supplier_payment')->count());
        $this->assertTrue($after->contains(fn (array $event): bool => ($event['event_kind'] ?? '') === 'supplier_payment_cancel_reversal'
            && (int) (($event['metadata']['reference_id'] ?? 0)) === (int) $purchaseB->id
            && abs((float) $event['supplier_delta'] - 3_000_000.0) < 0.01));
        $this->assertTrue($after->contains(fn (array $event): bool => ($event['event_kind'] ?? '') === 'supplier_payment'
            && (int) (($event['metadata']['reference_id'] ?? 0)) === (int) $purchases[0]->id
            && abs((float) $event['supplier_delta'] + 2_000_000.0) < 0.01));
        $this->assertTrue($after->contains(fn (array $event): bool => ($event['event_kind'] ?? '') === 'supplier_payment'
            && (int) (($event['metadata']['reference_id'] ?? 0)) === (int) $purchases[2]->id
            && abs((float) $event['supplier_delta'] + 4_000_000.0) < 0.01));
        $this->assertFalse($after->contains(fn (array $event): bool => ($event['event_kind'] ?? '') === 'supplier_payment_cancel_reversal'
            && abs((float) $event['supplier_delta'] - 9_000_000.0) < 0.01));
        $this->assertSame(3, DB::table('supplier_payment_allocations')->where('payment_id', $payment->id)->count());
        $cancelledAllocationId = DB::table('supplier_payment_allocations')
            ->where('payment_id', $payment->id)
            ->where('purchase_id', $purchaseB->id)
            ->value('id');
        $this->assertSame(1, DB::table('supplier_payment_allocation_reversals')
            ->where('allocation_id', $cancelledAllocationId)
            ->count());
        $this->assertSame('active', (string) $payment->fresh()->status);
    }

    public function test_document_csv_and_xlsx_exports_render_one_payment_row(): void
    {
        [$supplier, $payment] = $this->createExportFixture();
        $user = User::create([
            'name' => 'Supplier export test user',
            'email' => 'supplier-export-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);

        $csvResponse = $this->actingAs($user)->get(
            "/api/suppliers/{$supplier->id}/export-debt?format=csv&date_preset=all",
        );
        $csvResponse->assertOk();
        $csv = $csvResponse->streamedContent() ?: $csvResponse->getContent();
        $this->assertSame(1, substr_count($csv, $payment->code));
        $this->assertStringContainsString('-18400000', str_replace([',', '.', ' '], '', $csv));

        $xlsxResponse = $this->actingAs($user)->get(
            "/api/suppliers/{$supplier->id}/export-debt?format=xlsx&date_preset=all",
        );
        $xlsxResponse->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'supplier-export-');
        file_put_contents($path, $xlsxResponse->streamedContent() ?: $xlsxResponse->getContent());
        $workbook = IOFactory::load($path);
        @unlink($path);
        $rows = $workbook->getActiveSheet()->toArray(null, true, true, true);
        $matchingRows = collect($rows)->filter(fn (array $row): bool => in_array(
            $payment->code,
            array_map(fn ($value): string => (string) $value, $row),
            true,
        ));
        $this->assertCount(1, $matchingRows);
    }

    /** @return array{Customer, CashFlow} */
    private function createExportFixture(): array
    {
        $supplier = Customer::create([
            'code' => 'NCC-EXPORT-'.uniqid(),
            'name' => 'Supplier export fixture',
            'is_customer' => false,
            'is_supplier' => true,
            'supplier_debt_amount' => 0,
            'status' => 'active',
        ]);
        $purchases = collect();
        for ($index = 1; $index <= 12; $index++) {
            $purchases->push(Purchase::create([
                'code' => 'PN-EXPORT-'.$index.'-'.uniqid(),
                'supplier_id' => $supplier->id,
                'status' => 'completed',
                'total_amount' => 1_500_000,
                'paid_amount' => 1_500_000,
                'debt_amount' => 0,
                'purchase_date' => now()->subMinutes(2),
            ]));
        }
        $payment = CashFlow::create([
            'code' => 'PCPN-EXPORT-18400000',
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
            'operation_type' => 'supplier_payment_export_fixture',
            'idempotency_key' => 'export-fixture-'.$operationUuid,
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
                'idempotency_key' => 'export-allocation-'.$payment->id.'-'.$purchase->id,
                'operation_id' => $operationId,
                'allocated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$supplier, $payment];
    }
}
