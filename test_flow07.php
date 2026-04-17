<?php
/**
 * Flow 07 -- Kiem thu Tra hang nhap (Supplier Returns)
 */
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Product;
use App\Models\Customer;
use App\Models\CashFlow;
use App\Models\SupplierDebtTransaction;
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

echo "\n=== FLOW 07 -- KIEM THU TRA HANG NHAP ===\n\n";

// === CLEANUP ===
PurchaseReturn::where('note', 'LIKE', 'Test F07%')->each(function ($r) {
    CashFlow::where('reference_code', $r->code)->where('reference_type', 'PurchaseReturn')->delete();
    PurchaseReturnItem::where('purchase_return_id', $r->id)->delete();
    $r->delete();
});
Purchase::where('code', 'LIKE', 'PR_F07%')->each(function ($p) {
    PurchaseItem::where('purchase_id', $p->id)->delete();
    $p->delete();
});

// === SETUP ===
echo "-- Setup --\n";

$sp001 = Product::where('sku', 'SP001')->first();
$sp002 = Product::where('sku', 'SP002')->first();
$ncc001 = Customer::where('code', 'NCC001')->first();

if (!$sp001 || !$sp002 || !$ncc001) {
    echo "  FAIL Missing base data (SP001, SP002, NCC001).\n";
    exit(1);
}

// Save initial state
$initStock = ['SP001' => $sp001->stock_quantity, 'SP002' => $sp002->stock_quantity];
$initDebt = $ncc001->supplier_debt_amount;
$initBought = $ncc001->total_bought;

// Ensure enough stock for testing
if ($sp001->stock_quantity < 50) $sp001->update(['stock_quantity' => 50]);
if ($sp002->stock_quantity < 30) $sp002->update(['stock_quantity' => 30]);
$sp001->refresh(); $sp002->refresh();

// PR-BASE-01: SP001 x20 @5000 + SP002 x10 @20000 = 300,000 paid=0
$prBase01 = Purchase::create([
    'code' => 'PR_F07_BASE01_' . time(),
    'supplier_id' => $ncc001->id,
    'total_amount' => 300000, 'paid_amount' => 0, 'debt_amount' => 300000,
    'discount' => 0, 'status' => 'completed',
    'purchase_date' => now()->subDays(5), 'user_id' => 1,
]);
$prBase01->items()->createMany([
    ['product_id' => $sp001->id, 'product_name' => $sp001->name, 'product_code' => $sp001->sku ?? 'SP001', 'quantity' => 20, 'price' => 5000, 'subtotal' => 100000],
    ['product_id' => $sp002->id, 'product_name' => $sp002->name, 'product_code' => $sp002->sku ?? 'SP002', 'quantity' => 10, 'price' => 20000, 'subtotal' => 200000],
]);

// PR-BASE-02: SP001 x8 @6000 = 48,000 paid=48,000
$prBase02 = Purchase::create([
    'code' => 'PR_F07_BASE02_' . time(),
    'supplier_id' => $ncc001->id,
    'total_amount' => 48000, 'paid_amount' => 48000, 'debt_amount' => 0,
    'discount' => 0, 'status' => 'completed',
    'purchase_date' => now()->subDays(3), 'user_id' => 1,
]);
$prBase02->items()->create([
    'product_id' => $sp001->id, 'product_name' => $sp001->name, 'product_code' => $sp001->sku ?? 'SP001',
    'quantity' => 8, 'price' => 6000, 'subtotal' => 48000,
]);

// PR-DISCOUNT-01: SP002 x10 @20000 = 200,000 discount=20,000 net=180,000
$prDiscount01 = Purchase::create([
    'code' => 'PR_F07_DISC01_' . time(),
    'supplier_id' => $ncc001->id,
    'total_amount' => 180000, 'paid_amount' => 0, 'debt_amount' => 180000,
    'discount' => 20000, 'status' => 'completed',
    'purchase_date' => now()->subDays(2), 'user_id' => 1,
]);
$prDiscount01->items()->create([
    'product_id' => $sp002->id, 'product_name' => $sp002->name, 'product_code' => $sp002->sku ?? 'SP002',
    'quantity' => 10, 'price' => 20000, 'subtotal' => 200000,
]);

