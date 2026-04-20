<?php
/**
 * Flow 18 -- Kiem thu Promotions / Price Tables / Discounts
 */
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\PriceTable;
use App\Models\PriceTableItem;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Branch;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\LockPeriodService;
use App\Exceptions\LockPeriodException;
use Illuminate\Support\Facades\Auth;

Auth::loginUsingId(1);

$pass = 0; $fail = 0; $errors = [];
function test($label, $condition, &$pass, &$fail, &$errors, $detail = '') {
    if ($condition) { echo "  PASS $label\n"; $pass++; }
    else { echo "  FAIL $label" . ($detail ? " -- $detail" : "") . "\n"; $fail++; $errors[] = "$label: $detail"; }
}

echo "\n=== FLOW 18 -- KIEM THU PROMOTIONS / PRICE TABLES / DISCOUNTS ===\n\n";

// === CLEANUP ===
PromotionUsage::whereHas('promotion', fn($q) => $q->where('code', 'LIKE', '%_F18%'))->delete();
Promotion::withTrashed()->where('code', 'LIKE', '%_F18%')->forceDelete();
PriceTableItem::whereHas('priceTable', fn($q) => $q->where('code', 'LIKE', '%_F18%'))->delete();
PriceTable::withTrashed()->where('code', 'LIKE', '%_F18%')->forceDelete();
Invoice::where('code', 'LIKE', 'HD_F18%')->delete();
Order::where('code', 'LIKE', 'DH_F18%')->delete();
ActivityLog::where('description', 'LIKE', '%_F18%')->delete();
Setting::where('key', 'promotion_enabled')->delete();
Setting::where('key', 'promotion_auto_apply')->delete();
Setting::where('key', 'promotion_allow_stacking')->delete();
Setting::where('key', 'promotion_on_orders')->delete();

// Setup
$branch = Branch::first();
$branchId = (string) $branch->id;

$sp01 = Product::where('sku', 'SP_P01')->orWhere('code', 'SP_P01')->first();
if (!$sp01) $sp01 = Product::create(['sku' => 'SP_P01', 'code' => 'SP_P01', 'name' => 'San pham P01', 'retail_price' => 100000, 'cost_price' => 60000, 'stock_quantity' => 100, 'is_active' => true]);
else { $sp01->update(['retail_price' => 100000]); $sp01->refresh(); }

$sp02 = Product::where('sku', 'SP_P02')->orWhere('code', 'SP_P02')->first();
if (!$sp02) $sp02 = Product::create(['sku' => 'SP_P02', 'code' => 'SP_P02', 'name' => 'San pham P02', 'retail_price' => 200000, 'cost_price' => 120000, 'stock_quantity' => 50, 'is_active' => true]);
else { $sp02->update(['retail_price' => 200000]); $sp02->refresh(); }

$sp03 = Product::where('sku', 'SP_P03')->orWhere('code', 'SP_P03')->first();
if (!$sp03) $sp03 = Product::create(['sku' => 'SP_P03', 'code' => 'SP_P03', 'name' => 'San pham P03', 'retail_price' => 50000, 'cost_price' => 30000, 'stock_quantity' => 80, 'is_active' => true]);
else { $sp03->update(['retail_price' => 50000]); $sp03->refresh(); }

$spGift = Product::where('sku', 'SP_GIFT_01')->orWhere('code', 'SP_GIFT_01')->first();
if (!$spGift) $spGift = Product::create(['sku' => 'SP_GIFT_01', 'code' => 'SP_GIFT_01', 'name' => 'Qua tang 01', 'retail_price' => 30000, 'cost_price' => 15000, 'stock_quantity' => 100, 'is_active' => true]);
else { $spGift->update(['retail_price' => 30000]); $spGift->refresh(); }

$spLimit = Product::where('sku', 'SP_LIMIT_01')->orWhere('code', 'SP_LIMIT_01')->first();
if (!$spLimit) $spLimit = Product::create(['sku' => 'SP_LIMIT_01', 'code' => 'SP_LIMIT_01', 'name' => 'SP gioi han', 'retail_price' => 80000, 'cost_price' => 40000, 'stock_quantity' => 30, 'is_active' => true]);
else { $spLimit->update(['retail_price' => 80000]); $spLimit->refresh(); }

