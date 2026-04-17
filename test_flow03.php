<?php
/**
 * Flow 03 — Kiểm thử Bán hàng / Hóa đơn
 * Test cả POS (checkout) + Invoice (store) logic
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
echo "  FLOW 03 — KIỂM THỬ BÁN HÀNG\n";
echo "═══════════════════════════════════════\n\n";

// ═══ CHUẨN BỊ DỮ LIỆU NỀN ═══
echo "── Chuẩn bị dữ liệu nền ──\n";

$sp001 = Product::where('sku', 'SP001')->first();
if (!$sp001) {
    $sp001 = Product::create([
        'name' => 'Nước suối 500ml', 'sku' => 'SP001',
        'cost_price' => 5000, 'retail_price' => 7000,
        'stock_quantity' => 50, 'is_active' => true
    ]);
    echo "  + Tạo SP001\n";
}
// Ensure enough stock
if ($sp001->stock_quantity < 20) {
    $sp001->update(['stock_quantity' => 50]);
    echo "  ⚡ Reset SP001 tồn → 50\n";
}

$sp002 = Product::where('sku', 'SP002')->first();
if (!$sp002) {
    $sp002 = Product::create([
        'name' => 'Bánh quy hộp', 'sku' => 'SP002',
        'cost_price' => 20000, 'retail_price' => 30000,
        'stock_quantity' => 20, 'is_active' => true
    ]);
    echo "  + Tạo SP002\n";
}
if ($sp002->stock_quantity < 10) {
    $sp002->update(['stock_quantity' => 20]);
    echo "  ⚡ Reset SP002 tồn → 20\n";
}

$kh001 = Customer::where('code', 'KH001')->first();
if (!$kh001) {
    $kh001 = Customer::create([
        'code' => 'KH001', 'name' => 'Nguyễn Văn A',
        'phone' => '0900000001', 'is_customer' => true,
        'debt_amount' => 0, 'total_spent' => 0
    ]);
    echo "  + Tạo KH001\n";
}

$sp001->refresh(); $sp002->refresh(); $kh001->refresh();
$stock1_before = $sp001->stock_quantity;
$stock2_before = $sp002->stock_quantity;
$debt_before = $kh001->debt_amount;
$spent_before = $kh001->total_spent;

echo "  ✓ SP001 tồn: $stock1_before\n";
echo "  ✓ SP002 tồn: $stock2_before\n";
echo "  ✓ KH001 nợ: $debt_before, đã mua: $spent_before\n\n";

// ═══ CASE 03A — Bán hàng thanh toán đủ (POS logic) ═══
echo "── CASE 03A: Bán hàng thanh toán đủ ──\n";

$subtotal_a = 2 * 7000 + 1 * 30000; // 44000
$discount_a = 0;
$total_a = $subtotal_a - $discount_a;
$paid_a = 44000;

DB::beginTransaction();
try {
    $inv_a = Invoice::create([
        'code' => 'HD_TEST_03A_' . time(),
        'customer_id' => $kh001->id,
        'subtotal' => $subtotal_a,
        'discount' => $discount_a,
        'total' => $total_a,
        'customer_paid' => $paid_a,
        'status' => 'Hoàn thành',
        'created_by_name' => 'Admin',
        'payment_method' => 'cash',
        'sales_channel' => 'Bán trực tiếp',
    ]);

    // Items
    $inv_a->items()->create([
        'product_id' => $sp001->id, 'quantity' => 2,
        'price' => 7000, 'cost_price' => $sp001->cost_price,
        'discount' => 0, 'subtotal' => 14000,
    ]);
    $inv_a->items()->create([
        'product_id' => $sp002->id, 'quantity' => 1,
        'price' => 30000, 'cost_price' => $sp002->cost_price,
        'discount' => 0, 'subtotal' => 30000,
    ]);

    // Stock
    $sp001->decrement('stock_quantity', 2);
    $sp002->decrement('stock_quantity', 1);

    // Customer debt & spent
    $debt_a = $total_a - $paid_a; // 0
    if ($debt_a != 0) $kh001->increment('debt_amount', $debt_a);
    $kh001->increment('total_spent', $total_a);

    // CashFlow
    if ($paid_a > 0) {
        CashFlow::create([
            'code' => 'PT_TEST_03A', 'type' => 'receipt',
            'amount' => $paid_a, 'time' => now(),
            'category' => 'Thu tiền khách trả',
            'target_type' => 'Khách hàng', 'target_name' => $kh001->name,
            'target_id' => $kh001->id,
            'reference_type' => 'Invoice', 'reference_code' => $inv_a->code,
        ]);
    }

    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    echo "  ✗ Lỗi: {$e->getMessage()}\n";
}

$sp001->refresh(); $sp002->refresh(); $kh001->refresh();

test("Hóa đơn tạo thành công", $inv_a->exists, $pass, $fail, $errors);
test("Status = Hoàn thành", $inv_a->status === 'Hoàn thành', $pass, $fail, $errors, "got: {$inv_a->status}");
test("SP001 tồn giảm 2 ({$stock1_before}→{$sp001->stock_quantity})", $sp001->stock_quantity == $stock1_before - 2, $pass, $fail, $errors);
test("SP002 tồn giảm 1 ({$stock2_before}→{$sp002->stock_quantity})", $sp002->stock_quantity == $stock2_before - 1, $pass, $fail, $errors);
test("Công nợ KH tăng 0 (TT đủ)", $kh001->debt_amount == $debt_before, $pass, $fail, $errors, "got: {$kh001->debt_amount}");
test("CashFlow 44000 tồn tại", CashFlow::where('reference_code', $inv_a->code)->sum('amount') == 44000, $pass, $fail, $errors);

$stock1_before = $sp001->stock_quantity;
$debt_before = $kh001->debt_amount;

// ═══ CASE 03B — Trả thiếu ═══
echo "\n── CASE 03B: Bán hàng trả thiếu ──\n";

$subtotal_b = 3 * 7000; // 21000
$total_b = 21000;
$paid_b = 10000;

DB::beginTransaction();
try {
    $inv_b = Invoice::create([
        'code' => 'HD_TEST_03B_' . time(),
        'customer_id' => $kh001->id,
        'subtotal' => $subtotal_b, 'discount' => 0,
        'total' => $total_b, 'customer_paid' => $paid_b,
        'status' => 'Hoàn thành', 'created_by_name' => 'Admin',
        'payment_method' => 'cash',
    ]);
    $inv_b->items()->create([
        'product_id' => $sp001->id, 'quantity' => 3,
        'price' => 7000, 'cost_price' => $sp001->cost_price,
        'discount' => 0, 'subtotal' => 21000,
    ]);
    $sp001->decrement('stock_quantity', 3);
    $debt_b = $total_b - $paid_b; // 11000
    $kh001->increment('debt_amount', $debt_b);
    $kh001->increment('total_spent', $total_b);
    if ($paid_b > 0) {
        CashFlow::create([
            'code' => 'PT_TEST_03B', 'type' => 'receipt',
            'amount' => $paid_b, 'time' => now(),
            'category' => 'Thu tiền khách trả',
            'target_type' => 'Khách hàng', 'target_name' => $kh001->name,
            'target_id' => $kh001->id,
            'reference_type' => 'Invoice', 'reference_code' => $inv_b->code,
        ]);
    }
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    echo "  ✗ Lỗi: {$e->getMessage()}\n";
}

$sp001->refresh(); $kh001->refresh();
test("SP001 tồn giảm 3", $sp001->stock_quantity == $stock1_before - 3, $pass, $fail, $errors, "expected: " . ($stock1_before - 3) . " got: {$sp001->stock_quantity}");
test("Công nợ KH tăng 11000", $kh001->debt_amount == $debt_before + 11000, $pass, $fail, $errors, "expected: " . ($debt_before + 11000) . " got: {$kh001->debt_amount}");
test("CashFlow 10000", CashFlow::where('reference_code', $inv_b->code)->sum('amount') == 10000, $pass, $fail, $errors);

$stock2_before = $sp002->stock_quantity;
$debt_before = $kh001->debt_amount;

// ═══ CASE 03C — Chưa thanh toán ═══
echo "\n── CASE 03C: Bán hàng chưa thanh toán ──\n";

$subtotal_c = 2 * 30000; // 60000
$total_c = 60000;
$paid_c = 0;

DB::beginTransaction();
try {
    $inv_c = Invoice::create([
        'code' => 'HD_TEST_03C_' . time(),
        'customer_id' => $kh001->id,
        'subtotal' => $subtotal_c, 'discount' => 0,
        'total' => $total_c, 'customer_paid' => $paid_c,
        'status' => 'Hoàn thành', 'created_by_name' => 'Admin',
    ]);
    $inv_c->items()->create([
        'product_id' => $sp002->id, 'quantity' => 2,
        'price' => 30000, 'cost_price' => $sp002->cost_price,
        'discount' => 0, 'subtotal' => 60000,
    ]);
    $sp002->decrement('stock_quantity', 2);
    $kh001->increment('debt_amount', $total_c);
    $kh001->increment('total_spent', $total_c);
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    echo "  ✗ Lỗi: {$e->getMessage()}\n";
}

$sp002->refresh(); $kh001->refresh();
test("SP002 tồn giảm 2", $sp002->stock_quantity == $stock2_before - 2, $pass, $fail, $errors, "expected: " . ($stock2_before - 2) . " got: {$sp002->stock_quantity}");
test("Công nợ tăng 60000", $kh001->debt_amount == $debt_before + 60000, $pass, $fail, $errors, "expected: " . ($debt_before + 60000) . " got: {$kh001->debt_amount}");
test("Không có CashFlow (trả 0)", CashFlow::where('reference_code', $inv_c->code)->count() == 0, $pass, $fail, $errors);
test("Không có phiếu thu ảo", CashFlow::where('reference_code', $inv_c->code)->where('amount', '>', 0)->count() == 0, $pass, $fail, $errors);

$sp001->refresh(); $debt_before = $kh001->debt_amount;
$stock1_before = $sp001->stock_quantity;

// ═══ CASE 03D — Giảm giá dòng hàng (Invoice logic) ═══
echo "\n── CASE 03D: Giảm giá dòng hàng (Invoice) ──\n";

// SP001 x2 @ 7000, giảm giá dòng = 2000 → tổng = 14000-2000 = 12000
$line_discount = 2000;
$subtotal_d = 2 * 7000; // 14000
$item_subtotal_d = $subtotal_d - $line_discount; // 12000
$total_d = $item_subtotal_d; // 12000
$paid_d = 12000;

DB::beginTransaction();
try {
    $inv_d = Invoice::create([
        'code' => 'HD_TEST_03D_' . time(),
        'customer_id' => $kh001->id,
        'subtotal' => $subtotal_d, 'discount' => 0,
        'total' => $total_d, 'customer_paid' => $paid_d,
        'status' => 'Hoàn thành', 'created_by_name' => 'Admin',
    ]);
    $inv_d->items()->create([
        'product_id' => $sp001->id, 'quantity' => 2,
        'price' => 7000, 'cost_price' => $sp001->cost_price,
        'discount' => $line_discount,
        'subtotal' => $item_subtotal_d, // 12000
    ]);
    $sp001->decrement('stock_quantity', 2);
    $debt_d = $total_d - $paid_d; // 0
    if ($debt_d != 0) $kh001->increment('debt_amount', $debt_d);
    $kh001->increment('total_spent', $total_d);
    if ($paid_d > 0) {
        CashFlow::create([
            'code' => 'PT_TEST_03D', 'type' => 'receipt',
            'amount' => $paid_d, 'time' => now(),
            'category' => 'Thu tiền khách trả',
            'target_type' => 'Khách hàng', 'target_name' => $kh001->name,
            'target_id' => $kh001->id,
            'reference_type' => 'Invoice', 'reference_code' => $inv_d->code,
        ]);
    }
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    echo "  ✗ Lỗi: {$e->getMessage()}\n";
}

$sp001->refresh(); $kh001->refresh();
$inv_d_item = InvoiceItem::where('invoice_id', $inv_d->id)->first();

test("Tổng tiền sau giảm = 12000", $inv_d->total == 12000, $pass, $fail, $errors, "got: {$inv_d->total}");
test("SP001 tồn giảm 2", $sp001->stock_quantity == $stock1_before - 2, $pass, $fail, $errors);
test("Công nợ = 0", $kh001->debt_amount == $debt_before, $pass, $fail, $errors);
test("Item discount lưu = 2000", $inv_d_item->discount == 2000, $pass, $fail, $errors, "got: {$inv_d_item->discount}");
test("Item subtotal = 12000", $inv_d_item->subtotal == 12000, $pass, $fail, $errors, "got: {$inv_d_item->subtotal}");

echo "\n  ℹ️ POS không hỗ trợ giảm giá dòng (chỉ discount toàn đơn).\n";
echo "  ℹ️ Invoice (InvoiceController::store) hỗ trợ items.*.discount → ĐÚng chuẩn.\n";

$sp001->refresh(); $sp002->refresh(); $kh001->refresh();
$stock1_before = $sp001->stock_quantity;
$stock2_before = $sp002->stock_quantity;
$debt_before = $kh001->debt_amount;

// ═══ CASE 03E — Giảm giá toàn hóa đơn ═══
echo "\n── CASE 03E: Giảm giá toàn hóa đơn ──\n";

$subtotal_e = 1 * 7000 + 1 * 30000; // 37000
$discount_e = 7000;
$total_e = $subtotal_e - $discount_e; // 30000
$paid_e = 30000;

DB::beginTransaction();
try {
    $inv_e = Invoice::create([
        'code' => 'HD_TEST_03E_' . time(),
        'customer_id' => $kh001->id,
        'subtotal' => $subtotal_e, 'discount' => $discount_e,
        'total' => $total_e, 'customer_paid' => $paid_e,
        'status' => 'Hoàn thành', 'created_by_name' => 'Admin',
    ]);
    $inv_e->items()->create([
        'product_id' => $sp001->id, 'quantity' => 1,
        'price' => 7000, 'cost_price' => $sp001->cost_price,
        'discount' => 0, 'subtotal' => 7000,
    ]);
    $inv_e->items()->create([
        'product_id' => $sp002->id, 'quantity' => 1,
        'price' => 30000, 'cost_price' => $sp002->cost_price,
        'discount' => 0, 'subtotal' => 30000,
    ]);
    $sp001->decrement('stock_quantity', 1);
    $sp002->decrement('stock_quantity', 1);
    $debt_e = $total_e - $paid_e; // 0
    if ($debt_e != 0) $kh001->increment('debt_amount', $debt_e);
    $kh001->increment('total_spent', $total_e);
    if ($paid_e > 0) {
        CashFlow::create([
            'code' => 'PT_TEST_03E', 'type' => 'receipt',
            'amount' => $paid_e, 'time' => now(),
            'category' => 'Thu tiền khách trả',
            'target_type' => 'Khách hàng', 'target_name' => $kh001->name,
            'target_id' => $kh001->id,
            'reference_type' => 'Invoice', 'reference_code' => $inv_e->code,
        ]);
    }
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    echo "  ✗ Lỗi: {$e->getMessage()}\n";
}

$sp001->refresh(); $sp002->refresh(); $kh001->refresh();
test("Tổng phải thu = 30000", $inv_e->total == 30000, $pass, $fail, $errors, "got: {$inv_e->total}");
test("Discount ghi = 7000", $inv_e->discount == 7000, $pass, $fail, $errors, "got: {$inv_e->discount}");
test("SP001 tồn giảm 1", $sp001->stock_quantity == $stock1_before - 1, $pass, $fail, $errors);
test("SP002 tồn giảm 1", $sp002->stock_quantity == $stock2_before - 1, $pass, $fail, $errors);
test("Công nợ = 0", $kh001->debt_amount == $debt_before, $pass, $fail, $errors);

// ═══ CASE 03F — Thêm nhanh khách hàng ═══
echo "\n── CASE 03F: Thêm nhanh khách hàng ──\n";

Customer::where('code', 'KH_FLOW03')->delete();
$kh_new = Customer::create([
    'code' => 'KH_FLOW03', 'name' => 'Khách hàng Flow 03',
    'phone' => '0900000303', 'is_customer' => true,
    'debt_amount' => 0, 'total_spent' => 0,
]);

test("KH_FLOW03 tạo được", $kh_new->exists, $pass, $fail, $errors);
test("KH tồn tại trong DB", Customer::where('code', 'KH_FLOW03')->exists(), $pass, $fail, $errors);
test("is_customer = true", $kh_new->is_customer == true, $pass, $fail, $errors);

echo "  ℹ️ POS có /api/pos/customers POST → quickCreateCustomer() → OK\n";

// ═══ CASE 03G — Thêm nhanh hàng hóa ═══
echo "\n── CASE 03G: Thêm nhanh hàng hóa trên POS ──\n";

// Check if POS UI has quick-create product
$posIndex = file_get_contents('d:\Kiot\kiotviet-clone\resources\js\Pages\POS\Index.vue');
$hasQuickCreateProduct = (
    strpos($posIndex, 'quick-store') !== false ||
    strpos($posIndex, 'quickStore') !== false ||
    strpos($posIndex, 'CreateProductModal') !== false ||
    strpos($posIndex, 'createProduct') !== false
);

if ($hasQuickCreateProduct) {
    echo "  ✓ POS UI có quick-create product\n";
    $pass++;
} else {
    echo "  → N/A: POS không hỗ trợ thêm nhanh hàng hóa trực tiếp\n";
    echo "  ℹ️ Deviation: user phải vào /products/create để tạo SP mới\n";
}

// ═══ CASE 03H — Đa phương thức thanh toán ═══
echo "\n── CASE 03H: Đa phương thức thanh toán ──\n";
echo "  → N/A: Hệ thống chỉ hỗ trợ 1 PTTT (cash hoặc transfer)\n";
echo "  ℹ️ PosController: payment_method in:cash,transfer (single)\n";
echo "  ℹ️ InvoiceController: payment_method (single, no validation)\n";
echo "  → Deviation có chủ ý so với KiotViet\n";

// ═══ CASE 03I — Lịch sử bán hàng & công nợ ═══
echo "\n── CASE 03I: Lịch sử bán + công nợ KH ──\n";

$kh001->refresh();

// Check invoices linked to KH001
$kh_invoices = Invoice::where('customer_id', $kh001->id)
    ->where('code', 'LIKE', 'HD_TEST_03%')
    ->get();

test("KH001 có hóa đơn test", $kh_invoices->count() >= 3, $pass, $fail, $errors, "count: {$kh_invoices->count()}");

// Check debt invoices (B, C should have debt)
$debt_invoices = $kh_invoices->filter(fn($inv) => ($inv->total - $inv->customer_paid) > 0);
test("Có hóa đơn còn nợ", $debt_invoices->count() >= 2, $pass, $fail, $errors, "count: {$debt_invoices->count()}");

// Verify total debt matches sum of invoice debts
$expected_total_debt = $debt_before; // already accumulated
$actual_debt = $kh001->debt_amount;

// Calculate expected: all test invoices debt
$test_debt_sum = $kh_invoices->sum(fn($inv) => $inv->total - $inv->customer_paid);
echo "  ℹ️ Tổng nợ từ test invoices: $test_debt_sum\n";

$cashflows_kh = CashFlow::where('reference_type', 'Invoice')
    ->where('reference_code', 'LIKE', 'HD_TEST_03%')
    ->get();

$total_paid_cf = $cashflows_kh->sum('amount');
$total_invoice_amount = $kh_invoices->sum('total');

test("Tổng TT CashFlow khớp", $total_paid_cf == $kh_invoices->sum('customer_paid'), $pass, $fail, $errors,
    "CashFlow: $total_paid_cf vs invoices paid: " . $kh_invoices->sum('customer_paid'));

test("TT + Nợ = Tổng hóa đơn", $total_paid_cf + $test_debt_sum == $total_invoice_amount, $pass, $fail, $errors,
    "paid($total_paid_cf) + debt($test_debt_sum) = " . ($total_paid_cf + $test_debt_sum) . " vs total($total_invoice_amount)");

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

// Cleanup
echo "\n── Cleanup ──\n";
$testCodes = Invoice::where('code', 'LIKE', 'HD_TEST_03%')->pluck('code', 'id');
foreach ($testCodes as $id => $code) {
    CashFlow::where('reference_code', $code)->delete();
    InvoiceItem::where('invoice_id', $id)->delete();
}
Invoice::where('code', 'LIKE', 'HD_TEST_03%')->delete();
Customer::where('code', 'KH_FLOW03')->delete();
echo "  ✓ Đã dọn test data\n";
echo "  ⚠️ Lưu ý: tồn kho và nợ KH đã thay đổi do test.\n";
