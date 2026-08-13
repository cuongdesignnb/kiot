<?php

namespace Tests\Feature\CashFlow;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Services\CashFlowCancellationService;
use App\Services\CustomerDebtService;
use App\Services\CustomerPaymentService;
use App\Services\SupplierDebtDocumentTimelineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RRCashBookCancellationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_manual_cash_flow_is_cancelled_without_physical_delete_and_is_idempotent(): void
    {
        $flow = CashFlow::create([
            'code' => 'PT-CANCEL-'.Str::upper(Str::random(8)),
            'type' => 'receipt',
            'amount' => 125_000,
            'status' => 'active',
            'time' => now(),
            'reference_type' => null,
        ]);

        $service = app(CashFlowCancellationService::class);
        $this->assertSame(CashFlowCancellationService::CANCELLED, $service->cancel(
            $flow,
            'Điều chỉnh phiếu thủ công',
            'cash-flow-cancel-'.Str::random(20),
        ));

        $cancelled = CashFlow::withTrashed()->findOrFail($flow->id);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNull($cancelled->deleted_at);
        $this->assertSame('Điều chỉnh phiếu thủ công', $cancelled->cancel_reason);
        $this->assertSame(0, CashFlow::active()->whereKey($flow->id)->count());
        $this->assertSame(CashFlowCancellationService::ALREADY_CANCELLED, $service->cancel(
            $flow,
            'Điều chỉnh phiếu thủ công',
            'cash-flow-cancel-'.Str::random(20),
        ));
    }

    public function test_debt_payment_cancellation_reverses_invoice_and_allocation_once(): void
    {
        $customer = Customer::create([
            'code' => 'KH-CANCEL-'.Str::upper(Str::random(8)),
            'name' => 'Customer cancellation fixture',
            'is_customer' => true,
            'is_supplier' => false,
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'code' => 'HD-CANCEL-'.Str::upper(Str::random(8)),
            'customer_id' => $customer->id,
            'subtotal' => 300_000,
            'total' => 300_000,
            'customer_paid' => 0,
            'status' => 'completed',
        ]);
        app(CustomerDebtService::class)->recordSale($customer->id, 300_000, $invoice);

        $result = app(CustomerPaymentService::class)->collect($customer->fresh(), 300_000, 'manual', [
            ['invoice_id' => $invoice->id, 'amount' => 300_000],
        ]);
        $flow = CashFlow::findOrFail($result['cash_flow_id']);
        $this->assertSame(300_000.0, (float) $invoice->fresh()->customer_paid);
        $idempotencyKey = 'cash-flow-debt-cancel-'.Str::random(20);

        $this->assertSame(CashFlowCancellationService::CANCELLED, app(CashFlowCancellationService::class)->cancel(
            $flow,
            'Khách yêu cầu hủy phiếu',
            $idempotencyKey,
        ));
        $this->assertSame(0.0, (float) $invoice->fresh()->customer_paid);
        $this->assertSame(300_000.0, (float) $customer->fresh()->debt_amount);
        $this->assertSame(1, DB::table('customer_payment_allocation_reversals')
            ->where('allocation_id', DB::table('customer_payment_allocations')->where('cash_flow_id', $flow->id)->value('id'))
            ->count());
        $this->assertSame(CashFlowCancellationService::ALREADY_CANCELLED, app(CashFlowCancellationService::class)->cancel(
            $flow,
            'Khách yêu cầu hủy phiếu',
            $idempotencyKey,
        ));
        $this->assertSame(1, DB::table('customer_payment_allocation_reversals')->count());
    }

    public function test_supplier_payment_cancellation_reverses_active_purchase_and_writes_mirror(): void
    {
        $supplier = Customer::create([
            'code' => 'NCC-CANCEL-'.Str::upper(Str::random(8)),
            'name' => 'Supplier cancellation fixture',
            'is_customer' => false,
            'is_supplier' => true,
            'debt_amount' => 0,
            'supplier_debt_amount' => 400_000,
            'status' => 'active',
        ]);
        $purchase = Purchase::create([
            'code' => 'PN-CANCEL-'.Str::upper(Str::random(8)),
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => 1_000_000,
            'paid_amount' => 600_000,
            'debt_amount' => 400_000,
            'purchase_date' => now(),
        ]);
        $flow = CashFlow::create([
            'code' => 'PCPN-CANCEL-'.Str::upper(Str::random(8)),
            'type' => 'payment',
            'amount' => 600_000,
            'status' => 'active',
            'time' => now(),
            'target_type' => 'Supplier',
            'target_id' => $supplier->id,
            'reference_type' => 'SupplierPayment',
            'reference_code' => $purchase->code,
        ]);
        $operationId = DB::table('partner_debt_operations')->insertGetId([
            'operation_uuid' => (string) Str::uuid(),
            'partner_id' => $supplier->id,
            'operation_type' => 'supplier_payment_test_fixture',
            'idempotency_key' => 'supplier-payment-fixture-'.Str::random(20),
            'request_hash' => hash('sha256', (string) Str::uuid()),
            'status' => 'committed',
            'initiated_at' => now(),
            'committed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $allocationId = DB::table('supplier_payment_allocations')->insertGetId([
            'payment_id' => $flow->id,
            'purchase_id' => $purchase->id,
            'supplier_id' => $supplier->id,
            'amount' => 600_000,
            'allocation_source' => 'manual',
            'idempotency_key' => 'supplier-allocation-fixture-'.Str::random(20),
            'operation_id' => $operationId,
            'allocated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(CashFlowCancellationService::CANCELLED, app(CashFlowCancellationService::class)->cancel(
            $flow,
            'Hủy thanh toán nhà cung cấp',
            'cash-flow-supplier-cancel-'.Str::random(20),
        ));
        $this->assertSame(0.0, (float) $purchase->fresh()->paid_amount);
        $this->assertSame(1_000_000.0, (float) $purchase->fresh()->debt_amount);
        $this->assertSame(1_000_000.0, (float) $supplier->fresh()->supplier_debt_amount);
        $this->assertSame(1, DB::table('supplier_payment_allocation_reversals')
            ->where('allocation_id', $allocationId)
            ->count());
        $this->assertSame(1, DB::table('supplier_debt_transactions')
            ->where('code', 'H'.$flow->code)
            ->where('type', 'payment_cancel')
            ->count());

        $entries = collect(app(SupplierDebtDocumentTimelineService::class)->build($supplier->fresh())['entries']);
        $this->assertSame(1, $entries->where('code', $flow->code)->where('event_kind', 'supplier_payment')->count());
        $this->assertTrue($entries->contains(fn (array $entry): bool => ($entry['event_kind'] ?? '') === 'supplier_payment_cancel_reversal'
            && (string) ($entry['code'] ?? '') === $flow->code));
        $this->assertFalse($entries->contains(fn (array $entry): bool => ($entry['code'] ?? '') === 'H'.$flow->code));
    }
}
