<?php
/**
 * Phase 6 Verification — BÌNH QUÂN DI ĐỘNG (Moving Weighted Average).
 *
 * Scenarios:
 *  TC6.1) Nhập 5×100k → BQ=100k, total=500k, qty=5
 *  TC6.2) Nhập thêm 5×120k → BQ=110k, total=1.1M, qty=10 (chuẩn KiotViet)
 *  TC6.3) Bán 1 unit → COGS=110k, BQ vẫn = 110k, total=990k, qty=9
 *  TC6.4) Sửa chữa serial S1 (lắp parts +200k) → BQ tăng 200k/9 ≈ 132.222
 *  TC6.5) Hủy hóa đơn → khôi phục 1 unit ở COGS=110k, BQ recompute
 *  TC6.6) Trả NCC 2 unit → giảm tổng theo cost lúc nhập
 *  TC6.7) Nhập kỳ tiếp 3×150k → BQ tính theo công thức
 */
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\StockMovement;
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

function near(float $a, float $b, float $eps = 1.0): bool { return abs($a - $b) <= $eps; }

function callIt($controller, Request $request, string $method, string $tag): void
{
    static $lastCall = 0;
    $elapsed = microtime(true) - $lastCall;
    if ($lastCall > 0 && $elapsed < 1.1) usleep((int) ((1.1 - $elapsed) * 1_000_000));
    $lastCall = microtime(true);

    $request->setLaravelSession(app('session.store'));
    app()->instance('request', $request);
    \Illuminate\Support\Facades\Facade::clearResolvedInstance('request');

    try {
        $resp = $controller->{$method}($request);
        if ($resp instanceof \Illuminate\Http\RedirectResponse) {
            $errs = $resp->getSession()?->get('errors');
            if ($errs && method_exists($errs, 'getBag')) {
                $bag = $errs->getBag('default');
                if ($bag->any()) echo "    ! $tag errors: " . json_encode($bag->all()) . "\n";
            }
            $errMsg = $resp->getSession()?->get('error');
            if ($errMsg) echo "    ! $tag flash error: $errMsg\n";
        }
    } catch (\Throwable $e) {
        echo "    ! $tag error: " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

Setting::set('inventory_costing_method', 'average');

echo "\n════════════════════════════════════════════════════\n";
echo "  PHASE 6 MOVING-AVG (BQ DI ĐỘNG) VERIFICATION\n";
echo "════════════════════════════════════════════════════\n";

$pc = app(\App\Http\Controllers\PurchaseController::class);
$ic = app(\App\Http\Controllers\InvoiceController::class);

$supplier = Customer::create([
    'name' => 'NCC P6', 'code' => 'NCC-P6-' . substr((string)microtime(true), -8),
    'phone' => '0906' . substr((string)time(), -6), 'is_supplier' => true,
]);
$customer = Customer::create([
    'name' => 'KH P6', 'code' => 'KH-P6-' . substr((string)microtime(true), -8),
    'phone' => '0907' . substr((string)time(), -6), 'is_customer' => true,
]);

$sku = 'BQ-P6-' . time();
$product = Product::create([
    'sku' => $sku, 'name' => 'BQ TEST P6',
    'cost_price' => 0, 'inventory_total_cost' => 0,
    'retail_price' => 200000,
    'stock_quantity' => 0, 'has_serial' => false, 'is_active' => true, 'type' => 'standard',
]);

// ─────────────────────────────────────────────────────
echo "\n── TC6.1: Nhập 5 @ 100.000 ──\n";

$req = Request::create('/purchases', 'POST', [
    'code' => 'PN-P6A-' . uniqid(),
    'supplier_id' => $supplier->id,
    'paid_amount' => 500000,
    'payment_method' => 'cash',
    'status' => 'completed',
    'items' => [['product_id' => $product->id, 'quantity' => 5, 'price' => 100000, 'discount' => 0]],
]);
callIt($pc, $req, 'store', 'TC6.1');

$product->refresh();
check('stock_quantity = 5', (int)$product->stock_quantity === 5, $pass, $fail, $errors);
check('cost_price = 100.000', near((float)$product->cost_price, 100000), $pass, $fail, $errors, 'BQ=' . $product->cost_price);
check('inventory_total_cost = 500.000', near((float)$product->inventory_total_cost, 500000), $pass, $fail, $errors, 'total=' . $product->inventory_total_cost);

// ─────────────────────────────────────────────────────
echo "\n── TC6.2: Nhập thêm 5 @ 120.000 → BQ moving avg = 110.000 ──\n";

$req = Request::create('/purchases', 'POST', [
    'code' => 'PN-P6B-' . uniqid(),
    'supplier_id' => $supplier->id,
    'paid_amount' => 600000,
    'payment_method' => 'cash',
    'status' => 'completed',
    'items' => [['product_id' => $product->id, 'quantity' => 5, 'price' => 120000, 'discount' => 0]],
]);
callIt($pc, $req, 'store', 'TC6.2');

$product->refresh();
check('stock_quantity = 10', (int)$product->stock_quantity === 10, $pass, $fail, $errors);
check('cost_price = 110.000 (chuẩn KiotViet)', near((float)$product->cost_price, 110000), $pass, $fail, $errors, 'BQ=' . $product->cost_price);
check('inventory_total_cost = 1.100.000', near((float)$product->inventory_total_cost, 1100000), $pass, $fail, $errors, 'total=' . $product->inventory_total_cost);

// ─────────────────────────────────────────────────────
echo "\n── TC6.3: Bán 1 unit → COGS = BQ hiện tại = 110.000 ──\n";

$req = Request::create('/invoices', 'POST', [
    'customer_id' => $customer->id,
    'subtotal' => 200000, 'discount' => 0, 'tax' => 0, 'total' => 200000,
    'customer_paid' => 200000, 'payment_method' => 'cash',
    'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 200000, 'discount' => 0]],
]);
callIt($ic, $req, 'store', 'TC6.3');

$invoice = Invoice::where('customer_id', $customer->id)->latest('id')->first();
$item = $invoice->items->first();
check('invoice_item.cost_price = 110.000 (COGS = BQ)', near((float)$item->cost_price, 110000), $pass, $fail, $errors, 'cogs=' . $item->cost_price);

$product->refresh();
check('stock_quantity = 9', (int)$product->stock_quantity === 9, $pass, $fail, $errors);
check('cost_price = 110.000 (BQ KHÔNG đổi)', near((float)$product->cost_price, 110000), $pass, $fail, $errors, 'BQ=' . $product->cost_price);
check('inventory_total_cost = 990.000', near((float)$product->inventory_total_cost, 990000), $pass, $fail, $errors, 'total=' . $product->inventory_total_cost);

// Check stock movement type=out_invoice
$mov = StockMovement::where('product_id', $product->id)->where('type', 'out_invoice')->latest('id')->first();
check('Stock movement out_invoice unit_cost=110k', near((float)$mov?->unit_cost, 110000), $pass, $fail, $errors);

// ─────────────────────────────────────────────────────
echo "\n── TC6.4: Sửa chữa hàng thường (parts +200k vào tồn) ──\n";

\App\Services\MovingAvgCostingService::applyRepairAdjustment($product, 200000);
$product->refresh();
check('inventory_total_cost = 1.190.000', near((float)$product->inventory_total_cost, 1190000), $pass, $fail, $errors, 'total=' . $product->inventory_total_cost);
$expectedBQ = round(1190000 / 9, 2);
check("cost_price ≈ {$expectedBQ}", near((float)$product->cost_price, $expectedBQ), $pass, $fail, $errors, 'BQ=' . $product->cost_price);

// ─────────────────────────────────────────────────────
echo "\n── TC6.5: Hủy hóa đơn → phục hồi 1 unit ở COGS=110k ──\n";

$ic->destroy($invoice);
$product->refresh();
check('stock_quantity = 10', (int)$product->stock_quantity === 10, $pass, $fail, $errors);
$expectedTotal = 1190000 + 110000;
check("inventory_total_cost = {$expectedTotal}", near((float)$product->inventory_total_cost, $expectedTotal), $pass, $fail, $errors, 'total=' . $product->inventory_total_cost);
$expectedBQ = round($expectedTotal / 10, 2);
check("cost_price ≈ {$expectedBQ}", near((float)$product->cost_price, $expectedBQ), $pass, $fail, $errors, 'BQ=' . $product->cost_price);

// ─────────────────────────────────────────────────────
echo "\n── TC6.6: Trả NCC 2 unit @ 100k (purchase return) ──\n";

\App\Services\MovingAvgCostingService::applyPurchaseReturn($product, 2, 100000);
$product->refresh();
check('stock_quantity = 8', (int)$product->stock_quantity === 8, $pass, $fail, $errors);
$expectedTotal = 1300000 - 200000;
check("inventory_total_cost = {$expectedTotal}", near((float)$product->inventory_total_cost, $expectedTotal), $pass, $fail, $errors, 'total=' . $product->inventory_total_cost);
$expectedBQ = round($expectedTotal / 8, 2);
check("cost_price ≈ {$expectedBQ}", near((float)$product->cost_price, $expectedBQ), $pass, $fail, $errors, 'BQ=' . $product->cost_price);

// ─────────────────────────────────────────────────────
echo "\n── TC6.7: Nhập 3 @ 150k → công thức BQ ──\n";

\App\Services\MovingAvgCostingService::applyPurchase($product, 3, 150000);
$product->refresh();
check('stock_quantity = 11', (int)$product->stock_quantity === 11, $pass, $fail, $errors);
$expectedTotal = 1100000 + 450000;
check("inventory_total_cost = {$expectedTotal}", near((float)$product->inventory_total_cost, $expectedTotal), $pass, $fail, $errors, 'total=' . $product->inventory_total_cost);
$expectedBQ = round($expectedTotal / 11, 2);
check("cost_price ≈ {$expectedBQ}", near((float)$product->cost_price, $expectedBQ), $pass, $fail, $errors, 'BQ=' . $product->cost_price);

echo "\n════════════════════════════════════════════════════\n";
echo "  KẾT QUẢ: $pass passed, $fail failed\n";
echo "════════════════════════════════════════════════════\n";

if ($fail > 0) {
    echo "\nChi tiết lỗi:\n";
    foreach ($errors as $e) echo "  - $e\n";
    exit(1);
}
exit(0);
