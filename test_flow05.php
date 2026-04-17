<?php
/**
 * Flow 05 — Kiem thu Tra hang ban / Doi tra hang
 */
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Customer;
use App\Models\OrderReturn;
use App\Models\ReturnItem;
use App\Models\CashFlow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

Auth::loginUsingId(1);

$pass = 0; $fail = 0; $errors = [];

function test($label, $condition, &$pass, &$fail, &$errors, $detail = '') {
    if ($condition) {
        echo "  PASS $label\n";
        $pass++;
    } else {
        echo "  FAIL $label" . ($detail ? " -- $detail" : "") . "\n";
        $fail++;
        $errors[] = "$label: $detail";
    }
}

echo "\n=== FLOW 05 -- KIEM THU TRA HANG BAN ===\n\n";

// === CLEANUP ===
OrderReturn::where('note', 'LIKE', 'Test Flow05%')->each(function ($r) {
    CashFlow::where('reference_code', $r->code)->delete();
    ReturnItem::where('return_id', $r->id)->delete();
    $r->delete();
});
Invoice::where('code', 'LIKE', 'HD_F05%')->each(function ($inv) {
    InvoiceItem::where('invoice_id', $inv->id)->delete();
    $inv->delete();
});

// === SETUP ===
echo "-- Setup --\n";

$sp001 = Product::where('sku', 'SP001')->first();
$sp002 = Product::where('sku', 'SP002')->first();
$sp003 = Product::where('sku', 'SP003')->firstOrCreate(
    ['sku' => 'SP003'],
    ['name' => 'Sua hop', 'code' => 'SP003', 'retail_price' => 80000, 'cost_price' => 60000, 'stock_quantity' => 30, 'has_serial' => false]
);
$kh001 = Customer::where('code', 'KH001')->first();

if (!$sp001 || !$sp002 || !$kh001) {
    echo "  FAIL Missing base data.\n";
    exit(1);
}

// Save initial state
$initStock = ['SP001' => $sp001->stock_quantity, 'SP002' => $sp002->stock_quantity, 'SP003' => $sp003->stock_quantity];
$initDebt = $kh001->debt_amount;

// Invoice S1: SP001x10 + SP002x2 = 130K paid full
$invS1 = Invoice::create([
    'code' => 'HD_F05S1_' . time(), 'customer_id' => $kh001->id,
    'subtotal' => 130000, 'discount' => 0, 'total' => 130000,
    'customer_paid' => 130000, 'status' => 'Hoàn thành',
    'created_by_name' => 'Admin', 'created_at' => now()->subHours(3),
]);
$invS1->items()->createMany([
    ['product_id' => $sp001->id, 'quantity' => 10, 'price' => 7000, 'cost_price' => 5000, 'discount' => 0, 'subtotal' => 70000],
    ['product_id' => $sp002->id, 'quantity' => 2, 'price' => 30000, 'cost_price' => 20000, 'discount' => 0, 'subtotal' => 60000],
]);
$sp001->decrement('stock_quantity', 10);
$sp002->decrement('stock_quantity', 2);

// Invoice S2: SP003x2 = 160K paid 100K, debt 60K
$invS2 = Invoice::create([
    'code' => 'HD_F05S2_' . time(), 'customer_id' => $kh001->id,
    'subtotal' => 160000, 'discount' => 0, 'total' => 160000,
    'customer_paid' => 100000, 'status' => 'Hoàn thành',
    'created_by_name' => 'Admin', 'created_at' => now()->subHours(2),
]);
$invS2->items()->create([
    'product_id' => $sp003->id, 'quantity' => 2, 'price' => 80000, 'cost_price' => 60000, 'discount' => 0, 'subtotal' => 160000,
]);
$sp003->decrement('stock_quantity', 2);
$kh001->increment('debt_amount', 60000);

$sp001->refresh(); $sp002->refresh(); $sp003->refresh(); $kh001->refresh();
$salesStock = ['SP001' => $sp001->stock_quantity, 'SP002' => $sp002->stock_quantity, 'SP003' => $sp003->stock_quantity];

echo "  OK S1: {$invS1->code} (130K full)\n";
echo "  OK S2: {$invS2->code} (160K paid 100K)\n";
echo "  Stock SP001={$sp001->stock_quantity} SP002={$sp002->stock_quantity} SP003={$sp003->stock_quantity}\n";
echo "  KH001 debt={$kh001->debt_amount}\n";

$ctrl = new \App\Http\Controllers\OrderReturnController();

// === 05A: Tra hang theo HD goc ===
echo "\n-- 05A: Tra hang theo HD goc (S1, SP001 x 2) --\n";

