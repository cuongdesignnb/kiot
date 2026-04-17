<?php
/**
 * Flow 04 — Kiểm thử Quản lý công nợ khách hàng / Thu nợ
 */
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Customer;
use App\Models\CashFlow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

Auth::loginUsingId(1);

$pass = 0; $fail = 0; $errors = [];

function test($label, $condition, &$pass, &$fail, &$errors, $detail = '') {
    if ($condition) {
        echo "  ✓ $label\n";
        $pass++;
    } else {
        echo "  ✗ $label" . ($detail ? " — $detail" : "") . "\n";
        $fail++;
        $errors[] = "$label: $detail";
    }
}

echo "\n═══════════════════════════════════════\n";
echo "  FLOW 04 — KIỂM THỬ CÔNG NỢ KHÁCH HÀNG\n";
echo "═══════════════════════════════════════\n\n";

// ═══ CLEANUP trước ═══
CashFlow::where('reference_code', 'LIKE', '%HD_F04%')->delete();
CashFlow::where('reference_type', 'DebtPayment')->where('description', 'LIKE', '%Flow04%')->delete();
CashFlow::where('reference_type', 'DebtAdjustment')->where('description', 'LIKE', '%Flow04%')->delete();
Invoice::where('code', 'LIKE', 'HD_F04%')->each(function ($inv) {
    InvoiceItem::where('invoice_id', $inv->id)->delete();
    $inv->delete();
});

// ═══ CHUẨN BỊ ═══
echo "── Chuẩn bị dữ liệu ──\n";

$sp001 = Product::where('sku', 'SP001')->first();
$sp002 = Product::where('sku', 'SP002')->first();
$kh001 = Customer::where('code', 'KH001')->first();

if (!$sp001 || !$sp002 || !$kh001) {
    echo "  ✗ Thiếu dữ liệu nền (SP001, SP002, KH001). Chạy test_flow03.php trước.\n";
    exit(1);
}

// Reset KH001 debt
$kh001->update(['debt_amount' => 0]);
echo "  ✓ Reset KH001.debt_amount = 0\n";

// ═══ 04A — Phát sinh công nợ ═══
echo "\n── 04A: Phát sinh công nợ từ hóa đơn ──\n";

// Invoice A: 70,000 - paid 0 = debt 70,000
$invA = Invoice::create([
    'code' => 'HD_F04A_' . time(),
    'customer_id' => $kh001->id,
    'subtotal' => 70000, 'discount' => 0, 'total' => 70000,
    'customer_paid' => 0, 'status' => 'Hoàn thành',
    'created_by_name' => 'Admin', 'created_at' => now()->subHours(3),
]);
$invA->items()->create([
    'product_id' => $sp001->id, 'quantity' => 10, 'price' => 7000,
    'cost_price' => $sp001->cost_price, 'discount' => 0, 'subtotal' => 70000,
]);
$sp001->decrement('stock_quantity', 10);
$kh001->increment('debt_amount', 70000);

// Invoice B: 60,000 - paid 20,000 = debt 40,000
$invB = Invoice::create([
    'code' => 'HD_F04B_' . time(),
    'customer_id' => $kh001->id,
    'subtotal' => 60000, 'discount' => 0, 'total' => 60000,
    'customer_paid' => 20000, 'status' => 'Hoàn thành',
    'created_by_name' => 'Admin', 'created_at' => now()->subHours(2),
]);
$invB->items()->create([
    'product_id' => $sp002->id, 'quantity' => 2, 'price' => 30000,
    'cost_price' => $sp002->cost_price, 'discount' => 0, 'subtotal' => 60000,
]);
$sp002->decrement('stock_quantity', 2);
$kh001->increment('debt_amount', 40000); // 60k - 20k paid
CashFlow::create([
    'code' => 'PT_F04B', 'type' => 'receipt', 'amount' => 20000,
    'time' => now()->subHours(2), 'category' => 'Thu tiền khách trả',
    'target_type' => 'Khách hàng', 'target_name' => $kh001->name,
    'target_id' => $kh001->id,
    'reference_type' => 'Invoice', 'reference_code' => $invB->code,
]);

// Invoice C: 35,000 - paid 35,000 = debt 0
$invC = Invoice::create([
    'code' => 'HD_F04C_' . time(),
    'customer_id' => $kh001->id,
    'subtotal' => 35000, 'discount' => 0, 'total' => 35000,
    'customer_paid' => 35000, 'status' => 'Hoàn thành',
    'created_by_name' => 'Admin', 'created_at' => now()->subHours(1),
]);
$invC->items()->create([
    'product_id' => $sp001->id, 'quantity' => 5, 'price' => 7000,
    'cost_price' => $sp001->cost_price, 'discount' => 0, 'subtotal' => 35000,
]);
$sp001->decrement('stock_quantity', 5);
// No debt for C
CashFlow::create([
    'code' => 'PT_F04C', 'type' => 'receipt', 'amount' => 35000,
    'time' => now()->subHours(1), 'category' => 'Thu tiền khách trả',
    'target_type' => 'Khách hàng', 'target_name' => $kh001->name,
    'target_id' => $kh001->id,
    'reference_type' => 'Invoice', 'reference_code' => $invC->code,
]);

