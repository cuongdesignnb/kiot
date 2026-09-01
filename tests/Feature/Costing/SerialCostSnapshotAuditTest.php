<?php

namespace Tests\Feature\Costing;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\Task;
use App\Services\SerialCostSnapshotAuditService;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerialCostSnapshotAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_proves_repair_backed_cost_mismatch_across_all_sale_snapshots(): void
    {
        $product = $this->serialProduct();
        $serial = $this->serial($product, 'DB639W3', 8_029_022);
        [$invoice, $item] = $this->sale($product, $serial, 14_111_257, 'HD-AUDIT-5440');
        $this->completedRepair($product, $serial, 8_029_022, 'SC-AUDIT-1037', now()->subHour());
        $movement = $this->outgoingMovement($product, $invoice, 14_111_257);

        $row = app(SerialCostSnapshotAuditService::class)->inspect($product->id)->sole();

        $this->assertSame(SerialCostSnapshotAuditService::REPAIR_COST_MISMATCH, $row['classification']);
        $this->assertSame('SC-AUDIT-1037', $row['repair_task_code']);
        $this->assertSame(8_029_022.0, $row['expected_cost']);
        $this->assertSame(8_029_022.0, $row['expected_invoice_item_cost']);
        $this->assertSame(14_111_257.0, $row['stock_movement_cost']);
        $this->assertTrue($row['financial_impact']);
        $this->assertSame('document_and_cogs', $row['impact_scope']);
        $this->assertSame(['invoice_item', 'stock_movement'], $row['financial_mismatch_fields']);
        $this->assertSame($movement->id, $row['stock_movement_id']);
        $this->assertSame([
            'invoice_item_serial',
            'serial_sold_cost',
            'invoice_item',
            'stock_movement',
        ], $row['mismatch_types']);

        $this->artisan('costing:audit-serial-snapshots', [
            '--product' => $product->sku,
            '--limit' => 0,
        ])
            ->expectsOutputToContain('Confirmed repair snapshot mismatches: 1')
            ->expectsOutputToContain('Invoice lines with unprotected document/COGS mismatch: 1')
            ->expectsOutputToContain('HD-AUDIT-5440')
            ->assertExitCode(1);

        $this->assertSame(14_111_257.0, (float) $item->fresh()->cost_price);
        $this->assertSame(14_111_257.0, (float) InvoiceItemSerial::where('invoice_item_id', $item->id)->value('cost_price'));
        $this->assertSame(14_111_257.0, (float) $serial->fresh()->sold_cost_price);
    }

    public function test_audit_accepts_matching_repair_snapshot_without_writing_anything(): void
    {
        $product = $this->serialProduct();
        $serial = $this->serial($product, 'MATCHED-REPAIR', 5_185_218);
        [$invoice, $item] = $this->sale($product, $serial, 5_185_218, 'HD-AUDIT-MATCH');
        $this->completedRepair($product, $serial, 5_185_218, 'SC-AUDIT-MATCH', now()->subHour());
        $this->outgoingMovement($product, $invoice, 5_185_218);

        $row = app(SerialCostSnapshotAuditService::class)->inspect($product->id)->sole();

        $this->assertSame(SerialCostSnapshotAuditService::VERIFIED_REPAIR_SNAPSHOT, $row['classification']);
        $this->assertSame([], $row['mismatch_types']);
        $this->assertFalse($row['financial_impact']);

        $this->artisan('costing:audit-serial-snapshots', ['--product' => $product->sku])
            ->expectsOutputToContain('Confirmed repair snapshot mismatches: 0')
            ->assertExitCode(0);

        $this->assertSame(5_185_218.0, (float) $item->fresh()->cost_price);
    }

    public function test_audit_marks_a_resale_mismatch_as_protected_and_never_as_auto_repairable(): void
    {
        $product = $this->serialProduct();
        $serial = $this->serial($product, 'RESALE-PROTECTED', 8_029_022);
        [$firstInvoice] = $this->sale($product, $serial, 14_111_257, 'HD-AUDIT-RESALE-1', now()->subDays(2));
        $this->completedRepair($product, $serial, 8_029_022, 'SC-AUDIT-RESALE', now()->subDays(3));

        // This mirrors a legitimate historical resale: the current serial
        // snapshot belongs to the latest sale, while the first sale remains
        // protected from automatic historical correction.
        [$secondInvoice] = $this->sale($product, $serial, 8_029_022, 'HD-AUDIT-RESALE-2', now()->subDay());
        $serial->update([
            'invoice_id' => $secondInvoice->id,
            'sold_at' => now()->subDay(),
            'sold_cost_price' => 8_029_022,
        ]);

        $firstRow = app(SerialCostSnapshotAuditService::class)->inspect($product->id)
            ->firstWhere('invoice_id', $firstInvoice->id);

        $this->assertSame(SerialCostSnapshotAuditService::REPAIR_COST_MISMATCH_PROTECTED_RESALE, $firstRow['classification']);
        $this->assertTrue($firstRow['resale_protected']);
        $this->assertTrue($firstRow['line_resale_protected']);
        $this->assertFalse($firstRow['serial_snapshot_comparable']);
        $this->assertTrue($firstRow['financial_impact']);
        $this->assertSame('protected_historical_financial', $firstRow['impact_scope']);
        $this->assertContains('invoice_item_serial', $firstRow['mismatch_types']);
    }

    public function test_audit_does_not_infer_cost_when_there_is_no_completed_repair_evidence(): void
    {
        $product = $this->serialProduct();
        $serial = $this->serial($product, 'NO-INFERENCE', 9_999_999);
        $this->sale($product, $serial, 5_000_000, 'HD-AUDIT-NO-SOURCE');

        $row = app(SerialCostSnapshotAuditService::class)->inspect($product->id)->sole();

        $this->assertSame(SerialCostSnapshotAuditService::NO_INDEPENDENT_COST_EVIDENCE, $row['classification']);
        $this->assertNull($row['expected_cost']);
    }

    public function test_audit_separates_serial_snapshot_only_difference_from_document_cogs_difference(): void
    {
        $product = $this->serialProduct();
        $firstSerial = $this->serial($product, 'SERIAL-AVERAGE-A', 5_000_000);
        $secondSerial = $this->serial($product, 'SERIAL-AVERAGE-B', 7_000_000);
        $at = now();
        $invoice = Invoice::create([
            'code' => 'HD-AUDIT-SERIAL-ONLY',
            'status' => 'Hoàn thành',
            'subtotal' => 40_000_000,
            'total' => 40_000_000,
            'customer_paid' => 40_000_000,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 20_000_000,
            'cost_price' => 6_000_000,
            'subtotal' => 40_000_000,
        ]);

        foreach ([$firstSerial, $secondSerial] as $serial) {
            InvoiceItemSerial::create([
                'invoice_item_id' => $item->id,
                'serial_imei_id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'cost_price' => 6_000_000,
            ]);
            $serial->update([
                'invoice_id' => $invoice->id,
                'sold_at' => $at,
                'sold_cost_price' => 6_000_000,
            ]);
        }
        $this->completedRepair($product, $firstSerial, 5_000_000, 'SC-AUDIT-AVERAGE-A', now()->subHour());
        $this->completedRepair($product, $secondSerial, 7_000_000, 'SC-AUDIT-AVERAGE-B', now()->subHour());
        StockMovement::create([
            'product_id' => $product->id,
            'type' => StockMovementService::TYPE_OUT_INVOICE,
            'direction' => 'out',
            'qty' => 2,
            'unit_cost' => 6_000_000,
            'total_cost' => 12_000_000,
            'balance_qty' => 0,
            'balance_cost' => 0,
            'ref_type' => Invoice::class,
            'ref_id' => $invoice->id,
            'ref_code' => $invoice->code,
            'moved_at' => $at,
        ]);

        $rows = app(SerialCostSnapshotAuditService::class)->inspect($product->id);

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn (array $row) => $row['classification'] === SerialCostSnapshotAuditService::REPAIR_COST_MISMATCH));
        $this->assertTrue($rows->every(fn (array $row) => $row['financial_impact'] === false));
        $this->assertTrue($rows->every(fn (array $row) => $row['line_resale_protected'] === false));
        $this->assertTrue($rows->every(fn (array $row) => $row['impact_scope'] === 'serial_snapshot_only'));
        $this->assertTrue($rows->every(fn (array $row) => ! in_array('invoice_item', $row['mismatch_types'], true)));
        $this->assertTrue($rows->every(fn (array $row) => ! in_array('stock_movement', $row['mismatch_types'], true)));
    }

    public function test_audit_protects_an_entire_invoice_line_when_any_serial_has_resale_history(): void
    {
        $product = $this->serialProduct();
        $resoldSerial = $this->serial($product, 'LINE-PROTECTED-A', 5_000_000);
        $otherSerial = $this->serial($product, 'LINE-PROTECTED-B', 5_000_000);
        $this->completedRepair($product, $resoldSerial, 5_000_000, 'SC-LINE-PROTECTED-A', now()->subDays(3));
        $this->completedRepair($product, $otherSerial, 5_000_000, 'SC-LINE-PROTECTED-B', now()->subDays(3));
        $this->sale($product, $resoldSerial, 5_000_000, 'HD-AUDIT-PRIOR-SALE', now()->subDays(2));

        $at = now();
        $invoice = Invoice::create([
            'code' => 'HD-AUDIT-LINE-PROTECTED',
            'status' => 'Hoàn thành',
            'subtotal' => 40_000_000,
            'total' => 40_000_000,
            'customer_paid' => 40_000_000,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 20_000_000,
            'cost_price' => 6_000_000,
            'subtotal' => 40_000_000,
        ]);
        foreach ([$resoldSerial, $otherSerial] as $serial) {
            InvoiceItemSerial::create([
                'invoice_item_id' => $item->id,
                'serial_imei_id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'cost_price' => 6_000_000,
            ]);
            $serial->update([
                'invoice_id' => $invoice->id,
                'sold_at' => $at,
                'sold_cost_price' => 6_000_000,
            ]);
        }
        StockMovement::create([
            'product_id' => $product->id,
            'type' => StockMovementService::TYPE_OUT_INVOICE,
            'direction' => 'out',
            'qty' => 2,
            'unit_cost' => 6_000_000,
            'total_cost' => 12_000_000,
            'balance_qty' => 0,
            'balance_cost' => 0,
            'ref_type' => Invoice::class,
            'ref_id' => $invoice->id,
            'ref_code' => $invoice->code,
            'moved_at' => $at,
        ]);

        $targetRows = app(SerialCostSnapshotAuditService::class)->inspect($product->id)
            ->where('invoice_id', $invoice->id);

        $this->assertCount(2, $targetRows);
        $this->assertTrue($targetRows->every(fn (array $row) => $row['financial_impact']));
        $this->assertTrue($targetRows->every(fn (array $row) => $row['line_resale_protected']));
        $this->assertTrue($targetRows->every(fn (array $row) => $row['impact_scope'] === 'protected_historical_financial'));
    }

    private function serialProduct(): Product
    {
        return Product::create([
            'sku' => 'SP-AUDIT-'.uniqid(),
            'name' => 'Sản phẩm audit giá vốn serial',
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
            'status' => 'sold',
            'cost_price' => $cost,
            'original_cost' => $cost,
        ]);
    }

    /** @return array{0: Invoice, 1: InvoiceItem} */
    private function sale(Product $product, SerialImei $serial, int $storedCost, string $code, $at = null): array
    {
        $at ??= now();
        $invoice = Invoice::create([
            'code' => $code,
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
            'cost_price' => $storedCost,
            'subtotal' => 20_000_000,
        ]);
        InvoiceItemSerial::create([
            'invoice_item_id' => $item->id,
            'serial_imei_id' => $serial->id,
            'serial_number' => $serial->serial_number,
            'cost_price' => $storedCost,
        ]);
        $serial->update([
            'invoice_id' => $invoice->id,
            'sold_at' => $at,
            'sold_cost_price' => $storedCost,
        ]);

        return [$invoice, $item];
    }

    private function completedRepair(Product $product, SerialImei $serial, int $totalCost, string $code, $completedAt): Task
    {
        return Task::create([
            'code' => $code,
            'type' => Task::TYPE_REPAIR,
            'title' => $code,
            'product_id' => $product->id,
            'serial_imei_id' => $serial->id,
            'original_cost' => $totalCost,
            'parts_cost' => 0,
            'total_cost' => $totalCost,
            'status' => Task::STATUS_COMPLETED,
            'completed_at' => $completedAt,
            'created_at' => $completedAt,
            'updated_at' => $completedAt,
        ]);
    }

    private function outgoingMovement(Product $product, Invoice $invoice, int $cost): StockMovement
    {
        return StockMovement::create([
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
            'moved_at' => $invoice->created_at,
        ]);
    }
}
