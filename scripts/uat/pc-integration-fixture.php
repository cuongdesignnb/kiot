<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Product;
use App\Models\SerialImei;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");
$port = (int) config("database.connections.{$connection}.port");

if (! app()->environment('testing') || ! str_contains(strtolower($database), 'test')) {
    throw new \RuntimeException('UAT fixtures may only be created in an explicitly named testing database.');
}

$branch = Branch::firstOrCreate(['name' => 'PC UAT Provider Verification']);
$definitions = [
    ['sku' => 'UAT-PC-NORMAL-001', 'name' => 'UAT PC Normal Product', 'stock_quantity' => 10],
    ['sku' => 'UAT-PC-SERIAL-001', 'name' => 'UAT PC Serial Product', 'stock_quantity' => 2, 'has_serial' => true],
    ['sku' => 'UAT-PC-LOW-001', 'name' => 'UAT PC Low Stock Product', 'stock_quantity' => 1],
    ['sku' => 'UAT-PC-ZERO-001', 'name' => 'UAT PC Zero Stock Product', 'stock_quantity' => 0],
    ['sku' => 'UAT-PC-INACTIVE-001', 'name' => 'UAT PC Inactive Product', 'stock_quantity' => 5, 'is_active' => false],
    ['sku' => 'UAT-PC-NOTSELL-001', 'name' => 'UAT PC Not Sellable Product', 'stock_quantity' => 5, 'sell_directly' => false],
    ['sku' => 'UAT-PC-Case-Abc', 'name' => 'UAT PC Exact Case Product', 'stock_quantity' => 5],
    ['sku' => 'UAT-PC-DELETED-001', 'name' => 'UAT PC Deleted Product', 'stock_quantity' => 5, '_deleted' => true],
    ['sku' => 'UAT-PC-SERVICE-001', 'name' => 'UAT PC Service', 'stock_quantity' => 0, 'type' => 'service'],
];
$products = collect($definitions)->map(function (array $definition, int $index): Product {
    $deleted = (bool) ($definition['_deleted'] ?? false);
    unset($definition['_deleted']);
    $product = Product::withTrashed()->where('sku', $definition['sku'])->first() ?? new Product;
    $product->forceFill(array_merge([
        'type' => 'standard',
        'cost_price' => 500000,
        'retail_price' => 800000,
        'inventory_total_cost' => 500000 * (int) $definition['stock_quantity'],
        'has_serial' => false,
        'is_active' => true,
        'sell_directly' => true,
        'weight' => 500,
        'warranty_months' => 24,
        'barcode' => 'UATPC'.str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT),
    ], $definition));
    if ($product->trashed()) {
        $product->restore();
    }
    $product->save();
    Product::withoutTimestamps(function () use ($product, $index): void {
        $product->forceFill(['updated_at' => now()->subMinutes(20 - $index)])->save();
    });
    if ($deleted) {
        $product->delete();
    }

    return $product;
});

$serialProduct = $products->firstWhere('sku', 'UAT-PC-SERIAL-001');
foreach (['UAT-PC-SN-0001', 'UAT-PC-SN-0002'] as $serialNumber) {
    SerialImei::firstOrCreate([
        'product_id' => $serialProduct->id,
        'serial_number' => $serialNumber,
    ], [
        'status' => 'in_stock',
        'cost_price' => 500000,
        'original_cost' => 500000,
    ]);
}

$normalProduct = $products->firstWhere('sku', 'UAT-PC-NORMAL-001');

fwrite(STDOUT, json_encode([
    'environment' => app()->environment(),
    'connection' => $connection,
    'database' => $database,
    'port' => $port,
    'branch_id' => $branch->id,
    'normal_sku' => $normalProduct->sku,
    'normal_stock_quantity' => (int) $normalProduct->stock_quantity,
    'normal_retail_price' => (float) $normalProduct->retail_price,
    'fixtures' => $products->map(fn (Product $product) => [
        'sku' => $product->sku,
        'type' => $product->type,
        'is_active' => (bool) $product->is_active,
        'sell_directly' => (bool) $product->sell_directly,
        'stock_quantity' => (int) $product->stock_quantity,
        'deleted' => $product->trashed(),
    ])->values()->all(),
    'serials_in_stock' => SerialImei::where('product_id', $serialProduct->id)->where('status', 'in_stock')->count(),
    'integration_config' => [
        'enabled' => (bool) config('integrations.pc_website.enabled'),
        'client_configured' => trim((string) config('integrations.pc_website.client_id')) !== '',
        'secret_configured' => (string) config('integrations.pc_website.secret') !== '',
        'branch_id' => config('integrations.pc_website.default_branch_id'),
        'branch_exists' => Branch::query()->whereKey(config('integrations.pc_website.default_branch_id'))->exists(),
    ],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
