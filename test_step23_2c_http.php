<?php
/**
 * Step 23.2C — TRUE E2E Test via Laravel HTTP Test Client
 * Gọi qua Controller thật, validation thật, service thật.
 */
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$kernel = $app->make('Illuminate\Contracts\Http\Kernel');

use App\Models\{Customer, Product, SerialImei, Invoice, InvoiceItem, InvoiceItemSerial,
    OrderReturn, ReturnItem, CashFlow, CustomerDebt, StockMovement, User};
use Illuminate\Support\Facades\{Auth, DB, Schema};
use Illuminate\Http\Request;

// ── Schema fixes for SQLite ──
$sdTables = ['users','cash_flows','products','branches'];
foreach ($sdTables as $tbl) {
    if (Schema::hasTable($tbl) && !Schema::hasColumn($tbl, 'deleted_at'))
        Schema::table($tbl, fn($t) => $t->softDeletes());
}
$cols = [
    ['cash_flows','status',fn($t)=>$t->string('status')->nullable()],
    ['cash_flows','reference_code',fn($t)=>$t->string('reference_code')->nullable()],
    ['products','inventory_total_cost',fn($t)=>$t->decimal('inventory_total_cost',15,2)->default(0)],
    ['return_items','cost_price',fn($t)=>$t->decimal('cost_price',15,2)->default(0)],
    ['return_items','invoice_item_id',fn($t)=>$t->unsignedBigInteger('invoice_item_id')->nullable()],
    ['return_items','serial_ids',fn($t)=>$t->json('serial_ids')->nullable()],
    ['customer_debts','order_return_id',fn($t)=>$t->unsignedBigInteger('order_return_id')->nullable()],
    ['customer_debts','order_id',fn($t)=>$t->unsignedBigInteger('order_id')->nullable()],
    ['customer_debts','ref_code',fn($t)=>$t->string('ref_code')->nullable()],
    ['customer_debts','debt_total',fn($t)=>$t->decimal('debt_total',15,2)->default(0)],
    ['customer_debts','type',fn($t)=>$t->string('type')->default('sale')],
    ['customer_debts','created_by',fn($t)=>$t->unsignedBigInteger('created_by')->nullable()],
    ['customer_debts','recorded_at',fn($t)=>$t->timestamp('recorded_at')->nullable()],
    ['serial_imeis','sold_at',fn($t)=>$t->timestamp('sold_at')->nullable()],
    ['serial_imeis','invoice_id',fn($t)=>$t->unsignedBigInteger('invoice_id')->nullable()],
    ['serial_imeis','sold_cost_price',fn($t)=>$t->decimal('sold_cost_price',15,2)->nullable()],
    ['serial_imeis','original_cost',fn($t)=>$t->decimal('original_cost',15,2)->nullable()],
];
foreach ($cols as [$tbl,$col,$add]) {
    if (Schema::hasTable($tbl) && !Schema::hasColumn($tbl,$col)) Schema::table($tbl,$add);
}
if (!Schema::hasTable('stock_movements')) {
    Schema::create('stock_movements', function($t){
        $t->id();$t->unsignedBigInteger('product_id');$t->unsignedBigInteger('serial_imei_id')->nullable();
        $t->unsignedBigInteger('branch_id')->nullable();$t->string('type');$t->string('direction');
        $t->integer('qty');$t->decimal('unit_cost',15,2)->default(0);$t->decimal('total_cost',15,2)->default(0);
        $t->integer('balance_qty')->default(0);$t->decimal('balance_cost',15,2)->default(0);
        $t->string('ref_type')->nullable();$t->unsignedBigInteger('ref_id')->nullable();$t->string('ref_code')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();$t->unsignedBigInteger('employee_id')->nullable();
        $t->text('note')->nullable();$t->timestamp('moved_at')->nullable();$t->timestamps();
    });
}
if (!Schema::hasTable('invoice_item_serials')) {
    Schema::create('invoice_item_serials', function($t){
        $t->id();$t->unsignedBigInteger('invoice_item_id');$t->unsignedBigInteger('serial_imei_id');
        $t->string('serial_number')->nullable();$t->decimal('cost_price',15,2)->default(0);$t->timestamps();
    });
}

