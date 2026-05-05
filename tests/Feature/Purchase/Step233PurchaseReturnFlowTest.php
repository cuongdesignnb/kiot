<?php

namespace Tests\Feature\Purchase;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\SerialImei;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Step 23.3 — Purchase + Purchase Return business rules.
 *
 * Bugs covered:
 *  - BUG-1 count(serials)===qty (purchase store)
 *  - BUG-2 duplicate serial in same purchase request
 *  - BUG-3 cross-purchase serial return blocked
 *  - BUG-4 count(serial_ids)===qty (purchase return store)
 *  - BUG-5 duplicate serial_id in same return request
 *  - cost snapshot uses purchase_item.unit_cost_allocated
 */
class Step233PurchaseReturnFlowTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Customer $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name'     => 'Admin 23.3',
            'email'    => 'admin-233-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null,
        ]);
        $this->supplier = Customer::create([
            'code'                 => 'NCC-233-' . uniqid(),
            'name'                 => 'NCC 23.3',
            'phone'                => '090' . rand(1000000, 9999999),
            'email'                => 'ncc-233-' . uniqid() . '@test.local',
            'is_supplier'          => true,
            'supplier_debt_amount' => 0,
            'total_bought'         => 0,
        ]);
    }

    private function makeProduct(bool $hasSerial = false, int $stock = 10, float $cost = 100000): Product
    {
        $cat = Category::firstOrCreate(['name' => 'Cat 23.3']);
        return Product::create([
            'sku'                  => 'P233-' . uniqid(),
            'name'                 => 'Product 23.3',
            'cost_price'           => $cost,
            'retail_price'         => $cost * 2,
            'stock_quantity'       => $stock,
            'inventory_total_cost' => $stock * $cost,
            'is_active'            => true,
            'has_serial'           => $hasSerial,
            'category_id'          => $cat->id,
        ]);
    }

    private function purchaseNormal(Product $product, int $qty, float $price, float $paid): Purchase
    {
        $this->actingAs($this->admin)->post(route('purchases.store'), [
            'supplier_id' => $this->supplier->id,
            'paid_amount' => $paid,
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => $qty,
                'price'      => $price,
                'discount'   => 0,
            ]],
        ]);
        return Purchase::where('supplier_id', $this->supplier->id)->latest('id')->first();
    }

    private function purchaseSerial(Product $product, array $serials, float $price, float $paid): Purchase
    {
        $qty = count($serials);
        $beforeId = (int) (Purchase::max('id') ?? 0);
        $resp = $this->actingAs($this->admin)->post(route('purchases.store'), [
            'supplier_id' => $this->supplier->id,
            'paid_amount' => $paid,
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => $qty,
                'price'      => $price,
                'serials'    => $serials,
            ]],
        ]);
        $latest = Purchase::where('supplier_id', $this->supplier->id)->latest('id')->first();
        if (!$latest || $latest->id <= $beforeId) {
            $errs = session('errors')?->toArray() ?? [];
            $err = session('error');
            throw new \RuntimeException('purchaseSerial failed. Status=' . $resp->status() . ' errors=' . json_encode($errs, JSON_UNESCAPED_UNICODE) . ' error=' . $err);
        }
        return $latest;
    }

    /* ════════════════ A. Purchase normal ════════════════ */

    public function test_purchase_normal_should_increase_stock_and_avg_cost(): void
    {
        $product = $this->makeProduct(false, 10, 100000); // 10 @ 100k
        $purchase = $this->purchaseNormal($product, 5, 200000, 1000000);

        $this->assertNotNull($purchase);
        $product->refresh();
        $this->assertSame(15, (int) $product->stock_quantity);
        // BQ = (10*100k + 5*200k) / 15 = 133333.33
        $this->assertEqualsWithDelta(133333.33, (float) $product->cost_price, 1.0);
        $this->assertSame(1, StockMovement::where('product_id', $product->id)
            ->where('type', 'in_purchase')->where('ref_code', $purchase->code)->count());
    }

    public function test_purchase_credit_should_increase_supplier_debt(): void
    {
        $product = $this->makeProduct(false, 0, 0);
        $purchase = $this->purchaseNormal($product, 5, 200000, 400000); // pay 400k of 1m

        $this->supplier->refresh();
        $this->assertSame(600000.0, (float) $this->supplier->supplier_debt_amount);
    }

    /* ════════════════ B. Purchase serial guards ════════════════ */

    public function test_purchase_serial_requires_count_equal_quantity(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $this->actingAs($this->admin)->post(route('purchases.store'), [
            'supplier_id' => $this->supplier->id,
            'paid_amount' => 0,
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 2,
                'price'      => 5000000,
                'serials'    => ['SN-A'],
            ]],
        ]);
        $this->assertSame(0, Purchase::where('supplier_id', $this->supplier->id)->count(),
            'Purchase không được tạo khi serial thiếu.');
        $this->assertSame(0, SerialImei::where('product_id', $product->id)->count());
    }

    public function test_purchase_serial_duplicate_in_request_should_fail(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        $this->actingAs($this->admin)->post(route('purchases.store'), [
            'supplier_id' => $this->supplier->id,
            'paid_amount' => 0,
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 2,
                'price'      => 5000000,
                'serials'    => ['SN-DUP', 'SN-DUP'],
            ]],
        ]);
        $this->assertSame(0, Purchase::where('supplier_id', $this->supplier->id)->count());
        $this->assertSame(0, SerialImei::where('serial_number', 'SN-DUP')->count());
    }

    public function test_purchase_serial_existing_in_db_should_fail(): void
    {
        $product = $this->makeProduct(true, 0, 5000000);
        SerialImei::create([
            'product_id'    => $product->id,
            'serial_number' => 'SN-EXIST-' . uniqid(),
            'status'        => 'in_stock',
            'cost_price'    => 5000000,
            'original_cost' => 5000000,
        ]);
        $existing = SerialImei::where('product_id', $product->id)->first();

        $this->actingAs($this->admin)->post(route('purchases.store'), [
            'supplier_id' => $this->supplier->id,
            'paid_amount' => 0,
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 5000000,
                'serials'    => [$existing->serial_number],
            ]],
        ]);
        $this->assertSame(0, Purchase::where('supplier_id', $this->supplier->id)->count());
    }

    public function test_purchase_serial_success_should_create_serials_and_stock(): void
    {
        $product = $this->makeProduct(true, 0, 0);
        $sA = 'SN-OK-A-' . uniqid();
        $sB = 'SN-OK-B-' . uniqid();
        $purchase = $this->purchaseSerial($product, [$sA, $sB], 5000000, 10000000);

        $this->assertNotNull($purchase);
        $serials = SerialImei::where('purchase_id', $purchase->id)->get();
        $this->assertCount(2, $serials);
        foreach ($serials as $s) {
            $this->assertSame('in_stock', $s->status);
            $this->assertSame((int) $product->id, (int) $s->product_id);
            $this->assertSame(5000000.0, (float) $s->original_cost);
        }
        $this->assertSame(2, (int) $product->fresh()->stock_quantity);
    }

    /* ════════════════ C. Purchase return normal ════════════════ */

    public function test_purchase_return_normal_should_reduce_stock_and_supplier_debt(): void
    {
        $product = $this->makeProduct(false, 0, 0);
        $purchase = $this->purchaseNormal($product, 5, 200000, 0); // debt = 1m

        $this->actingAs($this->admin)->post(route('purchase-returns.store'), [
            'purchase_id'   => $purchase->id,
            'refund_amount' => 0,
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 2,
                'price'      => 200000,
            ]],
        ]);

        $product->refresh();
        $this->supplier->refresh();
        $this->assertSame(3, (int) $product->stock_quantity);
        // Debt giảm 400k (totalAmount - refund)
        $this->assertSame(600000.0, (float) $this->supplier->supplier_debt_amount);
        $return = PurchaseReturn::where('purchase_id', $purchase->id)->first();
        $this->assertSame(1, StockMovement::where('product_id', $product->id)
            ->where('type', 'out_purchase_return')->where('ref_code', $return->code)->count());
    }

    public function test_purchase_return_more_than_purchased_should_fail(): void
    {
        $product = $this->makeProduct(false, 0, 0);
        $purchase = $this->purchaseNormal($product, 2, 200000, 400000);

        $this->actingAs($this->admin)->post(route('purchase-returns.store'), [
            'purchase_id' => $purchase->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 5,
                'price'      => 200000,
            ]],
        ]);

        $this->assertSame(0, PurchaseReturn::where('purchase_id', $purchase->id)->count());
        $this->assertSame(2, (int) $product->fresh()->stock_quantity);
    }

    public function test_purchase_return_uses_purchase_item_unit_cost_not_current_product_cost(): void
    {
        $product = $this->makeProduct(false, 0, 0);
        $purchase = $this->purchaseNormal($product, 2, 100000, 200000); // unit_cost_allocated=100k
        $product->refresh();
        $this->assertSame(100000.0, (float) $product->cost_price);

        // Đổi product.cost_price hiện tại
        $product->update(['cost_price' => 999999, 'inventory_total_cost' => 2 * 999999]);

        $this->actingAs($this->admin)->post(route('purchase-returns.store'), [
            'purchase_id' => $purchase->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 100000,
            ]],
        ]);
        $return = PurchaseReturn::where('purchase_id', $purchase->id)->latest('id')->first();
        $returnItem = $return->items()->first();
        $this->assertSame(100000.0, (float) $returnItem->cost_price,
            'return_items.cost_price phải = unit_cost_allocated lúc nhập (100k), không phải 999k.');

        $movement = StockMovement::where('product_id', $product->id)
            ->where('type', 'out_purchase_return')
            ->where('ref_code', $return->code)->first();
        $this->assertSame(100000.0, (float) $movement->unit_cost);
    }

    /* ════════════════ D. Purchase return serial guards ════════════════ */

    public function test_purchase_return_serial_requires_count_equal_quantity(): void
    {
        $product = $this->makeProduct(true, 0, 0);
        $sA = 'SN-RET-A-' . uniqid();
        $sB = 'SN-RET-B-' . uniqid();
        $purchase = $this->purchaseSerial($product, [$sA, $sB], 5000000, 10000000);
        $serials = SerialImei::where('purchase_id', $purchase->id)->get();

        $this->actingAs($this->admin)->post(route('purchase-returns.store'), [
            'purchase_id' => $purchase->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 2,
                'price'      => 5000000,
                'serial_ids' => [$serials[0]->id], // chỉ 1 serial cho qty=2
            ]],
        ]);
        $this->assertSame(0, PurchaseReturn::where('purchase_id', $purchase->id)->count());
        $this->assertSame(2, (int) $product->fresh()->stock_quantity);
        foreach ($serials as $s) $this->assertSame('in_stock', $s->fresh()->status);
    }

    public function test_purchase_return_serial_not_from_purchase_should_fail(): void
    {
        $product = $this->makeProduct(true, 0, 0);
        $sA = 'SN-A-' . uniqid();
        $sB = 'SN-B-' . uniqid();
        $purchaseA = $this->purchaseSerial($product, [$sA], 5000000, 5000000);
        sleep(1); // tránh đụng PN<timestamp> code unique
        $purchaseB = $this->purchaseSerial($product, [$sB], 5000000, 5000000);

        $this->assertNotNull($purchaseA);
        $this->assertNotNull($purchaseB);
        $this->assertNotSame((int) $purchaseA->id, (int) $purchaseB->id, 'Phải là 2 phiếu nhập khác nhau.');

        $serialFromB = SerialImei::where('purchase_id', $purchaseB->id)->first();
        $this->assertNotNull($serialFromB, 'Serial phải thuộc purchaseB.');

        $resp = $this->actingAs($this->admin)->post(route('purchase-returns.store'), [
            'purchase_id' => $purchaseA->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 5000000,
                'serial_ids' => [$serialFromB->id], // serial của purchase B
            ]],
        ]);

        $this->assertSame(0, PurchaseReturn::where('purchase_id', $purchaseA->id)->count(),
            'Không cho trả serial của purchase khác. Errors: ' . json_encode(session('errors')?->toArray() ?? []));
        $this->assertSame('in_stock', $serialFromB->fresh()->status);
    }

    public function test_purchase_return_serial_sold_should_fail(): void
    {
        $product = $this->makeProduct(true, 0, 0);
        $sA = 'SN-SOLD-' . uniqid();
        $purchase = $this->purchaseSerial($product, [$sA], 5000000, 5000000);
        $serial = SerialImei::where('purchase_id', $purchase->id)->first();

        // Giả lập đã bán
        $serial->update(['status' => 'sold', 'invoice_id' => 1, 'sold_at' => now()]);

        $this->actingAs($this->admin)->post(route('purchase-returns.store'), [
            'purchase_id' => $purchase->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 5000000,
                'serial_ids' => [$serial->id],
            ]],
        ]);

        $this->assertSame(0, PurchaseReturn::where('purchase_id', $purchase->id)->count());
        $this->assertSame('sold', $serial->fresh()->status);
    }

    public function test_purchase_return_duplicate_serial_in_request_should_fail(): void
    {
        $product = $this->makeProduct(true, 0, 0);
        $sA = 'SN-DUP-' . uniqid();
        $sB = 'SN-DUP2-' . uniqid();
        $purchase = $this->purchaseSerial($product, [$sA, $sB], 5000000, 10000000);
        $serials = SerialImei::where('purchase_id', $purchase->id)->get();

        $this->actingAs($this->admin)->post(route('purchase-returns.store'), [
            'purchase_id' => $purchase->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 2,
                'price'      => 5000000,
                'serial_ids' => [$serials[0]->id, $serials[0]->id], // trùng
            ]],
        ]);

        $this->assertSame(0, PurchaseReturn::where('purchase_id', $purchase->id)->count());
        foreach ($serials as $s) $this->assertSame('in_stock', $s->fresh()->status);
    }

    public function test_purchase_return_serial_success_should_mark_returned_and_reduce_stock(): void
    {
        $product = $this->makeProduct(true, 0, 0);
        $sA = 'SN-OK-' . uniqid();
        $sB = 'SN-KEEP-' . uniqid();
        $purchase = $this->purchaseSerial($product, [$sA, $sB], 5000000, 10000000);
        $serials = SerialImei::where('purchase_id', $purchase->id)->orderBy('id')->get();
        [$first, $second] = [$serials[0], $serials[1]];

        $this->actingAs($this->admin)->post(route('purchase-returns.store'), [
            'purchase_id' => $purchase->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity'   => 1,
                'price'      => 5000000,
                'serial_ids' => [$first->id],
            ]],
        ]);

        $first->refresh(); $second->refresh();
        $this->assertSame('returned', $first->status);
        $this->assertSame('in_stock', $second->status, 'Serial khác KHÔNG được đụng.');
        $this->assertSame(1, (int) $product->fresh()->stock_quantity);

        $return = PurchaseReturn::where('purchase_id', $purchase->id)->first();
        $this->assertNotNull($return);
        $this->assertSame((int) $return->id, (int) $first->purchase_return_id);
    }
}
