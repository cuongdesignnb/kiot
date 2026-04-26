<?php
/**
 * Phase 2 Costing Verification
 *
 * Test cases:
 *  TC2.1) Hàng thường: Phân bổ phí nhập (other_costs) theo tỉ lệ
 *  TC2.2) Hàng thường: Trả NCC dùng unit_cost_allocated, KHÔNG dùng giá trả
 *  TC2.3) Hàng thường: Hủy hóa đơn → đảo cost theo invoice_item.cost_price
 *  TC2.4) Hàng thường: Hủy phiếu nhập → đảo cost theo unit_cost_allocated
 */
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
                if ($bag->any()) {
                    echo "    ! $tag errors: " . json_encode($bag->all()) . "\n";
                }
            }
            $errMsg = $resp->getSession()?->get('error');
            if ($errMsg) {
                echo "    ! $tag flash error: $errMsg\n";
            }
        }
    } catch (\Illuminate\Validation\ValidationException $e) {
        echo "    ! $tag validation: " . json_encode($e->errors()) . "\n";
    } catch (\Throwable $e) {
        echo "    ! $tag error: " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

Setting::set('inventory_costing_method', 'average');

echo "\n════════════════════════════════════════════════════\n";
echo "  PHASE 2 COSTING VERIFICATION\n";
echo "════════════════════════════════════════════════════\n";

$pc = app(\App\Http\Controllers\PurchaseController::class);
$prc = app(\App\Http\Controllers\PurchaseReturnController::class);
$ic = app(\App\Http\Controllers\InvoiceController::class);

$supplier = Customer::create([
    'name' => 'TEST NCC P2',
    'code' => 'NCC-P2-' . substr((string)time(), -6),
    'phone' => '0901' . substr((string)time(), -6),
    'is_supplier' => true,
]);

$skuA = 'TEST-P2-A-' . time();
$skuB = 'TEST-P2-B-' . time();

$pa = Product::create([
    'sku' => $skuA, 'name' => 'TEST P2 A',
    'cost_price' => 0, 'retail_price' => 200000,
    'stock_quantity' => 0, 'has_serial' => false, 'is_active' => true, 'type' => 'standard',
]);
$pb = Product::create([
    'sku' => $skuB, 'name' => 'TEST P2 B',
    'cost_price' => 0, 'retail_price' => 300000,
    'stock_quantity' => 0, 'has_serial' => false, 'is_active' => true, 'type' => 'standard',
]);

// ─────────────────────────────────────────────────────
echo "\n── TC2.1: Phân bổ phí nhập theo tỉ lệ subtotal ──\n";
// Nhập: A 10×100k = 1.000k ; B 5×200k = 1.000k. Total goods = 2.000k.
// Phí nhập 200k → A nhận 100k, B nhận 100k.
// → A unit_cost = (1000k+100k)/10 = 110k ; B unit_cost = (1000k+100k)/5 = 220k
$req = Request::create('/purchases', 'POST', [
    'code' => 'PN-P2A-' . uniqid(),
    'supplier_id' => $supplier->id,
    'paid_amount' => 2200000,
    'payment_method' => 'cash',
    'status' => 'completed',
    'other_costs' => [['name' => 'Vận chuyển', 'amount' => 200000]],
    'items' => [
        ['product_id' => $pa->id, 'quantity' => 10, 'price' => 100000, 'discount' => 0],
        ['product_id' => $pb->id, 'quantity' => 5, 'price' => 200000, 'discount' => 0],
    ],
]);
callIt($pc, $req, 'store', 'purchase TC2.1');

$pa->refresh(); $pb->refresh();
$piA = PurchaseItem::where('product_id', $pa->id)->latest('id')->first();
$piB = PurchaseItem::where('product_id', $pb->id)->latest('id')->first();

check('A unit_cost_allocated = 110,000', approxEqual((float)$piA->unit_cost_allocated, 110000), $pass, $fail, $errors, "actual={$piA->unit_cost_allocated}");
check('B unit_cost_allocated = 220,000', approxEqual((float)$piB->unit_cost_allocated, 220000), $pass, $fail, $errors, "actual={$piB->unit_cost_allocated}");
check('A.cost_price = 110,000', approxEqual((float)$pa->cost_price, 110000), $pass, $fail, $errors, "actual={$pa->cost_price}");
check('B.cost_price = 220,000', approxEqual((float)$pb->cost_price, 220000), $pass, $fail, $errors, "actual={$pb->cost_price}");
check('A.stock = 10', $pa->stock_quantity == 10, $pass, $fail, $errors, "actual={$pa->stock_quantity}");
check('B.stock = 5', $pb->stock_quantity == 5, $pass, $fail, $errors, "actual={$pb->stock_quantity}");

// ─────────────────────────────────────────────────────
echo "\n── TC2.2: Trả NCC dùng unit_cost_allocated ──\n";
// Trả 2 cái A với giá trả khác (vd: 90k thay vì 110k allocated).
// Yêu cầu: cost reversal phải dùng 110k (unit_cost_allocated), KHÔNG dùng 90k.
// Trước: A 10 × 110k = 1.100k.
// Sau khi trả 2 với unit_cost 110k: còn 8 × 110k = 880k → cost vẫn 110k.
$lastPurchase = Purchase::latest('id')->first();
$req = Request::create('/purchase-returns', 'POST', [
    'code' => 'PTN-P2-' . uniqid(),
    'purchase_id' => $lastPurchase->id,
    'items' => [
        ['product_id' => $pa->id, 'quantity' => 2, 'price' => 90000],
    ],
    'refund_amount' => 180000,
    'payment_method' => 'cash',
]);
callIt($prc, $req, 'store', 'purchase return TC2.2');

$pa->refresh();
check('A.stock sau trả = 8', $pa->stock_quantity == 8, $pass, $fail, $errors, "actual={$pa->stock_quantity}");
check('A.cost_price không đổi = 110,000 (KHÔNG bị méo bởi giá trả 90k)', approxEqual((float)$pa->cost_price, 110000), $pass, $fail, $errors, "actual={$pa->cost_price}");

$retItem = \App\Models\PurchaseReturnItem::latest('id')->first();
check('purchase_return_item.cost_price = 110,000 (lưu giá vốn lúc xuất)', approxEqual((float)$retItem->cost_price, 110000), $pass, $fail, $errors, "actual={$retItem->cost_price}");

// ─────────────────────────────────────────────────────
echo "\n── TC2.3: Hủy hóa đơn → đảo cost theo invoice_item.cost_price ──\n";
// Setup: bán 2 cái A với cost hiện tại 110k.
// Sau bán: stock=6, cost=110k (không đổi). Nhập thêm 4 × 200k.
// → cost = (6×110k + 4×200k)/10 = 1460k/10 = 146k. Stock=10.
// Hủy hóa đơn (2 cái cost 110k lúc bán): đảo gộp = (10×146k + 2×110k)/12 = 1680k/12 = 140k. Stock=12.
$invReq = Request::create('/invoices', 'POST', [
    'customer_id' => null,
    'subtotal' => 400000, 'discount' => 0, 'total' => 400000, 'customer_paid' => 400000,
    'payment_method' => 'cash',
    'items' => [['product_id' => $pa->id, 'quantity' => 2, 'price' => 200000, 'discount' => 0]],
]);
callIt($ic, $invReq, 'store', 'invoice TC2.3');

$pa->refresh();
$invoice = Invoice::latest('id')->first();
$invItem = $invoice->items()->where('product_id', $pa->id)->first();
check('invoice_item.cost_price snapshot = 110,000', approxEqual((float)$invItem->cost_price, 110000), $pass, $fail, $errors, "actual={$invItem->cost_price}");
check('Sau bán: A.stock = 6', $pa->stock_quantity == 6, $pass, $fail, $errors, "actual={$pa->stock_quantity}");

// Nhập thêm 4×200k (no other_costs)
$req = Request::create('/purchases', 'POST', [
    'code' => 'PN-P2C-' . uniqid(),
    'supplier_id' => $supplier->id,
    'paid_amount' => 800000,
    'payment_method' => 'cash',
    'status' => 'completed',
    'items' => [['product_id' => $pa->id, 'quantity' => 4, 'price' => 200000, 'discount' => 0]],
]);
callIt($pc, $req, 'store', 'purchase TC2.3');

$pa->refresh();
check('Sau nhập thêm 4×200k: A.cost = 146,000', approxEqual((float)$pa->cost_price, 146000), $pass, $fail, $errors, "actual={$pa->cost_price}");
check('A.stock = 10', $pa->stock_quantity == 10, $pass, $fail, $errors, "actual={$pa->stock_quantity}");

// Hủy hóa đơn
try {
    $ic->destroy($invoice);
} catch (\Throwable $e) {
    echo "    ! destroy invoice: " . $e->getMessage() . "\n";
}

$pa->refresh();
check('Sau hủy HĐ: A.stock = 12', $pa->stock_quantity == 12, $pass, $fail, $errors, "actual={$pa->stock_quantity}");
check('Sau hủy HĐ: A.cost = 140,000 (gộp theo cost lúc bán 110k)', approxEqual((float)$pa->cost_price, 140000), $pass, $fail, $errors, "actual={$pa->cost_price}");

// ─────────────────────────────────────────────────────
echo "\n── TC2.4: Hủy phiếu nhập → đảo cost theo unit_cost_allocated ──\n";
// Tạo product C riêng để test cô lập:
// Nhập 5 × 100k với phí 100k → unit_cost_allocated = (500k+100k)/5 = 120k. Stock=5, cost=120k.
// Hủy phiếu: stock = 0, cost = 0.
$skuC = 'TEST-P2-C-' . time();
$pcc = Product::create([
    'sku' => $skuC, 'name' => 'TEST P2 C',
    'cost_price' => 0, 'retail_price' => 200000,
    'stock_quantity' => 0, 'has_serial' => false, 'is_active' => true, 'type' => 'standard',
]);

$req = Request::create('/purchases', 'POST', [
    'code' => 'PN-P2D-' . uniqid(),
    'supplier_id' => $supplier->id,
    'paid_amount' => 600000,
    'payment_method' => 'cash',
    'status' => 'completed',
    'other_costs' => [['name' => 'Vận chuyển', 'amount' => 100000]],
    'items' => [['product_id' => $pcc->id, 'quantity' => 5, 'price' => 100000, 'discount' => 0]],
]);
callIt($pc, $req, 'store', 'purchase TC2.4');

$pcc->refresh();
$piC = PurchaseItem::where('product_id', $pcc->id)->latest('id')->first();
check('C unit_cost_allocated = 120,000', approxEqual((float)$piC->unit_cost_allocated, 120000), $pass, $fail, $errors, "actual={$piC->unit_cost_allocated}");
check('C.cost_price = 120,000', approxEqual((float)$pcc->cost_price, 120000), $pass, $fail, $errors, "actual={$pcc->cost_price}");
check('C.stock = 5', $pcc->stock_quantity == 5, $pass, $fail, $errors, "actual={$pcc->stock_quantity}");

// Hủy phiếu nhập
$pnP2D = Purchase::where('code', 'like', 'PN-P2D-%')->latest('id')->first();
try {
    $pc->destroy($pnP2D);
} catch (\Throwable $e) {
    echo "    ! destroy purchase: " . $e->getMessage() . "\n";
}

$pcc->refresh();
check('Sau hủy PN: C.stock = 0', $pcc->stock_quantity == 0, $pass, $fail, $errors, "actual={$pcc->stock_quantity}");
check('Sau hủy PN: C.cost = 0', approxEqual((float)$pcc->cost_price, 0), $pass, $fail, $errors, "actual={$pcc->cost_price}");

// ─────────────────────────────────────────────────────
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