// ── Auth ──
$user = User::first() ?: User::create(['name'=>'QA','email'=>'qa@test.local','password'=>bcrypt('pw'),'status'=>'active']);
Auth::loginUsingId($user->id);

$ts = date('Ymd_His');
$pass = 0; $fail = 0; $errors = []; $created = [];
function t($l,$c,&$p,&$f,&$e,$d=''){if($c){echo "  PASS $l\n";$p++;}else{echo "  FAIL $l".($d?" -- $d":"")."\n";$f++;$e[]="$l: $d";}}

// Helper: call controller action directly (bypasses middleware but tests full controller+service+DB)
function callController($method, $uri, $data, $user) {
    Auth::loginUsingId($user->id);

    // Create request with JSON body for proper nested array support
    $jsonContent = json_encode($data);
    $request = Request::create($uri, $method, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT'  => 'application/json',
    ], $jsonContent);
    $request->setUserResolver(fn() => $user);

    // Bind request to container so controller sees it
    app()->instance('request', $request);
    app()->instance(\Illuminate\Http\Request::class, $request);

    try {
        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(fn() => $route);
        // Resolve implicit model bindings (e.g. {return} -> OrderReturn)
        app(\Illuminate\Routing\ImplicitRouteBinding::class)::resolveForRoute(app(), $route);
        $response = $route->run();

        if ($response instanceof \Illuminate\Http\JsonResponse) {
            return ['status' => $response->getStatusCode(), 'json' => $response->getData(true), 'errors' => null];
        }
        if ($response instanceof \Illuminate\Http\RedirectResponse) {
            return ['status' => $response->getStatusCode(), 'json' => null, 'errors' => null];
        }
        if ($response instanceof \Illuminate\Http\Response) {
            return ['status' => $response->getStatusCode(), 'json' => json_decode($response->getContent(), true), 'errors' => null];
        }
        if (is_array($response)) return ['status' => 200, 'json' => $response, 'errors' => null];
        return ['status' => 200, 'json' => null, 'errors' => null];
    } catch (\Illuminate\Validation\ValidationException $ve) {
        return ['status' => 422, 'json' => $ve->errors(), 'errors' => $ve->errors()];
    } catch (\Exception $ex) {
        return ['status' => 500, 'json' => null, 'errors' => [$ex->getMessage()]];
    }
}

echo "\n=== STEP 23.2C — TRUE E2E via HTTP Controller ===\n";
echo "Timestamp: $ts\n\n";

// ── Cleanup ──
DB::statement('PRAGMA foreign_keys = OFF');
ReturnItem::whereHas('orderReturn',fn($q)=>$q->where('code','LIKE','TH_QA_AUTO_%'))->delete();
OrderReturn::where('code','LIKE','TH_QA_AUTO_%')->forceDelete();
CashFlow::where('reference_code','LIKE','%QA_AUTO%')->forceDelete();
DB::table('customer_debts')->whereIn('customer_id',Customer::where('code','LIKE','QA_AUTO_%')->pluck('id'))->delete();
DB::table('stock_movements')->whereIn('product_id',Product::withTrashed()->where('sku','LIKE','QA_AUTO_%')->pluck('id'))->delete();
DB::table('invoice_item_serials')->whereIn('serial_imei_id',SerialImei::where('serial_number','LIKE','QA_AUTO_IMEI_%')->pluck('id'))->delete();
InvoiceItem::whereHas('invoice',fn($q)=>$q->where('code','LIKE','HD_QA_AUTO_%'))->delete();
Invoice::where('code','LIKE','HD_QA_AUTO_%')->delete();
SerialImei::where('serial_number','LIKE','QA_AUTO_IMEI_%')->delete();
Product::where('sku','LIKE','QA_AUTO_%')->forceDelete();
Customer::where('code','LIKE','QA_AUTO_%')->forceDelete();
DB::statement('PRAGMA foreign_keys = ON');

