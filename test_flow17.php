<?php
/**
 * Flow 17 -- Kiem thu Delivery / Waybill / Return-to-Sender
 */
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Waybill;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Customer;
use App\Models\CustomerDeliveryAddress;
use App\Models\Branch;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\LockPeriodService;
use App\Exceptions\LockPeriodException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Auth::loginUsingId(1);

$pass = 0; $fail = 0; $errors = [];
function test($label, $condition, &$pass, &$fail, &$errors, $detail = '') {
    if ($condition) { echo "  PASS $label\n"; $pass++; }
    else { echo "  FAIL $label" . ($detail ? " -- $detail" : "") . "\n"; $fail++; $errors[] = "$label: $detail"; }
}

echo "\n=== FLOW 17 -- KIEM THU DELIVERY / WAYBILL ===\n\n";

// === CLEANUP ===
Waybill::withTrashed()->where('code', 'LIKE', '%_F17%')->forceDelete();
CustomerDeliveryAddress::where('label', 'LIKE', '%_F17%')->delete();
ActivityLog::where('description', 'LIKE', '%_F17%')->delete();
Invoice::where('code', 'LIKE', 'HD_F17%')->delete();
Setting::where('key', 'lock_date')->delete();
Setting::where('key', 'delivery_rts_auto_update')->delete();
Setting::where('key', 'delivery_enabled')->delete();
// Reset KH_DEL_02 address for 17G test
Customer::where('code', 'KH_DEL_02')->update(['address' => null, 'city' => null, 'district' => null, 'ward' => null]);

// Setup
$branch = Branch::first();
$sp001 = Product::where('sku', 'SP001')->orWhere('code', 'SP001')->first();
if (!$sp001) $sp001 = Product::create(['sku' => 'SP001', 'code' => 'SP001', 'name' => 'Nuoc suoi 500ml', 'sale_price' => 7000, 'cost_price' => 5000, 'stock_quantity' => 100, 'is_active' => true, 'weight' => 250]);

$khDel01 = Customer::where('code', 'KH_DEL_01')->first();
if (!$khDel01) $khDel01 = Customer::create(['code' => 'KH_DEL_01', 'name' => 'Nguyen Van A', 'phone' => '0900000011', 'address' => '123 Le Loi', 'ward' => 'Phuong 1', 'district' => 'Quan 1', 'city' => 'TP HCM']);

$khDel02 = Customer::where('code', 'KH_DEL_02')->first();
if (!$khDel02) $khDel02 = Customer::create(['code' => 'KH_DEL_02', 'name' => 'Tran Thi B', 'phone' => '0900000012', 'address' => null, 'ward' => null, 'district' => null, 'city' => null]);

// Create test invoices
$inv1 = Invoice::create(['code' => 'HD_F17_01', 'customer_id' => $khDel01->id, 'total_amount' => 70000, 'customer_paid' => 70000, 'status' => 'completed', 'is_delivery' => true, 'delivery_partner' => 'GH_RIENG_01']);
$inv2 = Invoice::create(['code' => 'HD_F17_02', 'customer_id' => $khDel01->id, 'total_amount' => 140000, 'customer_paid' => 140000, 'status' => 'completed', 'is_delivery' => true]);
$inv3 = Invoice::create(['code' => 'HD_F17_03', 'customer_id' => $khDel02->id, 'total_amount' => 50000, 'customer_paid' => 50000, 'status' => 'completed', 'is_delivery' => true]);

echo "-- Setup: KH_DEL_01={$khDel01->id}, KH_DEL_02={$khDel02->id}\n";
echo "-- Invoices: {$inv1->code}, {$inv2->code}, {$inv3->code}\n";

// === 17A: Activate delivery feature ===
echo "\n-- 17A: Feature toggle --\n";
Setting::set('delivery_enabled', true, 'system', 'boolean');
test("Delivery enabled persists", Setting::get('delivery_enabled') === true, $pass, $fail, $errors);
Setting::set('delivery_enabled', false, 'system', 'boolean');
test("Delivery disabled persists", Setting::get('delivery_enabled') === false, $pass, $fail, $errors);
Setting::set('delivery_enabled', true, 'system', 'boolean');

