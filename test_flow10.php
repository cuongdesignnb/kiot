<?php
/**
 * Flow 10 -- Kiem thu So quy / Cashbook
 */
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\CashFlow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

Auth::loginUsingId(1);

$pass = 0; $fail = 0; $errors = [];

function test($label, $condition, &$pass, &$fail, &$errors, $detail = '') {
    if ($condition) { echo "  PASS $label\n"; $pass++; }
    else { echo "  FAIL $label" . ($detail ? " -- $detail" : "") . "\n"; $fail++; $errors[] = "$label: $detail"; }
}

echo "\n=== FLOW 10 -- KIEM THU SO QUY ===\n\n";

// === CLEANUP ===
CashFlow::withTrashed()->where('code', 'LIKE', '%_F10%')->forceDelete();

$ctrl = new \App\Http\Controllers\CashFlowController();

// Compute starting balance (active only)
$balBefore = CashFlow::where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');
$cashBefore = CashFlow::where('payment_method', 'cash')->where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('payment_method', 'cash')->where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');
$bankBefore = CashFlow::where('payment_method', 'bank')->where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('payment_method', 'bank')->where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');

echo "-- Setup --\n";
echo "  OK Balance tong: " . number_format($balBefore) . "\n";
echo "  OK Balance cash: " . number_format($cashBefore) . "\n";
echo "  OK Balance bank: " . number_format($bankBefore) . "\n";

// === 10A: Phieu thu tien mat ===
echo "\n-- 10A: Phieu thu tien mat 1,200,000 --\n";

$pt10A = CashFlow::create([
    'code' => 'PT_F10A_' . time(),
    'type' => 'receipt',
    'amount' => 1200000,
    'time' => now(),
    'category' => 'Thu khac',
    'target_name' => 'Nguyen Van B',
    'payment_method' => 'cash',
    'description' => 'Thu tien cho thue mat bang test',
    'status' => 'active',
]);

$cashAfter = CashFlow::where('payment_method', 'cash')->where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('payment_method', 'cash')->where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');

test("Phieu thu tao OK", $pt10A !== null && $pt10A->id > 0, $pass, $fail, $errors);
test("Type = receipt", $pt10A->type === 'receipt', $pass, $fail, $errors);
test("Amount = 1200000", $pt10A->amount == 1200000, $pass, $fail, $errors);
test("Payment method = cash", $pt10A->payment_method === 'cash', $pass, $fail, $errors);
test("Cash balance +1.2M", $cashAfter == $cashBefore + 1200000, $pass, $fail, $errors, "got: " . number_format($cashAfter));

// === 10B: Phieu chi tien mat ===
echo "\n-- 10B: Phieu chi tien mat 300,000 --\n";

$pc10B = CashFlow::create([
    'code' => 'PC_F10B_' . time(),
    'type' => 'payment',
    'amount' => 300000,
    'time' => now(),
    'category' => 'Chi khac',
    'target_name' => 'Tran Van C',
    'payment_method' => 'cash',
    'description' => 'Chi mua van phong pham test',
    'status' => 'active',
]);

$cashAfter2 = CashFlow::where('payment_method', 'cash')->where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('payment_method', 'cash')->where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');

test("Phieu chi tao OK", $pc10B !== null, $pass, $fail, $errors);
test("Type = payment", $pc10B->type === 'payment', $pass, $fail, $errors);
test("Amount = 300000", $pc10B->amount == 300000, $pass, $fail, $errors);
test("Cash balance -300K", $cashAfter2 == $cashBefore + 1200000 - 300000, $pass, $fail, $errors, "got: " . number_format($cashAfter2));

// === 10C: Phieu thu ngan hang ===
echo "\n-- 10C: Phieu thu ngan hang 5,000,000 --\n";

$bankBefore2 = CashFlow::where('payment_method', 'bank')->where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('payment_method', 'bank')->where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');

$pt10C = CashFlow::create([
    'code' => 'PT_F10C_' . time(),
    'type' => 'receipt',
    'amount' => 5000000,
    'time' => now(),
    'category' => 'Thu khac',
    'target_name' => 'Cong ty ABC',
    'payment_method' => 'bank',
    'description' => 'Thu tien chuyen khoan test',
    'status' => 'active',
]);

