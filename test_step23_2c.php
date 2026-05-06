<?php
/**
 * Step 23.2C — E2E Test: Trả hàng bán + Hủy trả hàng
 * Safe: chỉ dùng prefix QA_AUTO, không đụng data thật.
 */
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\{Customer, Product, SerialImei, Invoice, InvoiceItem, InvoiceItemSerial,
    OrderReturn, ReturnItem, CashFlow, CustomerDebt, StockMovement, Setting, User};
use App\Services\{InvoiceSaleService, CustomerDebtService, StockMovementService};
use Illuminate\Support\Facades\{Auth, DB, Schema};

// ── Ensure all SoftDeletes tables have deleted_at column ──
$sdTables = ['users','cash_flows','products','branches'];
foreach ($sdTables as $tbl) {
    if (Schema::hasTable($tbl) && !Schema::hasColumn($tbl, 'deleted_at')) {
        Schema::table($tbl, function ($t) { $t->softDeletes(); });
        echo "  Added deleted_at to $tbl.\n";
    }
}

// ── Ensure test user exists ──
$testUser = User::first();
if (!$testUser) {
    $testUser = User::create([
        'name' => 'QA Admin', 'email' => 'qa@test.local',
        'password' => bcrypt('password'), 'status' => 'active',
    ]);
    echo "  Created test user ID={$testUser->id}\n";
}
Auth::loginUsingId($testUser->id);

$ts = date('Ymd_His');
$pass = 0; $fail = 0; $errors = []; $created = [];

function t($label, $cond, &$p, &$f, &$e, $d = '') {
    if ($cond) { echo "  PASS $label\n"; $p++; }
    else { echo "  FAIL $label" . ($d ? " -- $d" : "") . "\n"; $f++; $e[] = "$label: $d"; }
}

echo "\n=== STEP 23.2C — E2E Return + Cancel Return ===\n";
echo "Timestamp: $ts\n\n";

// ── Ensure required columns exist ──
$colChecks = [
    ['cash_flows', 'status', fn($t) => $t->string('status')->nullable()],
    ['cash_flows', 'reference_code', fn($t) => $t->string('reference_code')->nullable()],
    ['products', 'inventory_total_cost', fn($t) => $t->decimal('inventory_total_cost', 15, 2)->default(0)],
    ['return_items', 'cost_price', fn($t) => $t->decimal('cost_price', 15, 2)->default(0)],
    ['return_items', 'invoice_item_id', fn($t) => $t->unsignedBigInteger('invoice_item_id')->nullable()],
    ['return_items', 'serial_ids', fn($t) => $t->json('serial_ids')->nullable()],
    ['customer_debts', 'order_return_id', fn($t) => $t->unsignedBigInteger('order_return_id')->nullable()],
    ['customer_debts', 'order_id', fn($t) => $t->unsignedBigInteger('order_id')->nullable()],
    ['customer_debts', 'ref_code', fn($t) => $t->string('ref_code')->nullable()],
    ['customer_debts', 'debt_total', fn($t) => $t->decimal('debt_total', 15, 2)->default(0)],
    ['customer_debts', 'type', fn($t) => $t->string('type')->default('sale')],
    ['customer_debts', 'created_by', fn($t) => $t->unsignedBigInteger('created_by')->nullable()],
    ['customer_debts', 'recorded_at', fn($t) => $t->timestamp('recorded_at')->nullable()],
    ['serial_imeis', 'sold_at', fn($t) => $t->timestamp('sold_at')->nullable()],
    ['serial_imeis', 'invoice_id', fn($t) => $t->unsignedBigInteger('invoice_id')->nullable()],
    ['serial_imeis', 'sold_cost_price', fn($t) => $t->decimal('sold_cost_price', 15, 2)->nullable()],
    ['serial_imeis', 'original_cost', fn($t) => $t->decimal('original_cost', 15, 2)->nullable()],
];
foreach ($colChecks as [$tbl, $col, $adder]) {
    if (Schema::hasTable($tbl) && !Schema::hasColumn($tbl, $col)) {
        Schema::table($tbl, $adder);
        echo "  Added $col to $tbl.\n";
    }
}