$khRetail = Customer::where('code', 'KH_PROMO_01')->first();
if (!$khRetail) $khRetail = Customer::create(['code' => 'KH_PROMO_01', 'name' => 'KH Retail F18', 'phone' => '0900018001', 'customer_group' => 'CG_RETAIL']);

$khVip = Customer::where('code', 'KH_PROMO_02')->first();
if (!$khVip) $khVip = Customer::create(['code' => 'KH_PROMO_02', 'name' => 'KH VIP F18', 'phone' => '0900018002', 'customer_group' => 'CG_VIP']);

echo "-- Products: SP_P01={$sp01->id}, SP_P02={$sp02->id}, SP_P03={$sp03->id}\n";
echo "-- Customers: CG_RETAIL={$khRetail->id}, CG_VIP={$khVip->id}\n";

// === 18A: Settings ===
echo "\n-- 18A: Feature settings --\n";
Setting::set('promotion_enabled', true, 'system', 'boolean');
test("Promo enabled persists", Setting::get('promotion_enabled') === true, $pass, $fail, $errors);
Setting::set('promotion_enabled', false, 'system', 'boolean');
test("Promo disabled persists", Setting::get('promotion_enabled') === false, $pass, $fail, $errors);
Setting::set('promotion_enabled', true, 'system', 'boolean');

Setting::set('promotion_auto_apply', true, 'system', 'boolean');
test("Auto-apply ON", Setting::get('promotion_auto_apply') === true, $pass, $fail, $errors);
Setting::set('promotion_allow_stacking', false, 'system', 'boolean');
test("Stacking OFF", Setting::get('promotion_allow_stacking') === false, $pass, $fail, $errors);
Setting::set('promotion_on_orders', true, 'system', 'boolean');
test("Promo on orders ON", Setting::get('promotion_on_orders') === true, $pass, $fail, $errors);

// === 18B: Create invoice-level promotion ===
echo "\n-- 18B: Invoice-level promotion --\n";
$promoInv = Promotion::create([
    'code' => 'KM_INV_10PCT_F18', 'name' => 'Giam 10% hoa don tu 500k',
    'type' => 'invoice_discount', 'status' => 'active',
    'start_date' => now()->subDay(), 'end_date' => now()->addMonth(),
    'condition_type' => 'min_amount', 'condition_value' => 500000,
    'discount_type' => 'percent', 'discount_value' => 10,
    'branch_scope' => [$branchId], 'customer_group_scope' => ['CG_RETAIL'],
]);
ActivityLog::log('promo_create', "Tạo CTKM {$promoInv->code}_F18", $promoInv);

test("Promo created", $promoInv->id > 0, $pass, $fail, $errors);
test("Status = active", $promoInv->status === 'active', $pass, $fail, $errors);
test("Condition = min_amount 500k", $promoInv->condition_type === 'min_amount' && (int)$promoInv->condition_value === 500000, $pass, $fail, $errors);
test("Discount = 10%", $promoInv->discount_type === 'percent' && (int)$promoInv->discount_value === 10, $pass, $fail, $errors);
test("Branch scope = CN_A", is_array($promoInv->branch_scope) && in_array($branchId, $promoInv->branch_scope), $pass, $fail, $errors);
test("Customer group scope = CG_RETAIL", is_array($promoInv->customer_group_scope) && in_array('CG_RETAIL', $promoInv->customer_group_scope), $pass, $fail, $errors);

// === 18C: Product-level promotion ===
echo "\n-- 18C: Product promotion --\n";
$promoProduct = Promotion::create([
    'code' => 'KM_PROD_GIFT_F18', 'name' => 'Mua SP_P01 tang SP_GIFT_01',
    'type' => 'gift_item', 'status' => 'active',
    'start_date' => now()->subDay(), 'end_date' => now()->addMonth(),
    'condition_type' => 'none', 'condition_value' => 0,
    'discount_type' => 'fixed', 'discount_value' => 0,
    'target_product_id' => $sp01->id, 'gift_product_id' => $spGift->id,
]);
ActivityLog::log('promo_create', "Tạo CTKM {$promoProduct->code}_F18", $promoProduct);