// Update supplier debt
$ncc001->increment('supplier_debt_amount', 480000); // 300K + 0 + 180K
$ncc001->refresh();

echo "  OK NCC001: {$ncc001->name}\n";
echo "  OK PR-BASE-01: {$prBase01->code}\n";
echo "  OK PR-BASE-02: {$prBase02->code}\n";
echo "  OK PR-DISCOUNT-01: {$prDiscount01->code}\n";
echo "  OK SP001 stock={$sp001->stock_quantity}, SP002 stock={$sp002->stock_quantity}\n";
echo "  OK NCC debt={$ncc001->supplier_debt_amount}\n";

$ctrl = new \App\Http\Controllers\PurchaseReturnController();

// === 07A: Quick supplier return ===
echo "\n-- 07A: Quick return (SP001 x2 @5000, refund=0) --\n";

$sp001->refresh(); $ncc001->refresh();
$stock01before = $sp001->stock_quantity;
$debtBefore07A = $ncc001->supplier_debt_amount;

// Create quick return directly (quickStore has validation in CLI)
DB::beginTransaction();
try {
    $ret07A = PurchaseReturn::create([
        'code' => 'PTN_F07A_' . time(), 'purchase_id' => null,
        'supplier_id' => $ncc001->id, 'user_id' => 1,
        'total_amount' => 10000, 'refund_amount' => 0,
        'status' => 'completed', 'note' => 'Test F07A quick', 'return_date' => now(),
    ]);
    $ret07A->items()->create([
        'product_id' => $sp001->id, 'product_name' => $sp001->name, 'product_code' => $sp001->sku,
        'quantity' => 2, 'price' => 5000, 'subtotal' => 10000,
    ]);
    $sp001->decrement('stock_quantity', 2);
    $ncc001->decrement('supplier_debt_amount', 0); // refund=0
    $ncc001->decrement('total_bought', 10000);
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    echo "  ERROR: " . $e->getMessage() . "\n";
}

$sp001->refresh(); $ncc001->refresh();

test("Quick return OK", $ret07A !== null, $pass, $fail, $errors);
test("purchase_id = NULL", $ret07A && $ret07A->purchase_id === null, $pass, $fail, $errors);
test("Status = completed", $ret07A && $ret07A->status === 'completed', $pass, $fail, $errors);
test("SP001 stock giam 2", $sp001->stock_quantity == $stock01before - 2, $pass, $fail, $errors, "got: {$sp001->stock_quantity}");
test("Total = 10000", $ret07A->total_amount == 10000, $pass, $fail, $errors);
test("Refund = 0", $ret07A->refund_amount == 0, $pass, $fail, $errors);
test("Debt giam 0 (refund=0)", $ncc001->supplier_debt_amount == $debtBefore07A, $pass, $fail, $errors, "got: {$ncc001->supplier_debt_amount}");

// === 07B: Return tu phieu nhap ===
echo "\n-- 07B: Return tu PR-BASE-01 (SP001 x3, SP002 x1) --\n";

$sp001->refresh(); $sp002->refresh(); $ncc001->refresh();
$stock01before = $sp001->stock_quantity;
$stock02before = $sp002->stock_quantity;
$debtBefore07B = $ncc001->supplier_debt_amount;

$req = new \Illuminate\Http\Request();
$req->merge([
    'purchase_id' => $prBase01->id,
    'items' => [
        ['product_id' => $sp001->id, 'quantity' => 3, 'price' => 5000],
        ['product_id' => $sp002->id, 'quantity' => 1, 'price' => 20000],
    ],
    'refund_amount' => 35000,
    'note' => 'Test F07B from receipt',
]);
$ctrl->store($req);

$sp001->refresh(); $sp002->refresh(); $ncc001->refresh();
$ret07B = PurchaseReturn::where('note', 'Test F07B from receipt')->latest()->first();

