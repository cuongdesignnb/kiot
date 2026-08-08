<?php

namespace Tests\Feature\Purchases;

use App\Http\Controllers\PurchaseController;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchasesIndexPerformanceContractTest extends TestCase
{
    use DatabaseTransactions;

    public function test_default_date_filter_matches_this_month_and_all_remains_explicit(): void
    {
        $supplier = $this->supplier('date');
        $product = $this->product('date');

        $current = $this->purchase($supplier, $product, 'PERF-CURRENT', now()->subDay(), 100, 10, 'completed');
        $this->purchase($supplier, $product, 'PERF-OLD', now()->subMonths(2), 200, 20, 'completed');

        $default = $this->indexResponse();
        $all = $this->indexResponse(['date_filter' => 'all']);

        self::assertSame('this_month', $default['props']['filters']['date_filter']);
        self::assertSame(1, $default['props']['purchases']['total']);
        self::assertSame($current->code, $default['props']['purchases']['data'][0]['code']);
        self::assertSame(2, $all['props']['purchases']['total']);
    }

    public function test_summary_uses_at_most_two_queries_without_ordering_or_financial_multiplication(): void
    {
        $supplier = $this->supplier('summary');
        $product = $this->product('summary');
        $this->purchase($supplier, $product, 'PERF-SUM-1', now()->subDay(), 100, 10, 'completed', 2, 20);
        $this->purchase($supplier, $product, 'PERF-SUM-2', now()->subDay(), 300, 30, 'completed', 3, 30);
        $this->purchase($supplier, $product, 'PERF-SUM-CANCELLED', now()->subDay(), 999, 99, 'cancelled', 9, 90);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->indexResponse();
        $summaryQueries = array_values(array_filter($queries, function (string $sql): bool {
            return str_contains($sql, 'coalesce(sum(total_amount)')
                || str_contains($sql, 'sum(`purchase_items`.`quantity`)');
        }));

        self::assertLessThanOrEqual(2, count($summaryQueries));
        self::assertCount(0, array_filter($summaryQueries, fn (string $sql): bool => str_contains($sql, 'order by')));
        self::assertSame('400.00', (string) $response['props']['summary']['total_amount']);
        self::assertSame('40.00', (string) $response['props']['summary']['total_discount']);
        self::assertSame('50.00', (string) $response['props']['summary']['total_paid']);
        self::assertSame('310.00', (string) $response['props']['summary']['total_debt']);
        self::assertSame(2, $response['props']['summary']['total_count']);
        self::assertSame(5, (int) $response['props']['summary']['total_items']);
    }

    public function test_search_pagination_sort_and_item_payload_contracts_are_preserved(): void
    {
        $supplier = $this->supplier('search', 'SUP-SEARCH-001', 'Phone Search Supplier');
        $product = $this->product('search', 'SKU-SEARCH-001', 'Product Search Name');
        $target = $this->purchase($supplier, $product, 'PERF-SEARCH-CODE', now()->subDay(), 900, 20, 'completed', 2, 10, 'Search note');

        self::assertSame($target->code, $this->indexResponse(['date_filter' => 'all', 'search' => 'PERF-SEARCH-CODE'])['props']['purchases']['data'][0]['code']);
        self::assertSame($target->code, $this->indexResponse(['date_filter' => 'all', 'search' => 'Search note'])['props']['purchases']['data'][0]['code']);
        self::assertSame($target->code, $this->indexResponse(['date_filter' => 'all', 'search' => 'Phone Search'])['props']['purchases']['data'][0]['code']);
        self::assertSame($target->code, $this->indexResponse(['date_filter' => 'all', 'search' => 'Product Search'])['props']['purchases']['data'][0]['code']);

        $payload = $this->indexResponse(['date_filter' => 'all'])['props']['purchases']['data'][0];
        self::assertArrayHasKey('items', $payload);
        self::assertSame([
            'id',
            'purchase_id',
            'product_code',
            'product_name',
            'quantity',
            'price',
            'discount',
            'subtotal',
        ], array_keys($payload['items'][0]));
    }

    public function test_partial_filter_reload_does_not_query_static_filter_options(): void
    {
        $supplier = $this->supplier('partial');
        $product = $this->product('partial');
        $this->purchase($supplier, $product, 'PERF-PARTIAL', now()->subDay(), 100, 0, 'completed');

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $response = $this->indexResponse(
            ['date_filter' => 'all', 'search' => 'PERF-PARTIAL'],
            'purchases,summary,filters'
        );

        self::assertFalse((bool) array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `customers`') && str_contains($sql, 'is_supplier')));
        self::assertFalse((bool) array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `employees`') && str_contains($sql, 'is_active')));
        self::assertFalse((bool) array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from `branches`')));
        self::assertArrayNotHasKey('filterOptions', $response['props']);
    }

    private function indexResponse(array $params = [], ?string $partialData = null): array
    {
        $request = Request::create('/purchases', 'GET', $params);
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $request->headers->set('Accept', 'application/json');
        if ($partialData !== null) {
            $request->headers->set('X-Inertia-Partial-Component', 'Purchases/Index');
            $request->headers->set('X-Inertia-Partial-Data', $partialData);
        }

        return app(PurchaseController::class)->index($request)->toResponse($request)->getData(true);
    }

    private function supplier(string $suffix, ?string $code = null, ?string $name = null): Customer
    {
        return Customer::create([
            'code' => $code ?? 'PERF-NCC-'.strtoupper($suffix),
            'name' => $name ?? 'Performance Supplier '.strtoupper($suffix),
            'phone' => '09'.random_int(10000000, 99999999),
            'is_supplier' => true,
            'is_customer' => false,
            'status' => 'active',
        ]);
    }

    private function product(string $suffix, ?string $sku = null, ?string $name = null): Product
    {
        return Product::create([
            'sku' => $sku ?? 'PERF-SKU-'.strtoupper($suffix),
            'name' => $name ?? 'Performance Product '.strtoupper($suffix),
            'cost_price' => 100,
            'retail_price' => 200,
            'stock_quantity' => 0,
            'inventory_total_cost' => 0,
            'is_active' => true,
        ]);
    }

    private function purchase(
        Customer $supplier,
        Product $product,
        string $code,
        $purchaseDate,
        int $amount,
        int $discount,
        string $status,
        int $quantity = 1,
        int $paid = 0,
        ?string $note = null
    ): Purchase {
        $purchase = Purchase::create([
            'code' => $code,
            'supplier_id' => $supplier->id,
            'total_amount' => $amount,
            'discount' => $discount,
            'paid_amount' => $paid,
            'debt_amount' => $amount - $discount - $paid,
            'note' => $note,
            'status' => $status,
            'payment_method' => 'cash',
            'purchase_date' => $purchaseDate,
        ]);

        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_code' => $product->sku,
            'quantity' => $quantity,
            'price' => 100,
            'discount' => 0,
            'subtotal' => $quantity * 100,
        ]);

        return $purchase;
    }
}
