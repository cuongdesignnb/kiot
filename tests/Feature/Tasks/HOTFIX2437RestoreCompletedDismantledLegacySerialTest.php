<?php

namespace Tests\Feature\Tasks;

use App\Models\Product;
use App\Models\SerialImei;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HOTFIX2437RestoreCompletedDismantledLegacySerialTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_dismantled_serial_with_latest_completed_task_is_restored_and_idempotent(): void
    {
        $product = $this->product();
        $serial = $this->serial($product, ['status' => 'dismantled', 'repair_status' => 'repairing']);
        $this->task($product, $serial, 'completed');

        $this->artisan('serials:restore-completed-dismantled', ['--serial' => $serial->serial_number, '--apply' => true])
            ->expectsOutputToContain('Updated serials: 1')
            ->assertExitCode(0);

        $serial->refresh();
        $this->assertSame('in_stock', $serial->status);
        $this->assertSame('ready', $serial->repair_status);

        $this->artisan('serials:restore-completed-dismantled', ['--serial' => $serial->serial_number, '--apply' => true])
            ->expectsOutputToContain('Updated serials: 0')
            ->assertExitCode(0);
    }

    public function test_latest_task_not_completed_is_explained_and_not_restored(): void
    {
        $product = $this->product();
        $serial = $this->serial($product, ['status' => 'dismantled', 'repair_status' => 'repairing']);
        $this->task($product, $serial, 'completed');
        $this->task($product, $serial, 'in_progress');

        $this->artisan('serials:restore-completed-dismantled', ['--serial' => $serial->serial_number, '--explain' => true, '--apply' => true])
            ->expectsOutputToContain('latest task not completed')
            ->expectsOutputToContain('Updated serials: 0')
            ->assertExitCode(0);

        $this->assertSame('dismantled', $serial->fresh()->status);
    }

    public function test_sold_or_purchase_returned_serial_is_not_restored(): void
    {
        $product = $this->product();
        $sold = $this->serial($product, ['status' => 'dismantled', 'invoice_id' => 123, 'sold_at' => now()]);
        $returned = $this->serial($product, ['status' => 'dismantled', 'purchase_return_id' => 456]);
        $this->task($product, $sold, 'completed');
        $this->task($product, $returned, 'Hoàn thành');

        $this->artisan('serials:restore-completed-dismantled', ['--product' => $product->sku, '--explain' => true, '--apply' => true])
            ->expectsOutputToContain('serial sold')
            ->expectsOutputToContain('serial purchase-returned')
            ->expectsOutputToContain('Updated serials: 0')
            ->assertExitCode(0);

        $this->assertSame('dismantled', $sold->fresh()->status);
        $this->assertSame('dismantled', $returned->fresh()->status);
    }

    private function product(): Product
    {
        return Product::create([
            'sku' => 'RESTORE-' . uniqid(),
            'name' => 'Restore product',
            'stock_quantity' => 0,
            'cost_price' => 1_000_000,
            'inventory_total_cost' => 0,
            'retail_price' => 2_000_000,
            'has_serial' => true,
        ]);
    }

    private function serial(Product $product, array $overrides = []): SerialImei
    {
        return SerialImei::create(array_merge([
            'product_id' => $product->id,
            'serial_number' => 'RS-' . uniqid(),
            'status' => 'dismantled',
            'repair_status' => 'repairing',
            'cost_price' => 1_000_000,
        ], $overrides));
    }

    private function task(Product $product, SerialImei $serial, string $status): Task
    {
        return Task::create([
            'code' => 'SC-' . uniqid(),
            'type' => 'repair',
            'title' => 'Repair',
            'product_id' => $product->id,
            'serial_imei_id' => $serial->id,
            'status' => $status,
            'progress' => $status === 'completed' || $status === 'Hoàn thành' ? 100 : 50,
            'completed_at' => $status === 'completed' || $status === 'Hoàn thành' ? now() : null,
        ]);
    }
}