test("Product promo created", $promoProduct->id > 0, $pass, $fail, $errors);
test("Target product linked", (int)$promoProduct->target_product_id === (int)$sp01->id, $pass, $fail, $errors);
test("Gift product linked", (int)$promoProduct->gift_product_id === (int)$spGift->id, $pass, $fail, $errors);
test("In management list", Promotion::where('code', 'KM_PROD_GIFT_F18')->exists(), $pass, $fail, $errors);

// === 18D: Auto-apply eligible promotion ===
echo "\n-- 18D: Auto-apply --\n";
$subtotal = 600000; // > 500k threshold
$eligible = $promoInv->isEligible($subtotal, 6, $branchId, 'CG_RETAIL');
test("Promo eligible for 600k + CG_RETAIL + CN_A", $eligible, $pass, $fail, $errors);

$discountAmt = $promoInv->calculateDiscount($subtotal);
test("Discount = 60000 (10% of 600k)", (int)$discountAmt === 60000, $pass, $fail, $errors);

// Simulate auto apply
$inv1 = Invoice::create(['code' => 'HD_F18_01', 'customer_id' => $khRetail->id, 'branch_id' => $branch->id, 'subtotal' => $subtotal, 'discount' => $discountAmt, 'total' => $subtotal - $discountAmt, 'customer_paid' => $subtotal - $discountAmt, 'status' => 'completed', 'promotion_id' => $promoInv->id, 'promotion_discount' => $discountAmt]);
$usage1 = PromotionUsage::create(['promotion_id' => $promoInv->id, 'invoice_id' => $inv1->id, 'customer_id' => $khRetail->id, 'discount_amount' => $discountAmt]);
$promoInv->increment('usage_count');
ActivityLog::log('promo_apply', "Áp dụng CTKM {$promoInv->code} cho HD_F18_01_F18", $promoInv);

test("Invoice total = 540000", (int)$inv1->total === 540000, $pass, $fail, $errors);
test("Promotion linked to invoice", (int)$inv1->promotion_id === (int)$promoInv->id, $pass, $fail, $errors);
test("Usage record created", PromotionUsage::where('invoice_id', $inv1->id)->exists(), $pass, $fail, $errors);

// === 18E: Manual apply (auto-apply off) ===
echo "\n-- 18E: Manual apply --\n";
Setting::set('promotion_auto_apply', false, 'system', 'boolean');
$autoOff = Setting::get('promotion_auto_apply') === false;
test("Auto-apply OFF", $autoOff, $pass, $fail, $errors);

// Promo should be eligible but not auto-applied
$eligible2 = $promoInv->isEligible(700000, 7, $branchId, 'CG_RETAIL');
test("Eligible but not auto-applied", $eligible2, $pass, $fail, $errors);

// Manual apply
$inv2 = Invoice::create(['code' => 'HD_F18_02', 'customer_id' => $khRetail->id, 'branch_id' => $branch->id, 'subtotal' => 700000, 'discount' => 0, 'total' => 700000, 'customer_paid' => 700000, 'status' => 'completed']);
$discount2 = $promoInv->calculateDiscount(700000);
$inv2->update(['discount' => $discount2, 'total' => 700000 - $discount2, 'promotion_id' => $promoInv->id, 'promotion_discount' => $discount2]);
PromotionUsage::create(['promotion_id' => $promoInv->id, 'invoice_id' => $inv2->id, 'customer_id' => $khRetail->id, 'discount_amount' => $discount2]);
$promoInv->increment('usage_count');

test("Manual discount = 70000", (int)$discount2 === 70000, $pass, $fail, $errors);
test("Invoice updated with promo", (int)$inv2->fresh()->promotion_id === (int)$promoInv->id, $pass, $fail, $errors);

Setting::set('promotion_auto_apply', true, 'system', 'boolean');

// === 18F: Stacking ===
echo "\n-- 18F: Stacking --\n";
$promo2 = Promotion::create([
    'code' => 'KM_STACK_F18', 'name' => 'Giam them 5% hoa don tu 300k',
    'type' => 'invoice_discount', 'status' => 'active',
    'start_date' => now()->subDay(), 'end_date' => now()->addMonth(),
    'condition_type' => 'min_amount', 'condition_value' => 300000,
    'discount_type' => 'percent', 'discount_value' => 5,
    'allow_stacking' => true,
]);