// ── Create test data ──
echo "-- Preparing data --\n";
$cust = Customer::create(['code'=>"QA_AUTO_{$ts}",'name'=>"QA Customer {$ts}",'phone'=>'090'.substr(str_replace('_','',$ts),-7),'is_customer'=>true,'debt_amount'=>0,'total_spent'=>0]);
$prodN = Product::create(['sku'=>"QA_AUTO_N_{$ts}",'name'=>"QA Normal {$ts}",'retail_price'=>500000,'cost_price'=>300000,'stock_quantity'=>10,'has_serial'=>false,'is_active'=>true]);
$prodS = Product::create(['sku'=>"QA_AUTO_S_{$ts}",'name'=>"QA Serial {$ts}",'retail_price'=>1000000,'cost_price'=>600000,'stock_quantity'=>2,'has_serial'=>true,'is_active'=>true]);
$s1 = SerialImei::create(['product_id'=>$prodS->id,'serial_number'=>'QA_AUTO_IMEI_001','status'=>'in_stock','cost_price'=>600000]);
$s2 = SerialImei::create(['product_id'=>$prodS->id,'serial_number'=>'QA_AUTO_IMEI_002','status'=>'in_stock','cost_price'=>600000]);
echo "  Cust={$cust->id}, ProdN={$prodN->id}, ProdS={$prodS->id}, S1={$s1->id}, S2={$s2->id}\n";

// ════════════════════════════════════════
// TEST A — Hàng thường via POS checkout
// ════════════════════════════════════════
echo "\n== TEST A — Hang thuong (POS checkout → return → cancel) ==\n";
$stockBefore = (int)$prodN->stock_quantity;

// A1. POS Checkout (api/pos/checkout — returns JSON)
echo "-- A1: POS checkout qty=2 --\n";
$r = callController('POST', '/api/pos/checkout', [
    'subtotal'=>1000000,'discount'=>0,'total'=>1000000,'customer_paid'=>600000,
    'customer_id'=>$cust->id,'payment_method'=>'cash',
    'items'=>[['product_id'=>$prodN->id,'quantity'=>2,'price'=>500000,'discount'=>0,'serial_ids'=>[]]],
], $user);

$posOk = $r['status'] < 400;
$posSuccess = $r['json']['success'] ?? false;
$invCode = $r['json']['invoice_code'] ?? null;
t("A1 POS checkout HTTP status<400", $posOk, $pass, $fail, $errors, "status={$r['status']}");
t("A1 POS success=true", $posSuccess, $pass, $fail, $errors, "err=".json_encode($r['errors']));

$invA = $invCode ? Invoice::where('code', $invCode)->first() : null;
t("A1 Invoice found in DB", $invA !== null, $pass, $fail, $errors, "code=$invCode");

$prodN->refresh(); $cust->refresh();
t("A2 Stock -2", (int)$prodN->stock_quantity === $stockBefore - 2, $pass, $fail, $errors, "got={$prodN->stock_quantity}");
t("A2 Debt=400000", abs((float)$cust->debt_amount - 400000) < 1, $pass, $fail, $errors, "got={$cust->debt_amount}");

if (!$invA) { echo "  SKIP rest of A — invoice not created\n"; goto testB; }

// A3. Return via returns.store (POST /returns)
echo "-- A3: Return normal qty=1 via POST /returns --\n";
$invItemA = InvoiceItem::where('invoice_id', $invA->id)->where('product_id', $prodN->id)->first();
$r = callController('POST', '/returns', [
    'invoice_id'=>$invA->id, 'customer_id'=>$cust->id,
    'subtotal'=>500000,'discount'=>0,'fee'=>0,'total'=>500000,'paid_to_customer'=>500000,
    'items'=>[['product_id'=>$prodN->id,'qty'=>1,'price'=>500000,'discount'=>0,'invoice_item_id'=>$invItemA?->id]],
], $user);

