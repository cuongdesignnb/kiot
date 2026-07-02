<?php

namespace Tests\Feature\Costing;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\Product;
use App\Models\SerialImei;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HOTFIX2436SerialMovingAvgCostingSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_dry_run_by_default_and_apply_is_required(): void
    {
        $product = $this->serialProduct(['stock_quantity' => 1, 'cost_price' => 17_395_000, 'inventory_total_cost' => 17_395_000]);
        $this->serial($product, ['status' => 'in_stock', 'cost_price' => 4_400_000]);

        $this->artisan('costing:rebuild-moving-avg', ['--product' => $product->sku])
            ->expectsOutputToContain('[DRY-RUN]')
            ->assertExitCode(0);

        $product->refresh();
        $this->assertSame(17_395_000.0, (float) $product->cost_price);

        $this->artisan('costing:rebuild-moving-avg', ['--product' => $product->sku, '--dry-run' => true, '--apply' => true])
            ->assertExitCode(1);
    }

    public function test_cancelled_invoice_is_ignored_and_duplicate_cancelled_link_is_warning_only(): void
    {
        $product = $this->serialProduct();
        $sold = $this->serial($product, ['serial_number' => 'SER-SOLD', 'status' => 'sold', 'cost_price' => 4_400_000]);
        $this->serial($product, ['serial_number' => 'SER-STOCK', 'status' => 'in_stock', 'cost_price' => 4_400_000]);

        [$completedInvoice, $completedItem] = $this->invoiceWithItem($product, 'Hoàn thành', 1, 17_395_000);
        $sold->update(['invoice_id' => $completedInvoice->id, 'sold_at' => now(), 'sold_cost_price' => 17_395_000]);
        InvoiceItemSerial::create([
            'invoice_item_id' => $completedItem->id,
            'serial_imei_id' => $sold->id,
            'serial_number' => $sold->serial_number,
            'cost_price' => 17_395_000,
        ]);

        [, $cancelledItem] = $this->invoiceWithItem($product, 'Đã hủy', 1, 4_348_750);
        InvoiceItemSerial::create([
            'invoice_item_id' => $cancelledItem->id,
            'serial_imei_id' => $sold->id,
            'serial_number' => $sold->serial_number,
            'cost_price' => 4_348_750,
        ]);

        $this->artisan('costing:rebuild-moving-avg', ['--product' => $product->sku, '--dry-run' => true])
            ->expectsOutputToContain('Cleanup candidate')
            ->assertExitCode(0);
    }

    public function test_duplicate_serial_linked_to_two_completed_invoices_hard_fails_and_writes_nothing(): void
    {
        $product = $this->serialProduct();
        $serial = $this->serial($product, ['status' => 'sold', 'cost_price' => 4_400_000]);

        [$invoiceA, $itemA] = $this->invoiceWithItem($product, 'Hoàn thành', 1, 0);
        [$invoiceB, $itemB] = $this->invoiceWithItem($product, 'completed', 1, 0);
        $serial->update(['invoice_id' => $invoiceA->id, 'sold_at' => now()]);

        foreach ([$itemA, $itemB] as $item) {
            InvoiceItemSerial::create([
                'invoice_item_id' => $item->id,
                'serial_imei_id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'cost_price' => 0,
            ]);
        }

        $this->artisan('costing:rebuild-moving-avg', ['--product' => $product->sku, '--apply' => true])
            ->expectsOutputToContain('HARD ERROR')
            ->assertExitCode(1);

        $this->assertSame(0.0, (float) $itemA->fresh()->cost_price);
        $this->assertSame(0.0, (float) $itemB->fresh()->cost_price);
    }

    public function test_serial_product_final_aggregate_uses_in_stock_serials(): void
    {
        $product = $this->serialProduct(['stock_quantity' => 1, 'cost_price' => 17_395_000, 'inventory_total_cost' => 17_395_000]);
        $this->serial($product, ['status' => 'in_stock', 'cost_price' => 4_400_000]);

        $this->artisan('costing:rebuild-moving-avg', ['--product' => $product->sku, '--apply' => true])
            ->assertExitCode(0);

        $product->refresh();
        $this->assertSame(1, (int) $product->stock_quantity);
        $this->assertSame(4_400_000.0, (float) $product->inventory_total_cost);
        $this->assertSame(4_400_000.0, (float) $product->cost_price);
    }

    public function test_serial_sale_costs_update_item_link_and_serial_snapshots(): void
    {
        $product = $this->serialProduct();
        $serialA = $this->serial($product, ['status' => 'sold', 'cost_price' => 4_400_000]);
        $serialB = $this->serial($product, ['status' => 'sold', 'cost_price' => 3_990_000]);
        [$invoice, $item] = $this->invoiceWithItem($product, 'completed', 2, 0);

        foreach ([$serialA, $serialB] as $serial) {
            $serial->update(['invoice_id' => $invoice->id, 'sold_at' => now(), 'sold_cost_price' => 0]);
            InvoiceItemSerial::create([
                'invoice_item_id' => $item->id,
                'serial_imei_id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'cost_price' => 0,
            ]);
        }

        $this->artisan('costing:rebuild-moving-avg', ['--product' => $product->sku, '--apply' => true])
            ->expectsOutputToContain('COGS diff preview')
            ->assertExitCode(0);

        $this->assertSame(4_195_000.0, (float) $item->fresh()->cost_price);
        $this->assertSame(4_400_000.0, (float) $serialA->fresh()->sold_cost_price);
        $this->assertSame(3_990_000.0, (float) $serialB->fresh()->sold_cost_price);
        $this->assertSame(4_400_000.0, (float) InvoiceItemSerial::where('serial_imei_id', $serialA->id)->first()->cost_price);
    }

    public function test_missing_serial_link_falls_back_to_serial_invoice_id(): void
    {
        $product = $this->serialProduct();
        $serial = $this->serial($product, ['status' => 'sold', 'cost_price' => 4_400_000]);
        [$invoice, $item] = $this->invoiceWithItem($product, 'completed', 1, 0);
        $serial->update(['invoice_id' => $invoice->id, 'sold_at' => now(), 'sold_cost_price' => 0]);

        $this->artisan('costing:rebuild-moving-avg', ['--product' => $product->sku, '--apply' => true])
            ->assertExitCode(0);

        $this->assertSame(4_400_000.0, (float) $item->fresh()->cost_price);
        $this->assertSame(4_400_000.0, (float) $serial->fresh()->sold_cost_price);
    }

    public function test_missing_serial_really_hard_fails(): void
    {
        $product = $this->serialProduct();
        $this->invoiceWithItem($product, 'completed', 1, 0);

        $this->artisan('costing:rebuild-moving-avg', ['--product' => $product->sku, '--apply' => true])
            ->expectsOutputToContain('quantity mismatch')
            ->assertExitCode(1);
    }

    public function test_non_serial_product_keeps_moving_average_behavior(): void
    {
        $product = Product::create([
            'sku' => 'NON-SERIAL-' . uniqid(),
            'name' => 'Non serial',
            'stock_quantity' => 0,
            'cost_price' => 0,
            'inventory_total_cost' => 0,
            'retail_price' => 200_000,
            'has_serial' => false,
        ]);

        $purchaseId = DB::table('purchases')->insertGetId([
            'code' => 'PN-' . uniqid(),
            'status' => 'completed',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        DB::table('purchase_items')->insert([
            'purchase_id' => $purchaseId,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->sku,
            'quantity' => 10,
            'price' => 100_000,
            'subtotal' => 1_000_000,
            'unit_cost_allocated' => 100_000,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        [, $item] = $this->invoiceWithItem($product, 'completed', 3, 0);

        $this->artisan('costing:rebuild-moving-avg', ['--product' => $product->sku, '--apply' => true])
            ->assertExitCode(0);

        $product->refresh();
        $this->assertSame(7, (int) $product->stock_quantity);
        $this->assertSame(700_000.0, (float) $product->inventory_total_cost);
        $this->assertSame(100_000.0, (float) $product->cost_price);
        $this->assertSame(100_000.0, (float) $item->fresh()->cost_price);
    }

    public function test_cleanup_cancelled_links_is_idempotent(): void
    {
        $product = $this->serialProduct();
        $serial = $this->serial($product, ['status' => 'sold', 'cost_price' => 4_400_000]);
        [$completedInvoice, $completedItem] = $this->invoiceWithItem($product, 'completed', 1, 0);
        [, $cancelledItem] = $this->invoiceWithItem($product, 'Đã hủy', 1, 0);
        $serial->update(['invoice_id' => $completedInvoice->id, 'sold_at' => now()]);

        foreach ([$completedItem, $cancelledItem] as $item) {
            InvoiceItemSerial::create([
                'invoice_item_id' => $item->id,
                'serial_imei_id' => $serial->id,
                'serial_number' => $serial->serial_number,
                'cost_price' => 0,
            ]);
        }

        $this->artisan('serials:cleanup-cancelled-invoice-links', ['--product' => $product->sku, '--apply' => true])
            ->expectsOutputToContain('Deleted links: 1')
            ->assertExitCode(0);

        $this->artisan('serials:cleanup-cancelled-invoice-links', ['--product' => $product->sku, '--apply' => true])
            ->expectsOutputToContain('Deleted links: 0')
            ->assertExitCode(0);
    }

    private function serialProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'sku' => 'SERIAL-' . uniqid(),
            'name' => 'Serial product',
            'stock_quantity' => 0,
            'cost_price' => 0,
            'inventory_total_cost' => 0,
            'retail_price' => 5_000_000,
            'has_serial' => true,
        ], $overrides));
    }

    private function serial(Product $product, array $overrides = []): SerialImei
    {
        return SerialImei::create(array_merge([
            'product_id' => $product->id,
            'serial_number' => 'SN-' . uniqid(),
            'status' => 'in_stock',
            'cost_price' => 4_400_000,
            'original_cost' => 4_400_000,
        ], $overrides));
    }

    private function invoiceWithItem(Product $product, string $status, int $quantity, float $costPrice): array
    {
        $invoice = Invoice::create([
            'code' => 'HD-' . uniqid(),
            'status' => $status,
            'subtotal' => $quantity * 5_000_000,
            'total' => $quantity * 5_000_000,
            'customer_paid' => $quantity * 5_000_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => 5_000_000,
            'cost_price' => $costPrice,
            'subtotal' => $quantity * 5_000_000,
        ]);

        return [$invoice, $item];
    }
}
