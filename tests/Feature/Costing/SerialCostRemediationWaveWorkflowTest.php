<?php

namespace Tests\Feature\Costing;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\Task;
use App\Services\SerialCostRemediationPlanService;
use App\Services\SerialCostRemediationWaveService;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialCostRemediationWaveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepare_wave_selects_lowest_risk_whole_invoices_and_splits_them_into_guarded_batches(): void
    {
        $fixtures = $this->mismatchedSales(27);
        $sharedInvoice = $fixtures[0]['item']->invoice()->firstOrFail();
        $fixtures[1]['item']->update(['invoice_id' => $sharedInvoice->id]);
        $fixtures[1]['serial']->update(['invoice_id' => $sharedInvoice->id]);
        $fixtures[1]['movement']->update([
            'ref_id' => $sharedInvoice->id,
            'ref_code' => $sharedInvoice->code,
        ]);
        $plan = app(SerialCostRemediationPlanService::class)->build();

        $wave = app(SerialCostRemediationWaveService::class)->prepare(
            $plan,
            26,
            'Codex QA delegated',
            'COGS-WAVE-TEST',
        );

        $selectedKeys = collect($wave['batches'])
            ->flatMap(fn (array $batch): array => $batch['line_keys'])
            ->sort()
            ->values();
        $expectedKeys = collect($fixtures)
            ->take(26)
            ->pluck('item')
            ->map(fn (InvoiceItem $item): string => 'invoice_item:'.$item->id)
            ->sort()
            ->values();

        $this->assertSame(SerialCostRemediationWaveService::CONTRACT_VERSION, $wave['contract_version']);
        $this->assertSame(SerialCostRemediationWaveService::SELECTION_STRATEGY, $wave['selection_strategy']);
        $this->assertSame(26, $wave['selected_lines']);
        $this->assertSame(25, $wave['selected_invoices']);
        $this->assertSame(26, $wave['selected_serials']);
        $this->assertCount(2, $wave['batches']);
        $this->assertSame([25, 1], collect($wave['batches'])->pluck('preview.lines_selected')->all());
        $this->assertSame($expectedKeys->all(), $selectedKeys->all());
        $sharedInvoiceKeys = collect($fixtures)->take(2)->pluck('item')
            ->map(fn (InvoiceItem $item): string => 'invoice_item:'.$item->id);
        $this->assertSame(1, collect($wave['batches'])->filter(
            fn (array $batch): bool => $sharedInvoiceKeys->diff($batch['line_keys'])->isEmpty(),
        )->count());
        $this->assertSame('COGS-WAVE-TEST-01', $wave['batches'][0]['approval']['approval_reference']);
        $this->assertSame('COGS-WAVE-TEST-02', $wave['batches'][1]['approval']['approval_reference']);
        $this->assertSame(-3_510_000, $wave['expected_movement_cogs_delta']);
        $this->assertSame(
            'APPLY-SERIAL-COGS-WAVE-'.substr($wave['wave_hash'], 0, 16),
            $wave['confirmation_code'],
        );

        $preview = app(SerialCostRemediationWaveService::class)->preview($plan, $wave);
        $this->assertSame('dry-run', $preview['mode']);
        $this->assertSame('NO', $preview['database_mutation']);
        $this->assertSame(26, $preview['selected_lines']);
    }

    public function test_wave_applies_two_independent_batches_and_replays_without_duplicate_logs(): void
    {
        $fixtures = $this->mismatchedSales(26);
        $plan = app(SerialCostRemediationPlanService::class)->build();
        $waves = app(SerialCostRemediationWaveService::class);
        $wave = $waves->prepare($plan, 26, 'Codex QA delegated', 'COGS-WAVE-APPLY');

        $result = $waves->applyWave($plan, $wave, 'Production operator', 'backup:wave-apply');

        $this->assertSame('APPLIED', $result['result']);
        $this->assertSame('YES', $result['database_mutation']);
        $this->assertSame(2, $result['batches_completed']);
        $this->assertSame(26, $result['lines_changed']);
        $this->assertSame(26, $result['invoice_item_serials_updated']);
        $this->assertSame(26, $result['serial_sold_snapshots_updated']);
        $this->assertSame(26, $result['stock_movements_updated']);
        $this->assertSame(-3_510_000, $result['movement_cogs_delta_applied_this_run']);
        $this->assertSame(26, ActivityLog::where('action', ActivityLog::ACTION_SERIAL_COST_REMEDIATION_APPLY)->count());

        foreach ($fixtures as $fixture) {
            $this->assertSame((float) $fixture['expected_cost'], (float) $fixture['item']->fresh()->cost_price);
            $this->assertSame((float) $fixture['expected_cost'], (float) $fixture['serial']->fresh()->sold_cost_price);
            $this->assertSame((float) $fixture['expected_cost'], (float) $fixture['movement']->fresh()->unit_cost);
        }

        $replay = $waves->applyWave($plan, $wave, 'Production operator', 'backup:wave-apply');

        $this->assertSame('REPLAY', $replay['result']);
        $this->assertSame('NO', $replay['database_mutation']);
        $this->assertSame(0, $replay['lines_changed']);
        $this->assertSame(26, $replay['replayed_lines']);
        $this->assertSame(26, ActivityLog::where('action', ActivityLog::ACTION_SERIAL_COST_REMEDIATION_APPLY)->count());
    }

    public function test_wave_stops_after_the_first_committed_batch_when_the_next_batch_becomes_stale(): void
    {
        $fixtures = $this->mismatchedSales(26);
        $plan = app(SerialCostRemediationPlanService::class)->build();
        $waves = app(SerialCostRemediationWaveService::class);
        $wave = $waves->prepare($plan, 26, 'Codex QA delegated', 'COGS-WAVE-PARTIAL');
        $staleLineKey = $wave['batches'][1]['line_keys'][0];
        $staleItemId = (int) str_replace('invoice_item:', '', $staleLineKey);
        InvoiceItem::query()->findOrFail($staleItemId)->update(['cost_price' => 1]);

        $result = $waves->applyWave($plan, $wave, 'Production operator', 'backup:wave-partial');

        $this->assertSame('PARTIAL_FAILURE', $result['result']);
        $this->assertSame('YES_PARTIAL', $result['database_mutation']);
        $this->assertSame(1, $result['batches_completed']);
        $this->assertSame(2, $result['failed_batch']);
        $this->assertSame(25, $result['lines_changed']);
        $this->assertStringContainsString('Precondition changed', $result['error']);
        $this->assertSame(25, ActivityLog::where('action', ActivityLog::ACTION_SERIAL_COST_REMEDIATION_APPLY)->count());
        $this->assertSame(1.0, (float) InvoiceItem::query()->findOrFail($staleItemId)->cost_price);

        $firstAppliedItemId = (int) str_replace('invoice_item:', '', $wave['batches'][0]['line_keys'][0]);
        $firstFixture = collect($fixtures)->first(
            fn (array $fixture): bool => $fixture['item']->id === $firstAppliedItemId,
        );
        $this->assertIsArray($firstFixture);
        $this->assertSame(
            (float) $firstFixture['expected_cost'],
            (float) InvoiceItem::query()->findOrFail($firstAppliedItemId)->cost_price,
        );
    }

    public function test_wave_command_defaults_to_preview_and_requires_apply_guards(): void
    {
        $fixtures = $this->mismatchedSales(1);
        $plan = app(SerialCostRemediationPlanService::class)->build();
        $wave = app(SerialCostRemediationWaveService::class)->prepare(
            $plan,
            1,
            'Codex QA delegated',
            'COGS-WAVE-COMMAND',
        );
        $planPath = tempnam(sys_get_temp_dir(), 'serial-cost-wave-plan-');
        $wavePath = tempnam(sys_get_temp_dir(), 'serial-cost-wave-');

        try {
            file_put_contents($planPath, json_encode($plan, JSON_THROW_ON_ERROR));
            file_put_contents($wavePath, json_encode($wave, JSON_THROW_ON_ERROR));

            $this->artisan('costing:apply-serial-remediation-wave', [
                '--plan-json' => $planPath,
                '--wave-json' => $wavePath,
            ])->assertExitCode(0);
            $this->assertSame((float) $fixtures[0]['before_cost'], (float) $fixtures[0]['item']->fresh()->cost_price);

            $this->artisan('costing:apply-serial-remediation-wave', [
                '--plan-json' => $planPath,
                '--wave-json' => $wavePath,
                '--apply' => true,
                '--operator' => 'Production operator',
                '--confirm-wave-hash' => $wave['confirmation_code'],
            ])->assertExitCode(1);
            $this->assertSame((float) $fixtures[0]['before_cost'], (float) $fixtures[0]['item']->fresh()->cost_price);
            $this->assertSame(0, ActivityLog::where('action', ActivityLog::ACTION_SERIAL_COST_REMEDIATION_APPLY)->count());

            $arguments = [
                '--plan-json' => $planPath,
                '--wave-json' => $wavePath,
                '--apply' => true,
                '--operator' => 'Production operator',
                '--backup-confirmed' => true,
                '--backup-reference' => 'backup:wave-command',
                '--confirm-wave-hash' => $wave['confirmation_code'],
            ];
            $this->artisan('costing:apply-serial-remediation-wave', $arguments)->assertExitCode(0);
            $this->assertSame((float) $fixtures[0]['expected_cost'], (float) $fixtures[0]['item']->fresh()->cost_price);
            $this->assertSame(1, ActivityLog::where('action', ActivityLog::ACTION_SERIAL_COST_REMEDIATION_APPLY)->count());

            $this->artisan('costing:apply-serial-remediation-wave', $arguments)->assertExitCode(0);
            $this->assertSame(1, ActivityLog::where('action', ActivityLog::ACTION_SERIAL_COST_REMEDIATION_APPLY)->count());
        } finally {
            @unlink($planPath);
            @unlink($wavePath);
        }
    }

    /** @return array<int, array{item:InvoiceItem,serial:SerialImei,movement:StockMovement,before_cost:int,expected_cost:int}> */
    private function mismatchedSales(int $count): array
    {
        $fixtures = [];
        $saleAt = now();

        foreach (range(1, $count) as $index) {
            $expectedCost = 5_000_000;
            $beforeCost = $expectedCost + ($index * 10_000);
            $suffix = str_pad((string) $index, 3, '0', STR_PAD_LEFT).'-'.uniqid();
            $product = Product::create([
                'sku' => 'SP-WAVE-'.$suffix,
                'name' => 'Sản phẩm kiểm thử wave '.$suffix,
                'stock_quantity' => 0,
                'inventory_total_cost' => 0,
                'cost_price' => 0,
                'retail_price' => 10_000_000,
                'has_serial' => true,
                'is_active' => true,
            ]);
            $invoice = Invoice::create([
                'code' => 'HD-WAVE-'.$suffix,
                'status' => 'Hoàn thành',
                'subtotal' => 10_000_000,
                'total' => 10_000_000,
                'customer_paid' => 10_000_000,
                'created_at' => $saleAt,
                'updated_at' => $saleAt,
            ]);
            $item = InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 10_000_000,
                'cost_price' => $beforeCost,
                'subtotal' => 10_000_000,
            ]);
            $serial = SerialImei::create([
                'product_id' => $product->id,
                'serial_number' => 'SN-WAVE-'.$suffix,
                'status' => 'sold',
                'cost_price' => $expectedCost,
                'original_cost' => $expectedCost,
                'invoice_id' => $invoice->id,
                'sold_at' => $saleAt,
                'sold_cost_price' => $beforeCost,
            ]);
            InvoiceItemSerial::create([
                'invoice_item_id' => $item->id,
                'serial_imei_id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'cost_price' => $beforeCost,
            ]);
            $movement = StockMovement::create([
                'product_id' => $product->id,
                'type' => StockMovementService::TYPE_OUT_INVOICE,
                'direction' => 'out',
                'qty' => 1,
                'unit_cost' => $beforeCost,
                'total_cost' => $beforeCost,
                'balance_qty' => 0,
                'balance_cost' => 0,
                'ref_type' => Invoice::class,
                'ref_id' => $invoice->id,
                'ref_code' => $invoice->code,
                'moved_at' => $saleAt,
            ]);
            Task::create([
                'code' => 'SC-WAVE-'.$suffix,
                'type' => Task::TYPE_REPAIR,
                'title' => 'Repair wave '.$suffix,
                'product_id' => $product->id,
                'serial_imei_id' => $serial->id,
                'original_cost' => $expectedCost,
                'parts_cost' => 0,
                'total_cost' => $expectedCost,
                'status' => Task::STATUS_COMPLETED,
                'completed_at' => $saleAt->copy()->subHour(),
                'created_at' => $saleAt->copy()->subHour(),
                'updated_at' => $saleAt->copy()->subHour(),
            ]);

            $fixtures[] = [
                'item' => $item,
                'serial' => $serial,
                'movement' => $movement,
                'before_cost' => $beforeCost,
                'expected_cost' => $expectedCost,
            ];
        }

        return $fixtures;
    }
}