// returns.store redirects on success (302)
$returnCreated = $r['status'] === 302 || $r['status'] === 200;
$returnHasError = !empty($r['errors']);
t("A3 Return HTTP ok (302/200)", $returnCreated, $pass, $fail, $errors, "status={$r['status']}");
t("A3 No validation errors", !$returnHasError, $pass, $fail, $errors, $returnHasError ? json_encode($r['errors']) : '');

$retA = OrderReturn::where('invoice_id', $invA->id)->where('status', 'Đã trả')->latest()->first();
t("A3 Return record exists", $retA !== null, $pass, $fail, $errors);

$prodN->refresh(); $cust->refresh();
$stockAfterReturn = (int)$prodN->stock_quantity;
t("A4 Stock +1 after return", $stockAfterReturn === $stockBefore - 2 + 1, $pass, $fail, $errors, "got=$stockAfterReturn");
$debtAfterReturn = (float)$cust->debt_amount;
t("A4 Debt decreased", $debtAfterReturn < 400000, $pass, $fail, $errors, "got=$debtAfterReturn");

if (!$retA) { echo "  SKIP cancel A\n"; goto testB; }

// A5. Cancel via POST /returns/{id}/cancel
echo "-- A5: Cancel return via POST /returns/{$retA->id}/cancel --\n";
$r = callController('POST', "/returns/{$retA->id}/cancel", [], $user);
$cancelOk = $r['status'] === 302 || $r['status'] === 200;
t("A5 Cancel HTTP ok", $cancelOk, $pass, $fail, $errors, "status={$r['status']}");

$retA->refresh(); $prodN->refresh(); $cust->refresh();
t("A5 Status=Đã hủy", $retA->status === 'Đã hủy', $pass, $fail, $errors, "got={$retA->status}");
t("A6 Stock back to sale level", (int)$prodN->stock_quantity === $stockBefore - 2, $pass, $fail, $errors, "got={$prodN->stock_quantity}");
t("A6 Debt restored ~400k", abs((float)$cust->debt_amount - 400000) < 1, $pass, $fail, $errors, "got={$cust->debt_amount}");

// A6b. Double cancel
$r2 = callController('POST', "/returns/{$retA->id}/cancel", [], $user);
$retA->refresh();
t("A6 Double cancel blocked (still Đã hủy)", $retA->status === 'Đã hủy', $pass, $fail, $errors);

// ════════════════════════════════════════
// TEST B — Hàng Serial via POS + return + cancel
// ════════════════════════════════════════
testB:
echo "\n== TEST B — Hang Serial/IMEI ==\n";
$s1->refresh(); $prodS->refresh();
$stockBefore_B = (int)$prodS->stock_quantity;

// B1. POS Checkout with serial
echo "-- B1: POS checkout serial IMEI_001 --\n";
$r = callController('POST', '/api/pos/checkout', [
    'subtotal'=>1000000,'discount'=>0,'total'=>1000000,'customer_paid'=>1000000,
    'customer_id'=>$cust->id,'payment_method'=>'cash',
    'items'=>[['product_id'=>$prodS->id,'quantity'=>1,'price'=>1000000,'discount'=>0,'serial_ids'=>[$s1->id]]],
], $user);
t("B1 POS success", ($r['json']['success'] ?? false), $pass, $fail, $errors, "err=".json_encode($r['errors']));

$invB = isset($r['json']['invoice_code']) ? Invoice::where('code', $r['json']['invoice_code'])->first() : null;
t("B1 Invoice exists", $invB !== null, $pass, $fail, $errors);

