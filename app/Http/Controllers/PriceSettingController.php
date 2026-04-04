<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Product;
use App\Models\Category;
use App\Models\PriceBook;
use App\Models\PriceBookProduct;
use App\Models\Branch;
use Illuminate\Support\Facades\Response;

class PriceSettingController extends Controller
{
    private function buildQuery(Request $request)
    {
        $query = Product::with('category');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('stock_filter')) {
            switch ($request->input('stock_filter')) {
                case 'in_stock':
                    $query->where('stock_quantity', '>', 0);
                    break;
                case 'out_of_stock':
                    $query->where('stock_quantity', '<=', 0);
                    break;
            }
        }

        if ($request->filled('price_condition') && $request->filled('price_value')) {
            $cond = $request->input('price_condition');
            $val = $request->input('price_value');
            if (in_array($cond, ['>', '>=', '<', '<=', '='])) {
                $query->where('retail_price', $cond, $val);
            }
        }

        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->buildQuery($request);
        $activePriceBookId = $request->input('price_book_id');

        // If a custom price book is selected, join prices
        if ($activePriceBookId) {
            $query->leftJoin('price_book_products', function ($join) use ($activePriceBookId) {
                $join->on('products.id', '=', 'price_book_products.product_id')
                    ->where('price_book_products.price_book_id', '=', $activePriceBookId);
            })->select(
                'products.*',
                'price_book_products.price as book_price',
                'price_book_products.retail_price as book_retail_price',
                'price_book_products.technician_price as book_technician_price'
            );
        }

        $query->when($request->filled('sort_by'), function ($q) use ($request) {
            $allowed = ['sku' => 'products.sku', 'name' => 'products.name', 'cost_price' => 'products.cost_price', 'last_purchase_price' => 'products.last_purchase_price', 'retail_price' => 'products.retail_price'];
            $sortBy = isset($allowed[$request->sort_by]) ? $allowed[$request->sort_by] : 'products.id';
            $dir = $request->sort_direction === 'asc' ? 'asc' : 'desc';
            $q->orderBy($sortBy, $dir);
        }, function ($q) {
            $q->orderBy('products.id', 'desc');
        });

        $products = $query->paginate(20)->withQueryString();

        $priceBooks = PriceBook::withCount('priceBookProducts')
            ->orderBy('created_at', 'desc')
            ->get();

        $categories = Category::all();
        $branches = Branch::all();

        return Inertia::render('PriceSettings/Index', [
            'products' => $products,
            'categories' => $categories,
            'priceBooks' => $priceBooks,
            'branches' => $branches,
            'filters' => $request->only(['search', 'category_id', 'stock_filter', 'price_condition', 'price_value', 'price_book_id', 'sort_by', 'sort_direction']),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $request->validate([
            'cost_price' => 'sometimes|numeric|min:0',
            'retail_price' => 'sometimes|numeric|min:0',
            'technician_price' => 'sometimes|numeric|min:0',
        ]);

        $product->update($request->only('cost_price', 'retail_price', 'technician_price'));

        return redirect()->back()->with('success', 'Đã cập nhật giá: ' . $product->name);
    }

    /**
     * Update price for a specific product in a specific price book
     */
    public function updateBookPrice(Request $request, PriceBook $priceBook, Product $product)
    {
        $request->validate([
            'price' => 'sometimes|numeric|min:0',
            'retail_price' => 'sometimes|numeric|min:0',
            'technician_price' => 'sometimes|numeric|min:0',
        ]);

        if (!$request->hasAny(['price', 'retail_price', 'technician_price'])) {
            return redirect()->back()->with('error', 'Không có dữ liệu giá để cập nhật.');
        }

        $priceBookProduct = PriceBookProduct::firstOrCreate(
            ['price_book_id' => $priceBook->id, 'product_id' => $product->id],
            ['price' => $product->retail_price ?? 0]
        );

        $updateData = [];
        foreach (['price', 'retail_price', 'technician_price'] as $field) {
            if ($request->has($field)) {
                $updateData[$field] = $request->input($field);
            }
        }

        $priceBookProduct->update($updateData);

        return redirect()->back()->with('success', 'Đã cập nhật giá bảng giá');
    }

    public function applyFormula(Request $request)
    {
        $request->validate([
            'product_ids' => 'required|array',
            'base_field' => 'required|in:cost_price,retail_price,last_purchase_price',
            'operator' => 'required|in:+,-',
            'value' => 'required|numeric|min:0',
            'is_percent' => 'required|boolean',
            'price_book_id' => 'nullable|exists:price_books,id',
        ]);

        $products = Product::whereIn('id', $request->product_ids)->get();
        $base = $request->base_field;
        $operator = $request->operator;
        $value = $request->value;
        $isPercent = $request->is_percent;
        $priceBookId = $request->price_book_id;

        foreach ($products as $product) {
            $baseVal = $product->$base ?? 0;
            $modVal = $isPercent ? ($baseVal * $value / 100) : $value;
            $newPrice = $operator === '+' ? $baseVal + $modVal : max(0, $baseVal - $modVal);

            if ($priceBookId) {
                PriceBookProduct::updateOrCreate(
                    ['price_book_id' => $priceBookId, 'product_id' => $product->id],
                    ['price' => $newPrice]
                );
            } else {
                $product->update(['retail_price' => $newPrice]);
            }
        }

        return redirect()->back()->with('success', 'Đã áp dụng công thức cho ' . $products->count() . ' sản phẩm.');
    }

    /**
     * Create a new price book
     */
    public function storePriceBook(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'nullable|in:active,inactive',
            'formula_base' => 'nullable|string',
            'formula_operator' => 'nullable|in:+,-',
            'formula_value' => 'nullable|numeric|min:0',
            'formula_is_percent' => 'nullable|boolean',
            'scope_branch' => 'nullable|in:all,specific',
            'branch_ids' => 'nullable|array',
            'scope_customer_group' => 'nullable|in:all,specific',
            'customer_group_ids' => 'nullable|array',
            'cashier_rule' => 'nullable|in:allow_add,only_in_book',
            'cashier_warn_not_in_book' => 'nullable|boolean',
            'enable_retail_price' => 'nullable|boolean',
            'enable_technician_price' => 'nullable|boolean',
        ]);

