<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if (! app()->environment('testing') || ! str_contains(strtolower($database), 'test')) {
    throw new \RuntimeException('Provider snapshots may only read an explicitly named testing database.');
}

$sku = trim((string) getenv('PC_SMOKE_NORMAL_SKU'));
if ($sku === '') {
    throw new \RuntimeException('PC_SMOKE_NORMAL_SKU is required.');
}

$product = Product::withTrashed()->where('sku', $sku)->firstOrFail();
$tables = [
    'customers',
    'orders',
    'order_items',
    'integration_events',
    'external_inventory_reservations',
    'activity_logs',
    'invoices',
    'invoice_items',
    'invoice_item_serials',
    'cash_flows',
    'stock_movements',
    'customer_debts',
    'warranties',
];

$snapshot = [
    'captured_at_utc' => gmdate(DATE_ATOM),
    'environment' => app()->environment(),
    'connection' => $connection,
    'database' => $database,
    'counts' => collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all(),
    'pc_source_counts' => [
        'orders' => DB::table('orders')->where('external_source', 'pc_website')->count(),
        'integration_events' => DB::table('integration_events')->where('source', 'pc_website')->count(),
        'reservations' => DB::table('external_inventory_reservations')->where('source', 'pc_website')->count(),
    ],
    'serial_status_counts' => [
        'sold' => DB::table('serial_imeis')->where('status', 'sold')->count(),
        'in_stock' => DB::table('serial_imeis')->where('status', 'in_stock')->count(),
    ],
    'product' => [
        'id' => $product->id,
        'sku' => $product->sku,
        'stock_quantity' => (int) $product->stock_quantity,
        'cost_price' => (float) $product->cost_price,
        'inventory_total_cost' => (float) $product->inventory_total_cost,
    ],
];
$encoded = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
$evidenceFile = trim((string) getenv('PC_SNAPSHOT_EVIDENCE_FILE'));

if ($evidenceFile !== '') {
    $directory = dirname($evidenceFile);
    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new \RuntimeException("Unable to create evidence directory: {$directory}");
    }
    if (file_put_contents($evidenceFile, $encoded) === false) {
        throw new \RuntimeException("Unable to write evidence file: {$evidenceFile}");
    }
}

fwrite(STDOUT, $encoded);