$s1->refresh(); $prodS->refresh();
t("B2 Serial=sold", $s1->status === 'sold', $pass, $fail, $errors, "got={$s1->status}");
t("B2 Stock -1", (int)$prodS->stock_quantity === $stockBefore_B - 1, $pass, $fail, $errors);

$iis = DB::table('invoice_item_serials')->where('serial_imei_id', $s1->id)->first();
t("B2 IIS exists & id>0", $iis && $iis->invoice_item_id > 0, $pass, $fail, $errors);

if (!$invB) { echo "  SKIP rest B\n"; goto testC; }

// B3. Return serial via POST /returns
echo "-- B3: Return serial IMEI_001 via POST /returns --\n";
$invItemB = InvoiceItem::where('invoice_id', $invB->id)->where('product_id', $prodS->id)->first();
$r = callController('POST', '/returns', [
    'invoice_id'=>$invB->id, 'customer_id'=>$cust->id,
    'subtotal'=>1000000,'discount'=>0,'fee'=>0,'total'=>1000000,'paid_to_customer'=>1000000,
    'items'=>[['product_id'=>$prodS->id,'qty'=>1,'price'=>1000000,'discount'=>0,
              'invoice_item_id'=>$invItemB?->id, 'serial_ids'=>[$s1->id]]],
], $user);

$retBok = $r['status'] === 302 || $r['status'] === 200;
t("B3 Return HTTP ok", $retBok, $pass, $fail, $errors, "status={$r['status']} err=".json_encode($r['errors']));

$retB = OrderReturn::where('invoice_id', $invB->id)->where('status', 'Đã trả')->latest()->first();
t("B3 Return exists", $retB !== null, $pass, $fail, $errors);

$s1->refresh(); $prodS->refresh();
t("B4 Serial=in_stock", $s1->status === 'in_stock', $pass, $fail, $errors, "got={$s1->status}");
t("B4 Stock restored", (int)$prodS->stock_quantity === $stockBefore_B, $pass, $fail, $errors, "got={$prodS->stock_quantity}");

if (!$retB) { echo "  SKIP cancel B\n"; goto testC; }

// B5. Cancel
echo "-- B5: Cancel return serial --\n";
$r = callController('POST', "/returns/{$retB->id}/cancel", [], $user);
$retB->refresh(); $s1->refresh(); $prodS->refresh();
t("B5 Status=Đã hủy", $retB->status === 'Đã hủy', $pass, $fail, $errors, "got={$retB->status}");
t("B6 Serial=sold again", $s1->status === 'sold', $pass, $fail, $errors, "got={$s1->status}");
t("B6 Stock -1 again", (int)$prodS->stock_quantity === $stockBefore_B - 1, $pass, $fail, $errors);

// ════════════════════════════════════════
// TEST C — Negative cases via REAL controller
// ════════════════════════════════════════
testC:
echo "\n== TEST C — Negative cases (real HTTP) ==\n";

// We need a valid invoice for negative tests. Sell IMEI_001 again if not already sold
$s1->refresh();
if ($s1->status !== 'sold') {
    // sell it
    $r = callController('POST', '/api/pos/checkout', [
        'subtotal'=>1000000,'discount'=>0,'total'=>1000000,'customer_paid'=>1000000,
        'customer_id'=>$cust->id,'payment_method'=>'cash',
        'items'=>[['product_id'=>$prodS->id,'quantity'=>1,'price'=>1000000,'discount'=>0,'serial_ids'=>[$s1->id]]],
    ], $user);
}
$s1->refresh();
// Find the invoice where s1 was sold
$invoiceForNeg = Invoice::where('id', $s1->invoice_id)->first();
if (!$invoiceForNeg) {
    echo "  SKIP C — no invoice for negative tests\n";
    goto final_checks;
}

