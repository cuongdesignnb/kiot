<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\PriceTable;
use App\Models\PriceTableItem;
use App\Models\Product;
use App\Models\ActivityLog;
use App\Models\Setting;

class PriceTableController extends Controller
{
    public function index(Request $request)
    {
        $query = PriceTable::withCount('items')
            ->when($request->search, fn($q, $s) => $q->where('name', 'LIKE', "%{$s}%")->orWhere('code', 'LIKE', "%{$s}%"))
            ->when($request->status, fn($q, $s) => $q->whereIn('status', (array) $s))
            ->orderBy('created_at', 'desc');

        return Inertia::render('PriceTables/Index', [
            'priceTables' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function show(PriceTable $priceTable)
    {
        $priceTable->load('items.product');
        return Inertia::render('PriceTables/Show', ['priceTable' => $priceTable]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|unique:price_tables,code',
            'name' => 'required|string',
            'status' => 'nullable|in:applied,inactive,expired',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'formula_type' => 'nullable|in:fixed,percent_base',
            'formula_value' => 'nullable|numeric',
            'auto_update_from_base' => 'nullable|boolean',
            'rounding' => 'nullable|integer',
            'restrict_items' => 'nullable|boolean',
            'branch_scope' => 'nullable|array',
            'customer_group_scope' => 'nullable|array',
            'note' => 'nullable|string',
            'copy_all_products' => 'nullable|boolean',
        ]);

        $copyAll = $validated['copy_all_products'] ?? false;
        unset($validated['copy_all_products']);

        $priceTable = PriceTable::create($validated);

        // Copy all products if requested
        if ($copyAll) {
            $products = Product::where('is_active', true)->get();
            foreach ($products as $product) {
                $basePrice = $product->retail_price ?? $product->cost_price ?? 0;
                $tablePrice = $priceTable->applyFormula($basePrice);
                PriceTableItem::create([
                    'price_table_id' => $priceTable->id,
                    'product_id' => $product->id,
                    'base_price' => $basePrice,
                    'table_price' => $tablePrice,
                ]);
            }
        }

        ActivityLog::log('price_table_create', "Tạo bảng giá {$priceTable->code}: {$priceTable->name}", $priceTable);

        if ($request->wantsJson()) {
            return response()->json(['id' => $priceTable->id, 'code' => $priceTable->code]);
        }
        return redirect()->route('price-tables.index')->with('success', "Tạo bảng giá thành công.");
    }

    public function update(Request $request, PriceTable $priceTable)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string',
            'status' => 'sometimes|in:applied,inactive,expired',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'formula_type' => 'sometimes|in:fixed,percent_base',
            'formula_value' => 'sometimes|numeric',
            'auto_update_from_base' => 'sometimes|boolean',
            'rounding' => 'nullable|integer',
            'restrict_items' => 'sometimes|boolean',
            'branch_scope' => 'nullable|array',
            'customer_group_scope' => 'nullable|array',
            'note' => 'nullable|string',
        ]);

        $priceTable->update($validated);
        ActivityLog::log('price_table_update', "Cập nhật bảng giá {$priceTable->code}", $priceTable);

        return back()->with('success', 'Cập nhật thành công.');
    }

    public function destroy(PriceTable $priceTable)
    {
        $code = $priceTable->code;
        $priceTable->items()->delete();
        $priceTable->delete();
        ActivityLog::log('price_table_delete', "Xóa bảng giá {$code}");

        return back()->with('success', "Đã xóa bảng giá {$code}.");
    }

    /**
     * Add items to price table.
     */
    public function addItems(Request $request, PriceTable $priceTable)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.table_price' => 'nullable|numeric|min:0',
        ]);

        foreach ($request->items as $item) {
            $product = Product::find($item['product_id']);
            $basePrice = $product->retail_price ?? $product->cost_price ?? 0;
            $tablePrice = $item['table_price'] ?? $priceTable->applyFormula($basePrice);

            PriceTableItem::updateOrCreate(
                ['price_table_id' => $priceTable->id, 'product_id' => $item['product_id']],
                ['base_price' => $basePrice, 'table_price' => $tablePrice]
            );
        }

        ActivityLog::log('price_table_items', "Thêm/cập nhật SP vào bảng giá {$priceTable->code}", $priceTable);

        return back()->with('success', 'Đã cập nhật danh sách sản phẩm.');
    }

    /**
     * Apply formula to all items in table.
     */
    public function applyFormula(PriceTable $priceTable)
    {
        $items = $priceTable->items()->with('product')->get();
        foreach ($items as $item) {
            $basePrice = $item->product->retail_price ?? $item->base_price;
            $item->update([
                'base_price' => $basePrice,
                'table_price' => $priceTable->applyFormula($basePrice),
            ]);
        }

        ActivityLog::log('price_table_formula', "Áp dụng công thức bảng giá {$priceTable->code}", $priceTable);

        return back()->with('success', 'Đã áp dụng công thức.');
    }

    /**
     * Resolve price for a product given context.
     */
    public function resolvePrice(Request $request)
    {
        $productId = $request->input('product_id');
        $branchId = $request->input('branch_id');
        $customerGroup = $request->input('customer_group');

        $tables = PriceTable::currentlyValid()->get()->filter(function ($t) use ($branchId, $customerGroup) {
            return $t->matchesScope($branchId, $customerGroup);
        });

        foreach ($tables as $table) {
            $price = $table->getPriceFor($productId);
            if ($price !== null) {
                return response()->json([
                    'price_table_id' => $table->id,
                    'price_table_name' => $table->name,
                    'table_price' => $price,
                    'restrict_items' => $table->restrict_items,
                ]);
            }
        }

        // Fallback to default
        $product = Product::find($productId);
        return response()->json([
            'price_table_id' => null,
            'price_table_name' => 'Giá chung',
            'table_price' => $product?->retail_price ?? 0,
            'restrict_items' => false,
        ]);
    }

    public function export(Request $request, PriceTable $priceTable)
    {
        $items = $priceTable->items()->with('product')->get();

        return \App\Services\CsvService::export(
            ['Mã SP', 'Tên SP', 'Giá gốc', 'Giá bảng'],
            $items->map(fn($i) => [
                $i->product?->sku ?? $i->product?->code, $i->product?->name,
                $i->base_price, $i->table_price,
            ]),
            "bang_gia_{$priceTable->code}.csv"
        );
    }
}