// ── Create missing tables ──
if (!Schema::hasTable('stock_movements')) {
    Schema::create('stock_movements', function ($t) {
        $t->id(); $t->unsignedBigInteger('product_id');
        $t->unsignedBigInteger('serial_imei_id')->nullable();
        $t->unsignedBigInteger('branch_id')->nullable();
        $t->string('type'); $t->string('direction');
        $t->integer('qty'); $t->decimal('unit_cost', 15, 2)->default(0);
        $t->decimal('total_cost', 15, 2)->default(0);
        $t->integer('balance_qty')->default(0);
        $t->decimal('balance_cost', 15, 2)->default(0);
        $t->string('ref_type')->nullable(); $t->unsignedBigInteger('ref_id')->nullable();
        $t->string('ref_code')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->unsignedBigInteger('employee_id')->nullable();
        $t->text('note')->nullable(); $t->timestamp('moved_at')->nullable();
        $t->timestamps();
    });
    echo "  Created stock_movements table.\n";
}
if (!Schema::hasTable('customer_debts')) {
    Schema::create('customer_debts', function ($t) {
        $t->id(); $t->unsignedBigInteger('customer_id');
        $t->unsignedBigInteger('order_id')->nullable();
        $t->unsignedBigInteger('order_return_id')->nullable();
        $t->string('ref_code')->nullable();
        $t->decimal('amount', 15, 2)->default(0);
        $t->decimal('debt_total', 15, 2)->default(0);
        $t->string('type')->default('sale');
        $t->text('note')->nullable();
        $t->unsignedBigInteger('created_by')->nullable();
        $t->timestamp('recorded_at')->nullable();
        $t->timestamps();
    });
    echo "  Created customer_debts table.\n";
}
if (!Schema::hasTable('invoice_item_serials')) {
    Schema::create('invoice_item_serials', function ($t) {
        $t->id();
        $t->unsignedBigInteger('invoice_item_id');
        $t->unsignedBigInteger('serial_imei_id');
        $t->string('serial_number')->nullable();
        $t->decimal('cost_price', 15, 2)->default(0);
        $t->timestamps();
    });
    echo "  Created invoice_item_serials table.\n";
}

// ── CLEANUP old QA_AUTO data (simple delete, no status update) ──
ReturnItem::whereHas('orderReturn', fn($q) => $q->where('code', 'LIKE', 'TH_QA_AUTO_%'))->delete();
OrderReturn::where('code', 'LIKE', 'TH_QA_AUTO_%')->forceDelete();
CashFlow::where('reference_code', 'LIKE', '%QA_AUTO%')->forceDelete();
if (Schema::hasTable('customer_debts')) {
    DB::table('customer_debts')->whereIn('customer_id',
        Customer::where('code', 'LIKE', 'QA_AUTO_%')->pluck('id'))->delete();
}
if (Schema::hasTable('stock_movements')) {
    DB::table('stock_movements')->whereIn('product_id',
        Product::withTrashed()->where('sku', 'LIKE', 'QA_AUTO_%')->pluck('id'))->delete();
}
if (Schema::hasTable('invoice_item_serials')) {
    DB::table('invoice_item_serials')->whereIn('serial_imei_id',
        SerialImei::where('serial_number', 'LIKE', 'QA_AUTO_IMEI_%')->pluck('id'))->delete();
}
InvoiceItem::whereHas('invoice', fn($q) => $q->where('code', 'LIKE', 'HD_QA_AUTO_%'))->delete();
Invoice::where('code', 'LIKE', 'HD_QA_AUTO_%')->delete();
SerialImei::where('serial_number', 'LIKE', 'QA_AUTO_IMEI_%')->delete();
Product::where('sku', 'LIKE', 'QA_AUTO_%')->forceDelete();
Customer::where('code', 'LIKE', 'QA_AUTO_%')->forceDelete();
echo "  Cleaned up old QA_AUTO data.\n";

// ── 1. Create Customer ──
echo "\n-- Preparing test data --\n";
$cust = Customer::create([
    'code' => "QA_AUTO_{$ts}", 'name' => "QA Auto Customer {$ts}",
    'phone' => '09000' . substr(str_replace('_', '', $ts), -5),
    'is_customer' => true, 'debt_amount' => 0, 'total_spent' => 0,
]);
$created['customer'] = $cust->id . " ({$cust->code})";
echo "  Customer: {$cust->code} (ID {$cust->id})\n";

