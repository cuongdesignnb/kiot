<?php

namespace Tests\Feature\Exports;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Services\Exports\PartnerDebtExportDocumentResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartnerDebtExcelLegacyFallbackPerformanceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_legacy_purchase_codes_are_batch_resolved_without_per_entry_lookup(): void
    {
        $supplier = Customer::create([
            'code' => 'NCC-LEGACY-'.uniqid(),
            'name' => 'Legacy export supplier',
            'is_supplier' => true,
            'is_customer' => false,
        ]);
        $product = Product::create([
            'sku' => 'SKU-LEGACY-'.uniqid(),
            'name' => 'Legacy export product',
            'type' => 'standard',
            'cost_price' => 100,
            'retail_price' => 200,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $entries = [];
        for ($i = 1; $i <= 3; $i++) {
            $purchase = Purchase::create([
                'code' => 'PN-LEGACY-'.$i.'-'.uniqid(),
                'supplier_id' => $supplier->id,
                'total_amount' => 100,
                'discount' => 0,
                'paid_amount' => 0,
                'debt_amount' => 100,
                'status' => 'completed',
            ]);
            PurchaseItem::create([
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_code' => $product->sku,
                'quantity' => 1,
                'price' => 100,
                'discount' => 0,
                'subtotal' => 100,
            ]);
            $entries[] = [
                'event_kind' => 'purchase',
                'reference_type' => 'Purchase',
                'code' => $purchase->code,
                'amount' => 100,
                'running_balance' => $i * 100,
            ];
        }

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $resolver = new PartnerDebtExportDocumentResolver;
        $resolver->preload($entries, 'supplier');
        foreach ($entries as $entry) {
            self::assertCount(1, $resolver->loadDetailLines($entry, 'supplier'));
        }

        $purchaseQueries = count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, ' from `purchases`')));
        $purchaseItemQueries = count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, ' from `purchase_items`')));
        self::assertSame(1, $purchaseQueries);
        self::assertSame(1, $purchaseItemQueries);
    }
}
