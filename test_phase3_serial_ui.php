<?php
/**
 * Phase 3 Costing Verification — UI quản trị Serial cost
 *
 * Test cases:
 *  TC3.1) updateSerial cho phép sửa cost_price khi status=in_stock
 *  TC3.2) updateSerial CHẶN sửa cost_price khi status=sold
 *  TC3.3) Sau khi sửa cost_price, product.cost_price (BQ serial) recompute đúng
 *  TC3.4) ActivityLog ghi lại thay đổi
 *  TC3.5) Command serial:sync-cost-from-tasks --dry-run không ghi DB
 */
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

Auth::loginUsingId(1);

$pass = 0;
$fail = 0;
$errors = [];

function check(string $label, bool $cond, &$pass, &$fail, &$errors, string $detail = ''): void
{
    if ($cond) {
        echo "  ✓ $label\n";
        $pass++;
    } else {
        echo "  ✗ $label" . ($detail ? " — $detail" : '') . "\n";
        $fail++;
        $errors[] = "$label: $detail";
    }
}

function approxEqual(float $a, float $b, float $eps = 1.0): bool
{
    return abs($a - $b) <= $eps;
}

Setting::set('inventory_costing_method', 'average');

echo "\n════════════════════════════════════════════════════\n";
echo "  PHASE 3 SERIAL COST UI VERIFICATION\n";
echo "════════════════════════════════════════════════════\n";

$pcCtl = app(\App\Http\Controllers\ProductController::class);

$sku = 'TEST-P3-' . time();
$product = Product::create([
    'sku' => $sku, 'name' => 'TEST P3 Serial',
    'cost_price' => 0, 'retail_price' => 1000000,
    'stock_quantity' => 0, 'has_serial' => true, 'is_active' => true, 'type' => 'standard',
]);

$prefix = 'IMEI-P3-' . substr((string)time(), -6);
$s1 = SerialImei::create(['product_id' => $product->id, 'serial_number' => $prefix . 'A', 'status' => 'in_stock', 'cost_price' => 10000000, 'original_cost' => 10000000]);
$s2 = SerialImei::create(['product_id' => $product->id, 'serial_number' => $prefix . 'B', 'status' => 'in_stock', 'cost_price' => 12000000, 'original_cost' => 12000000]);
$s3 = SerialImei::create(['product_id' => $product->id, 'serial_number' => $prefix . 'C', 'status' => 'sold', 'cost_price' => 11000000, 'sold_cost_price' => 11000000, 'original_cost' => 11000000]);

$product->recomputeFromSerials();
$product->refresh();

echo "\n── TC3.1: Sửa cost_price serial in_stock thành công ──\n";

$logsBefore = ActivityLog::where('action', 'serial_cost_update')->count();

$req = Request::create('/products/' . $product->id . '/serials/' . $s1->id, 'PUT', [
    'serial_number' => $s1->serial_number,
    'status' => 'in_stock',
    'cost_price' => 11000000,
]);
$req->setLaravelSession(app('session.store'));
app()->instance('request', $req);
\Illuminate\Support\Facades\Facade::clearResolvedInstance('request');

$resp = $pcCtl->updateSerial($req, $product, $s1);
$status = $resp instanceof \Illuminate\Http\JsonResponse ? $resp->getStatusCode() : 0;
check('Response 200', $status === 200, $pass, $fail, $errors, "status=$status");

$s1->refresh();
check('Serial cost_price = 11M', approxEqual((float)$s1->cost_price, 11000000), $pass, $fail, $errors, "actual={$s1->cost_price}");

$product->refresh();
// avg in_stock = (11M + 12M) / 2 = 11.5M (s3 sold không tính)
check('Product cost_price recompute = 11.5M', approxEqual((float)$product->cost_price, 11500000), $pass, $fail, $errors, "actual={$product->cost_price}");
check('Product stock_quantity = 2 (chỉ tính in_stock)', $product->stock_quantity == 2, $pass, $fail, $errors, "actual={$product->stock_quantity}");

echo "\n── TC3.2: Chặn sửa cost_price khi status=sold ──\n";

$req = Request::create('/products/' . $product->id . '/serials/' . $s3->id, 'PUT', [
    'serial_number' => $s3->serial_number,
    'status' => 'sold',
    'cost_price' => 99999999,
]);
$req->setLaravelSession(app('session.store'));
app()->instance('request', $req);
\Illuminate\Support\Facades\Facade::clearResolvedInstance('request');

$resp = $pcCtl->updateSerial($req, $product, $s3);
$status = $resp instanceof \Illuminate\Http\JsonResponse ? $resp->getStatusCode() : 0;
check('Response 422 (rejected)', $status === 422, $pass, $fail, $errors, "status=$status");

$s3->refresh();
check('Serial sold cost_price KHÔNG đổi = 11M', approxEqual((float)$s3->cost_price, 11000000), $pass, $fail, $errors, "actual={$s3->cost_price}");

echo "\n── TC3.3: ActivityLog ghi lại thay đổi ──\n";

$logsAfter = ActivityLog::where('action', 'serial_cost_update')->count();
check('Log mới được tạo', $logsAfter === $logsBefore + 1, $pass, $fail, $errors, "before=$logsBefore, after=$logsAfter");

$lastLog = ActivityLog::where('action', 'serial_cost_update')->latest('id')->first();
check('Log có subject_id = serial id', $lastLog && $lastLog->subject_id === $s1->id, $pass, $fail, $errors, '');
check('Log properties chứa old_cost & new_cost',
    $lastLog && isset($lastLog->properties['old_cost']) && (float)$lastLog->properties['old_cost'] == 10000000
        && (float)$lastLog->properties['new_cost'] == 11000000,
    $pass, $fail, $errors, json_encode($lastLog?->properties));

echo "\n── TC3.4: Command sync với --dry-run không ghi DB ──\n";

// Đặt s2.cost_price = 0 để command có thể "fix" nó (nhưng dry-run không lưu)
$s2->cost_price = 0;
$s2->save();
$s2->refresh();
$beforeCost = (float) $s2->cost_price;

$exitCode = \Illuminate\Support\Facades\Artisan::call('serial:sync-cost-from-tasks', [
    '--dry-run' => true,
    '--product' => $product->id,
]);
check('Command dry-run exit 0', $exitCode === 0, $pass, $fail, $errors, "exit=$exitCode");

$s2->refresh();
check('Dry-run KHÔNG thay đổi cost_price', approxEqual((float)$s2->cost_price, $beforeCost), $pass, $fail, $errors, "before=$beforeCost, after={$s2->cost_price}");

// Chạy thật
\Illuminate\Support\Facades\Artisan::call('serial:sync-cost-from-tasks', [
    '--product' => $product->id,
    '--recompute-products' => true,
]);
$s2->refresh();
check('Sync thật: s2.cost_price được set > 0', (float)$s2->cost_price > 0, $pass, $fail, $errors, "actual={$s2->cost_price}");

echo "\n════════════════════════════════════════════════════\n";
echo "  KẾT QUẢ: $pass passed, $fail failed\n";
echo "════════════════════════════════════════════════════\n";

if ($fail > 0) {
    echo "\nChi tiết lỗi:\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
    exit(1);
}
exit(0);