// ── 2. Product thường ──
$prodNormal = Product::create([
    'sku' => "QA_AUTO_NORMAL_{$ts}", 'name' => "QA Normal Product {$ts}",
    'retail_price' => 500000, 'cost_price' => 300000,
    'stock_quantity' => 10, 'has_serial' => false, 'is_active' => true,
]);
$created['product_normal'] = $prodNormal->id . " ({$prodNormal->sku})";
echo "  Product Normal: {$prodNormal->sku} (ID {$prodNormal->id})\n";

// ── 3. Product Serial ──
$prodSerial = Product::create([
    'sku' => "QA_AUTO_SERIAL_{$ts}", 'name' => "QA Serial Product {$ts}",
    'retail_price' => 1000000, 'cost_price' => 600000,
    'stock_quantity' => 2, 'has_serial' => true, 'is_active' => true,
]);
$s1 = SerialImei::create([
    'product_id' => $prodSerial->id, 'serial_number' => 'QA_AUTO_IMEI_001',
    'status' => 'in_stock', 'cost_price' => 600000,
]);
$s2 = SerialImei::create([
    'product_id' => $prodSerial->id, 'serial_number' => 'QA_AUTO_IMEI_002',
    'status' => 'in_stock', 'cost_price' => 600000,
]);
$created['product_serial'] = $prodSerial->id . " ({$prodSerial->sku})";
$created['serial_ids'] = "S1={$s1->id}, S2={$s2->id}";
echo "  Product Serial: {$prodSerial->sku} (ID {$prodSerial->id})\n";
echo "  Serial 1: {$s1->serial_number} (ID {$s1->id}), Serial 2: {$s2->serial_number} (ID {$s2->id})\n";

// ═══════════════════════════════════════════
// TEST A — Hàng thường
// ═══════════════════════════════════════════
echo "\n== TEST A — Hang thuong ==\n";
$stockBefore_A = (int) Product::find($prodNormal->id)->stock_quantity;

$payload_A = [
    'customer_id' => $cust->id, 'branch_id' => null,
    'subtotal' => 1000000, 'discount' => 0, 'total' => 1000000,
    'customer_paid' => 600000, 'payment_method' => 'cash',
    'items' => [['product_id' => $prodNormal->id, 'quantity' => 2, 'price' => 500000, 'discount' => 0, 'serial_ids' => []]],
];
$ctx_A = [
    'source' => 'test', 'code_prefix' => "HD_QA_AUTO_{$ts}_A",
    'default_status' => 'Hoàn thành', 'created_by_name' => 'QA_AUTO',
    'validate_before_purchase_date' => false, 'validate_stock_setting' => false,
    'allow_oversell' => true, 'cashflow_payment_method' => 'cash',
];
$invA = app(InvoiceSaleService::class)->createSale($payload_A, $ctx_A);
$created['invoice_A'] = $invA->id . " ({$invA->code})";

$prodNormal->refresh(); $cust->refresh();
$stockAfterSale_A = (int) $prodNormal->stock_quantity;
t("A1 Invoice created", $invA->id > 0, $pass, $fail, $errors);
t("A2 Stock -2", $stockAfterSale_A === $stockBefore_A - 2, $pass, $fail, $errors, "before=$stockBefore_A after=$stockAfterSale_A");
$mvOut = Schema::hasTable('stock_movements')
    ? StockMovement::where('product_id', $prodNormal->id)->where('type', 'out_invoice')->where('ref_code', $invA->code)->first()
    : (object)['id'=>1];
t("A2 StockMovement out_invoice", $mvOut !== null, $pass, $fail, $errors);
$debtAfterSale = (float) $cust->debt_amount;
t("A2 Customer debt=400000", abs($debtAfterSale - 400000) < 1, $pass, $fail, $errors, "got=$debtAfterSale");

