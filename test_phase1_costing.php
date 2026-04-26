<?php
/**
 * Phase 1 Costing Verification
 *
 * Test cases:
 *  1) Hàng thường: bình quân gia quyền sau nhập 2 lần (Test case 1 trong tài liệu)
 *  2) Hàng thường: trả hàng bán DÙNG cost_price_at_sale, không phải cost hiện tại (TC3)
 *  3) Serial/IMEI: nhập 3 IMEI giá khác, bán IMEI001 → đích danh (TC4-5)
 *  4) Serial/IMEI: trả IMEI001 → restore đúng giá vốn lúc bán (TC6)
 */
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\OrderReturn;
use App\Models\SerialImei;
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

echo "\n════════════════════════════════════════════════════\n";
echo "  PHASE 1 COSTING VERIFICATION\n";
echo "════════════════════════════════════════════════════\n";

function callStore($controller, Request $request, string $tag): void
{
    // Tránh trùng code 'PC'.date('YmdHis') trong CashFlow giữa các call
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
        $resp = $controller->store($request);
        if ($resp instanceof \Illuminate\Http\RedirectResponse) {
            $errors = $resp->getSession()?->get('errors');
            if ($errors && method_exists($errors, 'getBag')) {
                $bag = $errors->getBag('default');
                if ($bag->any()) {
                    echo "    ! $tag returned errors: " . json_encode($bag->all()) . "\n";
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

// Cleanup any leftover test data
$sku = 'TEST-COST-NORMAL-' . time();
$skuSerial = 'TEST-COST-SERIAL-' . time();
$serialPrefix = 'IMEI' . substr((string) time(), -6);
$imeiA = $serialPrefix . 'A';
$imeiB = $serialPrefix . 'B';
$imeiC = $serialPrefix . 'C';

DB::transaction(function () {
    Product::where('sku', 'like', 'TEST-COST-%')->delete();
});

// Supplier (Customer with is_supplier flag)
$supplier = Customer::where('code', 'TEST-SUP-COSTING')->first();
if (!$supplier) {
    $supplier = Customer::create([
        'code' => 'TEST-SUP-COSTING',
        'name' => 'NCC Test Costing',
        'phone' => '0900' . substr((string) time(), -6),
        'is_supplier' => true,
    ]);
}
if (!$supplier->is_supplier) {
    $supplier->is_supplier = true;
    $supplier->save();
}

// ── TEST 1: Hàng thường — bình quân gia quyền ──
echo "\n── TEST 1: Hàng thường — bình quân gia quyền ──\n";

$normal = Product::create([
    'sku' => $sku,
    'name' => 'TEST Sản phẩm thường',
    'cost_price' => 0,
    'retail_price' => 8000000,
    'stock_quantity' => 0,
    'has_serial' => false,
    'is_active' => true,
    'type' => 'standard',
]);

// Tồn đầu: 10 × 5,000,000 (giả lập = 1 phiếu nhập)
$pc = app(\App\Http\Controllers\PurchaseController::class);

$req1 = Request::create('/purchases', 'POST', [
    'code' => 'PN-T1A-' . uniqid(),
    'supplier_id' => $supplier->id,
    'paid_amount' => 50000000,
    'payment_method' => 'cash',
    'status' => 'completed',
    'items' => [[
        'product_id' => $normal->id,
        'quantity' => 10,
        'price' => 5000000,
        'discount' => 0,
    ]],
]);
$req1->setLaravelSession(app('session.store'));
callStore($pc, $req1, 'purchase 1');

$normal->refresh();
check('Sau nhập 1: stock = 10', $normal->stock_quantity == 10, $pass, $fail, $errors, "actual={$normal->stock_quantity}");
check('Sau nhập 1: cost_price = 5,000,000', approxEqual((float)$normal->cost_price, 5000000), $pass, $fail, $errors, "actual={$normal->cost_price}");

// Nhập tiếp: 5 × 6,000,000 → cost mới = (50M+30M)/15 = 5,333,333.33
$req2 = Request::create('/purchases', 'POST', [
    'code' => 'PN-T1B-' . uniqid(),
    'supplier_id' => $supplier->id,
    'paid_amount' => 30000000,
    'payment_method' => 'cash',
    'status' => 'completed',
    'items' => [[
        'product_id' => $normal->id,
        'quantity' => 5,
        'price' => 6000000,
        'discount' => 0,
    ]],
]);
$req2->setLaravelSession(app('session.store'));
callStore($pc, $req2, 'purchase 2');

$normal->refresh();
check('Sau nhập 2: stock = 15', $normal->stock_quantity == 15, $pass, $fail, $errors, "actual={$normal->stock_quantity}");
check('Sau nhập 2: cost_price ≈ 5,333,333.33', approxEqual((float)$normal->cost_price, 5333333.33, 1), $pass, $fail, $errors, "actual={$normal->cost_price}");

// ── TEST 2: Bán → cost snapshot, sau đó nhập lần 3 → trả hàng cũ phải dùng cost LÚC BÁN ──
echo "\n── TEST 2: Trả hàng bán dùng cost_price_at_sale ──\n";

$ic = app(\App\Http\Controllers\InvoiceController::class);

$invReq = Request::create('/invoices', 'POST', [
    'customer_id' => null,
    'subtotal' => 16000000,
    'discount' => 0,
    'total' => 16000000,
    'customer_paid' => 16000000,
    'payment_method' => 'Tiền mặt',
    'items' => [[
        'product_id' => $normal->id,
        'quantity' => 2,
        'price' => 8000000,
        'discount' => 0,
    ]],
]);
$invReq->setLaravelSession(app('session.store'));
callStore($ic, $invReq, 'invoice');

$invoice = Invoice::orderByDesc('id')->first();
$invItem = $invoice->items()->where('product_id', $normal->id)->first();

check('invoice_item.cost_price ≈ 5,333,333.33 (snapshot lúc bán)', approxEqual((float)$invItem->cost_price, 5333333.33, 1), $pass, $fail, $errors, "actual={$invItem->cost_price}");

$normal->refresh();
check('Sau bán: stock = 13', $normal->stock_quantity == 13, $pass, $fail, $errors, "actual={$normal->stock_quantity}");

// Nhập thêm 7 × 7,000,000 → cost MỚI = (13×5,333,333.33 + 7×7,000,000) / 20 ≈ 5,916,666.67
$req3 = Request::create('/purchases', 'POST', [
    'code' => 'PN-T2-' . uniqid(),
    'supplier_id' => $supplier->id,
    'paid_amount' => 49000000,
    'payment_method' => 'cash',
    'status' => 'completed',
    'items' => [[
        'product_id' => $normal->id,
        'quantity' => 7,
        'price' => 7000000,
        'discount' => 0,
    ]],
]);
$req3->setLaravelSession(app('session.store'));
callStore($pc, $req3, 'purchase 3');

$normal->refresh();
$costBeforeReturn = (float) $normal->cost_price;
check('Sau nhập 3: cost ≈ 5,916,666.67', approxEqual($costBeforeReturn, 5916666.67, 2), $pass, $fail, $errors, "actual={$costBeforeReturn}");

// Trả 1 cái từ HĐ cũ — phải dùng cost lúc bán = 5,333,333.33
$orc = app(\App\Http\Controllers\OrderReturnController::class);
$retReq = Request::create('/returns', 'POST', [
    'invoice_id' => $invoice->id,
    'subtotal' => 8000000,
    'total' => 8000000,
    'paid_to_customer' => 8000000,
    'items' => [[
        'product_id' => $normal->id,
        'invoice_item_id' => $invItem->id,
        'qty' => 1,
        'price' => 8000000,
        'discount' => 0,
    ]],
]);
$retReq->setLaravelSession(app('session.store'));
callStore($orc, $retReq, 'return');

$lastReturn = OrderReturn::orderByDesc('id')->first();
$retItem = $lastReturn->items()->where('product_id', $normal->id)->first();

check('return_item.cost_price = 5,333,333.33 (cost lúc bán, KHÔNG phải 5,916,666)',
    approxEqual((float)$retItem->cost_price, 5333333.33, 1),
    $pass, $fail, $errors, "actual={$retItem->cost_price}");

$normal->refresh();
// Cost mới = (20 × 5,916,666.67 + 1 × 5,333,333.33) / 21 ≈ 5,888,888.89
$expectedCost = (20 * $costBeforeReturn + 1 * 5333333.33) / 21;
check('Sau trả: cost gộp đúng theo cost lúc bán', approxEqual((float)$normal->cost_price, $expectedCost, 5), $pass, $fail, $errors, "expected≈" . round($expectedCost, 2) . ", actual={$normal->cost_price}");
check('Sau trả: stock = 21', $normal->stock_quantity == 21, $pass, $fail, $errors, "actual={$normal->stock_quantity}");

// ── TEST 3: Serial/IMEI đích danh ──
echo "\n── TEST 3: Serial/IMEI giá vốn đích danh ──\n";

$serialP = Product::create([
    'sku' => $skuSerial,
    'name' => 'TEST IMEI Phone',
    'cost_price' => 0,
    'retail_price' => 13000000,
    'stock_quantity' => 0,
    'has_serial' => true,
    'is_active' => true,
    'type' => 'standard',
]);

// Nhập 3 IMEI: 10M, 11M, 12M (3 phiếu nhập riêng để mỗi phiếu có cost riêng)
foreach ([[$imeiA, 10000000], [$imeiB, 11000000], [$imeiC, 12000000]] as [$imei, $price]) {
    $r = Request::create('/purchases', 'POST', [
        'code' => 'PN-' . $imei,
        'supplier_id' => $supplier->id,
        'paid_amount' => $price,
        'payment_method' => 'cash',
        'status' => 'completed',
        'items' => [[
            'product_id' => $serialP->id,
            'quantity' => 1,
            'price' => $price,
            'discount' => 0,
            'serials' => [$imei],
        ]],
    ]);
    $r->setLaravelSession(app('session.store'));
    callStore($pc, $r, "serial purchase $imei");
}

$s1 = SerialImei::where('serial_number', $imeiA)->first();
$s2 = SerialImei::where('serial_number', $imeiB)->first();
$s3 = SerialImei::where('serial_number', $imeiC)->first();

check("$imeiA.cost_price = 10,000,000", $s1 && (int)$s1->cost_price === 10000000, $pass, $fail, $errors, "actual=" . ($s1->cost_price ?? 'null'));
check("$imeiB.cost_price = 11,000,000", $s2 && (int)$s2->cost_price === 11000000, $pass, $fail, $errors, "actual=" . ($s2->cost_price ?? 'null'));
check("$imeiC.cost_price = 12,000,000", $s3 && (int)$s3->cost_price === 12000000, $pass, $fail, $errors, "actual=" . ($s3->cost_price ?? 'null'));

// Bán IMEI001 → COGS PHẢI = 10M (đích danh), KHÔNG phải avg 11M
$invReq2 = Request::create('/invoices', 'POST', [
    'customer_id' => null,
    'subtotal' => 13000000,
    'discount' => 0,
    'total' => 13000000,
    'customer_paid' => 13000000,
    'payment_method' => 'Tiền mặt',
    'items' => [[
        'product_id' => $serialP->id,
        'quantity' => 1,
        'price' => 13000000,
        'discount' => 0,
        'serial_ids' => [$s1->id],
    ]],
]);
$invReq2->setLaravelSession(app('session.store'));
callStore($ic, $invReq2, 'invoice serial');

$invoice2 = Invoice::orderByDesc('id')->first();
$invItem2 = $invoice2->items()->where('product_id', $serialP->id)->first();

check("invoice_item.cost_price = 10,000,000 (đích danh $imeiA)",
    (int)$invItem2->cost_price === 10000000,
    $pass, $fail, $errors, "actual={$invItem2->cost_price}");

$linkRow = InvoiceItemSerial::where('invoice_item_id', $invItem2->id)->where('serial_imei_id', $s1->id)->first();
check("invoice_item_serials có dòng cho $imeiA với cost = 10M",
    $linkRow && (int)$linkRow->cost_price === 10000000,
    $pass, $fail, $errors, $linkRow ? "cost={$linkRow->cost_price}" : 'no row');

$s1->refresh();
check("$imeiA.status = sold", $s1->status === 'sold', $pass, $fail, $errors, "status={$s1->status}");
check("$imeiA.sold_cost_price = 10,000,000", (int)$s1->sold_cost_price === 10000000, $pass, $fail, $errors, "actual=" . ($s1->sold_cost_price ?? 'null'));

// ── TEST 4: Trả IMEI001 → restore cost = 10M ──
echo "\n── TEST 4: Trả serial → restore đúng giá vốn lúc bán ──\n";

$retReq2 = Request::create('/returns', 'POST', [
    'invoice_id' => $invoice2->id,
    'subtotal' => 13000000,
    'total' => 13000000,
    'paid_to_customer' => 13000000,
    'items' => [[
        'product_id' => $serialP->id,
        'invoice_item_id' => $invItem2->id,
        'serial_ids' => [$s1->id],
        'qty' => 1,
        'price' => 13000000,
        'discount' => 0,
    ]],
]);
$retReq2->setLaravelSession(app('session.store'));
callStore($orc, $retReq2, 'serial return');

$lastRet2 = OrderReturn::orderByDesc('id')->first();
$retItem2 = $lastRet2->items()->where('product_id', $serialP->id)->first();

check("return_item.cost_price = 10,000,000 (đúng cost $imeiA lúc bán)",
    (int)$retItem2->cost_price === 10000000,
    $pass, $fail, $errors, "actual={$retItem2->cost_price}");

$s1->refresh();
check("$imeiA.status restored = in_stock", $s1->status === 'in_stock', $pass, $fail, $errors, "status={$s1->status}");
check("$imeiA.cost_price = 10,000,000 (giữ nguyên)", (int)$s1->cost_price === 10000000, $pass, $fail, $errors, "actual={$s1->cost_price}");
check("$imeiA.sold_cost_price = null (cleared)", $s1->sold_cost_price === null, $pass, $fail, $errors, "actual=" . var_export($s1->sold_cost_price, true));
check("$imeiA.invoice_id = null", $s1->invoice_id === null, $pass, $fail, $errors, "actual=" . var_export($s1->invoice_id, true));

// ── KẾT QUẢ ──
echo "\n════════════════════════════════════════════════════\n";
echo "  KẾT QUẢ: $pass passed, $fail failed\n";
if ($fail > 0) {
    echo "\n  Errors:\n";
    foreach ($errors as $e) echo "    - $e\n";
}
echo "════════════════════════════════════════════════════\n\n";

exit($fail > 0 ? 1 : 0);
