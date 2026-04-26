<?php
/**
 * Phase 4 Verification — Stock Movements Ledger.
 *
 * Test cases:
 *  TC4.1) Nhập hàng → ghi 1 movement type=in_purchase, balance = stock_quantity
 *  TC4.2) Bán hàng → ghi 1 movement type=out_invoice
 *  TC4.3) Hủy hóa đơn → ghi 1 movement type=in_invoice_return
 *  TC4.4) Hủy phiếu nhập → ghi 1 movement type=out_purchase_return
 *  TC4.5) StockMovement.balance_qty/cost = product state SAU dịch chuyển
 *  TC4.6) ReportController::stockCard trả Inertia với movements
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

function approxEqual(float $a, float $b, float $eps = 1.0): bool
{
    return abs($a - $b) <= $eps;
}

function callIt($controller, Request $request, string $method, string $tag, ...$extraArgs): void
{
    static $lastCall = 0;
    $elapsed = microtime(true) - $lastCall;
    if ($lastCall > 0 && $elapsed < 1.1) {
        usleep((int) ((1.1 - $elapsed) * 1_000_000));
    }
    $lastCall = microtime(true);

    $request->setLaravelSession(app('session.store'));
    app()->instance('request', $request);
    \Illuminate\Support\Facades\Facade::clearResolvedInstance('request');

    try {
        $resp = $controller->{$method}($request, ...$extraArgs);
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
echo "  PHASE 4 STOCK MOVEMENTS VERIFICATION\n";
echo "════════════════════════════════════════════════════\n";

$pc = app(\App\Http\Controllers\PurchaseController::class);
$ic = app(\App\Http\Controllers\InvoiceController::class);

$supplier = Customer::create([
    'name' => 'TEST NCC P4',
    'code' => 'NCC-P4-' . substr((string)time(), -6),
    'phone' => '0904' . substr((string)time(), -6),
    'is_supplier' => true,
]);

$customer = Customer::create([
    'name' => 'TEST KH P4',
    'code' => 'KH-P4-' . substr((string)time(), -6),
    'phone' => '0905' . substr((string)time(), -6),
    'is_customer' => true,
]);

$sku = 'TEST-P4-' . time();
$product = Product::create([
    'sku' => $sku, 'name' => 'TEST P4',
    'cost_price' => 0, 'retail_price' => 200000,
    'stock_quantity' => 0, 'has_serial' => false, 'is_active' => true, 'type' => 'standard',
]);

// ─────────────────────────────────────────────────────
echo "\n── TC4.1: Nhập hàng → in_purchase movement ──\n";

$beforeCount = StockMovement::count();

$req = Request::create('/purchases', 'POST', [
    'code' => 'PN-P4-' . uniqid(),
    'supplier_id' => $supplier->id,
    'paid_amount' => 1000000,
    'payment_method' => 'cash',
    'status' => 'completed',
    'items' => [
        ['product_id' => $product->id, 'quantity' => 10, 'price' => 100000, 'discount' => 0],
    ],
]);
callIt($pc, $req, 'store', 'TC4.1');

$afterCount = StockMovement::count();
check('1 movement mới được tạo', $afterCount === $beforeCount + 1, $pass, $fail, $errors, "before=$beforeCount, after=$afterCount");

$mov = StockMovement::where('product_id', $product->id)->latest('id')->first();
check('Type = in_purchase', $mov?->type === 'in_purchase', $pass, $fail, $errors, "actual={$mov?->type}");
check('Direction = in', $mov?->direction === 'in', $pass, $fail, $errors);
check('Qty = 10', (int)$mov?->qty === 10, $pass, $fail, $errors);
check('Unit cost = 100000', approxEqual((float)$mov?->unit_cost, 100000), $pass, $fail, $errors, "actual={$mov?->unit_cost}");
check('Total cost = 1M', approxEqual((float)$mov?->total_cost, 1000000), $pass, $fail, $errors);
check('Balance qty = 10', (int)$mov?->balance_qty === 10, $pass, $fail, $errors);
check('Balance cost = 100000', approxEqual((float)$mov?->balance_cost, 100000), $pass, $fail, $errors);
check('Ref code = purchase code', !empty($mov?->ref_code) && str_starts_with($mov->ref_code, 'PN-P4-'), $pass, $fail, $errors);

// ─────────────────────────────────────────────────────
echo "\n── TC4.2: Bán hàng → out_invoice movement ──\n";

$beforeCount = StockMovement::count();

$req = Request::create('/invoices', 'POST', [
    'customer_id' => $customer->id,
    'subtotal' => 600000,
    'discount' => 0,
    'tax' => 0,
    'total' => 600000,
    'customer_paid' => 600000,
    'payment_method' => 'cash',
    'items' => [
        ['product_id' => $product->id, 'quantity' => 3, 'price' => 200000, 'discount' => 0],
    ],
]);
callIt($ic, $req, 'store', 'TC4.2');

$afterCount = StockMovement::count();
check('1 movement mới được tạo', $afterCount === $beforeCount + 1, $pass, $fail, $errors, "before=$beforeCount, after=$afterCount");

$mov = StockMovement::where('product_id', $product->id)->latest('id')->first();
check('Type = out_invoice', $mov?->type === 'out_invoice', $pass, $fail, $errors, "actual={$mov?->type}");
check('Direction = out', $mov?->direction === 'out', $pass, $fail, $errors);
check('Qty = 3', (int)$mov?->qty === 3, $pass, $fail, $errors);
check('Balance qty = 7', (int)$mov?->balance_qty === 7, $pass, $fail, $errors, "actual={$mov?->balance_qty}");

// ─────────────────────────────────────────────────────
echo "\n── TC4.3: Hủy hóa đơn → in_invoice_return movement ──\n";

$invoice = Invoice::where('customer_id', $customer->id)->latest('id')->first();
$beforeCount = StockMovement::count();

$ic->destroy($invoice);

$afterCount = StockMovement::count();
check('1 movement mới được tạo', $afterCount === $beforeCount + 1, $pass, $fail, $errors, "before=$beforeCount, after=$afterCount");

$mov = StockMovement::where('product_id', $product->id)->latest('id')->first();
check('Type = in_invoice_return', $mov?->type === 'in_invoice_return', $pass, $fail, $errors, "actual={$mov?->type}");
check('Direction = in', $mov?->direction === 'in', $pass, $fail, $errors);
check('Qty = 3 (hoàn nhập)', (int)$mov?->qty === 3, $pass, $fail, $errors);
check('Balance qty = 10 (trở lại)', (int)$mov?->balance_qty === 10, $pass, $fail, $errors, "actual={$mov?->balance_qty}");

// ─────────────────────────────────────────────────────
echo "\n── TC4.4: Hủy phiếu nhập → out_purchase_return movement ──\n";

$purchase = Purchase::where('supplier_id', $supplier->id)->latest('id')->first();
$beforeCount = StockMovement::count();

$pc->destroy($purchase);

$afterCount = StockMovement::count();
check('1 movement mới được tạo', $afterCount === $beforeCount + 1, $pass, $fail, $errors, "before=$beforeCount, after=$afterCount");

$mov = StockMovement::where('product_id', $product->id)->latest('id')->first();
check('Type = out_purchase_return', $mov?->type === 'out_purchase_return', $pass, $fail, $errors, "actual={$mov?->type}");
check('Direction = out', $mov?->direction === 'out', $pass, $fail, $errors);
check('Qty = 10', (int)$mov?->qty === 10, $pass, $fail, $errors);
check('Balance qty = 0', (int)$mov?->balance_qty === 0, $pass, $fail, $errors, "actual={$mov?->balance_qty}");

// ─────────────────────────────────────────────────────
echo "\n── TC4.5: StockCard report ──\n";

$rc = app(\App\Http\Controllers\ReportController::class);
$req = Request::create('/reports/stock-card', 'GET', ['product_id' => $product->id]);
$resp = $rc->stockCard($req);

check('Response is Inertia\Response', $resp instanceof \Inertia\Response, $pass, $fail, $errors);

$ref = new ReflectionClass($resp);
$compProp = $ref->getProperty('component'); $compProp->setAccessible(true);
check('Component = Reports/StockCard', $compProp->getValue($resp) === 'Reports/StockCard', $pass, $fail, $errors);

$propsProp = $ref->getProperty('props'); $propsProp->setAccessible(true);
$rawProps = $propsProp->getValue($resp);
$paginator = $rawProps['movements'];
check('Có ít nhất 4 movements (4 dịch chuyển trên)', $paginator->total() >= 4, $pass, $fail, $errors, 'total=' . $paginator->total());

$stats = $rawProps['stats'];
check('Stats total_in_qty >= 13 (10 nhập + 3 hoàn)', $stats['total_in_qty'] >= 13, $pass, $fail, $errors, 'in=' . $stats['total_in_qty']);
check('Stats total_out_qty >= 13 (3 bán + 10 hủy nhập)', $stats['total_out_qty'] >= 13, $pass, $fail, $errors, 'out=' . $stats['total_out_qty']);

echo "\n════════════════════════════════════════════════════\n";
echo "  KẾT QUẢ: $pass passed, $fail failed\n";
echo "════════════════════════════════════════════════════\n";

if ($fail > 0) {
    echo "\nChi tiết lỗi:\n";
    foreach ($errors as $e) echo "  - $e\n";
    exit(1);
}
exit(0);