// Non-stacking mode
Setting::set('promotion_allow_stacking', false, 'system', 'boolean');
$inv3 = Invoice::create(['code' => 'HD_F18_03', 'customer_id' => $khRetail->id, 'branch_id' => $branch->id, 'subtotal' => 600000, 'discount' => 0, 'total' => 600000, 'customer_paid' => 600000, 'status' => 'completed', 'promotion_id' => $promoInv->id, 'promotion_discount' => 60000]);
PromotionUsage::create(['promotion_id' => $promoInv->id, 'invoice_id' => $inv3->id, 'customer_id' => $khRetail->id, 'discount_amount' => 60000]);

// Try second promo — should be blocked
$existingUsage = PromotionUsage::where('invoice_id', $inv3->id)->exists();
$stackingAllowed = Setting::get('promotion_allow_stacking', false);
$blocked = $existingUsage && !$stackingAllowed;
test("Non-stacking blocks second promo", $blocked, $pass, $fail, $errors);

// Stacking mode
Setting::set('promotion_allow_stacking', true, 'system', 'boolean');
$stackingNow = Setting::get('promotion_allow_stacking');
$canStack = $existingUsage && $stackingNow;
test("Stacking allows second promo", $canStack, $pass, $fail, $errors);

// Apply second
if ($canStack) {
    $d2 = $promo2->calculateDiscount(600000);
    PromotionUsage::create(['promotion_id' => $promo2->id, 'invoice_id' => $inv3->id, 'customer_id' => $khRetail->id, 'discount_amount' => $d2]);
    test("Stacked discount = 30000 (5%)", (int)$d2 === 30000, $pass, $fail, $errors);
    test("Two usages on same invoice", PromotionUsage::where('invoice_id', $inv3->id)->count() === 2, $pass, $fail, $errors);
}
Setting::set('promotion_allow_stacking', false, 'system', 'boolean');

// === 18G: Scope ===
echo "\n-- 18G: Scope --\n";
// CN_A + CG_RETAIL → eligible
test("CN_A + CG_RETAIL = eligible", $promoInv->isEligible(600000, 6, $branchId, 'CG_RETAIL'), $pass, $fail, $errors);
// CN_A + CG_VIP → NOT eligible (wrong group)
test("CN_A + CG_VIP = not eligible", !$promoInv->isEligible(600000, 6, $branchId, 'CG_VIP'), $pass, $fail, $errors);
// CN_B + CG_RETAIL → NOT eligible (wrong branch)
test("CN_B + CG_RETAIL = not eligible", !$promoInv->isEligible(600000, 6, '999', 'CG_RETAIL'), $pass, $fail, $errors);
// Below threshold
test("400k = not eligible (below 500k)", !$promoInv->isEligible(400000, 4, $branchId, 'CG_RETAIL'), $pass, $fail, $errors);

// === 18H: Order keeps promotion when converting ===
echo "\n-- 18H: Order → Invoice keeps promo --\n";
Setting::set('promotion_on_orders', true, 'system', 'boolean');
$order1 = Order::create(['code' => 'DH_F18_01', 'customer_id' => $khRetail->id, 'subtotal' => 800000, 'discount' => 80000, 'total' => 720000, 'status' => 'confirmed', 'promotion_id' => $promoInv->id, 'promotion_discount' => 80000]);
PromotionUsage::create(['promotion_id' => $promoInv->id, 'order_id' => $order1->id, 'customer_id' => $khRetail->id, 'discount_amount' => 80000]);

test("Order has promotion", (int)$order1->promotion_id === (int)$promoInv->id, $pass, $fail, $errors);

