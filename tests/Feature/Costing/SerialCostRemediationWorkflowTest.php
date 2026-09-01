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
use App\Services\SerialCostRemediationApplyService;
use App\Services\SerialCostRemediationApprovalService;
use App\Services\SerialCostRemediationPlanService;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SerialCostRemediationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_exposes_only_complete_simple_lifecycle_evidence_as_an_auto_apply_candidate(): void
    {
        [$product, $serial, $invoice, $item] = $this->mismatchedSale('PLAN-CANDIDATE');

        $plan = app(SerialCostRemediationPlanService::class)->build($product->id);

        $this->assertCount(1, $plan['repair_lines']);
        $line = $plan['repair_lines'][0];
        $this->assertSame('invoice_item:'.$item->id, $line['line_key']);
        $this->assertSame(SerialCostRemediationPlanService::ACTION_REPAIR, $line['proposed_action']);
        $this->assertSame([], $line['blocking_flags']);
        $this->assertSame(8_029_022, $line['expected']['invoice_item_cost']);
        $this->assertSame(8_029_022, $line['expected']['stock_movement']['unit_cost']);
        $this->assertSame(8_029_022, $line['expected']['serials'][0]['expected_cost']);
        $this->assertSame(14_111_257.0, (float) $item->fresh()->cost_price);
        $this->assertSame(14_111_257.0, (float) $serial->fresh()->sold_cost_price);
        $this->assertSame($invoice->code, $line['invoice_code']);
    }

    public function test_plan_blocks_a_completed_return_even_if_the_sale_has_repair_cost_evidence(): void
    {
        [$product, $serial, $invoice, $item] = $this->mismatchedSale('RETURN-BLOCKED');
        $return = OrderReturn::create([
            'code' => 'TH-RETURN-BLOCKED',
            'invoice_id' => $invoice->id,
            'status' => 'Đã trả',
            'subtotal' => 20_000_000,
            'total' => 20_000_000,
            'paid_to_customer' => 0,
        ]);
        ReturnItem::create([
            'return_id' => $return->id,
            'invoice_item_id' => $item->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 20_000_000,
            'cost_price' => 14_111_257,
            'serial_ids' => [$serial->id],
        ]);

        $plan = app(SerialCostRemediationPlanService::class)->build($product->id);

        $this->assertSame([], $plan['repair_lines']);
        $line = collect($plan['manual_review_lines'])->sole('invoice_item_id', $item->id);
        $this->assertContains(SerialCostRemediationPlanService::BLOCK_RETURN_HISTORY, $line['blocking_flags']);
    }

    public function test_plan_blocks_resale_history_from_automatic_historical_repair(): void
    {
        [$product, $serial, $firstInvoice, $firstItem] = $this->mismatchedSale('RESALE-FIRST', now()->subDays(2));
        $this->sale($product, $serial, 8_029_022, 'RESALE-SECOND', now()->subDay());

        $plan = app(SerialCostRemediationPlanService::class)->build($product->id);

        $this->assertSame([], $plan['repair_lines']);
        $firstLine = collect($plan['manual_review_lines'])->sole('invoice_item_id', $firstItem->id);
        $this->assertContains(SerialCostRemediationPlanService::BLOCK_RESALE_HISTORY, $firstLine['blocking_flags']);
        $this->assertSame($firstInvoice->code, $firstLine['invoice_code']);
    }

    public function test_approved_apply_updates_every_financial_snapshot_atomically_and_replays_without_writing_again(): void
    {
        [$product, $serial, $invoice, $item, $movement] = $this->mismatchedSale('APPLY-CANDIDATE');
        $plans = app(SerialCostRemediationPlanService::class);
        $approvalService = app(SerialCostRemediationApprovalService::class);
        $applyService = app(SerialCostRemediationApplyService::class);
        $plan = $plans->build($product->id);
        $approval = $approvalService->create(
            $plan,
            [$invoice->code],
            null,
            'Kế toán A',
            'KT-COGS-001',
        );

        $preview = $applyService->preview($plan, $approval);
        $this->assertSame('dry-run', $preview['mode']);
        $this->assertSame('NO', $preview['database_mutation']);
        $this->assertSame(1, $preview['lines_selected']);

        $result = $applyService->apply($plan, $approval, 'Vận hành B', 'backup:test-001');

        $this->assertSame('APPLIED', $result['result']);
        $this->assertSame('Vận hành B', $result['operator']);
        $this->assertSame('backup:test-001', $result['backup_reference']);
        $this->assertSame(1, $result['lines_changed']);
        $this->assertSame(8_029_022.0, (float) $item->fresh()->cost_price);
        $this->assertSame(8_029_022.0, (float) InvoiceItemSerial::where('invoice_item_id', $item->id)->value('cost_price'));
        $this->assertSame(8_029_022.0, (float) $serial->fresh()->sold_cost_price);
        $this->assertSame(8_029_022.0, (float) $movement->fresh()->unit_cost);
        $this->assertSame(8_029_022.0, (float) $movement->fresh()->total_cost);
        $this->assertDatabaseHas('activity_logs', [
            'action' => ActivityLog::ACTION_SERIAL_COST_REMEDIATION_APPLY,
            'subject_id' => $invoice->id,
        ]);
        $activity = ActivityLog::where('action', ActivityLog::ACTION_SERIAL_COST_REMEDIATION_APPLY)->sole();
        $this->assertTrue($activity->properties['backup_confirmed']);
        $this->assertSame('backup:test-001', $activity->properties['backup_reference']);

        $replay = $applyService->apply($plan, $approval, 'Vận hành B', 'backup:test-001');
        $this->assertSame('REPLAY', $replay['result']);
        $this->assertSame(0, $replay['lines_changed']);
        $this->assertSame(1, ActivityLog::where('action', ActivityLog::ACTION_SERIAL_COST_REMEDIATION_APPLY)->count());
    }

    public function test_apply_rejects_a_plan_when_current_snapshot_changes_after_approval(): void
    {
        [$product, $serial, $invoice, $item] = $this->mismatchedSale('STALE-PLAN');
        $plans = app(SerialCostRemediationPlanService::class);
        $plan = $plans->build($product->id);
        $approval = app(SerialCostRemediationApprovalService::class)->create(
            $plan,
            [$invoice->code],
            null,
            'Kế toán A',
            'KT-COGS-STALE',
        );
        $item->update(['cost_price' => 12_000_000]);

        try {
            app(SerialCostRemediationApplyService::class)->apply($plan, $approval, 'Vận hành B', 'backup:test-stale');
            $this->fail('Expected stale approved plan to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Precondition changed', $exception->getMessage());
        }

        $this->assertSame(12_000_000.0, (float) $item->fresh()->cost_price);
        $this->assertSame(14_111_257.0, (float) $serial->fresh()->sold_cost_price);
        $this->assertSame(0, ActivityLog::where('action', ActivityLog::ACTION_SERIAL_COST_REMEDIATION_APPLY)->count());
    }

    public function test_replay_does_not_hide_a_later_serial_snapshot_change(): void
    {
        [$product, $serial, $invoice, $item] = $this->mismatchedSale('REPLAY-SERIAL-GUARD');
        $plans = app(SerialCostRemediationPlanService::class);
        $plan = $plans->build($product->id);
        $approval = app(SerialCostRemediationApprovalService::class)->create(
            $plan,
            [$invoice->code],
            null,
            'Kế toán A',
            'KT-COGS-REPLAY-GUARD',
        );

        $apply = app(SerialCostRemediationApplyService::class);
        $apply->apply($plan, $approval, 'Vận hành B', 'backup:test-replay');
        $serial->update(['sold_cost_price' => 1]);

        try {
            $apply->apply($plan, $approval, 'Vận hành B', 'backup:test-replay');
            $this->fail('A changed serial snapshot must never be treated as a safe replay.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Precondition changed', $exception->getMessage());
        }

        $this->assertSame(8_029_022.0, (float) $item->fresh()->cost_price);
        $this->assertSame(1.0, (float) $serial->fresh()->sold_cost_price);
        $this->assertSame(1, ActivityLog::where('action', ActivityLog::ACTION_SERIAL_COST_REMEDIATION_APPLY)->count());
    }

    public function test_apply_rejects_an_approved_line_when_a_completed_return_appears_after_approval(): void
    {
        [$product, $serial, $invoice, $item] = $this->mismatchedSale('STALE-RETURN');
        $plans = app(SerialCostRemediationPlanService::class);
        $plan = $plans->build($product->id);
        $approval = app(SerialCostRemediationApprovalService::class)->create(
            $plan,
            [$invoice->code],
            null,
            'Kế toán A',
            'KT-COGS-STALE-RETURN',
        );
        $return = OrderReturn::create([
            'code' => 'TH-STALE-RETURN',
            'invoice_id' => $invoice->id,
            'status' => 'Đã trả',
            'subtotal' => 20_000_000,
            'total' => 20_000_000,
            'paid_to_customer' => 0,
        ]);
        ReturnItem::create([
            'return_id' => $return->id,
            'invoice_item_id' => $item->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 20_000_000,
            'cost_price' => 14_111_257,
            'serial_ids' => [$serial->id],
        ]);

        try {
            app(SerialCostRemediationApplyService::class)->apply($plan, $approval, 'Vận hành B', 'backup:test-return');
            $this->fail('A completed return must make the approved line stale.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Precondition changed', $exception->getMessage());
        }

        $this->assertSame(14_111_257.0, (float) $item->fresh()->cost_price);
        $this->assertSame(14_111_257.0, (float) $serial->fresh()->sold_cost_price);
        $this->assertSame(0, ActivityLog::where('action', ActivityLog::ACTION_SERIAL_COST_REMEDIATION_APPLY)->count());
    }

    public function test_approval_requires_a_scoped_small_batch(): void
    {
        [$product] = $this->mismatchedSale('APPROVAL-SCOPE');
        $plan = app(SerialCostRemediationPlanService::class)->build($product->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must select invoice codes or an explicit positive limit');

        app(SerialCostRemediationApprovalService::class)->create(
            $plan,
            [],
            null,
            'Kế toán A',
            'KT-COGS-SCOPE',
        );
    }

    public function test_apply_command_defaults_to_dry_run_and_rejects_an_unguarded_write(): void
    {
        [$product, $serial, $invoice, $item] = $this->mismatchedSale('COMMAND-GUARD');
        $plan = app(SerialCostRemediationPlanService::class)->build($product->id);
        $approval = app(SerialCostRemediationApprovalService::class)->create(
            $plan,
            [$invoice->code],
            null,
            'Kế toán A',
            'KT-COGS-COMMAND-GUARD',
        );
        $planPath = tempnam(sys_get_temp_dir(), 'serial-cost-plan-');
        $approvalPath = tempnam(sys_get_temp_dir(), 'serial-cost-approval-');

        try {
            file_put_contents($planPath, json_encode($plan, JSON_THROW_ON_ERROR));
            file_put_contents($approvalPath, json_encode($approval, JSON_THROW_ON_ERROR));

            $this->artisan('costing:apply-serial-remediation', [
                '--plan-json' => $planPath,
                '--approval-json' => $approvalPath,
            ])->assertExitCode(0);
            $this->assertSame(14_111_257.0, (float) $item->fresh()->cost_price);
            $this->assertSame(14_111_257.0, (float) $serial->fresh()->sold_cost_price);

            $this->artisan('costing:apply-serial-remediation', [
                '--plan-json' => $planPath,
                '--approval-json' => $approvalPath,
                '--apply' => true,
                '--operator' => 'Vận hành B',
            ])->assertExitCode(1);
            $this->assertSame(14_111_257.0, (float) $item->fresh()->cost_price);
            $this->assertSame(0, ActivityLog::where('action', ActivityLog::ACTION_SERIAL_COST_REMEDIATION_APPLY)->count());
        } finally {
            @unlink($planPath);
            @unlink($approvalPath);
        }
    }

    /** @return array{0:Product,1:SerialImei,2:Invoice,3:InvoiceItem,4:StockMovement} */
    private function mismatchedSale(string $suffix, $at = null): array
    {
        $at ??= now();
        $product = Product::create([
            'sku' => 'SP-REMEDIATION-'.$suffix.'-'.uniqid(),
            'name' => 'Sản phẩm kiểm thử giá vốn serial',
            'stock_quantity' => 0,
            'inventory_total_cost' => 0,
            'cost_price' => 0,
            'retail_price' => 20_000_000,
            'has_serial' => true,
            'is_active' => true,
        ]);
        $serial = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-REMEDIATION-'.$suffix.'-'.uniqid(),
            'status' => 'sold',
            'cost_price' => 8_029_022,
            'original_cost' => 8_029_022,
        ]);
        [$invoice, $item, $movement] = $this->sale($product, $serial, 14_111_257, 'HD-'.$suffix, $at);
        Task::create([
            'code' => 'SC-'.$suffix,
            'type' => Task::TYPE_REPAIR,
            'title' => 'Repair '.$suffix,
            'product_id' => $product->id,
            'serial_imei_id' => $serial->id,
            'original_cost' => 8_029_022,
            'parts_cost' => 0,
            'total_cost' => 8_029_022,
            'status' => Task::STATUS_COMPLETED,
            'completed_at' => $at->copy()->subHour(),
            'created_at' => $at->copy()->subHour(),
            'updated_at' => $at->copy()->subHour(),
        ]);

        return [$product, $serial, $invoice, $item, $movement];
    }

    /** @return array{0:Invoice,1:InvoiceItem,2:StockMovement} */
    private function sale(Product $product, SerialImei $serial, int $cost, string $suffix, $at): array
    {
        $invoice = Invoice::create([
            'code' => 'HD-REMEDIATION-'.$suffix.'-'.uniqid(),
            'status' => 'Hoàn thành',
            'subtotal' => 20_000_000,
            'total' => 20_000_000,
            'customer_paid' => 20_000_000,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 20_000_000,
            'cost_price' => $cost,
            'subtotal' => 20_000_000,
        ]);
        InvoiceItemSerial::create([
            'invoice_item_id' => $item->id,
            'serial_imei_id' => $serial->id,
            'serial_number' => $serial->serial_number,
            'cost_price' => $cost,
        ]);
        $serial->update([
            'status' => 'sold',
            'invoice_id' => $invoice->id,
            'sold_at' => $at,
            'sold_cost_price' => $cost,
        ]);
        $movement = StockMovement::create([
            'product_id' => $product->id,
            'type' => StockMovementService::TYPE_OUT_INVOICE,
            'direction' => 'out',
            'qty' => 1,
            'unit_cost' => $cost,
            'total_cost' => $cost,
            'balance_qty' => 0,
            'balance_cost' => 0,
            'ref_type' => Invoice::class,
            'ref_id' => $invoice->id,
            'ref_code' => $invoice->code,
            'moved_at' => $at,
        ]);

        return [$invoice, $item, $movement];
    }
}
