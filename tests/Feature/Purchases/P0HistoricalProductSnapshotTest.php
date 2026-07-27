<?php

namespace Tests\Feature\Purchases;

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PurchaseController;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturnItem;
use App\Models\ReturnItem;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class P0HistoricalProductSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    public function test_purchase_detail_prefers_the_document_snapshot_after_product_changes(): void
    {
        [$purchase, $product] = $this->purchaseWithSnapshot('SKU-AT-PURCHASE', 'Tên tại lúc nhập');
        $product->update(['sku' => 'SKU-CURRENT', 'name' => 'Tên hiện tại']);

        $payload = app(PurchaseController::class)->detail($purchase)->getData(true);

        $this->assertSame('SKU-AT-PURCHASE', $payload['items'][0]['product_code']);
        $this->assertSame('Tên tại lúc nhập', $payload['items'][0]['product_name']);
    }

    public function test_purchase_detail_keeps_snapshot_when_product_is_soft_deleted(): void
    {
        [$purchase, $product] = $this->purchaseWithSnapshot('SKU-DELETED', 'Sản phẩm đã xóa mềm');
        $product->delete();

        $item = $purchase->items()->firstOrFail();
        $this->assertNotNull($item->product);
        $this->assertTrue($item->product->trashed());

        $payload = app(PurchaseController::class)->detail($purchase)->getData(true);

        $this->assertSame('SKU-DELETED', $payload['items'][0]['product_code']);
        $this->assertSame('Sản phẩm đã xóa mềm', $payload['items'][0]['product_name']);
    }

    public function test_purchase_detail_uses_snapshot_when_product_relation_is_null(): void
    {
        [$purchase] = $this->purchaseWithSnapshot('SKU-SNAPSHOT-ONLY', 'Chỉ còn snapshot');
        $purchase->items()->firstOrFail()->update(['product_id' => null]);

        $payload = app(PurchaseController::class)->detail($purchase)->getData(true);

        $this->assertSame('SKU-SNAPSHOT-ONLY', $payload['items'][0]['product_code']);
        $this->assertSame('Chỉ còn snapshot', $payload['items'][0]['product_name']);
    }

    public function test_invoice_detail_can_render_a_soft_deleted_product(): void
    {
        $product = $this->product('SKU-INVOICE-HISTORY', 'Sản phẩm hóa đơn');
        $invoice = Invoice::create([
            'code' => 'HD-P0-'.uniqid(),
            'subtotal' => 120000,
            'discount' => 0,
            'total' => 120000,
            'customer_paid' => 120000,
            'status' => 'Hoàn thành',
        ]);
        $invoice->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 120000,
            'subtotal' => 120000,
        ]);
        $product->delete();

        $payload = app(InvoiceController::class)->detail($invoice)->getData(true);

        $this->assertSame('SKU-INVOICE-HISTORY', $payload['items'][0]['product_code']);
        $this->assertSame('Sản phẩm hóa đơn', $payload['items'][0]['product_name']);
    }

    public function test_historical_line_item_relations_include_soft_deleted_products(): void
    {
        $product = $this->product('SKU-HISTORY-RELATIONS', 'Sản phẩm lịch sử');
        $product->delete();

        foreach ([
            PurchaseItem::class,
            InvoiceItem::class,
            OrderItem::class,
            PurchaseReturnItem::class,
            ReturnItem::class,
            StockMovement::class,
        ] as $modelClass) {
            $line = new $modelClass(['product_id' => $product->id]);

            $this->assertNotNull($line->product, $modelClass.' must retain historical product access.');
            $this->assertTrue($line->product->trashed());
        }
    }

    public function test_customer_debt_purchase_popup_prefers_snapshot_for_a_deleted_product(): void
    {
        $supplier = Customer::create([
            'code' => 'NCC-P0-'.uniqid(),
            'name' => 'Nhà cung cấp P0',
            'is_customer' => false,
            'is_supplier' => true,
        ]);
        [$purchase, $product] = $this->purchaseWithSnapshot('SKU-DEBT-SNAPSHOT', 'Tên snapshot công nợ');
        $purchase->update(['supplier_id' => $supplier->id]);
        $product->update(['sku' => 'SKU-DEBT-CURRENT', 'name' => 'Tên hiện tại']);
        $product->delete();

        $response = app(CustomerController::class)->debtVoucherDetail(
            Request::create('/customers/'.$supplier->id.'/debt-voucher-detail', 'GET', ['code' => $purchase->code]),
            $supplier,
        );
        $payload = $response->getData(true);

        $this->assertSame('SKU-DEBT-SNAPSHOT', $payload['data']['items'][0]['product_code']);
        $this->assertSame('Tên snapshot công nợ', $payload['data']['items'][0]['product_name']);
    }

    private function purchaseWithSnapshot(string $code, string $name): array
    {
        $product = $this->product('CURRENT-'.uniqid(), 'Current product');
        $purchase = Purchase::create([
            'code' => 'PN-P0-'.uniqid(),
            'total_amount' => 100000,
            'paid_amount' => 100000,
            'debt_amount' => 0,
            'status' => 'completed',
        ]);
        $purchase->items()->create([
            'product_id' => $product->id,
            'product_code' => $code,
            'product_name' => $name,
            'quantity' => 1,
            'price' => 100000,
            'discount' => 0,
            'subtotal' => 100000,
        ]);

        return [$purchase, $product];
    }

    private function product(string $sku, string $name): Product
    {
        return Product::create([
            'sku' => $sku,
            'name' => $name,
            'type' => 'standard',
            'cost_price' => 100000,
            'retail_price' => 120000,
            'stock_quantity' => 1,
            'is_active' => true,
            'sell_directly' => true,
        ]);
    }
}