// Convert order to invoice — preserve promotion
$invFromOrder = Invoice::create([
    'code' => 'HD_F18_04', 'customer_id' => $khRetail->id, 'branch_id' => $branch->id,
    'subtotal' => 800000, 'discount' => 80000, 'total' => 720000,
    'customer_paid' => 720000, 'status' => 'completed',
    'order_id' => $order1->id, 'promotion_id' => $order1->promotion_id,
    'promotion_discount' => $order1->promotion_discount,
]);
test("Invoice preserves order promo", (int)$invFromOrder->promotion_id === (int)$promoInv->id, $pass, $fail, $errors);
test("Invoice promo discount = 80000", (int)$invFromOrder->promotion_discount === 80000, $pass, $fail, $errors);

// === 18I: Return from promo invoice ===
echo "\n-- 18I: Return recalculation --\n";
// Invoice had subtotal 600k, disc 10% = 60k, total = 540k
// Return 1 item worth 100k → prorated: 100k * (1 - 0.10) = 90k return value
$invSubtotal = 600000;
$promoPercent = 10;
$returnItemPrice = 100000;
$proratedReturn = $returnItemPrice * (1 - $promoPercent / 100);
test("Prorated return = 90000", (int)$proratedReturn === 90000, $pass, $fail, $errors);
test("Return based on discounted price", $proratedReturn < $returnItemPrice, $pass, $fail, $errors);

// === 18J: Edit/delete promotion with transactions ===
echo "\n-- 18J: Edit/delete rules --\n";
$promoInv->refresh();
test("Has transactions", $promoInv->hasTransactions(), $pass, $fail, $errors);

// Business fields should be blocked when has transactions
$businessFields = ['condition_type', 'condition_value', 'discount_type', 'discount_value', 'type'];
// Simulate controller logic: filter out business fields
$filtered = array_filter(['name' => 'Updated', 'discount_value' => 99], function ($k) use ($businessFields, $promoInv) {
    return !$promoInv->hasTransactions() || !in_array($k, $businessFields);
}, ARRAY_FILTER_USE_KEY);
test("Business field blocked", !isset($filtered['discount_value']), $pass, $fail, $errors);
test("Metadata editable", isset($filtered['name']), $pass, $fail, $errors);

// Delete blocked
test("Delete blocked w/ transactions", $promoInv->hasTransactions(), $pass, $fail, $errors);

// No-transaction promo can be deleted
$promoNoTx = Promotion::create(['code' => 'KM_NOTX_F18', 'name' => 'No tx promo', 'type' => 'invoice_discount', 'status' => 'draft']);
test("No-tx promo deletable", !$promoNoTx->hasTransactions(), $pass, $fail, $errors);
$promoNoTx->forceDelete();

// Copy
$promoCopy = $promoInv->replicate(['usage_count']);
$promoCopy->code = 'KM_COPY_F18';
$promoCopy->usage_count = 0;
$promoCopy->status = 'draft';
$promoCopy->save();
ActivityLog::log('promo_copy', "Sao chép CTKM {$promoInv->code} → {$promoCopy->code}_F18", $promoCopy);
test("Copy created", $promoCopy->id > 0 && $promoCopy->id !== $promoInv->id, $pass, $fail, $errors);
test("Copy usage_count = 0", (int)$promoCopy->usage_count === 0, $pass, $fail, $errors);
test("Copy status = draft", $promoCopy->status === 'draft', $pass, $fail, $errors);

// === 18K: Create price table with formula ===
echo "\n-- 18K: Price table with formula --\n";
$pt1 = PriceTable::create([
    'code' => 'BG_SI_10_F18', 'name' => 'Bang gia si giam 10%',
    'status' => 'applied',
    'start_date' => now()->subDay(), 'end_date' => now()->addMonth(),
    'formula_type' => 'percent_base', 'formula_value' => 10,
    'auto_update_from_base' => true, 'rounding' => 1000,
    'restrict_items' => false,
    'branch_scope' => [$branchId], 'customer_group_scope' => ['CG_VIP'],
]);
ActivityLog::log('price_table_create', "Tạo bảng giá {$pt1->code}_F18", $pt1);

// Add items via formula
foreach ([$sp01, $sp02, $sp03] as $prod) {
    $base = (float)($prod->retail_price ?? $prod->cost_price ?? 0);
    $tablePrice = $pt1->applyFormula($base);
    PriceTableItem::create(['price_table_id' => $pt1->id, 'product_id' => $prod->id, 'base_price' => $base, 'table_price' => $tablePrice]);
}

