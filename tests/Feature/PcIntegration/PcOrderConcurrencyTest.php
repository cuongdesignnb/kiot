<?php

namespace Tests\Feature\PcIntegration;

use App\Models\Branch;
use App\Models\ExternalInventoryReservation;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PcOrderConcurrencyTest extends TestCase
{
    public function test_two_mysql_processes_cannot_reserve_the_same_last_unit(): void
    {
        $branch = Branch::create(['name' => 'PC Concurrent '.Str::uuid()]);
        $product = Product::create([
            'sku' => 'PC-CONCURRENT-'.strtoupper(Str::random(10)),
            'name' => 'PC concurrency product',
            'type' => 'standard',
            'cost_price' => 500000,
            'retail_price' => 800000,
            'stock_quantity' => 1,
            'inventory_total_cost' => 500000,
            'has_serial' => false,
            'is_active' => true,
            'sell_directly' => true,
        ]);
        $externalOrderIds = ['EXT-CONCURRENT-'.Str::random(10), 'EXT-CONCURRENT-'.Str::random(10)];
        $commands = [];
        foreach ($externalOrderIds as $index => $externalOrderId) {
            $payload = $this->payload($product, $externalOrderId, $index);
            $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $commands[] = [base64_encode($rawBody), 'pc-concurrent-'.Str::uuid()];
        }

        $results = $this->runConcurrentImports($branch, $commands);

        $this->assertCount(1, collect($results)->where('outcome', 'success'));
        $this->assertSame(
            'INSUFFICIENT_AVAILABLE_STOCK',
            collect($results)->firstWhere('outcome', 'integration_error')['error_code'] ?? null,
        );
        $this->assertSame(1, Order::where('external_source', 'pc_website')->whereIn('external_order_id', $externalOrderIds)->count());
        $this->assertSame(1, ExternalInventoryReservation::where('product_id', $product->id)->where('status', 'active')->count());
    }

    /** @param array<int, array{string, string}> $commands */
    private function runConcurrentImports(Branch $branch, array $commands): array
    {
        $barrierDirectory = storage_path('framework/testing/pc-order-concurrency-'.Str::uuid());
        if (! is_dir(dirname($barrierDirectory))) {
            mkdir(dirname($barrierDirectory), 0777, true);
        }
        mkdir($barrierDirectory, 0777, true);

        $processes = [];
        try {
            foreach ($commands as $index => [$encodedPayload, $idempotencyKey]) {
                $workerName = 'worker-'.$index;
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/PcOrderImportWorker.php'),
                    base_path(),
                    (string) $branch->id,
                    $encodedPayload,
                    $idempotencyKey,
                    $barrierDirectory,
                    $workerName,
                ], base_path(), $this->workerEnvironment());
                $process->setTimeout(30);
                $process->start();
                $processes[$workerName] = $process;
            }

            $deadline = microtime(true) + 15;
            foreach (array_keys($processes) as $workerName) {
                while (! is_file($barrierDirectory.'/'.$workerName.'.ready')) {
                    if (microtime(true) >= $deadline) {
                        $this->fail('Worker did not reach the concurrency barrier: '.$workerName);
                    }
                    usleep(20_000);
                }
            }
            file_put_contents($barrierDirectory.'/release', 'release');

            $results = [];
            foreach ($processes as $workerName => $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $workerName.' failed: '.$process->getErrorOutput());
                $this->assertMatchesRegularExpression('/PC_ORDER_WORKER_RESULT=(\{.+\})/', $process->getOutput());
                preg_match('/PC_ORDER_WORKER_RESULT=(\{.+\})/', $process->getOutput(), $matches);
                $results[] = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($barrierDirectory.'/*') ?: [] as $path) {
                unlink($path);
            }
            if (is_dir($barrierDirectory)) {
                rmdir($barrierDirectory);
            }
        }
    }

    private function workerEnvironment(): array
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}");

        return [
            'APP_BASE_PATH' => base_path(),
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => $connection,
            'DB_HOST' => (string) $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
            'CACHE_STORE' => 'array',
        ];
    }

    private function payload(Product $product, string $externalOrderId, int $index): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'external_order_id' => $externalOrderId,
            'external_order_code' => 'WEB-CONCURRENT-'.$index.'-'.strtoupper(Str::random(6)),
            'ordered_at' => now()->toIso8601String(),
            'customer' => [
                'name' => 'Khách concurrency '.$index,
                'phone' => '098'.random_int(1000000, 9999999),
                'email' => 'pc-concurrent-'.Str::random(8).'@example.test',
            ],
            'delivery' => [
                'is_delivery' => false,
                'weight' => 0,
                'shipping_fee' => 0,
            ],
            'payment' => ['method' => 'sepay', 'status' => 'pending'],
            'totals' => [
                'subtotal' => 800000,
                'discount' => 0,
                'shipping_fee' => 0,
                'total' => 800000,
            ],
            'items' => [[
                'sku' => $product->sku,
                'product_name' => $product->name,
                'quantity' => 1,
                'unit_price' => 800000,
                'discount' => 0,
                'line_total' => 800000,
                'bundle_ref' => null,
            ]],
            'note' => 'Concurrent stock safety test',
        ];
    }
}