$req = new \Illuminate\Http\Request();
$req->merge([
    'invoice_id' => $invS1->id, 'customer_id' => $kh001->id,
    'subtotal' => 14000, 'discount' => 0, 'fee' => 0,
    'total' => 14000, 'paid_to_customer' => 14000,
    'items' => [['product_id' => $sp001->id, 'qty' => 2, 'price' => 7000, 'discount' => 0]],
    'note' => 'Test Flow05A',
]);
$ctrl->store($req);

$sp001->refresh(); $kh001->refresh();
$ret05A = OrderReturn::where('note', 'Test Flow05A')->latest()->first();

test("Phieu tra tao OK", $ret05A !== null, $pass, $fail, $errors);
test("Status = Da tra", $ret05A && $ret05A->status === 'Đã trả', $pass, $fail, $errors);
test("Link invoice_id = S1", $ret05A && $ret05A->invoice_id == $invS1->id, $pass, $fail, $errors);
test("SP001 ton tang 2", $sp001->stock_quantity == $salesStock['SP001'] + 2, $pass, $fail, $errors, "got: {$sp001->stock_quantity}");
test("Total = 14000", $ret05A && $ret05A->total == 14000, $pass, $fail, $errors);
test("paid_to_customer = 14000", $ret05A && $ret05A->paid_to_customer == 14000, $pass, $fail, $errors);

$cf05A = CashFlow::where('reference_code', $ret05A->code)->where('reference_type', 'OrderReturn')->first();
test("CashFlow phieu chi ton tai", $cf05A !== null, $pass, $fail, $errors);
test("CashFlow amount = 14000", $cf05A && $cf05A->amount == 14000, $pass, $fail, $errors);

// === 05B: Phi tra hang ===
echo "\n-- 05B: Phi tra hang (S1, SP002 x 1, phi=5000) --\n";

$sp002->refresh();
$s02before = $sp002->stock_quantity;

$req = new \Illuminate\Http\Request();
$req->merge([
    'invoice_id' => $invS1->id, 'customer_id' => $kh001->id,
    'subtotal' => 30000, 'discount' => 0, 'fee' => 5000,
    'total' => 25000, 'paid_to_customer' => 25000,
    'items' => [['product_id' => $sp002->id, 'qty' => 1, 'price' => 30000, 'discount' => 0]],
    'note' => 'Test Flow05B',
]);
$ctrl->store($req);

$sp002->refresh(); $kh001->refresh();
$ret05B = OrderReturn::where('note', 'Test Flow05B')->latest()->first();

test("Phieu tra co phi OK", $ret05B !== null, $pass, $fail, $errors);
test("Fee = 5000", $ret05B && $ret05B->fee == 5000, $pass, $fail, $errors, "got: " . ($ret05B->fee ?? 'null'));
test("Total = 25000 (30K-5K)", $ret05B && $ret05B->total == 25000, $pass, $fail, $errors);
test("paid_to_customer = 25000", $ret05B && $ret05B->paid_to_customer == 25000, $pass, $fail, $errors);
test("SP002 ton tang 1", $sp002->stock_quantity == $s02before + 1, $pass, $fail, $errors, "got: {$sp002->stock_quantity}");

// === 05C: Tra mot phan ===
echo "\n-- 05C: Tra 1 phan (S1, SP001 x 3) --\n";

$sp001->refresh();
$s01before = $sp001->stock_quantity;

$req = new \Illuminate\Http\Request();
$req->merge([
    'invoice_id' => $invS1->id, 'customer_id' => $kh001->id,
    'subtotal' => 21000, 'discount' => 0, 'fee' => 0,
    'total' => 21000, 'paid_to_customer' => 21000,
    'items' => [['product_id' => $sp001->id, 'qty' => 3, 'price' => 7000, 'discount' => 0]],
    'note' => 'Test Flow05C',
]);
$ctrl->store($req);

$sp001->refresh();
$ret05C = OrderReturn::where('note', 'Test Flow05C')->latest()->first();

test("Phieu tra 1 phan OK", $ret05C !== null, $pass, $fail, $errors);
test("SP001 ton tang 3", $sp001->stock_quantity == $s01before + 3, $pass, $fail, $errors, "got: {$sp001->stock_quantity}");
test("Qty tra = 3", $ret05C && $ret05C->items->first()->quantity == 3, $pass, $fail, $errors);

// === 05D: Tra nhanh ===
echo "\n-- 05D: Tra nhanh SP003 x 1 (khong HD goc) --\n";

$sp003->refresh(); $kh001->refresh();
$s03before = $sp003->stock_quantity;
$debtBeforeQuick = $kh001->debt_amount;

$req = new \Illuminate\Http\Request();
$req->merge([
    'invoice_id' => null, 'customer_id' => $kh001->id,
    'subtotal' => 80000, 'discount' => 0, 'fee' => 0,
    'total' => 80000, 'paid_to_customer' => 80000,
    'items' => [['product_id' => $sp003->id, 'qty' => 1, 'price' => 80000, 'discount' => 0]],
    'note' => 'Test Flow05D',
]);
$ctrl->store($req);