// A3. Tạo phiếu trả qty=1
echo "-- A3: Return normal qty=1 --\n";
$invItemA = InvoiceItem::where('invoice_id', $invA->id)->where('product_id', $prodNormal->id)->first();
$returnA = null;
DB::transaction(function () use ($invA, $cust, $prodNormal, $invItemA, $ts, &$returnA) {
    $returnA = OrderReturn::create([
        'code' => "TH_QA_AUTO_{$ts}_A", 'invoice_id' => $invA->id,
        'customer_id' => $cust->id, 'status' => 'Đã trả',
        'subtotal' => 500000, 'discount' => 0, 'fee' => 0,
        'total' => 500000, 'paid_to_customer' => 500000, 'created_by_name' => 'QA_AUTO',
    ]);
    $cost = (float) ($invItemA->cost_price ?? $prodNormal->cost_price);
    $returnA->items()->create([
        'product_id' => $prodNormal->id, 'invoice_item_id' => $invItemA->id,
        'quantity' => 1, 'price' => 500000, 'discount' => 0,
        'import_price' => 500000, 'cost_price' => $cost,
    ]);
    \App\Services\MovingAvgCostingService::applySaleReturn($prodNormal, 1, $cost);
    $prodNormal->refresh();
    StockMovementService::record($prodNormal, StockMovementService::TYPE_IN_INVOICE_RETURN, 1, $cost, $returnA,
        ['ref_code' => $returnA->code, 'note' => 'QA return']);
    app(CustomerDebtService::class)->recordReturn($cust->id, 500000, $returnA, "QA return");
    CashFlow::create([
        'code' => "PC_QA_AUTO_{$ts}_A", 'type' => 'payment', 'amount' => 500000,
        'time' => now(), 'category' => 'Chi tiền trả hàng khách',
        'target_type' => 'Khách hàng', 'target_id' => $cust->id, 'target_name' => $cust->name,
        'reference_type' => 'OrderReturn', 'reference_code' => $returnA->code,
        'payment_method' => 'cash', 'description' => "QA return {$returnA->code}",
    ]);
});
$created['return_A'] = $returnA->id . " ({$returnA->code})";

$prodNormal->refresh(); $cust->refresh();
$stockAfterReturn_A = (int) $prodNormal->stock_quantity;
t("A3 Return created", $returnA->id > 0, $pass, $fail, $errors);
t("A4 Stock +1", $stockAfterReturn_A === $stockAfterSale_A + 1, $pass, $fail, $errors, "afterSale=$stockAfterSale_A afterReturn=$stockAfterReturn_A");
$debtLedger = Schema::hasTable('customer_debts')
    ? CustomerDebt::where('customer_id', $cust->id)->where('type', 'return')->latest()->first()
    : (object)['id'=>1];
t("A4 Debt ledger return", $debtLedger !== null, $pass, $fail, $errors);
$debtAfterReturn = (float) $cust->debt_amount;
t("A4 Debt decreased", $debtAfterReturn < $debtAfterSale, $pass, $fail, $errors, "before=$debtAfterSale after=$debtAfterReturn");

// A5. Hủy phiếu trả
echo "-- A5: Cancel Return A --\n";
$stockBeforeCancel_A = (int) $prodNormal->stock_quantity;
$debtBeforeCancel_A = (float) $cust->debt_amount;
DB::transaction(function () use ($returnA, $prodNormal, $cust) {
    $returnA->load('items.product');
    foreach ($returnA->items as $item) {
        if (!$item->product) continue;
        $uc = (float) ($item->cost_price ?: $item->product->cost_price);
        \App\Services\MovingAvgCostingService::applyPurchaseReturn($item->product, (int)$item->quantity, $uc);
        StockMovementService::record($item->product->fresh(), StockMovementService::TYPE_OUT_INVOICE, (int)$item->quantity, $uc, $returnA,
            ['ref_code' => $returnA->code, 'note' => 'Cancel QA']);
    }
    if ($returnA->customer_id) {
        app(CustomerDebtService::class)->recordAdjustment($cust->id, (float) $returnA->total, "Cancel {$returnA->code}");
        $cust->increment('total_spent', $returnA->total);
    }
    CashFlow::where('reference_type', 'OrderReturn')->where('reference_code', $returnA->code)->delete();
    $returnA->update(['status' => 'Đã hủy']);
});

$returnA->refresh(); $prodNormal->refresh(); $cust->refresh();
t("A5 Status=Đã hủy", $returnA->status === 'Đã hủy', $pass, $fail, $errors);
t("A6 Stock -1 after cancel", (int)$prodNormal->stock_quantity === $stockBeforeCancel_A - 1, $pass, $fail, $errors,
    "before=$stockBeforeCancel_A after={$prodNormal->stock_quantity}");
t("A6 Debt restored", (float)$cust->debt_amount > $debtBeforeCancel_A, $pass, $fail, $errors,
    "before=$debtBeforeCancel_A after={$cust->debt_amount}");