        $priceBook = PriceBook::create([
            'code' => 'BG' . date('ymdHis') . rand(10, 99),
            'name' => $request->name,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'is_active' => $request->status !== 'inactive',
            'status' => $request->status ?? 'active',
            'formula_base' => $request->formula_base,
            'formula_operator' => $request->formula_operator,
            'formula_value' => $request->formula_value ?? 0,
            'formula_is_percent' => $request->formula_is_percent ?? false,
            'scope_branch' => $request->scope_branch ?? 'all',
            'branch_ids' => $request->branch_ids,
            'scope_customer_group' => $request->scope_customer_group ?? 'all',
            'customer_group_ids' => $request->customer_group_ids,
            'cashier_rule' => $request->cashier_rule ?? 'allow_add',
            'cashier_warn_not_in_book' => $request->cashier_warn_not_in_book ?? false,
            'enable_retail_price' => $request->boolean('enable_retail_price'),
            'enable_technician_price' => $request->boolean('enable_technician_price'),
        ]);

        // If formula is set, auto-generate prices for all products
        if ($request->formula_base && $request->formula_operator) {
            $this->applyFormulaToBook($priceBook);
        }

        return redirect()->back()->with('success', 'Đã tạo bảng giá: ' . $priceBook->name);
    }

    /**
     * Update an existing price book
     */
    public function updatePriceBook(Request $request, PriceBook $priceBook)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'nullable|in:active,inactive',
            'formula_base' => 'nullable|string',
            'formula_operator' => 'nullable|in:+,-',
            'formula_value' => 'nullable|numeric|min:0',
            'formula_is_percent' => 'nullable|boolean',
            'scope_branch' => 'nullable|in:all,specific',
            'branch_ids' => 'nullable|array',
            'scope_customer_group' => 'nullable|in:all,specific',
            'customer_group_ids' => 'nullable|array',
            'cashier_rule' => 'nullable|in:allow_add,only_in_book',
            'cashier_warn_not_in_book' => 'nullable|boolean',
            'enable_retail_price' => 'nullable|boolean',
            'enable_technician_price' => 'nullable|boolean',
        ]);

        $priceBook->update([
            'name' => $request->name,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'is_active' => $request->status !== 'inactive',
            'status' => $request->status ?? 'active',
            'formula_base' => $request->formula_base,
            'formula_operator' => $request->formula_operator,
            'formula_value' => $request->formula_value ?? 0,
            'formula_is_percent' => $request->formula_is_percent ?? false,
            'scope_branch' => $request->scope_branch ?? 'all',
            'branch_ids' => $request->branch_ids,
            'scope_customer_group' => $request->scope_customer_group ?? 'all',
            'customer_group_ids' => $request->customer_group_ids,
            'cashier_rule' => $request->cashier_rule ?? 'allow_add',
            'cashier_warn_not_in_book' => $request->cashier_warn_not_in_book ?? false,
            'enable_retail_price' => $request->boolean('enable_retail_price'),
            'enable_technician_price' => $request->boolean('enable_technician_price'),
        ]);

        return redirect()->back()->with('success', 'Đã cập nhật bảng giá: ' . $priceBook->name);
    }

    /**
     * Delete a price book
     */
    public function destroyPriceBook(PriceBook $priceBook)
    {
        $name = $priceBook->name;
        $priceBook->delete();
        return redirect()->back()->with('success', 'Đã xóa bảng giá: ' . $name);
    }

    /**
     * Apply formula from a price book to generate prices for all products
     */
    private function applyFormulaToBook(PriceBook $priceBook)
    {
        if (!$priceBook->formula_base || !$priceBook->formula_operator) return;

        $baseField = $priceBook->formula_base;
        $products = Product::all();

        foreach ($products as $product) {
            // If base is another price book
            if (is_numeric($baseField)) {
                $bookPrice = PriceBookProduct::where('price_book_id', $baseField)
                    ->where('product_id', $product->id)
                    ->value('price');
                $baseVal = $bookPrice ?? $product->retail_price;
            } else {
                $baseVal = $product->$baseField ?? 0;
            }

            $modVal = $priceBook->formula_is_percent
                ? ($baseVal * $priceBook->formula_value / 100)
                : $priceBook->formula_value;

            $newPrice = $priceBook->formula_operator === '+'
                ? $baseVal + $modVal
                : max(0, $baseVal - $modVal);

            PriceBookProduct::updateOrCreate(
                ['price_book_id' => $priceBook->id, 'product_id' => $product->id],
                ['price' => $newPrice]
            );
        }
    }

    public function export(Request $request)
    {
        $query = $this->buildQuery($request);
        $priceBookId = $request->input('price_book_id');
        $priceBook = $priceBookId ? PriceBook::find($priceBookId) : null;

        if ($priceBookId) {
            $query->leftJoin('price_book_products', function ($join) use ($priceBookId) {
                $join->on('products.id', '=', 'price_book_products.product_id')
                    ->where('price_book_products.price_book_id', '=', $priceBookId);
            })->select('products.*', 'price_book_products.price as book_price');
        }

        $products = $query->get();

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="bang_gia.csv"',
        ];

        $callback = function () use ($products, $priceBook) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            $headerRow = ['Mã Hàng', 'Tên Hàng', 'Giá Vốn', 'Giá Nhập Cuối'];
            $headerRow[] = $priceBook ? $priceBook->name . ' (Import)' : 'Bảng Giá Chung (Import)';
            fputcsv($file, $headerRow);

            foreach ($products as $p) {
                $row = [$p->sku, $p->name, $p->cost_price, $p->last_purchase_price];
                $row[] = $priceBook ? ($p->book_price ?? '') : $p->retail_price;
                fputcsv($file, $row);
            }
            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt'
        ]);

        $priceBookId = $request->input('price_book_id');
        $path = $request->file('file')->getRealPath();
        $data = array_map('str_getcsv', file($path));
        $header = array_shift($data);

        $skuIndex = 0;
        $priceIndex = 4; // Column E = price

        $count = 0;
        foreach ($data as $row) {
            if (count($row) > $priceIndex) {
                $sku = $row[$skuIndex];
                $newPrice = preg_replace('/[^0-9.]/', '', $row[$priceIndex]);

                if (is_numeric($newPrice) && !empty($sku)) {
                    $product = Product::where('sku', $sku)->first();
                    if ($product) {
                        if ($priceBookId) {
                            PriceBookProduct::updateOrCreate(
                                ['price_book_id' => $priceBookId, 'product_id' => $product->id],
                                ['price' => $newPrice]
                            );
                        } else {
                            $product->update(['retail_price' => $newPrice]);
                        }
                        $count++;
                    }
                }
            }
        }

        return redirect()->back()->with('success', "Đã cập nhật giá cho {$count} sản phẩm từ file import.");
    }
}