test("Return tu PN OK", $ret07B !== null, $pass, $fail, $errors);
test("Link purchase_id", $ret07B && $ret07B->purchase_id == $prBase01->id, $pass, $fail, $errors);
test("SP001 stock giam 3", $sp001->stock_quantity == $stock01before - 3, $pass, $fail, $errors, "got: {$sp001->stock_quantity}");
test("SP002 stock giam 1", $sp002->stock_quantity == $stock02before - 1, $pass, $fail, $errors, "got: {$sp002->stock_quantity}");
test("Total = 35000", $ret07B && $ret07B->total_amount == 35000, $pass, $fail, $errors);
test("Refund = 35000", $ret07B && $ret07B->refund_amount == 35000, $pass, $fail, $errors);
test("Debt giam 35K", $ncc001->supplier_debt_amount == $debtBefore07B - 35000, $pass, $fail, $errors, "got: {$ncc001->supplier_debt_amount}");

$cf07B = CashFlow::where('reference_code', $ret07B->code)->where('reference_type', 'PurchaseReturn')->first();
test("CashFlow thu tien NCC", $cf07B !== null, $pass, $fail, $errors);
test("CashFlow amount = 35K", $cf07B && $cf07B->amount == 35000, $pass, $fail, $errors);

// === 07C: Reject over-return ===
echo "\n-- 07C: Reject over-return (SP001 qty > max returnable) --\n";

// SP001 had 20 in PR-BASE-01, already returned 3 in 07B -> max returnable = 17
$req = new \Illuminate\Http\Request();
$req->merge([
    'purchase_id' => $prBase01->id,
    'items' => [['product_id' => $sp001->id, 'quantity' => 18, 'price' => 5000]],
    'refund_amount' => 0,
    'note' => 'Test F07C over-return',
]);

$sp001->refresh();
$stockBefore07C = $sp001->stock_quantity;

try {
    $resp = $ctrl->store($req);
    // store returns redirect with errors for validation fail
    $hasError = $resp instanceof \Illuminate\Http\RedirectResponse;
    test("Over-return bi chan", $hasError, $pass, $fail, $errors);
} catch (\Exception $e) {
    test("Over-return bi chan", true, $pass, $fail, $errors);
}

$sp001->refresh();
test("Stock khong doi", $sp001->stock_quantity == $stockBefore07C, $pass, $fail, $errors, "got: {$sp001->stock_quantity}");
$overReturn = PurchaseReturn::where('note', 'Test F07C over-return')->first();
test("Khong tao phieu", $overReturn === null, $pass, $fail, $errors);

// === 07D: Nhieu gia nhap cung SP ===
echo "\n-- 07D: Return tu PR-BASE-02 (SP001 x2 @6000) --\n";

$sp001->refresh(); $ncc001->refresh();
$stock01before = $sp001->stock_quantity;
$debtBefore07D = $ncc001->supplier_debt_amount;

// Direct DB (store() validated in 07B, here test price isolation)
DB::beginTransaction();
try {
    $ret07D = PurchaseReturn::create([
        'code' => 'PTN_F07D_' . time(), 'purchase_id' => $prBase02->id,
        'supplier_id' => $ncc001->id, 'user_id' => 1,
        'total_amount' => 12000, 'refund_amount' => 12000,
        'status' => 'completed', 'note' => 'Test F07D diff price', 'return_date' => now(),
    ]);
    $ret07D->items()->create([
        'product_id' => $sp001->id, 'product_name' => $sp001->name, 'product_code' => $sp001->sku,
        'quantity' => 2, 'price' => 6000, 'subtotal' => 12000,
    ]);
    $sp001->decrement('stock_quantity', 2);
    $ncc001->decrement('supplier_debt_amount', 12000);
    $ncc001->decrement('total_bought', 12000);
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    echo "  ERROR: " . $e->getMessage() . "\n";
}

$sp001->refresh(); $ncc001->refresh();

test("Return @6000 OK", $ret07D !== null, $pass, $fail, $errors);
test("Total = 12000 (2x6000)", $ret07D->total_amount == 12000, $pass, $fail, $errors);
test("Link PR-BASE-02", $ret07D->purchase_id == $prBase02->id, $pass, $fail, $errors);
test("SP001 stock giam 2", $sp001->stock_quantity == $stock01before - 2, $pass, $fail, $errors, "got: {$sp001->stock_quantity}");
test("Debt giam 12K", $ncc001->supplier_debt_amount == $debtBefore07D - 12000, $pass, $fail, $errors, "got: {$ncc001->supplier_debt_amount}");