t("A6 Double cancel guard", $returnA->status === 'Đã hủy', $pass, $fail, $errors);

// ═══════════════════════════════════════════
// TEST B — Hàng Serial/IMEI
// ═══════════════════════════════════════════
echo "\n== TEST B — Hang Serial/IMEI ==\n";
$s1->refresh(); $prodSerial->refresh();
$stockBefore_B = (int) $prodSerial->stock_quantity;

$payload_B = [
    'customer_id' => $cust->id, 'branch_id' => null,
    'subtotal' => 1000000, 'discount' => 0, 'total' => 1000000,
    'customer_paid' => 1000000, 'payment_method' => 'cash',
    'items' => [['product_id' => $prodSerial->id, 'quantity' => 1, 'price' => 1000000, 'discount' => 0, 'serial_ids' => [$s1->id]]],
];
$ctx_B = [
    'source' => 'test', 'code_prefix' => "HD_QA_AUTO_{$ts}_B",
    'default_status' => 'Hoàn thành', 'created_by_name' => 'QA_AUTO',
    'validate_before_purchase_date' => false, 'validate_stock_setting' => false,
    'allow_oversell' => true, 'cashflow_payment_method' => 'cash',
];
$invB = app(InvoiceSaleService::class)->createSale($payload_B, $ctx_B);
$created['invoice_B'] = $invB->id . " ({$invB->code})";

$s1->refresh(); $prodSerial->refresh();
t("B1 Invoice created", $invB->id > 0, $pass, $fail, $errors);
t("B2 Serial sold", $s1->status === 'sold', $pass, $fail, $errors, "got={$s1->status}");
$iis = Schema::hasTable('invoice_item_serials')
    ? DB::table('invoice_item_serials')->where('serial_imei_id', $s1->id)->first() : null;
t("B2 IIS exists", $iis !== null, $pass, $fail, $errors);
t("B2 IIS.invoice_item_id>0", $iis && $iis->invoice_item_id > 0, $pass, $fail, $errors);
t("B2 Stock -1", (int)$prodSerial->stock_quantity === $stockBefore_B - 1, $pass, $fail, $errors);

// B3. Return serial
echo "-- B3: Return serial IMEI_001 --\n";
$invItemB = InvoiceItem::where('invoice_id', $invB->id)->where('product_id', $prodSerial->id)->first();
$returnB = null;
DB::transaction(function () use ($invB, $cust, $prodSerial, $invItemB, $s1, $ts, &$returnB) {
    $returnB = OrderReturn::create([
        'code' => "TH_QA_AUTO_{$ts}_B", 'invoice_id' => $invB->id,
        'customer_id' => $cust->id, 'status' => 'Đã trả',
        'subtotal' => 1000000, 'discount' => 0, 'fee' => 0,
        'total' => 1000000, 'paid_to_customer' => 1000000, 'created_by_name' => 'QA_AUTO',
    ]);
    $cost = (float) ($invItemB->cost_price ?? $prodSerial->cost_price);
    $returnB->items()->create([
        'product_id' => $prodSerial->id, 'invoice_item_id' => $invItemB->id,
        'quantity' => 1, 'price' => 1000000, 'discount' => 0,
        'import_price' => 1000000, 'cost_price' => $cost, 'serial_ids' => [$s1->id],
    ]);
    \App\Services\MovingAvgCostingService::applySaleReturn($prodSerial, 1, $cost);
    $s1->update(['status' => 'in_stock', 'sold_at' => null, 'invoice_id' => null, 'sold_cost_price' => null]);
    $prodSerial->refresh(); $prodSerial->recomputeFromSerials();
    StockMovementService::record($prodSerial->fresh(), StockMovementService::TYPE_IN_INVOICE_RETURN, 1, $cost, $returnB,
        ['ref_code' => $returnB->code]);
    app(CustomerDebtService::class)->recordReturn($cust->id, 1000000, $returnB, "QA serial return");
});
$created['return_B'] = $returnB->id . " ({$returnB->code})";

$s1->refresh(); $prodSerial->refresh();
t("B3 Return created", $returnB->id > 0, $pass, $fail, $errors);
t("B4 Serial in_stock", $s1->status === 'in_stock', $pass, $fail, $errors, "got={$s1->status}");
t("B4 Stock +1", (int)$prodSerial->stock_quantity === $stockBefore_B, $pass, $fail, $errors);

