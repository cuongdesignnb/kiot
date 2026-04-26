<?php
/**
 * Phase 5 Verification — Phân tích giá vốn + lịch sử + cron schedule
 *
 * TC5.1) costAnalysis() trả Inertia render với rows + summary
 * TC5.2) Detect mismatch: serial avg != snapshot
 * TC5.3) only_mismatch=1 lọc đúng
 * TC5.4) serialCostHistory() trả paginator các log serial_cost_update
 * TC5.5) Filter theo product_id
 * TC5.6) Schedule có entry serial:sync-cost-from-tasks
 */
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\SerialImei;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

Auth::loginUsingId(1);

$pass = 0;
$fail = 0;
$errors = [];

function check(string $label, bool $cond, &$pass, &$fail, &$errors, string $detail = ''): void
{
    if ($cond) { echo "  ✓ $label\n"; $pass++; }
    else { echo "  ✗ $label" . ($detail ? " — $detail" : '') . "\n"; $fail++; $errors[] = "$label: $detail"; }
}

echo "\n════════════════════════════════════════════════════\n";
echo "  PHASE 5 REPORTS VERIFICATION\n";
echo "════════════════════════════════════════════════════\n";

$rc = app(\App\Http\Controllers\ReportController::class);

// Tạo product có serial với cost_price snapshot LỆCH so với serial in_stock
$sku = 'TEST-P5-' . time();
$product = Product::create([
    'sku' => $sku, 'name' => 'TEST P5 Mismatch',
    'cost_price' => 10000000, // snapshot
    'retail_price' => 15000000,
    'stock_quantity' => 0, 'has_serial' => true, 'is_active' => true, 'type' => 'standard',
]);

$prefix = 'IMEI-P5-' . substr((string)time(), -6);
SerialImei::create(['product_id' => $product->id, 'serial_number' => $prefix . 'A', 'status' => 'in_stock', 'cost_price' => 12000000, 'original_cost' => 12000000]);
SerialImei::create(['product_id' => $product->id, 'serial_number' => $prefix . 'B', 'status' => 'in_stock', 'cost_price' => 14000000, 'original_cost' => 14000000]);
// Cố tình KHÔNG recompute để giữ snapshot=10M, avg=13M → lệch 30%

$product->update(['stock_quantity' => 2]);

echo "\n── TC5.1: costAnalysis() trả Inertia ──\n";

$req = Request::create('/reports/cost-analysis', 'GET', ['search' => $sku]);
$resp = $rc->costAnalysis($req);
check('Response is Inertia\Response', $resp instanceof \Inertia\Response, $pass, $fail, $errors);

// Dùng reflection để access props
$ref = new ReflectionClass($resp);
$propProp = $ref->getProperty('component');
$propProp->setAccessible(true);
$component = $propProp->getValue($resp);
check('Component = Reports/CostAnalysis', $component === 'Reports/CostAnalysis', $pass, $fail, $errors, "actual=$component");

$propsProp = $ref->getProperty('props');
$propsProp->setAccessible(true);
$rawProps = $propsProp->getValue($resp);
$rows = is_array($rawProps['rows'] ?? null) ? $rawProps['rows'] : (is_object($rawProps['rows'] ?? null) && method_exists($rawProps['rows'], 'toArray') ? $rawProps['rows']->toArray() : []);
if (is_object($rawProps['rows'] ?? null) && $rawProps['rows'] instanceof \Illuminate\Support\Collection) {
    $rows = $rawProps['rows']->toArray();
}
check('Rows array trả về', count($rows) >= 1, $pass, $fail, $errors, 'count=' . count($rows));

echo "\n── TC5.2: Detect mismatch ──\n";

$ourRow = collect($rows)->firstWhere('sku', $sku);
check('Tìm thấy row của product test', $ourRow !== null, $pass, $fail, $errors);