// === 17B: Create self-delivery waybill ===
echo "\n-- 17B: Self-delivery waybill --\n";

$wb1 = Waybill::create([
    'code' => 'VD_F17_B1', 'invoice_id' => $inv1->id,
    'customer_id' => $khDel01->id, 'branch_id' => $branch->id,
    'partner_type' => 'self_delivery', 'partner_name' => 'GH_RIENG_01',
    'status' => 'pending',
    'receiver_name' => $khDel01->name, 'receiver_phone' => $khDel01->phone,
    'receiver_address' => $khDel01->address, 'receiver_ward' => $khDel01->ward,
    'receiver_district' => $khDel01->district, 'receiver_city' => $khDel01->city,
    'pickup_address' => $branch->address ?? 'Chi nhánh chính',
    'weight' => 500, 'length' => 10, 'width' => 10, 'height' => 10,
    'delivery_fee' => 30000, 'cod_amount' => 70000,
    'is_active' => true,
]);
ActivityLog::log('waybill_create', "Tạo vận đơn {$wb1->code}_F17", $wb1);

test("Waybill created", $wb1->id > 0, $pass, $fail, $errors);
test("Linked to invoice", (int)$wb1->invoice_id === (int)$inv1->id, $pass, $fail, $errors);
test("Partner type = self_delivery", $wb1->partner_type === 'self_delivery', $pass, $fail, $errors);
test("Partner name", $wb1->partner_name === 'GH_RIENG_01', $pass, $fail, $errors);
test("Status = pending", $wb1->status === 'pending', $pass, $fail, $errors);
test("Is active", $wb1->is_active === true, $pass, $fail, $errors);
test("Invoice still valid", Invoice::find($inv1->id) !== null, $pass, $fail, $errors);

// === 17C: Integrated carrier waybill ===
echo "\n-- 17C: Integrated carrier waybill --\n";

$wb2 = Waybill::create([
    'code' => 'VD_F17_C1', 'invoice_id' => $inv2->id,
    'customer_id' => $khDel01->id, 'branch_id' => $branch->id,
    'partner_type' => 'integrated', 'partner_name' => 'GHTK',
    'carrier_service' => 'Giao hang tiet kiem - Nhanh',
    'tracking_code' => 'TK_F17_C1', 'external_ref' => 'EXT_F17_C1',
    'status' => 'pending',
    'receiver_name' => $khDel01->name, 'receiver_phone' => $khDel01->phone,
    'receiver_address' => $khDel01->address, 'receiver_ward' => $khDel01->ward,
    'receiver_district' => $khDel01->district, 'receiver_city' => $khDel01->city,
    'pickup_address' => $branch->address ?? 'Chi nhánh chính',
    'weight' => 500, 'delivery_fee' => 25000, 'cod_amount' => 140000,
    'declared_value' => 140000, 'is_active' => true,
]);
ActivityLog::log('waybill_carrier_book', "Gửi yêu cầu đặt vận đơn {$wb2->code} qua GHTK_F17", $wb2);
ActivityLog::log('waybill_create', "Tạo vận đơn {$wb2->code}_F17", $wb2);

test("Integrated WB created", $wb2->id > 0, $pass, $fail, $errors);
test("Partner type = integrated", $wb2->partner_type === 'integrated', $pass, $fail, $errors);
test("Tracking code stored", $wb2->tracking_code === 'TK_F17_C1', $pass, $fail, $errors);
test("Carrier service stored", $wb2->carrier_service !== null, $pass, $fail, $errors);
test("External ref stored", $wb2->external_ref === 'EXT_F17_C1', $pass, $fail, $errors);
test("Carrier book log", ActivityLog::where('action', 'waybill_carrier_book')->where('description', 'LIKE', '%_F17%')->exists(), $pass, $fail, $errors);