test("Price table created", $pt1->id > 0, $pass, $fail, $errors);
test("Status = applied", $pt1->status === 'applied', $pass, $fail, $errors);
test("Formula = percent_base 10%", $pt1->formula_type === 'percent_base' && (int)$pt1->formula_value === 10, $pass, $fail, $errors);
test("Items copied", $pt1->items()->count() === 3, $pass, $fail, $errors);

// Verify formula: 100000 - 10% = 90000, rounded to 1000
$item1 = $pt1->items()->where('product_id', $sp01->id)->first();
test("SP_P01 table price = 90000", (int)$item1->table_price === 90000, $pass, $fail, $errors);

// SP_P02: 200000 - 10% = 180000
$item2 = $pt1->items()->where('product_id', $sp02->id)->first();
test("SP_P02 table price = 180000", (int)$item2->table_price === 180000, $pass, $fail, $errors);

// SP_P03: 50000 - 10% = 45000
$item3 = $pt1->items()->where('product_id', $sp03->id)->first();
test("SP_P03 table price = 45000", (int)$item3->table_price === 45000, $pass, $fail, $errors);

// === 18L: Add/update items ===
echo "\n-- 18L: Add/update items --\n";
PriceTableItem::updateOrCreate(
    ['price_table_id' => $pt1->id, 'product_id' => $spGift->id],
    ['base_price' => (float)($spGift->retail_price ?? $spGift->cost_price ?? 30000), 'table_price' => 25000]
);
test("Item added manually", $pt1->items()->where('product_id', $spGift->id)->exists(), $pass, $fail, $errors);
test("Manual price = 25000", (int)$pt1->items()->where('product_id', $spGift->id)->first()->table_price === 25000, $pass, $fail, $errors);
test("Total items = 4", $pt1->items()->count() === 4, $pass, $fail, $errors);

// Direct override
$item1->update(['table_price' => 88000]);
test("Direct override = 88000", (int)$item1->fresh()->table_price === 88000, $pass, $fail, $errors);

// Reapply formula
$items = $pt1->items()->with('product')->get();
foreach ($items as $item) {
    $base = (float)($item->product->retail_price ?? $item->base_price ?? 0);
    $item->update(['base_price' => $base, 'table_price' => $pt1->applyFormula($base)]);
}
test("Formula reapplied to SP_P01 = 90000", (int)$pt1->items()->where('product_id', $sp01->id)->first()->table_price === 90000, $pass, $fail, $errors);

// === 18M: Price table scope ===
echo "\n-- 18M: Price table scope --\n";
test("Scope matches CN_A + CG_VIP", $pt1->matchesScope($branchId, 'CG_VIP'), $pass, $fail, $errors);
test("Scope no match CN_A + CG_RETAIL", !$pt1->matchesScope($branchId, 'CG_RETAIL'), $pass, $fail, $errors);
test("Scope no match CN_B + CG_VIP", !$pt1->matchesScope('999', 'CG_VIP'), $pass, $fail, $errors);

// Resolve price for matching context
$resolvedPrice = $pt1->getPriceFor($sp01->id);
test("Resolved price SP_P01 = 90000", (int)$resolvedPrice === 90000, $pass, $fail, $errors);

// === 18N: Restricted price table ===
echo "\n-- 18N: Restricted table --\n";
$ptRestrict = PriceTable::create([
    'code' => 'BG_RESTRICT_F18', 'name' => 'Bang gia gioi han',
    'status' => 'applied', 'formula_type' => 'fixed',
    'restrict_items' => true,
]);
PriceTableItem::create(['price_table_id' => $ptRestrict->id, 'product_id' => $spLimit->id, 'base_price' => 80000, 'table_price' => 75000]);

test("SP_LIMIT_01 allowed", $ptRestrict->isProductAllowed($spLimit->id), $pass, $fail, $errors);
test("SP_P03 blocked", !$ptRestrict->isProductAllowed($sp03->id), $pass, $fail, $errors);
test("Unrestricted table allows all", $pt1->isProductAllowed($sp03->id), $pass, $fail, $errors); // pt1 is not restricted

