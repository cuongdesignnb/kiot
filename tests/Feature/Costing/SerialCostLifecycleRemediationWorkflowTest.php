<?php

namespace Tests\Feature\Costing;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ReturnItem;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\Task;
use App\Services\SerialCostLifecycleRemediationApplyService;
use App\Services\SerialCostLifecycleRemediationApprovalService;
use App\Services\SerialCostLifecycleRemediationPlanService;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SerialCostLifecycleRemediationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_repairs_sale_return_resale_snapshots_atomically_and_replays(): void
    {
        $product = $this->product('RESALE');
        $serial = $this->serial($product, 'RESALE-1', 9_000_000);
        $this->repair($product, $serial, 8_000_000, now()->subDays(5), 'SC-LIFE-1');
        [$firstInvoice, $firstItem, $firstMovement] = $this->sale(
            $product,
            [$serial],
            14_000_000,
            now()->subDays(4),
            'HD-LIFE-1',
        );
        [, $returnItem, $returnMovement] = $this->completeReturn(
            $firstInvoice,
            $firstItem,
            [$serial],
            14_000_000,
            now()->subDays(3),
            'TH-LIFE-1',
        );
        $this->repair($product, $serial, 9_000_000, now()->subDays(2)->subHour(), 'SC-LIFE-2');
        [$secondInvoice, $secondItem, $secondMovement] = $this->sale(
            $product,
            [$serial],
            7_000_000,
            now()->subDays(2),
            'HD-LIFE-2',
        );

        [$plan, $approval] = $this->approvedPlan();
        $this->assertSame(2, $plan['summary']['repair_lines']);
        $this->assertSame(0, $plan['summary']['blocked_lines']);
        $this->assertSame(1, $plan['summary']['return_items_to_update']);

        $apply = app(SerialCostLifecycleRemediationApplyService::class);
        $preview = $apply->preview($plan, $approval);
        $this->assertSame('NO', $preview['database_mutation']);
        $this->assertSame(2, $preview['lines_selected']);

        $result = $apply->apply($plan, $approval, 'Codex QA', 'backup:test-lifecycle');

        $this->assertSame('APPLIED', $result['result']);
        $this->assertSame(8_000_000.0, (float) $firstItem->fresh()->cost_price);
        $this->assertSame(8_000_000.0, (float) $firstMovement->fresh()->unit_cost);
        $this->assertSame(8_000_000.0, (float) $returnItem->fresh()->cost_price);
        $this->assertSame(8_000_000.0, (float) $returnMovement->fresh()->total_cost);
        $this->assertSame(9_000_000.0, (float) $secondItem->fresh()->cost_price);
        $this->assertSame(9_000_000.0, (float) $secondMovement->fresh()->unit_cost);
        $this->assertSame(9_000_000.0, (float) $serial->fresh()->sold_cost_price);
        $this->assertSame($secondInvoice->id, $serial->fresh()->invoice_id);
        $this->assertSame(2, ActivityLog::where(
            'action',
            ActivityLog::ACTION_SERIAL_COST_LIFECYCLE_REMEDIATION_APPLY,
        )->count());

        $replay = $apply->apply($plan, $approval, 'Codex QA', 'backup:test-lifecycle');
        $this->assertSame('REPLAY', $replay['result']);
        $this->assertSame(0, $replay['lines_changed']);
        $this->assertSame(2, $replay['replayed_lines']);
        $this->assertSame(2, ActivityLog::where(
            'action',
            ActivityLog::ACTION_SERIAL_COST_LIFECYCLE_REMEDIATION_APPLY,
        )->count());
        $this->assertSame(0, app(SerialCostLifecycleRemediationPlanService::class)->build()['summary']['repair_lines']);
    }

    public function test_return_movement_preserves_exact_serial_sum_when_rounded_unit_cost_differs_by_one(): void
    {
        $product = $this->product('ROUNDING');
        $serials = [
            $this->serial($product, 'ROUND-A', 3_400_000),
            $this->serial($product, 'ROUND-B', 3_400_000),
            $this->serial($product, 'ROUND-C', 3_400_001),
        ];
        foreach ($serials as $index => $serial) {
            $this->repair($product, $serial, [3_400_000, 3_400_000, 3_400_001][$index], now()->subDays(3), 'SC-ROUND-'.$index);
        }
        [$invoice, $item] = $this->sale($product, $serials, 4_000_000, now()->subDays(2), 'HD-ROUND');
        [, $returnItem, $returnMovement] = $this->completeReturn(
            $invoice,
            $item,
            $serials,
            4_000_000,
            now()->subDay(),
            'TH-ROUND',
        );

        [$plan, $approval] = $this->approvedPlan();
        $line = collect($plan['repair_lines'])->sole('invoice_item_id', $item->id);
        $dependency = collect($line['return_dependencies'])->sole();
        $this->assertSame(3_400_000, data_get($dependency, 'expected.cost_price'));
        $this->assertSame(10_200_001, data_get($dependency, 'expected.stock_movement.total_cost'));

        app(SerialCostLifecycleRemediationApplyService::class)
            ->apply($plan, $approval, 'Codex QA', 'backup:test-rounding');

        $this->assertSame(3_400_000.0, (float) $returnItem->fresh()->cost_price);
        $this->assertSame(3_400_000.0, (float) $returnMovement->fresh()->unit_cost);
        $this->assertSame(10_200_001.0, (float) $returnMovement->fresh()->total_cost);
    }

    public function test_partial_evidence_repairs_only_the_proven_serial_and_its_isolated_return(): void
    {
        $product = $this->product('PARTIAL');
        $proven = $this->serial($product, 'PARTIAL-PROVEN', 5_000_000);
        $unknown = $this->serial($product, 'PARTIAL-UNKNOWN', 6_000_000);
        $this->repair($product, $proven, 5_000_000, now()->subDays(3), 'SC-PARTIAL');
        [$invoice, $item, $saleMovement] = $this->sale(
            $product,
            [$proven, $unknown],
            6_000_000,
            now()->subDays(2),
            'HD-PARTIAL',
        );
        [, $returnItem, $returnMovement] = $this->completeReturn(
            $invoice,
            $item,
            [$proven],
            6_000_000,
            now()->subDay(),
            'TH-PARTIAL',
        );

        [$plan, $approval] = $this->approvedPlan();
        $this->assertSame(1, $plan['summary']['repair_lines']);
        $this->assertSame(0, $plan['summary']['blocked_lines']);
        $this->assertSame(0, $plan['repair_lines'][0]['sale_cogs_delta']);
        $this->assertSame(-1_000_000, $plan['repair_lines'][0]['return_cogs_delta']);

        app(SerialCostLifecycleRemediationApplyService::class)
            ->apply($plan, $approval, 'Codex QA', 'backup:test-partial');

        $this->assertSame(6_000_000.0, (float) $item->fresh()->cost_price);
        $this->assertSame(12_000_000.0, (float) $saleMovement->fresh()->total_cost);
        $this->assertSame(5_000_000.0, (float) InvoiceItemSerial::where('serial_imei_id', $proven->id)->value('cost_price'));
        $this->assertSame(6_000_000.0, (float) InvoiceItemSerial::where('serial_imei_id', $unknown->id)->value('cost_price'));
        $this->assertSame(5_000_000.0, (float) $returnItem->fresh()->cost_price);
        $this->assertSame(5_000_000.0, (float) $returnMovement->fresh()->total_cost);
        $this->assertNull($proven->fresh()->sold_cost_price);
        $this->assertSame(6_000_000.0, (float) $unknown->fresh()->sold_cost_price);
    }

    public function test_unresolved_multiple_sales_are_blocked_and_never_approved(): void
    {
        $product = $this->product('UNRESOLVED');
        $serial = $this->serial($product, 'UNRESOLVED', 5_000_000);
        $this->repair($product, $serial, 5_000_000, now()->subDays(4), 'SC-UNRESOLVED');
        $this->sale($product, [$serial], 9_000_000, now()->subDays(3), 'HD-UNRESOLVED-1');
        $this->sale($product, [$serial], 9_000_000, now()->subDay(), 'HD-UNRESOLVED-2');

        $plan = app(SerialCostLifecycleRemediationPlanService::class)->build();

        $this->assertSame(0, $plan['summary']['repair_lines']);
        $this->assertGreaterThan(0, $plan['summary']['blocked_lines']);
        $this->assertContains(
            SerialCostLifecycleRemediationPlanService::BLOCK_LIFECYCLE,
            $plan['blocked_lines'][0]['lifecycle_blocking_flags'],
        );

        $this->expectException(RuntimeException::class);
        app(SerialCostLifecycleRemediationApprovalService::class)->create(
            $plan,
            'Codex QA',
            'QA-UNRESOLVED',
        );
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function approvedPlan(): array
    {
        $plan = app(SerialCostLifecycleRemediationPlanService::class)->build();
        $approval = app(SerialCostLifecycleRemediationApprovalService::class)->create(
            $plan,
            'Codex-QA-owner-delegated',
            'QA-LIFECYCLE-TEST',
        );

        return [$plan, $approval];
    }

    private function product(string $suffix): Product
    {
        return Product::create([
            'sku' => 'SP-LIFECYCLE-'.$suffix.'-'.uniqid(),
            'name' => 'Lifecycle '.$suffix,
            'stock_quantity' => 0,
            'inventory_total_cost' => 0,
            'cost_price' => 0,
            'retail_price' => 20_000_000,
            'has_serial' => true,
            'is_active' => true,
        ]);
    }

    private function serial(Product $product, string $number, int $cost): SerialImei
    {
        return SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => $number.'-'.uniqid(),
            'status' => 'in_stock',
            'cost_price' => $cost,
            'original_cost' => $cost,
            'sold_cost_price' => null,
        ]);
    }

    private function repair(Product $product, SerialImei $serial, int $cost, $at, string $code): Task
    {
        return Task::create([
            'code' => $code.'-'.uniqid(),
            'type' => Task::TYPE_REPAIR,
            'title' => $code,
            'product_id' => $product->id,
            'serial_imei_id' => $serial->id,
            'original_cost' => $cost,
            'parts_cost' => 0,
            'total_cost' => $cost,
            'status' => Task::STATUS_COMPLETED,
            'completed_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /** @param array<int, SerialImei> $serials
     * @return array{0:Invoice,1:InvoiceItem,2:StockMovement}
     */
    private function sale(Product $product, array $serials, int $storedUnitCost, $at, string $code): array
    {
        $quantity = count($serials);
        $invoice = Invoice::create([
            'code' => $code.'-'.uniqid(),
            'status' => 'Hoàn thành',
            'subtotal' => 20_000_000 * $quantity,
            'total' => 20_000_000 * $quantity,
            'customer_paid' => 20_000_000 * $quantity,
            'transaction_date' => $at,
            'lock_started_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => 20_000_000,
            'cost_price' => $storedUnitCost,
            'subtotal' => 20_000_000 * $quantity,
        ]);
        foreach ($serials as $serial) {
            InvoiceItemSerial::create([
                'invoice_item_id' => $item->id,
                'serial_imei_id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'cost_price' => $storedUnitCost,
            ]);
            $serial->update([
                'status' => 'sold',
                'invoice_id' => $invoice->id,
                'sold_at' => $at,
                'sold_cost_price' => $storedUnitCost,
            ]);
        }
        $movement = StockMovement::create([
            'product_id' => $product->id,
            'type' => StockMovementService::TYPE_OUT_INVOICE,
            'direction' => 'out',
            'qty' => $quantity,
            'unit_cost' => $storedUnitCost,
            'total_cost' => $storedUnitCost * $quantity,
            'balance_qty' => 0,
            'balance_cost' => 0,
            'ref_type' => Invoice::class,
            'ref_id' => $invoice->id,
            'ref_code' => $invoice->code,
            'moved_at' => $at,
        ]);

        return [$invoice, $item, $movement];
    }

    /** @param array<int, SerialImei> $serials
     * @return array{0:OrderReturn,1:ReturnItem,2:StockMovement}
     */
    private function completeReturn(
        Invoice $invoice,
        InvoiceItem $item,
        array $serials,
        int $storedUnitCost,
        $at,
        string $code,
    ): array {
        $quantity = count($serials);
        $return = OrderReturn::create([
            'code' => $code.'-'.uniqid(),
            'invoice_id' => $invoice->id,
            'status' => 'Đã trả',
            'subtotal' => 20_000_000 * $quantity,
            'total' => 20_000_000 * $quantity,
            'paid_to_customer' => 0,
            'recorded_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $returnItem = ReturnItem::create([
            'return_id' => $return->id,
            'invoice_item_id' => $item->id,
            'product_id' => $item->product_id,
            'quantity' => $quantity,
            'price' => 20_000_000,
            'cost_price' => $storedUnitCost,
            'serial_ids' => collect($serials)->pluck('id')->all(),
        ]);
        foreach ($serials as $serial) {
            $serial->update([
                'status' => 'in_stock',
                'invoice_id' => null,
                'sold_at' => null,
                'sold_cost_price' => null,
            ]);
        }
        $movement = StockMovement::create([
            'product_id' => $item->product_id,
            'type' => StockMovementService::TYPE_IN_INVOICE_RETURN,
            'direction' => 'in',
            'qty' => $quantity,
            'unit_cost' => $storedUnitCost,
            'total_cost' => $storedUnitCost * $quantity,
            'balance_qty' => $quantity,
            'balance_cost' => $storedUnitCost,
            'ref_type' => OrderReturn::class,
            'ref_id' => $return->id,
            'ref_code' => $return->code,
            'moved_at' => $at,
        ]);

        return [$return, $returnItem, $movement];
    }
}