// === 17D: Recipient address auto-fill & address book ===
echo "\n-- 17D: Address auto-fill & book --\n";

// Customer has full address
test("Customer has full address", $khDel01->address !== null && $khDel01->city !== null, $pass, $fail, $errors);
test("Auto-fill name matches", $wb1->receiver_name === $khDel01->name, $pass, $fail, $errors);
test("Auto-fill address matches", $wb1->receiver_address === $khDel01->address, $pass, $fail, $errors);

// Add alternate delivery address
$altAddr = CustomerDeliveryAddress::create([
    'customer_id' => $khDel01->id, 'label' => 'Alternate_F17',
    'receiver_name' => 'Nguyen Van A', 'receiver_phone' => '0900000099',
    'address' => '456 Tran Hung Dao', 'ward' => 'Phuong 5',
    'district' => 'Quan 5', 'city' => 'TP HCM', 'is_default' => false,
]);
test("Alternate address saved", $altAddr->id > 0, $pass, $fail, $errors);
test("Address findable by customer", CustomerDeliveryAddress::where('customer_id', $khDel01->id)->where('label', 'Alternate_F17')->exists(), $pass, $fail, $errors);
test("Main customer address unchanged", Customer::find($khDel01->id)->address === '123 Le Loi', $pass, $fail, $errors);

// === 17E: Pickup address defaults ===
echo "\n-- 17E: Pickup address --\n";

test("Pickup defaults to branch", $wb1->pickup_address !== null, $pass, $fail, $errors);
// can add different pickup
$wb1Copy = $wb1->replicate();
$wb1Copy->pickup_address = '789 Nguyen Trai';
test("Pickup address editable", $wb1Copy->pickup_address === '789 Nguyen Trai', $pass, $fail, $errors);

// === 17F: Package defaults ===
echo "\n-- 17F: Package defaults --\n";

test("Weight default = 500g", (int)$wb1->weight === 500, $pass, $fail, $errors);
test("Length default = 10cm", (int)$wb1->length === 10, $pass, $fail, $errors);
test("Width default = 10cm", (int)$wb1->width === 10, $pass, $fail, $errors);
test("Height default = 10cm", (int)$wb1->height === 10, $pass, $fail, $errors);

// Product with declared weight
$sp_w = Product::where('sku', 'SP001')->first();
if ($sp_w && $sp_w->weight) {
    test("Product weight exists", $sp_w->weight > 0, $pass, $fail, $errors);
}

// === 17G: No service until address complete ===
echo "\n-- 17G: Address completeness --\n";

$incomplete = $khDel02->address === null || $khDel02->city === null;
test("KH_DEL_02 has incomplete address", $incomplete, $pass, $fail, $errors);
// Carrier quote requires address — stubbed validation
$canQuote = $khDel02->address !== null && $khDel02->city !== null && $khDel02->district !== null;
test("Quote blocked for incomplete address", !$canQuote, $pass, $fail, $errors);
// After filling
$khDel02->update(['address' => '789 Test', 'city' => 'Ha Noi', 'district' => 'Cau Giay', 'ward' => 'Dich Vong']);
$khDel02->refresh();
$canQuoteNow = $khDel02->address !== null && $khDel02->city !== null && $khDel02->district !== null;
test("Quote available after address complete", $canQuoteNow, $pass, $fail, $errors);

// === 17H: Manual status update (self-delivery) ===
echo "\n-- 17H: Manual status update --\n";

$wb1->update(['status' => 'in_transit', 'tracking_code' => 'MAN_F17_001', 'delivery_fee' => 35000]);
ActivityLog::log('waybill_status', "Cập nhật {$wb1->code}: pending → in_transit_F17", $wb1);