if ($ourRow) {
    check('snapshot_cost = 10M', (float)$ourRow['snapshot_cost'] == 10000000, $pass, $fail, $errors, "actual={$ourRow['snapshot_cost']}");
    check('avg_serial_cost = 13M', (float)$ourRow['avg_serial_cost'] == 13000000, $pass, $fail, $errors, "actual={$ourRow['avg_serial_cost']}");
    check('in_stock_serial_count = 2', (int)$ourRow['in_stock_serial_count'] === 2, $pass, $fail, $errors);
    check('status = mismatch', $ourRow['status'] === 'mismatch', $pass, $fail, $errors, "actual={$ourRow['status']}");
    check('diff = +3M', (float)$ourRow['diff'] == 3000000, $pass, $fail, $errors, "actual={$ourRow['diff']}");
}

echo "\n── TC5.3: only_mismatch=1 lọc đúng ──\n";

$req2 = Request::create('/reports/cost-analysis', 'GET', ['search' => $sku, 'only_mismatch' => 1]);
$resp2 = $rc->costAnalysis($req2);
$rawProps2 = $propsProp->getValue($resp2);
$rows2 = $rawProps2['rows'] instanceof \Illuminate\Support\Collection ? $rawProps2['rows']->toArray() : (array) $rawProps2['rows'];
check('Filter mismatch trả về 1 row', count($rows2) === 1, $pass, $fail, $errors, 'count=' . count($rows2));
check('Row được lọc là mismatch', count($rows2) > 0 && $rows2[0]['status'] === 'mismatch', $pass, $fail, $errors);

echo "\n── TC5.4: serialCostHistory() ──\n";

// Tạo một log serial_cost_update
ActivityLog::log('serial_cost_update', "Test log " . uniqid(), null, [
    'old_cost' => 10000000, 'new_cost' => 11000000, 'product_id' => $product->id,
]);

$req3 = Request::create('/reports/serial-cost-history', 'GET', []);
$resp3 = $rc->serialCostHistory($req3);
$ref3 = new ReflectionClass($resp3);
$compProp3 = $ref3->getProperty('component'); $compProp3->setAccessible(true);
check('Component = Reports/SerialCostHistory', $compProp3->getValue($resp3) === 'Reports/SerialCostHistory', $pass, $fail, $errors);

$propsProp3 = $ref3->getProperty('props'); $propsProp3->setAccessible(true);
$raw3 = $propsProp3->getValue($resp3);
$paginator = $raw3['logs'];
check('Paginator có data', $paginator !== null && is_object($paginator) && $paginator->total() > 0, $pass, $fail, $errors, 'total=' . ($paginator->total() ?? 'null'));

echo "\n── TC5.5: Filter theo product_id ──\n";

$req4 = Request::create('/reports/serial-cost-history', 'GET', ['product_id' => $product->id]);
$resp4 = $rc->serialCostHistory($req4);
$raw4 = $propsProp3->getValue($resp4);
$pag4 = $raw4['logs'];
check('Filter product_id trả về log của product', $pag4->total() >= 1, $pass, $fail, $errors, 'total=' . $pag4->total());

$found = false;
foreach ($pag4->items() as $item) {
    if (($item->properties['product_id'] ?? null) == $product->id) { $found = true; break; }
}
check('Log lọc đúng product_id', $found, $pass, $fail, $errors);

echo "\n── TC5.6: Schedule cron ──\n";

$schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
$events = $schedule->events();
$found = false;
foreach ($events as $ev) {
    if (str_contains($ev->command ?? '', 'serial:sync-cost-from-tasks')) { $found = true; break; }
}
check('Schedule có entry serial:sync-cost-from-tasks', $found, $pass, $fail, $errors, 'events=' . count($events));

echo "\n════════════════════════════════════════════════════\n";
echo "  KẾT QUẢ: $pass passed, $fail failed\n";
echo "════════════════════════════════════════════════════\n";

if ($fail > 0) {
    echo "\nChi tiết lỗi:\n";
    foreach ($errors as $e) echo "  - $e\n";
    exit(1);
}
exit(0);