$sp003->refresh(); $kh001->refresh();
$ret05D = OrderReturn::where('note', 'Test Flow05D')->latest()->first();

test("Phieu tra nhanh OK", $ret05D !== null, $pass, $fail, $errors);
test("invoice_id = NULL", $ret05D && $ret05D->invoice_id === null, $pass, $fail, $errors);
test("SP003 ton tang 1", $sp003->stock_quantity == $s03before + 1, $pass, $fail, $errors, "got: {$sp003->stock_quantity}");
test("Debt giam 80K", $kh001->debt_amount == $debtBeforeQuick - 80000, $pass, $fail, $errors, "got: {$kh001->debt_amount}");

// === 05E: Doi tra ===
echo "\n-- 05E: Doi tra cung GD --\n";
echo "  N/A: Missing Feature\n";

// === 05F: Quan ly phieu tra ===
echo "\n-- 05F: Quan ly phieu tra --\n";

$indexReq = new \Illuminate\Http\Request();
$indexResp = $ctrl->index($indexReq);
test("Index page renders", $indexResp !== null, $pass, $fail, $errors);

if ($ret05A) {
    $showResp = $ctrl->show($ret05A);
    test("Show detail renders", $showResp !== null, $pass, $fail, $errors);
}

$items05A = ReturnItem::where('return_id', $ret05A->id)->get();
test("05A items in DB", $items05A->count() == 1, $pass, $fail, $errors, "count: " . $items05A->count());
test("05A item qty = 2", $items05A->first() && $items05A->first()->quantity == 2, $pass, $fail, $errors);

// === 05G: Huy phieu tra & rollback ===
echo "\n-- 05G: Huy phieu tra & rollback (05A) --\n";

$sp001->refresh(); $kh001->refresh();
$stockBeforeCancel = $sp001->stock_quantity;
$debtBeforeCancel = $kh001->debt_amount;

// Call cancel directly
$ctrl->cancel($ret05A);

$ret05A->refresh(); $sp001->refresh(); $kh001->refresh();

test("Status = Da huy", $ret05A->status === 'Đã hủy', $pass, $fail, $errors, "got: {$ret05A->status}");
test("SP001 ton rollback -2", $sp001->stock_quantity == $stockBeforeCancel - 2, $pass, $fail, $errors, "got: {$sp001->stock_quantity}");
test("Debt rollback +14K", $kh001->debt_amount == $debtBeforeCancel + 14000, $pass, $fail, $errors, "got: {$kh001->debt_amount}");

$cfAfterCancel = CashFlow::where('reference_code', $ret05A->code)->where('reference_type', 'OrderReturn')->first();
test("CashFlow deleted after cancel", $cfAfterCancel === null, $pass, $fail, $errors);

// Cancel again should be blocked (status stays same)
$debtAfterFirst = $kh001->debt_amount;
$ctrl->cancel($ret05A);
$kh001->refresh();
test("Cancel lan 2: debt khong doi", $kh001->debt_amount == $debtAfterFirst, $pass, $fail, $errors, "got: {$kh001->debt_amount}");

// === 05H: Chuyen hoan ===
echo "\n-- 05H: Chuyen hoan van chuyen --\n";
echo "  N/A: Khong co module van chuyen\n";

// === SUMMARY ===
echo "\n=== KET QUA: $pass PASS / $fail FAIL ===\n\n";

if (count($errors) > 0) {
    echo "DANH SACH LOI:\n";
    foreach ($errors as $i => $e) {
        echo "  " . ($i + 1) . ". $e\n";
    }
}

// === Cleanup ===
echo "\n-- Cleanup --\n";
OrderReturn::where('note', 'LIKE', 'Test Flow05%')->each(function ($r) {
    CashFlow::where('reference_code', $r->code)->delete();
    ReturnItem::where('return_id', $r->id)->delete();
    $r->delete();
});
Invoice::where('code', 'LIKE', 'HD_F05%')->each(function ($inv) {
    InvoiceItem::where('invoice_id', $inv->id)->delete();
    $inv->delete();
});
$sp001 = Product::where('sku', 'SP001')->first();
$sp002 = Product::where('sku', 'SP002')->first();
$sp003 = Product::where('sku', 'SP003')->first();
$kh001 = Customer::where('code', 'KH001')->first();
if ($sp001) $sp001->update(['stock_quantity' => $initStock['SP001']]);
if ($sp002) $sp002->update(['stock_quantity' => $initStock['SP002']]);
if ($sp003) $sp003->update(['stock_quantity' => $initStock['SP003']]);
if ($kh001) $kh001->update(['debt_amount' => $initDebt]);
echo "  OK Cleaned up & restored state\n";