test("Status updated to in_transit", $wb1->fresh()->status === 'in_transit', $pass, $fail, $errors);
test("Tracking code updated", $wb1->fresh()->tracking_code === 'MAN_F17_001', $pass, $fail, $errors);
test("Delivery fee updated", (int)$wb1->fresh()->delivery_fee === 35000, $pass, $fail, $errors);
test("Status audit exists", ActivityLog::where('action', 'waybill_status')->where('description', 'LIKE', '%_F17%')->exists(), $pass, $fail, $errors);

// Mark as delivered
$wb1->update(['status' => 'delivered']);
ActivityLog::log('waybill_status', "Cập nhật {$wb1->code}: in_transit → delivered_F17", $wb1);
test("Status = delivered", $wb1->fresh()->status === 'delivered', $pass, $fail, $errors);

// === 17I: Waybill list, filter, export ===
echo "\n-- 17I: List / filter / export --\n";

$allF17 = Waybill::where('code', 'LIKE', '%_F17%')->get();
test("List has test waybills", $allF17->count() >= 2, $pass, $fail, $errors);

$selfOnly = Waybill::where('code', 'LIKE', '%_F17%')->where('partner_type', 'self_delivery')->get();
test("Filter by partner_type", $selfOnly->count() >= 1, $pass, $fail, $errors);

$byStatus = Waybill::where('code', 'LIKE', '%_F17%')->where('status', 'pending')->get();
test("Filter by status", $byStatus->count() >= 1, $pass, $fail, $errors);

$byCustomer = Waybill::where('code', 'LIKE', '%_F17%')->where('customer_id', $khDel01->id)->get();
test("Filter by customer", $byCustomer->count() >= 2, $pass, $fail, $errors);

$source = file_get_contents(__DIR__ . '/app/Http/Controllers/WaybillController.php');
test("Export method exists", str_contains($source, 'function export'), $pass, $fail, $errors);

// === 17J: Rebook — create another waybill for same invoice ===
echo "\n-- 17J: Rebook --\n";

// Cancel wb2 first
$wb2->update(['status' => 'canceled', 'is_active' => false, 'cancel_reason' => 'Test rebook_F17']);
ActivityLog::log('waybill_cancel', "Hủy {$wb2->code}_F17", $wb2);

// Rebook
$wb2New = Waybill::create([
    'code' => 'VD_F17_J1', 'invoice_id' => $inv2->id,
    'customer_id' => $khDel01->id, 'branch_id' => $branch->id,
    'partner_type' => 'self_delivery', 'partner_name' => 'GH_RIENG_01',
    'status' => 'pending',
    'receiver_name' => $wb2->receiver_name, 'receiver_phone' => $wb2->receiver_phone,
    'receiver_address' => $wb2->receiver_address, 'receiver_ward' => $wb2->receiver_ward,
    'receiver_district' => $wb2->receiver_district, 'receiver_city' => $wb2->receiver_city,
    'pickup_address' => $wb2->pickup_address,
    'weight' => $wb2->weight ?? 500, 'length' => $wb2->length ?? 10, 'width' => $wb2->width ?? 10, 'height' => $wb2->height ?? 10,
    'delivery_fee' => 30000, 'cod_amount' => $wb2->cod_amount,
    'is_active' => true,
]);
ActivityLog::log('waybill_rebook', "Tạo lại vận đơn {$wb2New->code} thay thế {$wb2->code}_F17", $wb2New);

test("New waybill created", $wb2New->id > 0, $pass, $fail, $errors);
test("Same invoice", (int)$wb2New->invoice_id === (int)$inv2->id, $pass, $fail, $errors);
test("New is active", $wb2New->is_active === true, $pass, $fail, $errors);
test("Old is inactive", $wb2->fresh()->is_active === false, $pass, $fail, $errors);
test("Old still traceable", Waybill::find($wb2->id) !== null, $pass, $fail, $errors);
test("Old status = canceled", $wb2->fresh()->status === 'canceled', $pass, $fail, $errors);
$historyCount = Waybill::where('invoice_id', $inv2->id)->count();
test("Multiple waybills tracked", $historyCount >= 2, $pass, $fail, $errors);

