<?php
/**
 * Test rebuild moving-avg theo kịch bản screenshot Thinkpad L13:
 *  - Nhập 10 IMEI @ 4.100.000
 *  - Sửa chữa thêm linh kiện cho từng IMEI (tạo task_parts) tổng = 11.642.600
 *  - Bán PW0205MQ với COGS đích danh CŨ = 5.340.000 (giả lập trạng thái lệch)
 *  - Sau rebuild: COGS phải = BQ tại lúc bán = 52.642.600/10 = 5.264.260
 *  - Tồn cuối: 47.378.340; BQ = 5.264.260; sản phẩm.cost_price = 5.264.260
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
use App\Models\TaskPart;
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
echo "  TEST REBUILD MOVING-AVG (Kịch bản Thinkpad L13)\n";
echo "══════════════════════════════════════════════════\n";

DB::beginTransaction();
try {
    $supplier = Customer::create([
        'name' => 'NCC RB', 'code' => 'NCC-RB-' . substr((string)microtime(true), -8),
        'phone' => '0908' . substr((string)time(), -6), 'is_supplier' => true,
    ]);
    $customer = Customer::create([
        'name' => 'KH RB', 'code' => 'KH-RB-' . substr((string)microtime(true), -8),
        'phone' => '0909' . substr((string)time(), -6), 'is_customer' => true,
    ]);

    $sku = 'SP-RB-' . time();
    $product = Product::create([
        'sku' => $sku, 'name' => 'Thinkpad L13 RB',
        'cost_price' => 0, 'inventory_total_cost' => 0,
        'retail_price' => 7500000,
        'stock_quantity' => 0, 'has_serial' => true, 'is_active' => true, 'type' => 'standard',
    ]);

    // ── 1. Nhập 10 IMEI @ 4.100.000
    $serials = [];
    $imeis = ['PW0205MQ','PW0205N1','PW0205M6','PW0205NT','PW0205NR','PW0205M9','PW0205MT','PW0205ND','PW0205MW','PW0205N2'];
    $purchase = \App\Models\Purchase::create([
        'code' => 'PN-RB-' . uniqid(),
        'supplier_id' => $supplier->id,
        'subtotal' => 41000000, 'discount' => 0, 'total' => 41000000,
        'paid_amount' => 41000000, 'status' => 'completed',
        'payment_method' => 'cash', 'created_by' => 1,
    ]);
    \App\Models\PurchaseItem::create([
        'purchase_id' => $purchase->id, 'product_id' => $product->id, 'product_name' => $product->name, 'product_code' => $product->sku,
        'quantity' => 10, 'price' => 4100000, 'discount' => 0,
        'subtotal' => 41000000, 'unit_cost_allocated' => 4100000,
    ]);

    foreach ($imeis as $imei) {
        $s = SerialImei::create([
            'product_id' => $product->id, 'product_name' => $product->name, 'product_code' => $product->sku, 'serial_number' => 'RB-' . $imei . '-' . time(),
            'status' => 'in_stock', 'cost_price' => 4100000, 'original_cost' => 4100000,
            'purchase_id' => $purchase->id,
        ]);
        $serials[$imei] = $s;
        // Ghi stock_movement cho từng serial
        $product->refresh();
        \App\Services\MovingAvgCostingService::applyPurchase($product, 1, 4100000);
        $product->refresh();
        \App\Services\StockMovementService::record(
            $product, \App\Services\StockMovementService::TYPE_IN_PURCHASE, 1, 4100000, $purchase,
            ['serial_imei_id' => $s->id, 'moved_at' => now()->subDays(10)]
        );
    }
    $product->refresh();
    echo "Sau nhập 10: qty={$product->stock_quantity}, BQ={$product->cost_price}, total={$product->inventory_total_cost}\n";

    // ── 2. Tạo task sửa chữa thêm parts cho từng IMEI
    // Từ screenshot: chênh lệch (parts) per IMEI:
    $partsPerImei = [
        'PW0205MQ' => 1240000, 'PW0205N1' => 1240000, 'PW0205M6' => 1130000,
        'PW0205NT' => 877800,  'PW0205NR' => 1388500, 'PW0205M9' => 767800,
        'PW0205MT' => 1240000, 'PW0205ND' => 1130000, 'PW0205MW' => 1240000,
        'PW0205N2' => 1388500,
    ];
    // Cần 1 part product để tham chiếu
    $partProduct = Product::create([
        'sku' => 'PART-RB-' . time(), 'name' => 'Linh kiện RB',
        'cost_price' => 0, 'retail_price' => 0, 'stock_quantity' => 999,
        'has_serial' => false, 'is_active' => true, 'type' => 'standard',
    ]);

    foreach ($partsPerImei as $imei => $cost) {
        $serial = $serials[$imei];
        $task = Task::create([
            'code' => 'RP-' . $imei,
            'product_id' => $product->id, 'product_name' => $product->name, 'product_code' => $product->sku, 'serial_imei_id' => $serial->id,
            'type' => 'repair', 'title' => "Sửa $imei", 'status' => 'completed',
            'created_by' => 1,
        ]);
        // Insert thẳng để override created_at (Eloquent::create ghi đè timestamp)
        $partTs = now()->subDays(5)->toDateTimeString();
        DB::table('task_parts')->insert([
            'task_id' => $task->id, 'product_id' => $partProduct->id,
            'quantity' => 1, 'unit_cost' => $cost, 'total_cost' => $cost,
            'direction' => 'in',
            'created_at' => $partTs, 'updated_at' => $partTs,
        ]);
        // Cập nhật giá vốn serial + product BQ
        $serial->cost_price = 4100000 + $cost;
        $serial->save();
        \App\Services\MovingAvgCostingService::applyRepairAdjustment($product, $cost);
    }
    $product->refresh();
    echo "Sau repair: qty={$product->stock_quantity}, BQ={$product->cost_price}, total={$product->inventory_total_cost}\n";
    check('Total inventory = 52.642.600', near((float)$product->inventory_total_cost, 52642600), $pass, $fail, $errors);
    check('BQ trước bán = 5.264.260', near((float)$product->cost_price, 5264260, 1), $pass, $fail, $errors, 'BQ='.$product->cost_price);

    // ── 3. Giả lập bán PW0205MQ với COGS ĐÍCH DANH CŨ (5.340.000)
    // Tạo invoice + invoice_item + invoice_item_serial + stock_movement với cost = 5.340.000
    $sMq = $serials['PW0205MQ'];
    $invoice = Invoice::create([
        'code' => 'HD-RB-' . uniqid(),
        'customer_id' => $customer->id,
        'subtotal' => 7500000, 'discount' => 0, 'total' => 7500000,
        'customer_paid' => 7500000, 'payment_method' => 'cash', 'status' => 'completed',
        'created_by' => 1, 'sale_time' => now()->subDays(2),
        'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
    ]);
    $item = InvoiceItem::create([
        'invoice_id' => $invoice->id, 'product_id' => $product->id, 'product_name' => $product->name, 'product_code' => $product->sku,
        'quantity' => 1, 'price' => 7500000, 'discount' => 0, 'subtotal' => 7500000,
        'cost_price' => 5340000,  // đích danh CŨ
        'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
    ]);
    InvoiceItemSerial::create([
        'invoice_item_id' => $item->id, 'serial_imei_id' => $sMq->id,
        'serial_number' => $sMq->serial_number, 'cost_price' => 5340000,
    ]);
    $sMq->status = 'sold';
    $sMq->invoice_id = $invoice->id;
    $sMq->sold_at = now()->subDays(2);
    $sMq->sold_cost_price = 5340000; // đích danh CŨ
    $sMq->save();

    // Manually trừ tồn (như logic CŨ đích danh)
    $product->stock_quantity -= 1;
    $product->inventory_total_cost = (float)$product->inventory_total_cost - 5340000;
    $product->cost_price = (float)$product->inventory_total_cost / max(1, (int)$product->stock_quantity);
    $product->save();

    \App\Services\StockMovementService::record(
        $product, \App\Services\StockMovementService::TYPE_OUT_INVOICE, 1, 5340000, $invoice,
        ['serial_imei_id' => $sMq->id, 'moved_at' => now()->subDays(2)]
    );

    $product->refresh();
    echo "Sau bán (đích danh CŨ): qty={$product->stock_quantity}, BQ=" . number_format((float)$product->cost_price, 2)
        . ", total=" . number_format((float)$product->inventory_total_cost, 2) . "\n";
    check('Trạng thái lệch: total = 47.302.600 (logic cũ)', near((float)$product->inventory_total_cost, 47302600), $pass, $fail, $errors);

    // ── 4. Chạy rebuild
    echo "\n── Chạy costing:rebuild-moving-avg ──\n";
    \Illuminate\Support\Facades\Artisan::call('costing:rebuild-moving-avg', [
        '--product' => (string) $product->id,
    ]);
    echo \Illuminate\Support\Facades\Artisan::output();

    // ── 5. Verify sau rebuild
    echo "\n── Verify sau rebuild ──\n";
    $product->refresh();
    check('total = 47.378.340 (chuẩn moving-avg)', near((float)$product->inventory_total_cost, 47378340, 5), $pass, $fail, $errors, 'total='.$product->inventory_total_cost);
    check('BQ = 5.264.260', near((float)$product->cost_price, 5264260, 1), $pass, $fail, $errors, 'BQ='.$product->cost_price);
    check('qty = 9', (int)$product->stock_quantity === 9, $pass, $fail, $errors);

    $item->refresh();
    check('invoice_item.cost_price = 5.264.260 (BQ tại lúc bán)', near((float)$item->cost_price, 5264260, 1), $pass, $fail, $errors, 'cost='.$item->cost_price);

    $sMq->refresh();
    check('serial.sold_cost_price = 5.264.260', near((float)$sMq->sold_cost_price, 5264260, 1), $pass, $fail, $errors, 'sold_cost='.$sMq->sold_cost_price);

    $iis = InvoiceItemSerial::where('invoice_item_id', $item->id)->where('serial_imei_id', $sMq->id)->first();
    check('invoice_item_serials.cost_price = 5.264.260', $iis && near((float)$iis->cost_price, 5264260, 1), $pass, $fail, $errors, 'cost='.($iis?->cost_price ?? 'null'));

    $mov = StockMovement::where('product_id', $product->id)->where('type', 'out_invoice')->latest('id')->first();
    check('stock_movement.unit_cost = 5.264.260', near((float)$mov->unit_cost, 5264260, 1), $pass, $fail, $errors, 'uc='.$mov->unit_cost);

} finally {
    DB::rollBack();
}

echo "\n══════════════════════════════════════════════════\n";
echo "  KẾT QUẢ: $pass passed, $fail failed\n";
echo "══════════════════════════════════════════════════\n";
exit($fail > 0 ? 1 : 0);