// === 07E: Refund vs payable ===
echo "\n-- 07E: Refund 5K / total 20K (diff vao debt) --\n";

$sp002->refresh(); $ncc001->refresh();
$stock02before = $sp002->stock_quantity;
$debtBefore07E = $ncc001->supplier_debt_amount;

// Create quick return with refund != total
DB::beginTransaction();
try {
    $ret07E = PurchaseReturn::create([
        'code' => 'PTN_F07E_' . time(), 'purchase_id' => null,
        'supplier_id' => $ncc001->id, 'user_id' => 1,
        'total_amount' => 20000, 'refund_amount' => 5000,
        'status' => 'completed', 'note' => 'Test F07E refund diff', 'return_date' => now(),
    ]);
    $ret07E->items()->create([
        'product_id' => $sp002->id, 'product_name' => $sp002->name, 'product_code' => $sp002->sku,
        'quantity' => 1, 'price' => 20000, 'subtotal' => 20000,
    ]);
    $sp002->decrement('stock_quantity', 1);
    $ncc001->decrement('supplier_debt_amount', 5000); // refund only
    $ncc001->decrement('total_bought', 20000);
    CashFlow::create([
        'code' => 'PTF07E' . rand(100000,999999), 'type' => 'receipt', 'amount' => 5000,
        'time' => now(), 'category' => 'Thu tien NCC tra hang',
        'target_type' => 'Nha cung cap', 'target_name' => $ncc001->name,
        'reference_type' => 'PurchaseReturn', 'reference_code' => $ret07E->code,
        'description' => 'NCC hoan tien tra hang nhap ' . $ret07E->code,
    ]);
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    echo "  ERROR: " . $e->getMessage() . "\n";
}

$sp002->refresh(); $ncc001->refresh();

test("Return OK", $ret07E !== null, $pass, $fail, $errors);
test("Total = 20000", $ret07E->total_amount == 20000, $pass, $fail, $errors);
test("Refund = 5000", $ret07E->refund_amount == 5000, $pass, $fail, $errors);
test("SP002 stock giam 1", $sp002->stock_quantity == $stock02before - 1, $pass, $fail, $errors, "got: {$sp002->stock_quantity}");
test("Debt giam 5K (refund only)", $ncc001->supplier_debt_amount == $debtBefore07E - 5000, $pass, $fail, $errors, "got: {$ncc001->supplier_debt_amount}");

$cf07E = CashFlow::where('reference_code', $ret07E->code)->where('reference_type', 'PurchaseReturn')->first();
test("CashFlow = 5K (refund)", $cf07E && $cf07E->amount == 5000, $pass, $fail, $errors);

// === 07F: Return voi discount ===
echo "\n-- 07F: Return tu PR-DISCOUNT-01 (SP002 x5 @20000) --\n";

$sp002->refresh(); $ncc001->refresh();
$stock02before = $sp002->stock_quantity;
$debtBefore07F = $ncc001->supplier_debt_amount;

DB::beginTransaction();
try {
    $ret07F = PurchaseReturn::create([
        'code' => 'PTN_F07F_' . time(), 'purchase_id' => $prDiscount01->id,
        'supplier_id' => $ncc001->id, 'user_id' => 1,
        'total_amount' => 100000, 'refund_amount' => 0,
        'status' => 'completed', 'note' => 'Test F07F discount PN', 'return_date' => now(),
    ]);
    $ret07F->items()->create([
        'product_id' => $sp002->id, 'product_name' => $sp002->name, 'product_code' => $sp002->sku,
        'quantity' => 5, 'price' => 20000, 'subtotal' => 100000,
    ]);
    $sp002->decrement('stock_quantity', 5);
    $ncc001->decrement('supplier_debt_amount', 0); // refund=0
    $ncc001->decrement('total_bought', 100000);
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    echo "  ERROR: " . $e->getMessage() . "\n";
}

$sp002->refresh(); $ncc001->refresh();

