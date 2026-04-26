<?php
/**
 * Test rebuild với LEGACY data (không có stock_movements ban đầu).
 * Reproduce kịch bản production: sản phẩm tạo trước Phase 4, chỉ có
 * serials + tasks + invoice_items, không có stock_movements purchase.
 */
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::loginUsingId(1);
$pass = 0; $fail = 0; $errors = [];
function check(string $label, bool $cond, &$pass, &$fail, &$errors, string $detail=''):void{
    if($cond){echo "  ✓ $label\n";$pass++;}
    else{echo "  ✗ $label".($detail?" — $detail":'')."\n";$fail++;$errors[]=$label;}
}
function near(float $a,float $b,float $eps=1.0):bool{return abs($a-$b)<=$eps;}

echo "\n══════════════════════════════════════════════════\n";
echo "  TEST REBUILD - LEGACY DATA (không có stock_movements)\n";
echo "══════════════════════════════════════════════════\n";

DB::beginTransaction();
try {
    $supplier = Customer::create([
        'name' => 'NCC LG', 'code' => 'NCC-LG-' . substr((string)microtime(true), -8),
        'phone' => '0918' . substr((string)time(), -6), 'is_supplier' => true,
    ]);
    $customer = Customer::create([
        'name' => 'KH LG', 'code' => 'KH-LG-' . substr((string)microtime(true), -8),
        'phone' => '0919' . substr((string)time(), -6), 'is_customer' => true,
    ]);

    $sku = 'SP-LG-' . time();
    $product = Product::create([
        'sku' => $sku, 'name' => 'Thinkpad LG legacy',
        'cost_price' => 5255844, 'inventory_total_cost' => 47302600,
        'retail_price' => 7500000,
        'stock_quantity' => 9, 'has_serial' => true, 'is_active' => true, 'type' => 'standard',
    ]);

    // 10 IMEI thật, mỗi cái original_cost = 4.1M, KHÔNG có stock_movement
    $imeis = ['MQ','N1','M6','NT','NR','M9','MT','ND','MW','N2'];
    $partsPerImei = [
        'MQ'=>1240000,'N1'=>1240000,'M6'=>1130000,'NT'=>877800,'NR'=>1388500,
        'M9'=>767800,'MT'=>1240000,'ND'=>1130000,'MW'=>1240000,'N2'=>1388500,
    ];
    $serials = [];
    foreach ($imeis as $i => $imei) {
        $finalCost = 4100000 + $partsPerImei[$imei];
        // Tất cả nhập trong khoảng 30..21 ngày trước (cách nhau 1 ngày)
        $createdAt = now()->subDays(30 - $i);
        $s = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => 'LG-' . $imei . '-' . time(),
            'status' => 'in_stock',
            'cost_price' => $finalCost,
            'original_cost' => 4100000,
        ]);
        // Override created_at để timeline đúng
        DB::table('serial_imeis')->where('id', $s->id)->update([
            'created_at' => $createdAt->toDateTimeString(),
            'updated_at' => $createdAt->toDateTimeString(),
        ]);
        $serials[$imei] = $s;
    }

    // Tạo task + parts cho từng IMEI (KHÔNG gọi service, chỉ tạo data)
    $partProduct = Product::create([
        'sku' => 'PART-LG-' . time(), 'name' => 'Linh kiện LG',
        'cost_price' => 0, 'retail_price' => 0, 'stock_quantity' => 999,
        'has_serial' => false, 'is_active' => true, 'type' => 'standard',
    ]);
    foreach ($partsPerImei as $imei => $cost) {
        $serial = $serials[$imei];
        $task = Task::create([
            'code' => 'RP-LG-' . $imei,
            'product_id' => $product->id, 'serial_imei_id' => $serial->id,
            'type' => 'repair', 'title' => "Sửa $imei", 'status' => 'completed',
            'created_by' => 1,
        ]);
        $partTs = now()->subDays(15)->toDateTimeString();
        DB::table('task_parts')->insert([
            'task_id' => $task->id, 'product_id' => $partProduct->id,
            'quantity' => 1, 'unit_cost' => $cost, 'total_cost' => $cost,
            'direction' => 'in',
            'created_at' => $partTs, 'updated_at' => $partTs,
        ]);
    }

    // Tạo hóa đơn bán MQ với COGS đích danh CŨ = 5.340.000, KHÔNG có stock_movement
    $sMq = $serials['MQ'];
    $invoiceTs = now()->subDays(2);
    $invoice = Invoice::create([
        'code' => 'HD-LG-' . uniqid(),
        'customer_id' => $customer->id,
        'subtotal' => 7500000, 'discount' => 0, 'total' => 7500000,
        'customer_paid' => 7500000, 'payment_method' => 'cash', 'status' => 'completed',
        'created_by' => 1, 'sale_time' => $invoiceTs,
    ]);
    DB::table('invoices')->where('id', $invoice->id)->update([
        'created_at' => $invoiceTs->toDateTimeString(),
        'updated_at' => $invoiceTs->toDateTimeString(),
    ]);
    $item = InvoiceItem::create([
        'invoice_id' => $invoice->id, 'product_id' => $product->id,
        'quantity' => 1, 'price' => 7500000, 'discount' => 0, 'subtotal' => 7500000,
        'cost_price' => 5340000,  // ĐÍCH DANH CŨ
    ]);
    InvoiceItemSerial::create([
        'invoice_item_id' => $item->id, 'serial_imei_id' => $sMq->id,
        'serial_number' => $sMq->serial_number, 'cost_price' => 5340000,
    ]);
    DB::table('serial_imeis')->where('id', $sMq->id)->update([
        'status' => 'sold', 'invoice_id' => $invoice->id,
        'sold_at' => $invoiceTs->toDateTimeString(),
        'sold_cost_price' => 5340000,
    ]);

    // Verify state TRƯỚC rebuild
    $movCount = StockMovement::where('product_id', $product->id)->count();
    check('Trước rebuild: stock_movements = 0 (legacy)', $movCount === 0, $pass, $fail, $errors, "count=$movCount");

    // ── Chạy rebuild
    echo "\n── Chạy rebuild ──\n";
    \Illuminate\Support\Facades\Artisan::call('costing:rebuild-moving-avg', [
        '--product' => (string) $product->id,
    ]);
    echo \Illuminate\Support\Facades\Artisan::output();

    // ── Verify
    echo "\n── Verify sau rebuild ──\n";
    $product->refresh();
    check('qty = 9', (int)$product->stock_quantity === 9, $pass, $fail, $errors, "qty={$product->stock_quantity}");
    check('total = 47.378.340', near((float)$product->inventory_total_cost, 47378340, 5), $pass, $fail, $errors, 'total='.$product->inventory_total_cost);
    check('BQ = 5.264.260', near((float)$product->cost_price, 5264260, 1), $pass, $fail, $errors, 'BQ='.$product->cost_price);

    $item->refresh();
    check('invoice_item.cost_price = 5.264.260', near((float)$item->cost_price, 5264260, 1), $pass, $fail, $errors, 'cost='.$item->cost_price);

    $sMq->refresh();
    check('serial.sold_cost_price = 5.264.260', near((float)$sMq->sold_cost_price, 5264260, 1), $pass, $fail, $errors, 'sold_cost='.$sMq->sold_cost_price);

} finally {
    DB::rollBack();
}

echo "\n══════════════════════════════════════════════════\n";
echo "  KẾT QUẢ: $pass passed, $fail failed\n";
echo "══════════════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
