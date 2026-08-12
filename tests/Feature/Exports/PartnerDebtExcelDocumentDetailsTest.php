<?php

namespace Tests\Feature\Exports;

use App\Models\Customer;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\ReturnItem;
use App\Models\User;
use App\Services\Exports\PartnerDebtExportDocumentResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartnerDebtExcelDocumentDetailsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_purchase_return_and_sales_return_details_resolve_from_canonical_identity(): void
    {
        $supplier = Customer::create([
            'code' => 'NCC-RETURN-'.uniqid(),
            'name' => 'Return detail supplier',
            'phone' => '09'.random_int(10000000, 99999999),
            'is_supplier' => true,
            'is_customer' => true,
        ]);
        $product = Product::create([
            'sku' => 'SKU-RETURN-'.uniqid(),
            'name' => 'Return detail product',
            'type' => 'standard',
            'cost_price' => 100,
            'retail_price' => 200,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $purchaseReturn = PurchaseReturn::create([
            'code' => 'PTN-DETAIL-'.uniqid(),
            'supplier_id' => $supplier->id,
            'total_amount' => 200,
            'refund_amount' => 0,
            'status' => 'completed',
        ]);
        PurchaseReturnItem::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->sku,
            'quantity' => 1,
            'price' => 200,
            'subtotal' => 200,
        ]);
        $salesReturn = OrderReturn::create([
            'code' => 'TH-DETAIL-'.uniqid(),
            'customer_id' => $supplier->id,
            'status' => 'completed',
            'subtotal' => 300,
            'total' => 300,
        ]);
        ReturnItem::create([
            'return_id' => $salesReturn->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 300,
            'subtotal' => 300,
            'import_price' => 100,
        ]);

        $entries = [
            [
                'event_identity' => 'supplier|purchase_returns|'.$purchaseReturn->id.'|purchase_return|payable',
                'reference_type' => 'PurchaseReturn',
                'reference_id' => $purchaseReturn->id,
                'event_kind' => 'purchase_return',
            ],
            [
                'event_identity' => 'customer|returns|'.$salesReturn->id.'|sales_return|receivable',
                'reference_type' => 'OrderReturn',
                'reference_id' => $salesReturn->id,
                'event_kind' => 'sales_return',
            ],
        ];
        $resolver = new PartnerDebtExportDocumentResolver;
        $resolver->preload($entries);

        $purchaseLines = $resolver->loadDetailLines($entries[0]);
        $salesLines = $resolver->loadDetailLines($entries[1]);

        $this->assertCount(1, $purchaseLines);
        $this->assertSame($product->sku, $purchaseLines[0]['code']);
        $this->assertCount(1, $salesLines);
        $this->assertSame($product->sku, $salesLines[0]['code']);
    }

    public function test_supplier_detail_rows_are_batch_loaded_without_per_document_item_queries(): void
    {
        $actor = User::create([
            'name' => 'Excel detail QA',
            'email' => 'excel-detail-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
        ]);
        $supplier = Customer::create([
            'code' => 'NCC-DETAIL-'.uniqid(),
            'name' => 'Detail supplier',
            'phone' => '09'.random_int(10000000, 99999999),
            'is_supplier' => true,
            'is_customer' => false,
        ]);
        $product = Product::create([
            'sku' => 'SKU-DETAIL-'.uniqid(),
            'name' => 'Batch detail product',
            'type' => 'standard',
            'cost_price' => 100,
            'retail_price' => 200,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        for ($i = 1; $i <= 3; $i++) {
            $purchase = Purchase::create([
                'code' => 'PN-DETAIL-'.$i.'-'.uniqid(),
                'supplier_id' => $supplier->id,
                'total_amount' => 1000,
                'discount' => 0,
                'paid_amount' => 0,
                'debt_amount' => 1000,
                'status' => 'completed',
            ]);
            PurchaseItem::create([
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_code' => $product->sku,
                'quantity' => 1,
                'price' => 1000,
                'discount' => 0,
                'subtotal' => 1000,
            ]);
        }

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->actingAs($actor)->get(
            "/api/suppliers/{$supplier->id}/export-debt?format=xlsx&date_preset=all&include_detail=1&columns[]=quantity"
        );
        $response->assertOk();

        $purchaseItemQueries = count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'purchase_items')));
        $this->assertSame(1, $purchaseItemQueries, 'purchase detail items must be loaded in one batch query');
    }
}