// C1. Wrong serial: s2 was never sold in this invoice
echo "-- C1: Return wrong serial (IMEI_002 not in invoice) --\n";
$s2->refresh();
$r = callController('POST', '/returns', [
    'invoice_id'=>$invoiceForNeg->id, 'customer_id'=>$cust->id,
    'subtotal'=>1000000,'discount'=>0,'fee'=>0,'total'=>1000000,'paid_to_customer'=>1000000,
    'items'=>[['product_id'=>$prodS->id,'qty'=>1,'price'=>1000000,'discount'=>0,
              'serial_ids'=>[$s2->id]]],
], $user);
// Should be blocked (422 or redirect with errors, or exception)
$c1blocked = $r['status'] === 422 || !empty($r['errors']) || $r['status'] >= 400;
// Also verify no return was created for this
$badReturn = OrderReturn::where('invoice_id', $invoiceForNeg->id)->where('status', 'Đã trả')
    ->whereHas('items', fn($q) => $q->whereJsonContains('serial_ids', $s2->id))->first();
$c1noRecord = $badReturn === null;
t("C1 Wrong serial blocked by controller", $c1blocked, $pass, $fail, $errors, "status={$r['status']} err=".json_encode($r['errors']));
t("C1 No return record created", $c1noRecord, $pass, $fail, $errors);

// C2. qty=2 but only 1 serial
echo "-- C2: qty=2 but 1 serial --\n";
$r = callController('POST', '/returns', [
    'invoice_id'=>$invoiceForNeg->id, 'customer_id'=>$cust->id,
    'subtotal'=>2000000,'discount'=>0,'fee'=>0,'total'=>2000000,'paid_to_customer'=>2000000,
    'items'=>[['product_id'=>$prodS->id,'qty'=>2,'price'=>1000000,'discount'=>0,
              'serial_ids'=>[$s1->id]]], // only 1 serial for qty=2
], $user);
$c2blocked = $r['status'] === 422 || !empty($r['errors']) || $r['status'] >= 400;
t("C2 Qty/serial mismatch blocked", $c2blocked, $pass, $fail, $errors, "status={$r['status']}");

// C3. Duplicate serial
echo "-- C3: Duplicate serial in request --\n";
$r = callController('POST', '/returns', [
    'invoice_id'=>$invoiceForNeg->id, 'customer_id'=>$cust->id,
    'subtotal'=>2000000,'discount'=>0,'fee'=>0,'total'=>2000000,'paid_to_customer'=>2000000,
    'items'=>[
        ['product_id'=>$prodS->id,'qty'=>1,'price'=>1000000,'discount'=>0,'serial_ids'=>[$s1->id]],
        ['product_id'=>$prodS->id,'qty'=>1,'price'=>1000000,'discount'=>0,'serial_ids'=>[$s1->id]],
    ],
], $user);
$c3blocked = $r['status'] === 422 || !empty($r['errors']) || $r['status'] >= 400;
t("C3 Duplicate serial blocked", $c3blocked, $pass, $fail, $errors, "status={$r['status']}");

// ════════════════════════════════════════
// Final integrity
// ════════════════════════════════════════
final_checks:
echo "\n== Final Integrity ==\n";
t("No bad serial state", SerialImei::where('serial_number','LIKE','QA_AUTO_IMEI_%')->whereNotIn('status',['in_stock','sold'])->count()===0, $pass,$fail,$errors);
t("No IIS id=0", DB::table('invoice_item_serials')->where('invoice_item_id',0)->count()===0, $pass,$fail,$errors);
t("No negative stock", Product::where('sku','LIKE','QA_AUTO_%')->where('stock_quantity','<',0)->count()===0, $pass,$fail,$errors);

echo "\n========================================\n";
echo "RESULT: $pass PASS / $fail FAIL\n";
echo "========================================\n";
if (!empty($errors)) { echo "\nFAILS:\n"; foreach($errors as $i=>$e) echo "  ".($i+1).". $e\n"; }
echo "\nKet luan: ".($fail===0?"PASS":"FAIL")."\n";