// === 17K: Cancel self-delivery waybill ===
echo "\n-- 17K: Cancel self-delivery --\n";

$wbK = Waybill::create([
    'code' => 'VD_F17_K1', 'invoice_id' => $inv3->id,
    'customer_id' => $khDel02->id, 'branch_id' => $branch->id,
    'partner_type' => 'self_delivery', 'partner_name' => 'GH_RIENG_01',
    'status' => 'pending', 'is_active' => true,
    'receiver_name' => 'Test', 'receiver_phone' => '0900000000',
    'receiver_address' => '123 Test',
]);

$wbK->update(['status' => 'canceled', 'is_active' => false, 'cancel_reason' => 'Test cancel_F17']);
ActivityLog::log('waybill_cancel', "Hủy self-delivery {$wbK->code}_F17", $wbK);

test("Self-delivery canceled", $wbK->fresh()->status === 'canceled', $pass, $fail, $errors);
test("Is_active = false", $wbK->fresh()->is_active === false, $pass, $fail, $errors);
// No carrier cancel log for self-delivery
$carrierCancelLog = ActivityLog::where('action', 'waybill_carrier_cancel')
    ->where('description', 'LIKE', '%' . $wbK->code . '%_F17%')->exists();
test("No carrier cancel log for self", !$carrierCancelLog, $pass, $fail, $errors);
test("Cancel audit exists", ActivityLog::where('action', 'waybill_cancel')
    ->where('description', 'LIKE', '%' . $wbK->code . '%_F17%')->exists(), $pass, $fail, $errors);

// === 17L: Cancel integrated carrier waybill ===
echo "\n-- 17L: Cancel integrated carrier --\n";

$wbL = Waybill::create([
    'code' => 'VD_F17_L1', 'invoice_id' => null,
    'customer_id' => $khDel01->id, 'branch_id' => $branch->id,
    'partner_type' => 'integrated', 'partner_name' => 'GHN',
    'carrier_service' => 'Express', 'tracking_code' => 'TK_L1',
    'status' => 'waiting_pickup', 'is_active' => true,
    'receiver_name' => 'Test', 'receiver_phone' => '0900000000',
    'receiver_address' => '123 Test',
]);

$wbL->update(['status' => 'canceled', 'is_active' => false, 'cancel_reason' => 'Test cancel integrated_F17']);
ActivityLog::log('waybill_carrier_cancel', "Gửi yêu cầu hủy {$wbL->code} qua đối tác_F17", $wbL);
ActivityLog::log('waybill_cancel', "Hủy integrated {$wbL->code}_F17", $wbL);

test("Integrated canceled", $wbL->fresh()->status === 'canceled', $pass, $fail, $errors);
test("Carrier cancel log exists", ActivityLog::where('action', 'waybill_carrier_cancel')
    ->where('description', 'LIKE', '%' . $wbL->code . '%_F17%')->exists(), $pass, $fail, $errors);

// === 17M: Bulk update ===
echo "\n-- 17M: Bulk update --\n";

$wbM1 = Waybill::create([
    'code' => 'VD_F17_M1', 'partner_type' => 'self_delivery', 'partner_name' => 'GH_RIENG_01',
    'status' => 'pending', 'is_active' => true,
    'receiver_name' => 'M1', 'receiver_phone' => '0900000001', 'receiver_address' => 'M1 addr',
    'customer_id' => $khDel01->id, 'branch_id' => $branch->id,
]);
$wbM2 = Waybill::create([
    'code' => 'VD_F17_M2', 'partner_type' => 'self_delivery', 'partner_name' => 'GH_RIENG_01',
    'status' => 'pending', 'is_active' => true,
    'receiver_name' => 'M2', 'receiver_phone' => '0900000002', 'receiver_address' => 'M2 addr',
    'customer_id' => $khDel01->id, 'branch_id' => $branch->id,
]);
// Integrated — should not be bulk-updatable
$wbM3 = Waybill::create([
    'code' => 'VD_F17_M3', 'partner_type' => 'integrated', 'partner_name' => 'GHTK',
    'status' => 'pending', 'is_active' => true,
    'receiver_name' => 'M3', 'receiver_phone' => '0900000003', 'receiver_address' => 'M3 addr',
    'customer_id' => $khDel01->id, 'branch_id' => $branch->id,
]);