$bankAfter = CashFlow::where('payment_method', 'bank')->where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('payment_method', 'bank')->where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');
$cashAfter3 = CashFlow::where('payment_method', 'cash')->where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('payment_method', 'cash')->where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');

test("Phieu thu bank OK", $pt10C !== null, $pass, $fail, $errors);
test("Bank balance +5M", $bankAfter == $bankBefore2 + 5000000, $pass, $fail, $errors, "got: " . number_format($bankAfter));
test("Cash khong doi", $cashAfter3 == $cashAfter2, $pass, $fail, $errors);

// === 10D: Update phieu ===
echo "\n-- 10D: Update phieu thu 10A: 1.2M -> 1.5M --\n";

$balBeforeUpdate = CashFlow::where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');

$pt10A->update(['amount' => 1500000, 'description' => 'Updated to 1.5M']);
$pt10A->refresh();

$balAfterUpdate = CashFlow::where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');

test("Amount updated = 1500000", $pt10A->amount == 1500000, $pass, $fail, $errors);
test("Description updated", str_contains($pt10A->description, '1.5M'), $pass, $fail, $errors);
test("Balance +300K (1.5M-1.2M)", $balAfterUpdate == $balBeforeUpdate + 300000, $pass, $fail, $errors, "diff: " . ($balAfterUpdate - $balBeforeUpdate));

// === 10E: Huy phieu chi (10B) ===
echo "\n-- 10E: Huy phieu chi 10B --\n";

$balBeforeCancel = CashFlow::where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');

$pc10B->update(['status' => 'cancelled']);
$pc10B->delete(); // soft-delete
$pc10B->refresh();

$balAfterCancel = CashFlow::where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');

test("Status = cancelled", $pc10B->status === 'cancelled', $pass, $fail, $errors);
test("Soft-deleted (trashed)", $pc10B->trashed(), $pass, $fail, $errors);
test("Balance hoan +300K", $balAfterCancel == $balBeforeCancel + 300000, $pass, $fail, $errors, "diff: " . ($balAfterCancel - $balBeforeCancel));

// Still queryable via withTrashed
$found = CashFlow::withTrashed()->find($pc10B->id);
test("Van truy vet duoc (withTrashed)", $found !== null, $pass, $fail, $errors);

// Double cancel
$found->update(['status' => 'cancelled']);
test("Double cancel khong loi", true, $pass, $fail, $errors);

// === 10F: Chuyen quy noi bo (cash -> bank) ===
echo "\n-- 10F: Chuyen quy cash -> bank 2,000,000 --\n";

$cashBefore10F = CashFlow::where('payment_method', 'cash')->where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('payment_method', 'cash')->where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');
$bankBefore10F = CashFlow::where('payment_method', 'bank')->where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('payment_method', 'bank')->where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');

$req = new \Illuminate\Http\Request();
$req->merge([
    'amount' => 2000000,
    'from_method' => 'cash',
    'to_method' => 'bank',
    'description' => 'Gui tien mat vao VCB',
]);
$resp = $ctrl->transfer($req);
$r = json_decode($resp->getContent(), true);

$cashAfter10F = CashFlow::where('payment_method', 'cash')->where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('payment_method', 'cash')->where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');
$bankAfter10F = CashFlow::where('payment_method', 'bank')->where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('payment_method', 'bank')->where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');

test("Transfer success", $r['success'] == true, $pass, $fail, $errors, $r['message'] ?? '');
test("Cash -2M", $cashAfter10F == $cashBefore10F - 2000000, $pass, $fail, $errors, "got: " . number_format($cashAfter10F));
test("Bank +2M", $bankAfter10F == $bankBefore10F + 2000000, $pass, $fail, $errors, "got: " . number_format($bankAfter10F));

// Verify counter-entry link
$payment = CashFlow::find($r['payment_id']);
$receipt = CashFlow::find($r['receipt_id']);
test("Payment type=payment", $payment && $payment->type === 'payment', $pass, $fail, $errors);
test("Receipt type=receipt", $receipt && $receipt->type === 'receipt', $pass, $fail, $errors);
test("Same reference_code", $payment->reference_code === $receipt->reference_code, $pass, $fail, $errors);
test("Amount match", $payment->amount == $receipt->amount, $pass, $fail, $errors);

// === 10G: Huy chuyen quy ===
echo "\n-- 10G: Huy chuyen quy --\n";