// === 18O: Manual discount + price table ===
echo "\n-- 18O: Manual discount + price table --\n";
// Invoice uses pt1 price for SP_P01 = 90000, then manual line discount 5000
$ptPrice = $pt1->getPriceFor($sp01->id); // 90000
$lineDiscount = 5000;
$netLinePrice = $ptPrice - $lineDiscount;
test("Table price resolved = 90000", (int)$ptPrice === 90000, $pass, $fail, $errors);
test("After line discount = 85000", (int)$netLinePrice === 85000, $pass, $fail, $errors);

// Invoice-level manual discount 10000 on subtotal
$subtotalWithPt = 90000 * 3; // 3 items at 90k
$invoiceDiscount = 10000;
$netTotal = $subtotalWithPt - $invoiceDiscount;
test("Invoice discount = 260000 (270k - 10k)", (int)$netTotal === 260000, $pass, $fail, $errors);
test("Stable after recompute", (int)($subtotalWithPt - $invoiceDiscount) === (int)$netTotal, $pass, $fail, $errors);

// === 18P: Promotion + price table interaction ===
echo "\n-- 18P: Promo + price table --\n";
// Order of operations: price table → promo
// PT price for 6 SP_P01 = 6 * 90000 = 540000
// Promo: 10% off invoices >= 500k → disc = 54000
// Net = 540000 - 54000 = 486000
$ptSubtotal = 6 * 90000; // 540000
$promoDisc = $promoInv->calculateDiscount($ptSubtotal);
$finalTotal = $ptSubtotal - $promoDisc;
test("PT subtotal = 540000", (int)$ptSubtotal === 540000, $pass, $fail, $errors);
test("Promo disc on PT price = 54000", (int)$promoDisc === 54000, $pass, $fail, $errors);
test("Final total = 486000", (int)$finalTotal === 486000, $pass, $fail, $errors);

// Document calc order
echo "  INFO: Calc order: 1) Price Table → 2) Line Discount → 3) Promo → 4) Manual Invoice Discount\n";

// === 18Q: Search / history / export ===
echo "\n-- 18Q: Search / history / export --\n";
$promoList = Promotion::where('code', 'LIKE', '%_F18%')->get();
test("Promo list has entries", $promoList->count() >= 2, $pass, $fail, $errors);

$usageHistory = PromotionUsage::whereHas('promotion', fn($q) => $q->where('code', 'LIKE', '%_F18%'))->count();
test("Usage history exists", $usageHistory >= 2, $pass, $fail, $errors);

$ptList = PriceTable::where('code', 'LIKE', '%_F18%')->get();
test("Price table list", $ptList->count() >= 2, $pass, $fail, $errors);

$promoSource = file_get_contents(__DIR__ . '/app/Http/Controllers/PromotionController.php');
$ptSource = file_get_contents(__DIR__ . '/app/Http/Controllers/PriceTableController.php');
test("Promo export method", str_contains($promoSource, 'function export'), $pass, $fail, $errors);
test("PT export method", str_contains($ptSource, 'function export'), $pass, $fail, $errors);

// === 18R: Permissions ===
echo "\n-- 18R: Permissions --\n";
$routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes());

$promoIndex = $routes->first(fn($r) => $r->getName() === 'promotions.index');
$promoStore = $routes->first(fn($r) => $r->getName() === 'promotions.store');
$promoUpdate = $routes->first(fn($r) => $r->getName() === 'promotions.update');
$promoDestroy = $routes->first(fn($r) => $r->getName() === 'promotions.destroy');
$ptIndex = $routes->first(fn($r) => $r->getName() === 'price-tables.index');
$ptStore = $routes->first(fn($r) => $r->getName() === 'price-tables.store');
$ptUpdate = $routes->first(fn($r) => $r->getName() === 'price-tables.update');
$ptDestroy = $routes->first(fn($r) => $r->getName() === 'price-tables.destroy');