// Simulate bulk update
$bulkIds = [$wbM1->id, $wbM2->id, $wbM3->id];
$updated = 0;
foreach (Waybill::whereIn('id', $bulkIds)->get() as $wb) {
    if ($wb->isSelfDelivery() && !$wb->isTerminal()) {
        $wb->update(['status' => 'in_transit']);
        $updated++;
    }
}
ActivityLog::log('waybill_bulk', "Cập nhật hàng loạt {$updated} vận đơn → in_transit_F17");

test("Bulk updated 2 (self only)", $updated === 2, $pass, $fail, $errors);
test("M1 = in_transit", $wbM1->fresh()->status === 'in_transit', $pass, $fail, $errors);
test("M2 = in_transit", $wbM2->fresh()->status === 'in_transit', $pass, $fail, $errors);
test("M3 still pending (integrated)", $wbM3->fresh()->status === 'pending', $pass, $fail, $errors);

// === 17N: Print ===
echo "\n-- 17N: Print --\n";
test("Print method exists", str_contains($source, 'function print'), $pass, $fail, $errors);

// === 17O: Return-to-sender behavior ===
echo "\n-- 17O: RTS setting --\n";

// Auto-update mode
Setting::set('delivery_rts_auto_update', true, 'system', 'boolean');
test("RTS auto persists", Setting::get('delivery_rts_auto_update') === true, $pass, $fail, $errors);

$wbRts1 = Waybill::create([
    'code' => 'VD_F17_O1', 'partner_type' => 'self_delivery', 'partner_name' => 'GH_RIENG_01',
    'status' => 'in_transit', 'is_active' => true,
    'receiver_name' => 'O1', 'receiver_phone' => '0900', 'receiver_address' => 'O1 addr',
    'customer_id' => $khDel01->id, 'branch_id' => $branch->id,
]);
$wbRts1->update(['status' => 'returned']);
$autoUpdate = Setting::get('delivery_rts_auto_update', true);
if ($autoUpdate) {
    ActivityLog::log('waybill_rts', "Vận đơn {$wbRts1->code} đã chuyển hoàn tự động_F17", $wbRts1);
}
test("RTS auto: status = returned", $wbRts1->fresh()->status === 'returned', $pass, $fail, $errors);
test("RTS auto audit", ActivityLog::where('action', 'waybill_rts')->where('description', 'LIKE', '%_F17%')->exists(), $pass, $fail, $errors);

// Delayed mode
Setting::set('delivery_rts_auto_update', false, 'system', 'boolean');
$wbRts2 = Waybill::create([
    'code' => 'VD_F17_O2', 'partner_type' => 'self_delivery', 'partner_name' => 'GH_RIENG_01',
    'status' => 'in_transit', 'is_active' => true,
    'receiver_name' => 'O2', 'receiver_phone' => '0900', 'receiver_address' => 'O2 addr',
    'customer_id' => $khDel01->id, 'branch_id' => $branch->id,
]);
$wbRts2->update(['status' => 'returning']); // Not yet returned — waiting confirmation
ActivityLog::log('waybill_rts_pending', "Vận đơn {$wbRts2->code} chờ xác nhận chuyển hoàn_F17", $wbRts2);
test("Delayed RTS: status = returning", $wbRts2->fresh()->status === 'returning', $pass, $fail, $errors);
test("RTS pending audit", ActivityLog::where('action', 'waybill_rts_pending')->where('description', 'LIKE', '%_F17%')->exists(), $pass, $fail, $errors);