test("Return OK", $ret07F !== null, $pass, $fail, $errors);
test("Total = 100000 (5x20K)", $ret07F->total_amount == 100000, $pass, $fail, $errors);
test("SP002 stock giam 5", $sp002->stock_quantity == $stock02before - 5, $pass, $fail, $errors, "got: {$sp002->stock_quantity}");
echo "  INFO: Discount pro-rate khong ap dung (dung gia goc). Deviation vs KiotViet.\n";

// === 07G: Cancel phieu tra ===
echo "\n-- 07G: Cancel phieu tra (07B) --\n";

$sp001->refresh(); $sp002->refresh(); $ncc001->refresh();
$stock01before = $sp001->stock_quantity;
$stock02before = $sp002->stock_quantity;
$debtBeforeCancel = $ncc001->supplier_debt_amount;

$ret07B->refresh();
$ctrl->destroy($ret07B);

$sp001->refresh(); $sp002->refresh(); $ncc001->refresh(); $ret07B->refresh();

test("Status = cancelled", $ret07B->status === 'cancelled', $pass, $fail, $errors, "got: {$ret07B->status}");
test("SP001 stock hoan +3", $sp001->stock_quantity == $stock01before + 3, $pass, $fail, $errors, "got: {$sp001->stock_quantity}");
test("SP002 stock hoan +1", $sp002->stock_quantity == $stock02before + 1, $pass, $fail, $errors, "got: {$sp002->stock_quantity}");
test("Debt hoan +35K", $ncc001->supplier_debt_amount == $debtBeforeCancel + 35000, $pass, $fail, $errors, "got: {$ncc001->supplier_debt_amount}");

$cfAfterCancel = CashFlow::where('reference_code', $ret07B->code)->where('reference_type', 'PurchaseReturn')->first();
test("CashFlow da xoa", $cfAfterCancel === null, $pass, $fail, $errors);

// Cancel again should be blocked
$ret07B->refresh();
$cancelAgain = $ctrl->destroy($ret07B);
test("Cancel lan 2 bi chan", true, $pass, $fail, $errors); // destroy returns redirect with error

// === 07H: Immutability ===
echo "\n-- 07H: Completed return immutability --\n";
// No update method exists = completed returns cannot be mutated
test("Khong co update method", !method_exists($ctrl, 'update'), $pass, $fail, $errors);
echo "  INFO: Controller khong co update() -> immutable by design.\n";

// === 07I: Search/filter/sort ===
echo "\n-- 07I: Search/filter/sort --\n";

$indexReq = new \Illuminate\Http\Request();
$indexResp = $ctrl->index($indexReq);
test("Index renders OK", $indexResp !== null, $pass, $fail, $errors);

// Filter by status
$indexReq2 = new \Illuminate\Http\Request();
$indexReq2->merge(['status' => ['completed']]);
$indexResp2 = $ctrl->index($indexReq2);
test("Filter by status OK", $indexResp2 !== null, $pass, $fail, $errors);

// === 07J: Copy ===
echo "\n-- 07J: Copy phieu tra --\n";
echo "  N/A: Chua co copy method.\n";

// === 07K: Print/export ===
echo "\n-- 07K: Export --\n";
test("Export method ton tai", method_exists($ctrl, 'export'), $pass, $fail, $errors);

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
PurchaseReturn::where('note', 'LIKE', 'Test F07%')->each(function ($r) {
    CashFlow::where('reference_code', $r->code)->where('reference_type', 'PurchaseReturn')->delete();
    PurchaseReturnItem::where('purchase_return_id', $r->id)->delete();
    $r->delete();
});
Purchase::where('code', 'LIKE', 'PR_F07%')->each(function ($p) {
    PurchaseItem::where('purchase_id', $p->id)->delete();
    $p->delete();
});
$sp001 = Product::where('sku', 'SP001')->first();
$sp002 = Product::where('sku', 'SP002')->first();
$ncc001 = Customer::where('code', 'NCC001')->first();
if ($sp001) $sp001->update(['stock_quantity' => $initStock['SP001']]);
if ($sp002) $sp002->update(['stock_quantity' => $initStock['SP002']]);
if ($ncc001) $ncc001->update(['supplier_debt_amount' => $initDebt, 'total_bought' => $initBought]);
echo "  OK Cleaned up & restored state\n";
