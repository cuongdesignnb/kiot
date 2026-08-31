<?php

namespace Tests\Feature\Costing;

use App\Models\Customer;
use App\Models\InvoiceItemSerial;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Services\InvoiceSaleService;
use App\Services\MovingAvgCostingService;
use App\Services\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P0SerialCostSnapshotParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_serial_sale_uses_selected_serial_cost_and_rebuilds_product_projection(): void
    {
        $product = $this->serialProduct([
            // Deliberately corrupt product projection: the selected serial must
            // still carry its own cost into COGS and the stock card.
            'stock_quantity' => 2,
            'inventory_total_cost' => 25_414_058,
            'cost_price' => 25_414_058,
        ]);
        $selected = $this->serial($product, 'SER-P0-SELECTED', 5_185_218);
        $remaining = $this->serial($product, 'SER-P0-REMAINING', 8_029_022);
        $customer = Customer::create([
            'code' => 'KH-P0-COST-'.uniqid(),
            'name' => 'Khách P0 giá vốn',
            'phone' => '09'.random_int(10000000, 99999999),
            'is_customer' => true,
            'debt_amount' => 0,
            'total_spent' => 0,
        ]);

        $invoice = app(InvoiceSaleService::class)->createSale([
            'customer_id' => $customer->id,
            'subtotal' => 9_000_000,
            'discount' => 0,
            'total' => 9_000_000,
            'customer_paid' => 9_000_000,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 9_000_000,
                'discount' => 0,
                'serial_ids' => [$selected->id],
            ]],
        ], [
            'allow_oversell' => false,
            'default_status' => 'Hoàn thành',
            'code_prefix' => 'HD-P0-COST-',
        ]);

        $item = $invoice->items()->sole();
        $link = InvoiceItemSerial::where('invoice_item_id', $item->id)->sole();
        $movement = StockMovement::query()
            ->where('ref_type', get_class($invoice))
            ->where('ref_id', $invoice->id)
            ->where('type', StockMovementService::TYPE_OUT_INVOICE)
            ->sole();

        $this->assertSame(5_185_218.0, (float) $item->cost_price);
        $this->assertSame(5_185_218.0, (float) $link->cost_price);
        $this->assertSame(5_185_218.0, (float) $selected->fresh()->sold_cost_price);
        $this->assertSame(5_185_218.0, (float) $movement->unit_cost);

        $product->refresh();
        $this->assertSame(1, (int) $product->stock_quantity);
        $this->assertSame(8_029_022.0, (float) $product->inventory_total_cost);
        $this->assertSame(8_029_022.0, (float) $product->cost_price);
        $this->assertSame('in_stock', $remaining->fresh()->status);
    }

    public function test_recompute_from_serials_replaces_a_stale_aggregate_with_in_stock_serial_totals(): void
    {
        $product = $this->serialProduct([
            'stock_quantity' => 99,
            'inventory_total_cost' => 99_999_999,
            'cost_price' => 99_999_999,
        ]);
        $this->serial($product, 'SER-P0-A', 7_775_225);
        $this->serial($product, 'SER-P0-B', 253_797);
        $this->serial($product, 'SER-P0-SOLD', 14_111_257, 'sold');

        $product->recomputeFromSerials();
        $product->refresh();

        $this->assertSame(2, (int) $product->stock_quantity);
        $this->assertSame(8_029_022.0, (float) $product->inventory_total_cost);
        $this->assertSame(4_014_511.0, (float) $product->cost_price);
    }

    public function test_multi_serial_sale_keeps_exact_cost_per_serial_and_average_on_invoice_line(): void
    {
        $product = $this->serialProduct([
            'stock_quantity' => 3,
            'inventory_total_cost' => 75_000_000,
            'cost_price' => 25_000_000,
        ]);
        $first = $this->serial($product, 'SER-P0-MULTI-A', 5_185_218);
        $second = $this->serial($product, 'SER-P0-MULTI-B', 8_029_022);
        $remaining = $this->serial($product, 'SER-P0-MULTI-C', 7_775_225);
        $customer = Customer::create([
            'code' => 'KH-P0-MULTI-'.uniqid(),
            'name' => 'Khách P0 nhiều serial',
            'phone' => '09'.random_int(10000000, 99999999),
            'is_customer' => true,
            'debt_amount' => 0,
            'total_spent' => 0,
        ]);

        $invoice = app(InvoiceSaleService::class)->createSale([
            'customer_id' => $customer->id,
            'subtotal' => 30_000_000,
            'discount' => 0,
            'total' => 30_000_000,
            'customer_paid' => 30_000_000,
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'price' => 15_000_000,
                'discount' => 0,
                'serial_ids' => [$first->id, $second->id],
            ]],
        ], [
            'allow_oversell' => false,
            'default_status' => 'Hoàn thành',
            'code_prefix' => 'HD-P0-MULTI-',
        ]);

        $item = $invoice->items()->sole();
        $links = InvoiceItemSerial::query()
            ->where('invoice_item_id', $item->id)
            ->pluck('cost_price', 'serial_imei_id');

        $this->assertSame(6_607_120.0, (float) $item->cost_price);
        $this->assertSame(5_185_218.0, (float) $links[$first->id]);
        $this->assertSame(8_029_022.0, (float) $links[$second->id]);
        $this->assertSame(5_185_218.0, (float) $first->fresh()->sold_cost_price);
        $this->assertSame(8_029_022.0, (float) $second->fresh()->sold_cost_price);

        $product->refresh();
        $this->assertSame(1, (int) $product->stock_quantity);
        $this->assertSame(7_775_225.0, (float) $product->inventory_total_cost);
        $this->assertSame(7_775_225.0, (float) $product->cost_price);
        $this->assertSame('in_stock', $remaining->fresh()->status);
    }

    public function test_serial_repair_adjustment_rebuilds_projection_from_serial_source_not_stale_average(): void
    {
        $product = $this->serialProduct([
            'stock_quantity' => 1,
            'inventory_total_cost' => 25_414_058,
            'cost_price' => 25_414_058,
        ]);
        $serial = $this->serial($product, 'SER-P0-REPAIR', 5_185_218);

        // Simulate a repair which adds 616,694 to this exact IMEI. The old
        // moving-average path would have produced 26,030,752 instead.
        $serial->update(['cost_price' => 5_801_912]);
        MovingAvgCostingService::applyRepairAdjustment($product, 616_694);

        $product->refresh();

        $this->assertSame(1, (int) $product->stock_quantity);
        $this->assertSame(5_801_912.0, (float) $product->inventory_total_cost);
        $this->assertSame(5_801_912.0, (float) $product->cost_price);
    }

    private function serialProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'sku' => 'SP-P0-COST-'.uniqid(),
            'name' => 'Sản phẩm serial P0 giá vốn',
            'stock_quantity' => 0,
            'inventory_total_cost' => 0,
            'cost_price' => 0,
            'retail_price' => 9_000_000,
            'has_serial' => true,
            'is_active' => true,
        ], $overrides));
    }

    private function serial(Product $product, string $number, int $cost, string $status = 'in_stock'): SerialImei
    {
        return SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => $number.'-'.uniqid(),
            'status' => $status,
            'cost_price' => $cost,
            'original_cost' => $cost,
        ]);
    }
}