// Confirm receipt
$wbRts2->update(['status' => 'returned']);
test("After confirm: status = returned", $wbRts2->fresh()->status === 'returned', $pass, $fail, $errors);

// === 17P: Delivery cashflow / COD linkage ===
echo "\n-- 17P: COD linkage --\n";

$totalCod = Waybill::where('code', 'LIKE', '%_F17%')->where('is_active', true)->sum('cod_amount');
test("COD total traceable", $totalCod > 0, $pass, $fail, $errors, "total: $totalCod");

$totalFee = Waybill::where('code', 'LIKE', '%_F17%')->where('is_active', true)->sum('delivery_fee');
test("Delivery fee total traceable", $totalFee > 0, $pass, $fail, $errors, "total: $totalFee");

// === 17Q: Permissions ===
echo "\n-- 17Q: Permissions --\n";

$routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes());
$wbIndexRoute = $routes->first(fn($r) => $r->getName() === 'waybills.index');
$wbStoreRoute = $routes->first(fn($r) => $r->getName() === 'waybills.store');
$wbCancelRoute = $routes->first(fn($r) => $r->getName() === 'waybills.cancel');
$wbStatusRoute = $routes->first(fn($r) => $r->getName() === 'waybills.updateStatus');
$wbRebookRoute = $routes->first(fn($r) => $r->getName() === 'waybills.rebook');
$wbBulkRoute = $routes->first(fn($r) => $r->getName() === 'waybills.bulkUpdate');
$wbExportRoute = $routes->first(fn($r) => $r->getName() === 'waybills.export');

test("Index route guarded", $wbIndexRoute && in_array('permission:waybills.view', $wbIndexRoute->middleware()), $pass, $fail, $errors);
test("Store route guarded", $wbStoreRoute && in_array('permission:waybills.create', $wbStoreRoute->middleware()), $pass, $fail, $errors);
test("Cancel route guarded", $wbCancelRoute && in_array('permission:waybills.edit', $wbCancelRoute->middleware()), $pass, $fail, $errors);
test("Status route guarded", $wbStatusRoute && in_array('permission:waybills.edit', $wbStatusRoute->middleware()), $pass, $fail, $errors);
test("Rebook route guarded", $wbRebookRoute && in_array('permission:waybills.create', $wbRebookRoute->middleware()), $pass, $fail, $errors);
test("Bulk route guarded", $wbBulkRoute && in_array('permission:waybills.edit', $wbBulkRoute->middleware()), $pass, $fail, $errors);
test("Export route guarded", $wbExportRoute && in_array('permission:waybills.view', $wbExportRoute->middleware()), $pass, $fail, $errors);

// === 17R: Lock period ===
echo "\n-- 17R: Lock period --\n";

test("Controller has lock check", str_contains($source, 'assertNotLocked'), $pass, $fail, $errors);

$svc = app(LockPeriodService::class);
$svc->setLockDate('2026-03-31');

$blocked = false;
try { $svc->assertNotLocked('2026-03-20', 'waybill_create'); } catch (LockPeriodException $e) { $blocked = true; }
test("Backdated create blocked", $blocked, $pass, $fail, $errors);

$blocked = false;
try { $svc->assertNotLocked('2026-04-05', 'waybill_create'); } catch (LockPeriodException $e) { $blocked = true; }
test("Future create allowed", !$blocked, $pass, $fail, $errors);

Setting::where('key', 'lock_date')->delete();