$kh001->refresh(); $invA->refresh(); $invB->refresh(); $invC->refresh();

test("Invoice A nợ = 70,000", ($invA->total - $invA->customer_paid) == 70000, $pass, $fail, $errors, "got: " . ($invA->total - $invA->customer_paid));
test("Invoice B nợ = 40,000", ($invB->total - $invB->customer_paid) == 40000, $pass, $fail, $errors, "got: " . ($invB->total - $invB->customer_paid));
test("Invoice C nợ = 0", ($invC->total - $invC->customer_paid) == 0, $pass, $fail, $errors, "got: " . ($invC->total - $invC->customer_paid));
test("KH001 tổng nợ = 110,000", $kh001->debt_amount == 110000, $pass, $fail, $errors, "got: {$kh001->debt_amount}");

// ═══ 04B — Xem công nợ (debtHistory API) ═══
echo "\n── 04B: Xem công nợ hồ sơ KH ──\n";

$controller = new \App\Http\Controllers\CustomerController();
$debtResp = $controller->debtHistory($kh001);
$debtData = json_decode($debtResp->getContent(), true);

test("debtHistory API trả về entries", isset($debtData['entries']) && count($debtData['entries']) > 0, $pass, $fail, $errors, "entries count: " . count($debtData['entries'] ?? []));
test("Net balance = 110,000 (hoặc tương đương)", $debtData['summary']['net'] >= 100000, $pass, $fail, $errors, "net: " . ($debtData['summary']['net'] ?? 'null'));

// ═══ 04C — Thu nợ auto-allocate (80,000) ═══
echo "\n── 04C: Thu nợ auto-allocate 80,000 ──\n";

$request = new \Illuminate\Http\Request();
$request->merge([
    'mode' => 'auto',
    'amount' => 80000,
    'note' => 'Thu nợ test Flow04 auto',
]);
$request->headers->set('Accept', 'application/json');

$response = $controller->debtPayment($request, $kh001);
$result = json_decode($response->getContent(), true);

$kh001->refresh(); $invA->refresh(); $invB->refresh();

test("API success", $result['success'] == true, $pass, $fail, $errors);
test("Invoice A tất toán (paid=70,000)", $invA->customer_paid == 70000, $pass, $fail, $errors, "got: {$invA->customer_paid}");
test("Invoice B thu thêm 10,000 (paid=30,000)", $invB->customer_paid == 30000, $pass, $fail, $errors, "got: {$invB->customer_paid}");
test("KH001 nợ còn 30,000", $kh001->debt_amount == 30000, $pass, $fail, $errors, "got: {$kh001->debt_amount}");

// Check CashFlow
$cf_auto = CashFlow::where('reference_type', 'DebtPayment')
    ->where('target_id', $kh001->id)
    ->where('amount', 80000)
    ->latest()
    ->first();
test("CashFlow 80,000 tồn tại", $cf_auto !== null, $pass, $fail, $errors);
test("CashFlow reference có HD codes", $cf_auto && str_contains($cf_auto->reference_code, $invA->code), $pass, $fail, $errors, "ref: " . ($cf_auto->reference_code ?? 'null'));

// ═══ 04D — Thu nợ manual allocation ═══
echo "\n── 04D: Thu nợ manual (Invoice B: 15,000) ──\n";

// Current state: Invoice A paid=70k (full), Invoice B remaining=30k
$request2 = new \Illuminate\Http\Request();
$request2->merge([
    'mode' => 'manual',
    'allocations' => [
        ['invoice_id' => $invB->id, 'amount' => 15000],
    ],
    'note' => 'Thu nợ test Flow04 manual',
]);
$request2->headers->set('Accept', 'application/json');

$response2 = $controller->debtPayment($request2, $kh001);
$result2 = json_decode($response2->getContent(), true);

$kh001->refresh(); $invB->refresh();

test("API success", $result2['success'] == true, $pass, $fail, $errors);
test("Invoice B paid=45,000 (30k+15k)", $invB->customer_paid == 45000, $pass, $fail, $errors, "got: {$invB->customer_paid}");
test("Invoice B remaining=15,000", ($invB->total - $invB->customer_paid) == 15000, $pass, $fail, $errors);
test("KH001 nợ còn 15,000", $kh001->debt_amount == 15000, $pass, $fail, $errors, "got: {$kh001->debt_amount}");

$cf_manual = CashFlow::where('reference_type', 'DebtPayment')
    ->where('target_id', $kh001->id)
    ->where('amount', 15000)
    ->latest()
    ->first();
test("CashFlow 15,000 tồn tại", $cf_manual !== null, $pass, $fail, $errors);

