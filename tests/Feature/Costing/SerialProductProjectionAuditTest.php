<?php

namespace Tests\Feature\Costing;

use App\Models\Category;
use App\Models\Product;
use App\Models\SerialImei;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SerialProductProjectionAuditTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dry_run_reports_zero_target_without_mutating_sold_serial_history(): void
    {
        [$product, $soldSerial] = $this->staleSoldOutProduct();

        $this->assertSame(0, Artisan::call('costing:audit-serial-product-projection', ['--product' => $product->sku]));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('DRY_RUN', $payload['result']);
        $this->assertTrue($payload['projection_mismatch']);
        $this->assertSame(0.0, (float) $payload['target']['cost_price']);
        $this->assertSame('NO', $payload['historical_cogs_mutation']);

        $this->assertSame(9000000.0, (float) $product->fresh()->cost_price);
        $this->assertSame(3500000.0, (float) $soldSerial->fresh()->sold_cost_price);
        $this->assertStringContainsString(
            'if (inStock.length === 0) return formatCurrency(0)',
            file_get_contents(resource_path('js/Pages/Welcome.vue')),
        );
    }

    public function test_apply_requires_exact_confirmation_and_backup_then_changes_only_product_projection(): void
    {
        [$product, $soldSerial] = $this->staleSoldOutProduct();
        $before = [
            'stock_quantity' => 0,
            'inventory_total_cost' => 0.0,
            'cost_price' => 9000000.0,
        ];
        $target = [
            'stock_quantity' => 0,
            'inventory_total_cost' => 0.0,
            'cost_price' => 0.0,
        ];
        $hash = hash('sha256', json_encode([
            'contract' => 'serial-product-projection-v1',
            'product_id' => (int) $product->id,
            'before' => $before,
            'target' => $target,
        ], JSON_THROW_ON_ERROR));
        $confirmation = 'APPLY-SERIAL-PROJECTION-'.substr($hash, 0, 16);

        $this->artisan('costing:audit-serial-product-projection', [
            '--product' => $product->sku,
            '--apply' => true,
            '--confirm' => $confirmation,
            '--backup-reference' => 'TEST-BACKUP-SYNTHETIC',
        ])->expectsOutputToContain('"result": "APPLIED"')->assertSuccessful();

        $product->refresh();
        $this->assertSame(0, (int) $product->stock_quantity);
        $this->assertSame(0.0, (float) $product->inventory_total_cost);
        $this->assertSame(0.0, (float) $product->cost_price);
        $this->assertSame('sold', $soldSerial->fresh()->status);
        $this->assertSame(3500000.0, (float) $soldSerial->fresh()->sold_cost_price);
    }

    private function staleSoldOutProduct(): array
    {
        $category = Category::firstOrCreate(['name' => 'Synthetic serial projection QA']);
        $product = Product::create([
            'sku' => 'SP-SYNTH-PROJECTION-'.uniqid(),
            'name' => 'Synthetic sold-out serial product',
            'cost_price' => 9000000,
            'retail_price' => 12000000,
            'stock_quantity' => 0,
            'inventory_total_cost' => 0,
            'is_active' => true,
            'has_serial' => true,
            'category_id' => $category->id,
        ]);
        $serial = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-SYNTH-'.uniqid(),
            'status' => 'sold',
            'cost_price' => 3500000,
            'original_cost' => 3800000,
            'sold_cost_price' => 3500000,
        ]);

        return [$product, $serial];
    }
}