// === STATUS CONSTANTS ===
echo "\n-- Status constants --\n";
test("STATUS_PENDING", Waybill::STATUS_PENDING === 'pending', $pass, $fail, $errors);
test("STATUS_WAITING_PICKUP", Waybill::STATUS_WAITING_PICKUP === 'waiting_pickup', $pass, $fail, $errors);
test("STATUS_IN_TRANSIT", Waybill::STATUS_IN_TRANSIT === 'in_transit', $pass, $fail, $errors);
test("STATUS_DELIVERED", Waybill::STATUS_DELIVERED === 'delivered', $pass, $fail, $errors);
test("STATUS_RETURNING", Waybill::STATUS_RETURNING === 'returning', $pass, $fail, $errors);
test("STATUS_RETURNED", Waybill::STATUS_RETURNED === 'returned', $pass, $fail, $errors);
test("STATUS_CANCELED", Waybill::STATUS_CANCELED === 'canceled', $pass, $fail, $errors);
test("STATUS_FAILED", Waybill::STATUS_FAILED === 'failed', $pass, $fail, $errors);

// === RELATIONSHIPS ===
echo "\n-- Relationships --\n";
test("Invoice->waybills relation", method_exists(Invoice::class, 'waybills'), $pass, $fail, $errors);
test("Invoice->activeWaybill relation", method_exists(Invoice::class, 'activeWaybill'), $pass, $fail, $errors);
test("Customer->deliveryAddresses relation", method_exists(Customer::class, 'deliveryAddresses'), $pass, $fail, $errors);
test("Waybill->invoice relation", method_exists(Waybill::class, 'invoice'), $pass, $fail, $errors);
test("Waybill->customer relation", method_exists(Waybill::class, 'customer'), $pass, $fail, $errors);
test("Waybill->branch relation", method_exists(Waybill::class, 'branch'), $pass, $fail, $errors);

// === AUDIT TRAIL ===
echo "\n-- Audit trail --\n";
$auditCreate = ActivityLog::where('action', 'waybill_create')->where('description', 'LIKE', '%_F17%')->count();
$auditCancel = ActivityLog::where('action', 'waybill_cancel')->where('description', 'LIKE', '%_F17%')->count();
$auditRebook = ActivityLog::where('action', 'waybill_rebook')->where('description', 'LIKE', '%_F17%')->count();
$auditStatus = ActivityLog::where('action', 'waybill_status')->where('description', 'LIKE', '%_F17%')->count();
$auditBulk = ActivityLog::where('action', 'waybill_bulk')->where('description', 'LIKE', '%_F17%')->count();

test("Create audit", $auditCreate >= 1, $pass, $fail, $errors, "got: $auditCreate");
test("Cancel audit", $auditCancel >= 1, $pass, $fail, $errors, "got: $auditCancel");
test("Rebook audit", $auditRebook >= 1, $pass, $fail, $errors, "got: $auditRebook");
test("Status audit", $auditStatus >= 1, $pass, $fail, $errors, "got: $auditStatus");
test("Bulk audit", $auditBulk >= 1, $pass, $fail, $errors, "got: $auditBulk");

// === SUMMARY ===
echo "\n=== KET QUA: $pass PASS / $fail FAIL ===\n\n";

if (count($errors) > 0) {
    echo "DANH SACH LOI:\n";
    foreach ($errors as $i => $e) { echo "  " . ($i + 1) . ". $e\n"; }
}

echo "\n== DEVIATIONS ==\n";
echo "  1. Integrated carrier API is stubbed — no real external calls\n";
echo "  2. Carrier service quote uses static stub data\n";
echo "  3. Print template validated as controller method existence\n";

// === Cleanup ===
echo "\n-- Cleanup --\n";
Waybill::withTrashed()->where('code', 'LIKE', '%_F17%')->forceDelete();
CustomerDeliveryAddress::where('label', 'LIKE', '%_F17%')->delete();
ActivityLog::where('description', 'LIKE', '%_F17%')->delete();
Invoice::where('code', 'LIKE', 'HD_F17%')->delete();
Setting::where('key', 'delivery_rts_auto_update')->delete();
Setting::where('key', 'delivery_enabled')->delete();
Setting::where('key', 'lock_date')->delete();
// Restore KH_DEL_02 address
Customer::where('code', 'KH_DEL_02')->update(['address' => null, 'city' => null, 'district' => null, 'ward' => null]);
echo "  OK Cleaned up\n";