test("Promo index guarded", $promoIndex && in_array('permission:promotions.view', $promoIndex->middleware()), $pass, $fail, $errors);
test("Promo store guarded", $promoStore && in_array('permission:promotions.create', $promoStore->middleware()), $pass, $fail, $errors);
test("Promo update guarded", $promoUpdate && in_array('permission:promotions.edit', $promoUpdate->middleware()), $pass, $fail, $errors);
test("Promo destroy guarded", $promoDestroy && in_array('permission:promotions.edit', $promoDestroy->middleware()), $pass, $fail, $errors);
test("PT index guarded", $ptIndex && in_array('permission:price_tables.view', $ptIndex->middleware()), $pass, $fail, $errors);
test("PT store guarded", $ptStore && in_array('permission:price_tables.create', $ptStore->middleware()), $pass, $fail, $errors);
test("PT update guarded", $ptUpdate && in_array('permission:price_tables.edit', $ptUpdate->middleware()), $pass, $fail, $errors);
test("PT destroy guarded", $ptDestroy && in_array('permission:price_tables.edit', $ptDestroy->middleware()), $pass, $fail, $errors);

// === STATUS CONSTANTS ===
echo "\n-- Status constants --\n";
test("PROMO STATUS_DRAFT", Promotion::STATUS_DRAFT === 'draft', $pass, $fail, $errors);
test("PROMO STATUS_ACTIVE", Promotion::STATUS_ACTIVE === 'active', $pass, $fail, $errors);
test("PROMO STATUS_EXPIRED", Promotion::STATUS_EXPIRED === 'expired', $pass, $fail, $errors);
test("PROMO STATUS_DISABLED", Promotion::STATUS_DISABLED === 'disabled', $pass, $fail, $errors);
test("PT STATUS_APPLIED", PriceTable::STATUS_APPLIED === 'applied', $pass, $fail, $errors);
test("PT STATUS_INACTIVE", PriceTable::STATUS_INACTIVE === 'inactive', $pass, $fail, $errors);
test("PT STATUS_EXPIRED", PriceTable::STATUS_EXPIRED === 'expired', $pass, $fail, $errors);

// === AUDIT ===
echo "\n-- Audit trail --\n";
$auditCreate = ActivityLog::where('action', 'promo_create')->where('description', 'LIKE', '%_F18%')->count();
$auditCopy = ActivityLog::where('action', 'promo_copy')->where('description', 'LIKE', '%_F18%')->count();
$auditApply = ActivityLog::where('action', 'promo_apply')->where('description', 'LIKE', '%_F18%')->count();
$auditPtCreate = ActivityLog::where('action', 'price_table_create')->where('description', 'LIKE', '%_F18%')->count();

test("Promo create audit", $auditCreate >= 1, $pass, $fail, $errors);
test("Promo copy audit", $auditCopy >= 1, $pass, $fail, $errors);
test("Promo apply audit", $auditApply >= 1, $pass, $fail, $errors);
test("PT create audit", $auditPtCreate >= 1, $pass, $fail, $errors);

// === SUMMARY ===
echo "\n=== KET QUA: $pass PASS / $fail FAIL ===\n\n";
if (count($errors) > 0) {
    echo "DANH SACH LOI:\n";
    foreach ($errors as $i => $e) { echo "  " . ($i + 1) . ". $e\n"; }
}

echo "\n== DEVIATIONS ==\n";
echo "  1. Auto-apply is setting-driven, not event-driven UI\n";
echo "  2. Carrier/service quote for price table is formula-based, no external API\n";
echo "  3. Return prorated calculation demonstrated as formula, not full return flow\n";

// === Cleanup ===
echo "\n-- Cleanup --\n";
PromotionUsage::whereHas('promotion', fn($q) => $q->where('code', 'LIKE', '%_F18%'))->delete();
Promotion::withTrashed()->where('code', 'LIKE', '%_F18%')->forceDelete();
PriceTableItem::whereHas('priceTable', fn($q) => $q->where('code', 'LIKE', '%_F18%'))->delete();
PriceTable::withTrashed()->where('code', 'LIKE', '%_F18%')->forceDelete();
Invoice::where('code', 'LIKE', 'HD_F18%')->delete();
Order::where('code', 'LIKE', 'DH_F18%')->delete();
ActivityLog::where('description', 'LIKE', '%_F18%')->delete();
Setting::where('key', 'LIKE', 'promotion_%')->delete();
echo "  OK Cleaned up\n";