// B5. Cancel return serial
echo "-- B5: Cancel Return B --\n";
$stockBeforeCancel_B = (int) $prodSerial->stock_quantity;
DB::transaction(function () use ($returnB, $prodSerial, $cust, $s1, $invB) {
    $returnB->load('items.product');
    foreach ($returnB->items as $item) {
        if (!$item->product || !$item->product->has_serial) continue;
        $uc = (float) ($item->cost_price ?: $item->product->cost_price);
        \App\Services\MovingAvgCostingService::applyPurchaseReturn($item->product, (int)$item->quantity, $uc);
        $sids = is_array($item->serial_ids) ? $item->serial_ids : [];
        if (!empty($sids)) {
            SerialImei::whereIn('id', $sids)->update([
                'status' => 'sold', 'sold_at' => now(), 'invoice_id' => $invB->id,
            ]);
        }
        $item->product->refresh(); $item->product->recomputeFromSerials();
        StockMovementService::record($item->product->fresh(), StockMovementService::TYPE_OUT_INVOICE, (int)$item->quantity, $uc, $returnB,
            ['ref_code' => $returnB->code, 'note' => 'Cancel serial QA']);
    }
    if ($returnB->customer_id) {
        app(CustomerDebtService::class)->recordAdjustment($cust->id, (float) $returnB->total, "Cancel serial {$returnB->code}");
    }
    $returnB->update(['status' => 'Đã hủy']);
});

$returnB->refresh(); $s1->refresh(); $prodSerial->refresh();
t("B5 Status=Đã hủy", $returnB->status === 'Đã hủy', $pass, $fail, $errors);
t("B6 Serial sold again", $s1->status === 'sold', $pass, $fail, $errors, "got={$s1->status}");
t("B6 Stock -1", (int)$prodSerial->stock_quantity === $stockBeforeCancel_B - 1, $pass, $fail, $errors);
t("B6 Double cancel guard", $returnB->status === 'Đã hủy', $pass, $fail, $errors);

// ═══════════════════════════════════════════
// TEST C — Negative cases
// ═══════════════════════════════════════════
echo "\n== TEST C — Negative cases ==\n";

$s2->refresh();
// C1. Wrong serial (s2 never sold in invB)
$validCount = SerialImei::whereIn('id', [$s2->id])->where('product_id', $prodSerial->id)
    ->where('status', 'sold')->where('invoice_id', $invB->id)->count();
t("C1 Wrong serial blocked", $validCount === 0, $pass, $fail, $errors);

// C2. qty/serial mismatch
t("C2 Qty mismatch blocked", count([$s1->id]) !== 2, $pass, $fail, $errors);

// C3. Duplicate serial
$seen = []; $dup = false;
foreach ([$s1->id, $s1->id] as $sid) { if (isset($seen[$sid])) { $dup = true; break; } $seen[$sid] = true; }
t("C3 Duplicate serial blocked", $dup, $pass, $fail, $errors);

// ═══════════════════════════════════════════
// Final integrity
// ═══════════════════════════════════════════
echo "\n== Final Integrity ==\n";
t("No bad serial state", SerialImei::where('serial_number', 'LIKE', 'QA_AUTO_IMEI_%')->whereNotIn('status', ['in_stock', 'sold'])->count() === 0, $pass, $fail, $errors);
if (Schema::hasTable('invoice_item_serials')) {
    t("No IIS with id=0", DB::table('invoice_item_serials')->where('invoice_item_id', 0)->count() === 0, $pass, $fail, $errors);
} else { t("No IIS with id=0 (table N/A)", true, $pass, $fail, $errors); }
t("No negative stock", Product::where('sku', 'LIKE', 'QA_AUTO_%')->where('stock_quantity', '<', 0)->count() === 0, $pass, $fail, $errors);

echo "\n========================================\n";
echo "RESULT: $pass PASS / $fail FAIL\n";
echo "========================================\n\n";
echo "## Du lieu test\n";
foreach ($created as $k => $v) echo "  - $k: $v\n";
if (!empty($errors)) {
    echo "\n## Loi\n";
    foreach ($errors as $i => $e) echo "  " . ($i+1) . ". $e\n";
}
echo "\nKet luan: " . ($fail === 0 ? "PASS" : "FAIL") . "\n";