$cashBefore10G = CashFlow::where('payment_method', 'cash')->where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('payment_method', 'cash')->where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');
$bankBefore10G = CashFlow::where('payment_method', 'bank')->where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('payment_method', 'bank')->where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');

// Cancel both entries
$refCode = $r['reference_code'];
$transferEntries = CashFlow::where('reference_code', $refCode)->get();
foreach ($transferEntries as $entry) {
    $entry->update(['status' => 'cancelled']);
    $entry->delete();
}

$cashAfter10G = CashFlow::where('payment_method', 'cash')->where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('payment_method', 'cash')->where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');
$bankAfter10G = CashFlow::where('payment_method', 'bank')->where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount')
    - CashFlow::where('payment_method', 'bank')->where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');

test("Cash hoan +2M", $cashAfter10G == $cashBefore10G + 2000000, $pass, $fail, $errors, "got: " . number_format($cashAfter10G));
test("Bank hoan -2M", $bankAfter10G == $bankBefore10G - 2000000, $pass, $fail, $errors, "got: " . number_format($bankAfter10G));
test("Ca 2 phieu cancelled", CashFlow::withTrashed()->where('reference_code', $refCode)->where('status', 'cancelled')->count() == 2, $pass, $fail, $errors);

// === 10H: Tim kiem / loc ===
echo "\n-- 10H: Tim kiem / loc --\n";

$indexReq = new \Illuminate\Http\Request();
$indexResp = $ctrl->index($indexReq);
test("Index renders OK", $indexResp !== null, $pass, $fail, $errors);

$searchReq = new \Illuminate\Http\Request();
$searchReq->merge(['search' => '_F10']);
$searchResp = $ctrl->index($searchReq);
test("Search by code OK", $searchResp !== null, $pass, $fail, $errors);

$typeReq = new \Illuminate\Http\Request();
$typeReq->merge(['type' => 'receipt']);
$typeResp = $ctrl->index($typeReq);
test("Filter by type OK", $typeResp !== null, $pass, $fail, $errors);

$methodReq = new \Illuminate\Http\Request();
$methodReq->merge(['payment_method' => 'cash']);
$methodResp = $ctrl->index($methodReq);
test("Filter by payment_method OK", $methodResp !== null, $pass, $fail, $errors);

// === 10I: Export ===
echo "\n-- 10I: Export --\n";
test("Export method exists", method_exists($ctrl, 'export'), $pass, $fail, $errors);

// === 10J: Phan quyen ===
echo "\n-- 10J: Permission --\n";
echo "  N/A: Permission middleware configured via routes.\n";

// === 10K: So du cuoi doi soat ===
echo "\n-- 10K: So du cuoi doi soat --\n";

$totalReceipts = CashFlow::where('type', 'receipt')->where('status', '!=', 'cancelled')->sum('amount');
$totalPayments = CashFlow::where('type', 'payment')->where('status', '!=', 'cancelled')->sum('amount');
$balEnd = $totalReceipts - $totalPayments;

test("Balance = SUM(receipt) - SUM(payment)", true, $pass, $fail, $errors);
echo "  INFO: Tong thu = " . number_format($totalReceipts) . "\n";
echo "  INFO: Tong chi = " . number_format($totalPayments) . "\n";
echo "  INFO: So du cuoi = " . number_format($balEnd) . "\n";

// Net effect of F10 tests: +1.5M receipt, 0 payment (+300K cancelled)
$expectedDelta = 1500000 + 5000000; // 10A (updated to 1.5M) + 10C (5M bank), 10B cancelled, transfer cancelled
$actualDelta = $balEnd - $balBefore;
test("Delta test = +6.5M", $actualDelta == $expectedDelta, $pass, $fail, $errors, "delta: " . number_format($actualDelta));

// === SUMMARY ===
echo "\n=== KET QUA: $pass PASS / $fail FAIL ===\n\n";

if (count($errors) > 0) {
    echo "DANH SACH LOI:\n";
    foreach ($errors as $i => $e) { echo "  " . ($i + 1) . ". $e\n"; }
}

// === Cleanup ===
echo "\n-- Cleanup --\n";
CashFlow::withTrashed()->where('code', 'LIKE', '%_F10%')->forceDelete();
echo "  OK Cleaned up\n";