// ═══ 04E — Điều chỉnh công nợ ═══
echo "\n── 04E: Điều chỉnh công nợ giảm 5,000 ──\n";

$request3 = new \Illuminate\Http\Request();
$request3->merge([
    'amount' => 5000,
    'note' => 'Điều chỉnh công nợ test Flow04',
]);

$response3 = $controller->debtAdjust($request3, $kh001);

$kh001->refresh();
test("KH001 nợ giảm 5,000 → 10,000", $kh001->debt_amount == 10000, $pass, $fail, $errors, "got: {$kh001->debt_amount}");

$cf_adj = CashFlow::where('reference_type', 'DebtAdjustment')
    ->where('target_id', $kh001->id)
    ->latest()
    ->first();
test("CashFlow điều chỉnh tồn tại", $cf_adj !== null, $pass, $fail, $errors);
test("CashFlow category = Điều chỉnh công nợ", $cf_adj && $cf_adj->category === 'Điều chỉnh công nợ', $pass, $fail, $errors, "got: " . ($cf_adj->category ?? 'null'));
test("CashFlow type=receipt (giảm nợ)", $cf_adj && $cf_adj->type === 'receipt', $pass, $fail, $errors, "got: " . ($cf_adj->type ?? 'null'));

// ═══ 04F — Chiết khấu thanh toán ═══
echo "\n── 04F: Chiết khấu thanh toán ──\n";
echo "  → N/A: Missing Feature - payment discount on customer receivables\n";
echo "  ℹ️ Hệ thống không có tính năng chiết khấu thanh toán riêng biệt\n";

// ═══ 04G — Lịch sử giao dịch ═══
echo "\n── 04G: Lịch sử giao dịch ──\n";

$debtResp2 = $controller->debtHistory($kh001);
$debtData2 = json_decode($debtResp2->getContent(), true);

$entries = $debtData2['entries'] ?? [];
$codes = array_column($entries, 'code');

test("Có entries lịch sử", count($entries) >= 3, $pass, $fail, $errors, "count: " . count($entries));

// Check invoice codes appear
test("Invoice A có trong lịch sử", in_array($invA->code, $codes), $pass, $fail, $errors);
test("Invoice B có trong lịch sử", in_array($invB->code, $codes), $pass, $fail, $errors);

// Check debt payment entries appear
$paymentEntries = array_filter($entries, fn($e) => $e['type'] === 'Thanh toán');
test("Có entries thanh toán", count($paymentEntries) >= 1, $pass, $fail, $errors, "count: " . count($paymentEntries));

// Check sales history
$salesResp = $controller->salesHistory($kh001);
$salesData = json_decode($salesResp->getContent(), true);
test("salesHistory trả về invoices", count($salesData['invoices'] ?? []) >= 3, $pass, $fail, $errors, "count: " . count($salesData['invoices'] ?? []));

// ═══ 04H — Phân quyền chi nhánh ═══
echo "\n── 04H: Phân quyền chi nhánh ──\n";
echo "  → N/A: branch-scoped customer management not enabled\n";

// ═══ Outstanding Invoices API ═══
echo "\n── Bonus: Outstanding Invoices API ──\n";

$outResp = $controller->outstandingInvoices($kh001);
$outData = json_decode($outResp->getContent(), true);

// After all payments: Only Invoice B should still have remaining
$remaining_invs = array_filter($outData, fn($inv) => $inv['remaining'] > 0);
test("Outstanding invoices API trả về data", is_array($outData), $pass, $fail, $errors);
test("Invoice B còn nợ 15,000", count(array_filter($outData, fn($inv) => $inv['id'] == $invB->id && $inv['remaining'] == 15000)) == 1, $pass, $fail, $errors);

// ═══ TỔNG KẾT ═══
echo "\n═══════════════════════════════════════\n";
echo "  KẾT QUẢ: $pass ✓ / $fail ✗\n";
echo "═══════════════════════════════════════\n\n";

if (count($errors) > 0) {
    echo "DANH SÁCH LỖI:\n";
    foreach ($errors as $i => $e) {
        echo "  " . ($i + 1) . ". $e\n";
    }
}

// ═══ Cleanup ═══
echo "\n── Cleanup ──\n";
CashFlow::where('reference_code', 'LIKE', '%HD_F04%')->delete();
CashFlow::where('code', 'PT_F04B')->delete();
CashFlow::where('code', 'PT_F04C')->delete();
CashFlow::where('reference_type', 'DebtPayment')->where('target_id', $kh001->id)->where('description', 'LIKE', '%Flow04%')->delete();
CashFlow::where('reference_type', 'DebtAdjustment')->where('target_id', $kh001->id)->where('description', 'LIKE', '%Flow04%')->delete();
Invoice::where('code', 'LIKE', 'HD_F04%')->each(function ($inv) {
    InvoiceItem::where('invoice_id', $inv->id)->delete();
    $inv->delete();
});
$kh001->update(['debt_amount' => 0]);
echo "  ✓ Đã dọn test data\n";
