<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\PriceBook;
use App\Models\PriceBookProduct;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Services\ProductSearchService;

class ProductController extends Controller
{
    public function apiSearch(Request $request, ProductSearchService $productSearch)
    {
        $query = Product::query();

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $productSearch->apply($query, $search, [
                'include_serials' => true,
                'serial_relation' => 'serialImeis',
            ]);
            $productSearch->applyScore($query, $search);
        }

        $productIds = $request->input('product_ids', []);
        if (is_string($productIds)) {
            $productIds = array_filter(explode(',', $productIds));
        }
        if (!is_array($productIds)) {
            $productIds = [];
        }

        if (!empty($productIds)) {
            $query->whereIn('id', $productIds);
        } else {
            $query->limit(20);
        }

        $products = $query->get();
        $priceBookId = $request->input('price_book_id');
        $priceBookName = 'Bảng giá chung';
        $bookPriceByProductId = collect();

        if (!empty($priceBookId)) {
            $priceBook = PriceBook::find($priceBookId);
            if ($priceBook) {
                $priceBookName = $priceBook->name;
                $bookPriceByProductId = PriceBookProduct::where('price_book_id', $priceBook->id)
                    ->whereIn('product_id', $products->pluck('id'))
                    ->pluck('price', 'product_id');
            }
        }

        $result = $products->map(function ($product) use ($bookPriceByProductId, $priceBookName, $priceBookId) {
            $bookPrice = $bookPriceByProductId->get($product->id);
            $sellingPrice = $bookPrice ?? $product->retail_price ?? $product->cost_price ?? 0;

            return array_merge($product->toArray(), [
                'selling_price' => (float) $sellingPrice,
                'price_book_id' => $priceBookId,
                'price_book_name' => $priceBookName,
            ]);
        });

        return response()->json($result);
    }

    public function index(Request $request, ProductSearchService $productSearch)
    {
        $query = Product::with(['category', 'brand']);

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $productSearch->apply($query, $search, [
                'include_serials' => true,
                'serial_relation' => 'serialImeis',
            ]);
            $productSearch->applyScore($query, $search);
        }

        // Lọc theo nhóm hàng (bao gồm cả nhóm con)
        if ($request->filled('category_id')) {
            $categoryId = $request->input('category_id');
            $categoryIds = [$categoryId];
            // Lấy tất cả nhóm con
            $childIds = Category::where('parent_id', $categoryId)->pluck('id')->toArray();
            $categoryIds = array_merge($categoryIds, $childIds);
            // Lấy cả nhóm con cấp 3 nếu có
            if (!empty($childIds)) {
                $grandChildIds = Category::whereIn('parent_id', $childIds)->pluck('id')->toArray();
                $categoryIds = array_merge($categoryIds, $grandChildIds);
            }
            $query->whereIn('category_id', $categoryIds);
        }

        // Lọc theo thương hiệu
        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->input('brand_id'));
        }

        // Lọc theo loại hàng
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        // Lọc theo trạng thái
        if ($request->filled('status')) {
            if ($request->input('status') === 'inactive') {
                $query->where('is_active', false);
            } elseif ($request->input('status') === 'all') {
                // Không lọc - hiển thị tất cả
            }
        } else {
            // Mặc định: chỉ hiển thị đang kinh doanh
            $query->where(function ($q) {
                $q->where('is_active', true)->orWhereNull('is_active');
            });
        }

        // Lọc theo tồn kho
        if ($request->filled('stock_filter')) {
            switch ($request->input('stock_filter')) {
                case 'in_stock':
                    $query->where('stock_quantity', '>', 0);
                    break;
                case 'out_of_stock':
                    $query->where('stock_quantity', '<=', 0);
                    break;
                case 'below_min':
                    $query->whereColumn('stock_quantity', '<', 'min_stock')
                          ->where('min_stock', '>', 0);
                    break;
            }
        }

        // Sắp xếp
        $sortBy = $request->input('sort_by', 'id');
        $sortDirection = $request->input('sort_direction', 'desc');
        $allowedSorts = ['id', 'sku', 'name', 'retail_price', 'cost_price', 'stock_quantity', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDirection === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('id', 'desc');
        }

        $products = $query->paginate(50)->withQueryString();

        $searchTerm = $request->input('search');
        $serialLike = $productSearch->serialLikePattern($searchTerm);

        // Append serial counts for serial products
        $products->getCollection()->transform(function ($product) use ($searchTerm, $serialLike) {
            if ($product->has_serial) {
                $inStockSerials = $product->serialImeis()->where('status', 'in_stock');
                // Tồn kho = tất cả serial có status 'in_stock' (kể cả đang sửa chữa)
                $product->in_stock_count = (clone $inStockSerials)->count();
                // Sẵn bán = in_stock VÀ không đang sửa chữa
                $product->ready_count = (clone $inStockSerials)
                    ->where(function ($q) {
                        $q->whereNull('repair_status')
                          ->orWhereNotIn('repair_status', ['not_started', 'repairing']);
                    })
                    ->count();
                // Đang sửa chữa = in_stock VÀ đang trong luồng repair
                $product->repairing_count = (clone $inStockSerials)
                    ->whereIn('repair_status', ['not_started', 'repairing'])
                    ->count();
                // HOTFIX 24.34 — Đã bóc tách = physical status = dismantled.
                // Phải tách khỏi repairing để UI không gộp vào "Đang sửa".
                $product->dismantled_count = $product->serialImeis()
                    ->where('status', 'dismantled')->count();
                // Tổng serial (bao gồm đã bán, để tham khảo)
                $product->total_serial_count = $product->serialImeis()->count();
                // Giá vốn BQ cuối = trung bình cost_price của serial in_stock
                $product->avg_final_cost = (clone $inStockSerials)->avg('cost_price') ?? 0;

                // Nếu user search theo serial → attach các serial khớp + status/repair_status
                // để UI có thể hiển thị nhãn (Sẵn hàng / Đang sửa / Đã bán...)
                if ($searchTerm && $serialLike !== null) {
                    $product->matched_serials = $product->serialImeis()
                        ->where('serial_number', 'like', $serialLike)
                        ->limit(10)
                        ->get(['id', 'serial_number', 'status', 'repair_status']);
                }
            }
            return $product;
        });

        return Inertia::render('Welcome', [
            'products' => $products,
            'categories' => Category::with('children')->whereNull('parent_id')->orderBy('name')->get(),
            'brands' => Brand::all(),
            'filters' => $request->only('search', 'category_id', 'brand_id', 'type', 'status', 'stock_filter', 'sort_by', 'sort_direction'),
            'canViewCostPrice' => auth()->check() && auth()->user()->hasPermission('products.view_cost_price'),
        ]);
    }

    public function create(Request $request, $type = 'standard')
    {
        // Allowed types
        if (!in_array($type, ['standard', 'service', 'combo', 'manufactured'])) {
            $type = 'standard';
        }

        $priceBooks = PriceBook::where('is_active', true)->get();

        return Inertia::render('Products/Create', [
            'type' => $type,
            'categories' => Category::with('children')->whereNull('parent_id')->orderBy('name')->get(),
            'brands' => Brand::all(),
            'showRetailPrice' => $priceBooks->contains('enable_retail_price', true),
            'showTechnicianPrice' => $priceBooks->contains('enable_technician_price', true),
            'productAttributes' => ProductAttribute::with('values')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'type' => 'required|in:standard,service,combo,manufactured',
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|unique:products,sku',
            'barcode' => 'nullable|string',
            'image' => 'nullable|string|max:500',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'cost_price' => 'numeric|min:0',
            'retail_price' => 'numeric|min:0',
            'technician_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'numeric|min:0',
            'min_stock' => 'numeric|min:0',
            'has_serial' => 'boolean',
            'has_variants' => 'boolean',
            'sell_directly' => 'boolean',
            'allow_point_accumulation' => 'boolean',
            'weight' => 'nullable|string',
            'location' => 'nullable|string',
            'base_unit_name' => 'nullable|string',
            'units' => 'nullable|array',
            'units.*.unit_name' => 'required|string',
            'units.*.conversion_rate' => 'required|numeric|min:1',
            'units.*.retail_price' => 'nullable|numeric',
            'variants' => 'nullable|array',
            'variants.*.sku' => 'nullable|string',
            'variants.*.name' => 'required|string|max:255',
            'variants.*.cost_price' => 'numeric|min:0',
            'variants.*.retail_price' => 'numeric|min:0',
            'variants.*.stock_quantity' => 'numeric|min:0',
            'variants.*.attribute_value_ids' => 'nullable|array',
            // Step 24.9 — warranty / maintenance configuration
            'warranty_months' => 'nullable|integer|min:0|max:1200',
            'warranty_policies' => 'nullable|array',
            'warranty_policies.*.name' => 'required_with:warranty_policies|string|max:255',
            'warranty_policies.*.duration_value' => 'required_with:warranty_policies|integer|min:0|max:1200',
            'warranty_policies.*.duration_unit' => 'required_with:warranty_policies|in:day,month,year',
            'warranty_policies.*.is_default' => 'nullable|boolean',
            'maintenance_policies' => 'nullable|array',
            'maintenance_policies.*.name' => 'required_with:maintenance_policies|string|max:255',
            'maintenance_policies.*.duration_value' => 'required_with:maintenance_policies|integer|min:0|max:1200',
            'maintenance_policies.*.duration_unit' => 'required_with:maintenance_policies|in:day,month,year',
        ]);

        // Step 24.9 — normalise + derive primary warranty_months for back-compat
        $normalizer = app(\App\Services\ProductWarrantyPolicyNormalizer::class);
        $warrantyPolicies = $normalizer->normalizeWarrantyPolicies($validatedData['warranty_policies'] ?? null);
        $maintenancePolicies = $normalizer->normalizeMaintenancePolicies($validatedData['maintenance_policies'] ?? null);
        $validatedData['warranty_policies'] = $warrantyPolicies ?: null;
        $validatedData['maintenance_policies'] = $maintenancePolicies ?: null;
        $validatedData['warranty_months'] = $warrantyPolicies
            ? $normalizer->resolvePrimaryWarrantyMonths($warrantyPolicies)
            : (isset($validatedData['warranty_months']) ? (int) $validatedData['warranty_months'] : null);

        if (empty($validatedData['sku'])) {
            do {
                $sku = 'SP' . date('ymd') . str_pad(random_int(1000, 99999), 5, '0', STR_PAD_LEFT);
            } while (Product::where('sku', $sku)->exists());
            $validatedData['sku'] = $sku;
        }

        if (empty($validatedData['barcode']) && \App\Models\Setting::get('product_barcode_auto', true)) {
            $validatedData['barcode'] = $validatedData['sku'];
        }
        if (!empty($validatedData['barcode']) && Product::where('barcode', $validatedData['barcode'])->exists()) {
            $validatedData['barcode'] = $validatedData['sku'] . '-' . random_int(10, 99);
        }

        $technicianPrice = $validatedData['technician_price'] ?? 0;
        unset($validatedData['technician_price']);

        $units = $validatedData['units'] ?? [];
        $baseUnitName = $validatedData['base_unit_name'] ?? null;
        $variants = $validatedData['variants'] ?? [];
        unset($validatedData['units'], $validatedData['base_unit_name'], $validatedData['variants']);

        $product = Product::create($validatedData);

        // Save technician_price to active price books if provided
        if ($technicianPrice > 0) {
            $activeBooks = PriceBook::where('is_active', true)
                ->where('enable_technician_price', true)->get();
            foreach ($activeBooks as $book) {
                PriceBookProduct::updateOrCreate(
                    ['price_book_id' => $book->id, 'product_id' => $product->id],
                    ['technician_price' => $technicianPrice, 'price' => $product->retail_price ?? 0]
                );
            }
        }

        // Handle Units
        if (!empty($baseUnitName)) {
            $product->units()->create([
                'unit_name' => $baseUnitName,
                'conversion_rate' => 1,
                'is_base_unit' => true,
                'retail_price' => $product->retail_price,
                'sku' => $product->sku,
            ]);
        }

        if (!empty($units)) {
            foreach ($units as $unit) {
                $product->units()->create([
                    'unit_name' => $unit['unit_name'],
                    'conversion_rate' => $unit['conversion_rate'],
                    'retail_price' => $unit['retail_price'] ?? $product->retail_price * $unit['conversion_rate'],
                    'is_base_unit' => false,
                ]);
            }
        }

        // Handle Variants
        if (!empty($variants) && ($product->has_variants ?? false)) {
            foreach ($variants as $vData) {
                $variantSku = $vData['sku'] ?? ($product->sku . '-V' . random_int(100, 999));
                $variant = $product->variants()->create([
                    'sku' => $variantSku,
                    'name' => $vData['name'],
                    'cost_price' => $vData['cost_price'] ?? $product->cost_price,
                    'retail_price' => $vData['retail_price'] ?? $product->retail_price,
                    'stock_quantity' => $vData['stock_quantity'] ?? 0,
                ]);
                if (!empty($vData['attribute_value_ids'])) {
                    $variant->attributeValues()->sync($vData['attribute_value_ids']);
                }
            }
        }

        return redirect()->route('products.index')->with('success', 'Hàng hóa được tạo thành công!');
    }

    /**
     * Quick store a product via AJAX (used from Purchase Create page).
     */
    public function quickStore(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|unique:products,sku',
            'barcode' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'cost_price' => 'numeric|min:0',
            'retail_price' => 'numeric|min:0',
            'technician_price' => 'nullable|numeric|min:0',
            'has_serial' => 'boolean',
        ]);

        $validatedData['type'] = 'standard';
        $validatedData['stock_quantity'] = 0;
        $validatedData['min_stock'] = 0;
        $validatedData['sell_directly'] = true;
        $validatedData['is_active'] = true;

        if (empty($validatedData['sku'])) {
            do {
                $sku = 'SP' . date('ymd') . str_pad(random_int(1000, 99999), 5, '0', STR_PAD_LEFT);
            } while (Product::where('sku', $sku)->exists());
            $validatedData['sku'] = $sku;
        }

        if (empty($validatedData['barcode']) && \App\Models\Setting::get('product_barcode_auto', true)) {
            $validatedData['barcode'] = $validatedData['sku'];
        }
        if (!empty($validatedData['barcode']) && Product::where('barcode', $validatedData['barcode'])->exists()) {
            $validatedData['barcode'] = $validatedData['sku'] . '-' . random_int(10, 99);
        }

        $technicianPriceQuick = $validatedData['technician_price'] ?? 0;
        unset($validatedData['technician_price']);

        $product = Product::create($validatedData);

        // Save technician_price to active price books if provided
        if ($technicianPriceQuick > 0) {
            $activeBooks = PriceBook::where('is_active', true)
                ->where('enable_technician_price', true)->get();
            foreach ($activeBooks as $book) {
                PriceBookProduct::updateOrCreate(
                    ['price_book_id' => $book->id, 'product_id' => $product->id],
                    ['technician_price' => $technicianPriceQuick, 'price' => $product->retail_price ?? 0]
                );
            }
        }

        return response()->json([
            'success' => true,
            'product' => $product,
        ]);
    }

    public function edit(Product $product)
    {
        $product->load(['variants.attributeValues']);
        $priceBooks = PriceBook::where('is_active', true)->get();
        $showTechnician = $priceBooks->contains('enable_technician_price', true);

        // Load technician_price from PriceBookProduct if applicable
        $technicianPrice = 0;
        if ($showTechnician) {
            $pbp = PriceBookProduct::whereHas('priceBook', fn($q) => $q->where('is_active', true)->where('enable_technician_price', true))
                ->where('product_id', $product->id)->first();
            $technicianPrice = $pbp ? $pbp->technician_price : 0;
        }

        return Inertia::render('Products/Edit', [
            'product' => $product,
            'categories' => Category::with('children')->whereNull('parent_id')->orderBy('name')->get(),
            'brands' => Brand::all(),
            'showRetailPrice' => $priceBooks->contains('enable_retail_price', true),
            'showTechnicianPrice' => $showTechnician,
            'technicianPrice' => $technicianPrice,
            'productAttributes' => ProductAttribute::with('values')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'nullable|string|unique:products,sku,' . $product->id,
            'barcode' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'cost_price' => 'numeric|min:0',
            'retail_price' => 'numeric|min:0',
            'technician_price' => 'nullable|numeric|min:0',
            'stock_quantity' => 'numeric|min:0',
            'min_stock' => 'numeric|min:0',
            'has_serial' => 'boolean',
            'has_variants' => 'boolean',
            'sell_directly' => 'boolean',
            'allow_point_accumulation' => 'boolean',
            'weight' => 'nullable|string',
            'location' => 'nullable|string',
            'variants' => 'nullable|array',
            'variants.*.id' => 'nullable|integer',
            'variants.*.sku' => 'nullable|string',
            'variants.*.name' => 'required|string|max:255',
            'variants.*.cost_price' => 'numeric|min:0',
            'variants.*.retail_price' => 'numeric|min:0',
            'variants.*.stock_quantity' => 'numeric|min:0',
            'variants.*.attribute_value_ids' => 'nullable|array',
            // Step 24.9 — warranty / maintenance configuration
            'warranty_months' => 'nullable|integer|min:0|max:1200',
            'warranty_policies' => 'nullable|array',
            'warranty_policies.*.name' => 'required_with:warranty_policies|string|max:255',
            'warranty_policies.*.duration_value' => 'required_with:warranty_policies|integer|min:0|max:1200',
            'warranty_policies.*.duration_unit' => 'required_with:warranty_policies|in:day,month,year',
            'warranty_policies.*.is_default' => 'nullable|boolean',
            'maintenance_policies' => 'nullable|array',
            'maintenance_policies.*.name' => 'required_with:maintenance_policies|string|max:255',
            'maintenance_policies.*.duration_value' => 'required_with:maintenance_policies|integer|min:0|max:1200',
            'maintenance_policies.*.duration_unit' => 'required_with:maintenance_policies|in:day,month,year',
        ]);

        // Step 24.9 — normalise + derive primary warranty_months
        $normalizer = app(\App\Services\ProductWarrantyPolicyNormalizer::class);
        if (array_key_exists('warranty_policies', $validated) || array_key_exists('warranty_months', $validated)) {
            $warrantyPolicies = $normalizer->normalizeWarrantyPolicies($validated['warranty_policies'] ?? null);
            $validated['warranty_policies'] = $warrantyPolicies ?: null;
            $validated['warranty_months'] = $warrantyPolicies
                ? $normalizer->resolvePrimaryWarrantyMonths($warrantyPolicies)
                : (isset($validated['warranty_months']) ? (int) $validated['warranty_months'] : null);
        }
        if (array_key_exists('maintenance_policies', $validated)) {
            $maintenancePolicies = $normalizer->normalizeMaintenancePolicies($validated['maintenance_policies'] ?? null);
            $validated['maintenance_policies'] = $maintenancePolicies ?: null;
        }

        $technicianPrice = $validated['technician_price'] ?? null;
        $variants = $validated['variants'] ?? [];
        unset($validated['technician_price'], $validated['variants']);

        // Serial products: stock_quantity is managed by purchase/sale flows,
        // never allow the Edit form to overwrite it
        if ($product->has_serial) {
            unset($validated['stock_quantity']);
        }

        $product->update($validated);

        // Save technician_price to active price books
        if ($technicianPrice !== null) {
            $activeBooks = PriceBook::where('is_active', true)
                ->where('enable_technician_price', true)->get();
            foreach ($activeBooks as $book) {
                PriceBookProduct::updateOrCreate(
                    ['price_book_id' => $book->id, 'product_id' => $product->id],
                    ['technician_price' => $technicianPrice, 'price' => $product->retail_price ?? 0]
                );
            }
        }

        // Handle Variants sync
        if ($product->has_variants) {
            $keepIds = [];
            foreach ($variants as $vData) {
                if (!empty($vData['id'])) {
                    $variant = ProductVariant::where('id', $vData['id'])->where('product_id', $product->id)->first();
                    if ($variant) {
                        $variant->update([
                            'sku' => $vData['sku'] ?? $variant->sku,
                            'name' => $vData['name'],
                            'cost_price' => $vData['cost_price'] ?? $variant->cost_price,
                            'retail_price' => $vData['retail_price'] ?? $variant->retail_price,
                            'stock_quantity' => $vData['stock_quantity'] ?? $variant->stock_quantity,
                        ]);
                        if (isset($vData['attribute_value_ids'])) {
                            $variant->attributeValues()->sync($vData['attribute_value_ids']);
                        }
                        $keepIds[] = $variant->id;
                        continue;
                    }
                }
                $variantSku = $vData['sku'] ?? ($product->sku . '-V' . random_int(100, 999));
                $variant = $product->variants()->create([
                    'sku' => $variantSku,
                    'name' => $vData['name'],
                    'cost_price' => $vData['cost_price'] ?? $product->cost_price,
                    'retail_price' => $vData['retail_price'] ?? $product->retail_price,
                    'stock_quantity' => $vData['stock_quantity'] ?? 0,
                ]);
                if (!empty($vData['attribute_value_ids'])) {
                    $variant->attributeValues()->sync($vData['attribute_value_ids']);
                }
                $keepIds[] = $variant->id;
            }
            $product->variants()->whereNotIn('id', $keepIds)->delete();
        }

        return redirect()->route('products.index')->with('success', 'Cập nhật hàng hóa thành công!');
    }

    public function inventoryCard(Product $product)
    {
        $transactions = collect();

        // 1. Nhập hàng (Purchases) — loại trừ phiếu huỷ/draft
        $purchases = \App\Models\PurchaseItem::with(['purchase', 'purchase.supplier'])
            ->where('product_id', $product->id)
            ->whereHas('purchase', fn($q) => $q->where(function($sq) {
                $sq->whereNull('status')
                   ->orWhereNotIn('status', ['cancelled', 'draft']);
            }))
            ->get()
            ->map(function ($item) {
                $unitCost = (float) ($item->unit_cost_allocated ?? $item->price ?? 0);
                return [
                    'date' => $item->purchase->created_at,
                    'code' => $item->purchase->code,
                    'doc_type' => 'purchase',
                    'doc_id' => $item->purchase->id,
                    'type' => 'Nhập hàng',
                    'partner' => $item->purchase->supplier->name ?? 'NCC',
                    'sell_price' => (float) ($item->price ?? 0),
                    'cost_price' => $unitCost,
                    'change' => $item->quantity,
                    'method' => 'Cộng kho',
                ];
            });
        $transactions = $transactions->concat($purchases);

        // 2. Bán hàng (Invoices) — loại bỏ hoá đơn đã huỷ
        $sales = \App\Models\InvoiceItem::with(['invoice', 'invoice.customer'])
            ->where('product_id', $product->id)
            ->whereHas('invoice', fn($q) => $q->where(function($sq) {
                $sq->whereNull('status')->orWhere('status', '!=', 'cancelled');
            }))
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->invoice->created_at,
                    'code' => $item->invoice->code,
                    'doc_type' => 'invoice',
                    'doc_id' => $item->invoice->id,
                    'type' => 'Bán hàng',
                    'partner' => $item->invoice->customer->name ?? 'Khách lẻ',
                    'sell_price' => (float) ($item->price ?? 0),
                    'cost_price' => (float) ($item->cost_price ?? 0),
                    'change' => -$item->quantity,
                    'method' => 'Trừ kho',
                ];
            });
        $transactions = $transactions->concat($sales);

        // 3. Trả hàng (Returns) — dùng return_item.cost_price (snapshot)
        $returns = \App\Models\ReturnItem::with(['orderReturn', 'orderReturn.customer'])
            ->where('product_id', $product->id)
            ->whereHas('orderReturn', fn($q) => $q->where(function($sq) {
                $sq->where('status', '!=', 'Đã hủy')->orWhereNull('status');
            }))
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->orderReturn->created_at,
                    'code' => $item->orderReturn->code,
                    'doc_type' => 'return',
                    'doc_id' => $item->orderReturn->id,
                    'type' => 'Khách trả hàng',
                    'partner' => $item->orderReturn->customer->name ?? 'Khách lẻ',
                    'sell_price' => 0,
                    'cost_price' => (float) ($item->cost_price ?? $item->import_price ?? 0),
                    'change' => $item->quantity,
                    'method' => 'Cộng kho',
                ];
            });
        $transactions = $transactions->concat($returns);

        // 4. Kiểm kho (Stock Takes) — chỉ phiếu đã cân bằng
        $defaultCostPrice = (float) ($product->cost_price ?? 0);
        $stockTakes = \App\Models\StockTakeItem::with('stockTake')
            ->where('product_id', $product->id)
            ->whereHas('stockTake', fn($q) => $q->where('status', 'balanced'))
            ->get()
            ->map(function ($item) use ($defaultCostPrice) {
                $diff = (int) $item->actual_stock - (int) $item->system_stock;
                return [
                    'date' => $item->stockTake->balanced_date ?? $item->stockTake->created_at,
                    'code' => $item->stockTake->code,
                    'doc_type' => 'stock_take',
                    'doc_id' => $item->stockTake->id,
                    'type' => 'Kiểm hàng',
                    'partner' => 'Hệ thống',
                    'sell_price' => 0,
                    'cost_price' => $defaultCostPrice,
                    'change' => $diff,
                    'method' => $diff >= 0 ? 'Cộng kho' : 'Trừ kho',
                ];
            });
        $transactions = $transactions->concat($stockTakes);

        // 5. Xuất hủy (Damage)
        $damages = \App\Models\DamageItem::with('damage')
            ->where('product_id', $product->id)
            ->get()
            ->map(function ($item) use ($defaultCostPrice) {
                return [
                    'date' => $item->damage->created_at,
                    'code' => $item->damage->code,
                    'doc_type' => 'damage',
                    'doc_id' => $item->damage->id,
                    'type' => 'Xuất hủy',
                    'partner' => 'Hệ thống',
                    'sell_price' => 0,
                    'cost_price' => $defaultCostPrice,
                    'change' => -$item->quantity,
                    'method' => 'Trừ kho',
                ];
            });
        $transactions = $transactions->concat($damages);

        // 6. Chuyển kho (Transfers)
        $transfers = \App\Models\StockTransferItem::with(['stockTransfer', 'stockTransfer.toBranch'])
            ->where('product_id', $product->id)
            ->get()
            ->map(function ($item) use ($defaultCostPrice) {
                return [
                    'date' => $item->stockTransfer->created_at,
                    'code' => $item->stockTransfer->code,
                    'doc_type' => 'transfer',
                    'doc_id' => $item->stockTransfer->id,
                    'type' => 'Chuyển kho',
                    'partner' => $item->stockTransfer->toBranch->name ?? 'Kho khác',
                    'sell_price' => 0,
                    'cost_price' => $defaultCostPrice,
                    'change' => -$item->quantity,
                    'method' => 'Trừ kho',
                ];
            });
        $transactions = $transactions->concat($transfers);

        // 7. Xuất sửa chữa & Nhập bóc máy (Repair Parts)
        $repairParts = \App\Models\TaskPart::with(['task', 'task.product'])
            ->where('product_id', $product->id)
            ->get()
            ->map(function ($part) {
                $task = $part->task;
                $repairedName = $task?->product?->name ?? $task?->title ?? 'Sửa chữa';
                $isImport = ($part->direction ?? 'export') === 'import';
                return [
                    'date' => $part->created_at,
                    'code' => $task?->code ?? ('TP-' . $part->id),
                    'doc_type' => $isImport ? 'disassemble_part' : 'repair_part',
                    'doc_id' => $task?->id,
                    'type' => $isImport ? 'Nhập bóc máy' : 'Xuất sửa chữa',
                    'partner' => $repairedName,
                    'sell_price' => 0,
                    'cost_price' => (float) ($part->unit_cost ?? 0),
                    'change' => $isImport ? $part->quantity : -$part->quantity,
                    'method' => $isImport ? 'Nhập kho' : 'Trừ kho',
                ];
            });
        $transactions = $transactions->concat($repairParts);

        // 8. Trả hàng nhập NCC (Purchase Returns)
        $purchaseReturns = \App\Models\PurchaseReturnItem::with(['purchaseReturn', 'purchaseReturn.supplier'])
            ->where('product_id', $product->id)
            ->whereHas('purchaseReturn', fn($q) => $q->where('status', 'completed'))
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->purchaseReturn->return_date ?? $item->purchaseReturn->created_at,
                    'code' => $item->purchaseReturn->code,
                    'doc_type' => 'purchase_return',
                    'doc_id' => $item->purchaseReturn->id,
                    'type' => 'Trả hàng NCC',
                    'partner' => $item->purchaseReturn->supplier->name ?? 'NCC',
                    'sell_price' => (float) ($item->price ?? 0),
                    'cost_price' => (float) ($item->cost_price ?? $item->price ?? 0),
                    'change' => -$item->quantity,
                    'method' => 'Trừ kho',
                ];
            });
        $transactions = $transactions->concat($purchaseReturns);

        // (Bỏ tồn đầu kỳ — tất cả SP đều có phiếu nhập đầy đủ, không cần initial_balance)

        // Sort by date ASC and compute running balance
        $sorted = $transactions->sortBy('date')->values();
        $balance = 0;
        $result = $sorted->map(function ($tx) use (&$balance) {
            $balance += $tx['change'];
            $tx['balance'] = $balance;
            return $tx;
        });

        return response()->json($result->sortByDesc('date')->values());
    }

    public function serials(Product $product, Request $request)
    {
        $query = $product->serials();

        // HOTFIX 24.34 — semantic status filter. The UI exposes
        //   ready       → in_stock AND not in a repair flow
        //   repairing   → in_stock AND repair_status in (not_started, repairing)
        //   dismantled  → physical status = dismantled (NEVER bundled with repairing)
        // Other values fall through as raw status equality.
        $status = $request->input('status');
        if ($status && $status !== 'all') {
            switch ($status) {
                case 'ready':
                    $query->where('status', 'in_stock')
                          ->where(function ($q) {
                              $q->whereNull('repair_status')
                                ->orWhereNotIn('repair_status', ['not_started', 'repairing']);
                          });
                    break;
                case 'repairing':
                    $query->where('status', 'in_stock')
                          ->whereIn('repair_status', ['not_started', 'repairing']);
                    break;
                case 'dismantled':
                    $query->where('status', 'dismantled');
                    break;
                default:
                    $query->where('status', $status);
            }
        }

        if ($request->filled('search')) {
            $query->where('serial_number', 'LIKE', '%' . $request->search . '%');
        }

        return response()->json($query->orderBy('created_at', 'desc')->get());
    }

    /**
     * GET /products/{product}/warranties
     */
    public function warranties(Product $product)
    {
        $warranties = \App\Models\Warranty::where('product_id', $product->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($w) {
                return [
                    'id' => $w->id,
                    'invoice_code' => $w->invoice_code,
                    'customer_name' => $w->customer_name,
                    'serial_imei' => $w->serial_imei,
                    'warranty_period' => $w->warranty_period,
                    'purchase_date' => $w->purchase_date?->format('d/m/Y'),
                    'warranty_end_date' => $w->warranty_end_date?->format('d/m/Y'),
                    'status' => $w->warranty_end_date && $w->warranty_end_date->isFuture() ? 'active' : 'expired',
                    'maintenance_note' => $w->maintenance_note,
                ];
            });

        return response()->json($warranties);
    }

    /**
     * GET /products/document-detail?type=invoice&id=123
     * Popup chi tiết chứng từ từ thẻ kho
     */
    public function documentDetail(Request $request)
    {
        $type = $request->input('type');
        $id = $request->input('id');

        if (!$type || !$id) {
            return response()->json(['error' => 'Missing type or id'], 400);
        }

        // Step 24.7: resolve the source-voucher URL once. Each case below
        // injects this into its response so the FE never has to guess routes.
        $source = app(\App\Services\DocumentLinkResolver::class)->resolve((string) $type, (int) $id);

        switch ($type) {
            case 'invoice':
                $doc = \App\Models\Invoice::with(['items.product', 'items.serials', 'customer'])->find($id);
                if (!$doc) return response()->json(['error' => 'Not found'], 404);
                return response()->json([
                    'source_document' => $source,
                    'type' => 'invoice',
                    'title' => 'Hóa đơn',
                    'code' => $doc->code,
                    'status' => $doc->status ?? 'Hoàn thành',
                    'partner_name' => $doc->customer->name ?? 'Khách lẻ',
                    'created_by' => $doc->created_by_name ?? '',
                    'seller' => $doc->seller_name ?? '',
                    'sales_channel' => $doc->sales_channel ?? 'Bán trực tiếp',
                    'price_book' => $doc->price_book_name ?? 'Bảng giá chung',
                    'date' => $doc->created_at?->format('d/m/Y H:i'),
                    'note' => $doc->note,
                    'items' => $doc->items->map(function ($i) {
                        $serials = $i->serials
                            ->map(fn ($s) => [
                                'id' => $s->id,
                                'serial_imei_id' => $s->serial_imei_id,
                                'serial_number' => $s->serial_number,
                                'cost_price' => (float) ($s->cost_price ?? 0),
                                'legacy' => false,
                            ])
                            ->values();

                        if ($serials->isEmpty() && !empty($i->serial)) {
                            $serials = collect(array_filter(array_map('trim', explode(',', $i->serial))))
                                ->map(fn ($serialNumber) => [
                                    'id' => null,
                                    'serial_imei_id' => null,
                                    'serial_number' => $serialNumber,
                                    'cost_price' => 0,
                                    'legacy' => true,
                                ])
                                ->values();
                        }

                        return [
                            'product_code' => $i->product->sku ?? '',
                            'product_name' => $i->product->name ?? '',
                            'has_serial' => $i->product->has_serial ?? false,
                            'quantity' => $i->quantity,
                            'price' => (float) $i->price,
                            'discount' => (float) ($i->discount ?? 0),
                            'sell_price' => (float) ($i->price - ($i->discount ?? 0)),
                            'subtotal' => (float) $i->subtotal,
                            'serials' => $serials,
                            'serial_count' => $serials->count(),
                        ];
                    }),
                    'subtotal' => (float) $doc->subtotal,
                    'discount' => (float) ($doc->discount ?? 0),
                    'total' => (float) $doc->total,
                    'customer_paid' => (float) ($doc->customer_paid ?? 0),
                ]);
            case 'purchase':
                $doc = \App\Models\Purchase::with(['items.product', 'supplier', 'user'])->find($id);
                if (!$doc) return response()->json(['error' => 'Not found'], 404);
                return response()->json([
                    'source_document' => $source,
                    'type' => 'purchase',
                    'title' => 'Phiếu nhập hàng',
                    'code' => $doc->code,
                    'status' => $doc->status ?? 'Hoàn thành',
                    'partner_name' => $doc->supplier->name ?? 'NCC',
                    'created_by' => $doc->user->name ?? '',
                    'seller' => '',
                    'sales_channel' => '',
                    'price_book' => '',
                    'date' => $doc->created_at?->format('d/m/Y H:i'),
                    'note' => $doc->note,
                    'items' => $doc->items->map(fn($i) => [
                        'product_code' => $i->product_code ?? ($i->product->sku ?? ''),
                        'product_name' => $i->product_name ?? ($i->product->name ?? ''),
                        'has_serial' => $i->product->has_serial ?? false,
                        'quantity' => $i->quantity,
                        'price' => (float) $i->price,
                        'discount' => (float) ($i->discount ?? 0),
                        'sell_price' => (float) ($i->price - ($i->discount ?? 0)),
                        'subtotal' => (float) $i->subtotal,
                    ]),
                    'subtotal' => (float) $doc->total_amount,
                    'discount' => (float) ($doc->discount ?? 0),
                    'total' => (float) ($doc->total_amount - ($doc->discount ?? 0)),
                    'customer_paid' => (float) ($doc->paid_amount ?? 0),
                ]);

            case 'return':
                $doc = \App\Models\OrderReturn::with(['items.product', 'customer'])->find($id);
                if (!$doc) return response()->json(['error' => 'Not found'], 404);
                return response()->json([
                    'source_document' => $source,
                    'type' => 'return',
                    'title' => 'Phiếu trả hàng',
                    'code' => $doc->code,
                    'status' => $doc->status ?? 'Hoàn thành',
                    'partner_name' => $doc->customer->name ?? 'Khách lẻ',
                    'created_by' => '',
                    'seller' => '',
                    'sales_channel' => '',
                    'price_book' => '',
                    'date' => $doc->created_at?->format('d/m/Y H:i'),
                    'note' => $doc->note ?? '',
                    'items' => $doc->items->map(fn($i) => [
                        'product_code' => $i->product->sku ?? '',
                        'product_name' => $i->product->name ?? '',
                        'has_serial' => $i->product->has_serial ?? false,
                        'quantity' => $i->quantity,
                        'price' => (float) ($i->price ?? 0),
                        'discount' => 0,
                        'sell_price' => (float) ($i->price ?? 0),
                        'subtotal' => (float) ($i->subtotal ?? 0),
                    ]),
                    'subtotal' => (float) ($doc->total ?? 0),
                    'discount' => 0,
                    'total' => (float) ($doc->total ?? 0),
                    'customer_paid' => 0,
                ]);

            // ─── PHIẾU SỬA CHỮA / BÓC MÁY ───
            case 'repair_part':
            case 'disassemble_part':
                $task = \App\Models\Task::with(['parts.product', 'product', 'serialImei', 'assignedEmployee'])->find($id);
                if (!$task) return response()->json(['error' => 'Not found'], 404);
                $machineName = $task->product->name ?? $task->title ?? 'Sửa chữa';
                $serialNumber = $task->serialImei->serial_number ?? null;
                return response()->json([
                    'source_document' => $source,
                    'type' => $type,
                    'title' => $type === 'repair_part' ? 'Phiếu xuất sửa chữa' : 'Phiếu nhập bóc máy',
                    'code' => $task->code,
                    'status' => $task->status_label ?? $task->status,
                    'partner_name' => $machineName . ($serialNumber ? " ({$serialNumber})" : ''),
                    'created_by' => $task->creator?->name ?? '',
                    'seller' => $task->assignedEmployee?->name ?? '',
                    'sales_channel' => $task->type === 'repair' ? 'Sửa chữa' : 'Công việc',
                    'price_book' => '',
                    'date' => $task->created_at?->format('d/m/Y H:i'),
                    'note' => $task->notes ?? $task->issue_description ?? '',
                    'items' => $task->parts->map(fn($p) => [
                        'product_code' => $p->product->sku ?? '',
                        'product_name' => $p->product->name ?? '',
                        'has_serial' => $p->product->has_serial ?? false,
                        'quantity' => $p->quantity ?? 1,
                        'price' => (float) ($p->unit_cost ?? 0),
                        'discount' => 0,
                        'sell_price' => (float) ($p->unit_cost ?? 0),
                        'subtotal' => (float) ($p->total_cost ?? 0),
                        'direction' => $p->direction ?? 'export',
                        'direction_label' => ($p->direction ?? 'export') === 'import' ? '↩ Nhập (bóc máy)' : '↗ Xuất (sửa chữa)',
                    ]),
                    'subtotal' => (float) $task->parts_cost,
                    'discount' => 0,
                    'total' => (float) $task->total_cost,
                    'customer_paid' => 0,
                ]);

            // ─── PHIẾU TRẢ HÀNG NCC ───
            case 'purchase_return':
                $doc = \App\Models\PurchaseReturn::with(['items.product', 'supplier'])->find($id);
                if (!$doc) return response()->json(['error' => 'Not found'], 404);
                return response()->json([
                    'source_document' => $source,
                    'type' => 'purchase_return',
                    'title' => 'Phiếu trả hàng NCC',
                    'code' => $doc->code,
                    'status' => $doc->status ?? 'completed',
                    'partner_name' => $doc->supplier->name ?? 'NCC',
                    'created_by' => $doc->created_by_name ?? '',
                    'seller' => '',
                    'sales_channel' => '',
                    'price_book' => '',
                    'date' => ($doc->return_date ?? $doc->created_at)?->format('d/m/Y H:i'),
                    'note' => $doc->note ?? '',
                    'items' => $doc->items->map(fn($i) => [
                        'product_code' => $i->product->sku ?? '',
                        'product_name' => $i->product->name ?? '',
                        'has_serial' => $i->product->has_serial ?? false,
                        'quantity' => $i->quantity,
                        'price' => (float) ($i->price ?? 0),
                        'discount' => 0,
                        'sell_price' => (float) ($i->price ?? 0),
                        'subtotal' => (float) ($i->quantity * ($i->price ?? 0)),
                    ]),
                    'subtotal' => (float) ($doc->total_amount ?? 0),
                    'discount' => 0,
                    'total' => (float) ($doc->total_amount ?? 0),
                    'customer_paid' => 0,
                ]);

            // ─── PHIẾU KIỂM KHO ───
            case 'stock_take':
                $doc = \App\Models\StockTake::with(['items.product'])->find($id);
                if (!$doc) return response()->json(['error' => 'Not found'], 404);
                return response()->json([
                    'source_document' => $source,
                    'type' => 'stock_take',
                    'title' => 'Phiếu kiểm kho',
                    'code' => $doc->code,
                    'status' => $doc->status ?? 'draft',
                    'partner_name' => $doc->user_name ?? 'Hệ thống',
                    'created_by' => $doc->user_name ?? '',
                    'seller' => $doc->balancer_name ?? '',
                    'sales_channel' => '',
                    'price_book' => '',
                    'date' => ($doc->balanced_date ?? $doc->created_at)?->format('d/m/Y H:i'),
                    'note' => $doc->note ?? '',
                    'items' => $doc->items->map(fn($i) => [
                        'product_code' => $i->product->sku ?? '',
                        'product_name' => $i->product->name ?? '',
                        'has_serial' => false,
                        'quantity' => $i->diff_qty,
                        'price' => 0,
                        'discount' => 0,
                        'sell_price' => 0,
                        'subtotal' => (float) ($i->diff_value ?? 0),
                        'system_stock' => $i->system_stock,
                        'actual_stock' => $i->actual_stock,
                    ]),
                    'subtotal' => (float) ($doc->total_diff_value ?? 0),
                    'discount' => 0,
                    'total' => (float) ($doc->total_diff_value ?? 0),
                    'customer_paid' => 0,
                ]);

            // ─── PHIẾU XUẤT HỦY ───
            case 'damage':
                $doc = \App\Models\Damage::with(['items.product'])->find($id);
                if (!$doc) return response()->json(['error' => 'Not found'], 404);
                return response()->json([
                    'source_document' => $source,
                    'type' => 'damage',
                    'title' => 'Phiếu xuất hủy',
                    'code' => $doc->code,
                    'status' => $doc->status ?? 'completed',
                    'partner_name' => $doc->destroyed_by_name ?? 'Hệ thống',
                    'created_by' => $doc->created_by_name ?? '',
                    'seller' => '',
                    'sales_channel' => '',
                    'price_book' => '',
                    'date' => ($doc->destroyed_date ?? $doc->created_at)?->format('d/m/Y H:i'),
                    'note' => $doc->note ?? '',
                    'items' => $doc->items->map(fn($i) => [
                        'product_code' => $i->product->sku ?? '',
                        'product_name' => $i->product->name ?? '',
                        'has_serial' => false,
                        'quantity' => $i->qty,
                        'price' => (float) ($i->cost_price ?? 0),
                        'discount' => 0,
                        'sell_price' => (float) ($i->cost_price ?? 0),
                        'subtotal' => (float) ($i->total_value ?? 0),
                    ]),
                    'subtotal' => (float) ($doc->total_value ?? 0),
                    'discount' => 0,
                    'total' => (float) ($doc->total_value ?? 0),
                    'customer_paid' => 0,
                ]);

            default:
                return response()->json(['error' => 'Unknown document type'], 400);
        }
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return redirect()->back()->with('success', 'Đã xoá hàng hóa!');
    }
    public function export(Request $request, ProductSearchService $productSearch)
    {
        $query = Product::with(['category', 'brand']);

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $productSearch->apply($query, $search, [
                'include_serials' => true,
                'serial_relation' => 'serialImeis',
            ]);
            $productSearch->applyScore($query, $search);
        }

        $products = $query->orderBy('id', 'desc')->get();

        return \App\Services\CsvService::export(
            ['Mã hàng', 'Tên hàng', 'Loại', 'Nhóm hàng', 'Thương hiệu', 'Giá vốn', 'Giá bán', 'Tồn kho', 'Định mức tồn ít nhất', 'Định mức tồn nhiều nhất', 'Trọng lượng', 'Vị trí', 'Mô tả'],
            $products->map(fn($p) => [$p->sku, $p->name, $p->type, $p->category?->name, $p->brand?->name, $p->cost_price, $p->retail_price, $p->stock_quantity, $p->min_stock, $p->max_stock, $p->weight, $p->location, $p->description]),
            'hang_hoa.csv'
        );
    }

    public function import(Request $request)
    {
        [$headers, $rows] = \App\Services\CsvService::parse($request);
        $count = 0;
        foreach ($rows as $row) {
            if (count($row) < 2 || empty(trim($row[1] ?? ''))) continue;
            $categoryName = trim($row[3] ?? '');
            $brandName = trim($row[4] ?? '');
            $categoryId = $categoryName ? \App\Models\Category::firstOrCreate(['name' => $categoryName])->id : null;
            $brandId = $brandName ? \App\Models\Brand::firstOrCreate(['name' => $brandName])->id : null;

            Product::updateOrCreate(
                ['sku' => trim($row[0])],
                array_filter([
                    'name' => trim($row[1]),
                    'type' => in_array(trim($row[2] ?? ''), ['standard', 'service', 'combo', 'manufactured']) ? trim($row[2]) : 'standard',
                    'category_id' => $categoryId,
                    'brand_id' => $brandId,
                    'cost_price' => (float) ($row[5] ?? 0),
                    'retail_price' => (float) ($row[6] ?? 0),
                    'stock_quantity' => (int) ($row[7] ?? 0),
                    'min_stock' => (int) ($row[8] ?? 0),
                    'max_stock' => (int) ($row[9] ?? 0),
                    'weight' => trim($row[10] ?? ''),
                    'location' => trim($row[11] ?? ''),
                    'description' => trim($row[12] ?? ''),
                ], fn($v) => $v !== '' && $v !== null)
            );
            $count++;
        }
        return back()->with('success', "Đã nhập {$count} sản phẩm từ file.");
    }

    /**
     * POST /products/{product}/serials
     */
    public function storeSerial(Request $request, Product $product)
    {
        $data = $request->validate([
            'serial_number' => 'required|string|max:255|unique:serial_imeis,serial_number',
        ]);

        $serial = SerialImei::create([
            'product_id' => $product->id,
            'serial_number' => $data['serial_number'],
            'status' => 'in_stock',
            'cost_price' => $product->cost_price ?? 0,
            'original_cost' => $product->cost_price ?? 0,
        ]);

        // Sync stock_quantity with serial count
        $product->increment('stock_quantity');

        return response()->json($serial, 201);
    }

    /**
     * POST /products/{product}/serials/bulk
     */
    public function bulkStoreSerials(Request $request, Product $product)
    {
        $data = $request->validate([
            'serials' => 'required|string',
        ]);

        $lines = array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $data['serials'])));
        $created = 0;
        $duplicates = [];

        foreach ($lines as $serial) {
            if (SerialImei::where('serial_number', $serial)->exists()) {
                $duplicates[] = $serial;
                continue;
            }
            SerialImei::create([
                'product_id' => $product->id,
                'serial_number' => $serial,
                'status' => 'in_stock',
                'cost_price' => $product->cost_price ?? 0,
                'original_cost' => $product->cost_price ?? 0,
            ]);
            $created++;
        }

        // Sync stock_quantity with newly created serials
        if ($created > 0) {
            $product->increment('stock_quantity', $created);
        }

        return response()->json([
            'created' => $created,
            'duplicates' => $duplicates,
        ]);
    }

    /**
     * PUT /products/{product}/serials/{serial}
     */
    public function updateSerial(Request $request, Product $product, SerialImei $serial)
    {
        $data = $request->validate([
            'serial_number' => 'required|string|max:255|unique:serial_imeis,serial_number,' . $serial->id,
            'status' => 'nullable|string|in:in_stock,sold,returning,warranty,defective',
            'cost_price' => 'nullable|numeric|min:0',
        ]);

        // Chỉ cho sửa cost_price khi serial CÒN TỒN. Sold/returned/etc giữ snapshot lúc bán.
        $costChanged = false;
        $oldCost = (float) $serial->cost_price;
        if (array_key_exists('cost_price', $data) && $data['cost_price'] !== null) {
            if ($serial->status !== 'in_stock') {
                return response()->json([
                    'message' => 'Chỉ có thể sửa giá vốn khi serial đang CÒN TỒN (in_stock).',
                ], 422);
            }
            $newCost = (float) $data['cost_price'];
            if (abs($newCost - $oldCost) > 0.001) {
                $costChanged = true;
            }
        } else {
            // Không gửi field thì không update
            unset($data['cost_price']);
        }

        $oldStatus = $serial->status;
        $serial->update($data);
        $newStatus = $serial->status;

        // Sync stock_quantity when serial status changes (chỉ cho hàng thường - serial product
        // sẽ được recompute bên dưới). Hàng has_serial vẫn cần fallback đề phòng stock lệch.
        if ($oldStatus !== $newStatus) {
            if ($oldStatus === 'in_stock' && $newStatus !== 'in_stock') {
                $product->decrement('stock_quantity');
            } elseif ($oldStatus !== 'in_stock' && $newStatus === 'in_stock') {
                $product->increment('stock_quantity');
            }
        }

        // Recompute product cost từ các serial in_stock
        if ($product->has_serial) {
            $product->refresh();
            $product->recomputeFromSerials();
        }

        if ($costChanged) {
            \App\Models\ActivityLog::log(
                'serial_cost_update',
                "Sửa giá vốn serial {$serial->serial_number} (SP: {$product->name}): "
                    . number_format($oldCost, 0, ',', '.') . ' → ' . number_format((float)$serial->cost_price, 0, ',', '.'),
                $serial,
                ['old_cost' => $oldCost, 'new_cost' => (float) $serial->cost_price, 'product_id' => $product->id]
            );
        }

        return response()->json($serial);
    }

    /**
     * DELETE /products/{product}/serials/{serial}
     */
    public function destroySerial(Product $product, SerialImei $serial)
    {
        if ($serial->status === 'sold') {
            return response()->json(['message' => 'Không thể xóa serial đã bán.'], 422);
        }

        $wasInStock = $serial->status === 'in_stock';
        $serial->delete();

        // Sync stock_quantity when in_stock serial is deleted
        if ($wasInStock) {
            $product->decrement('stock_quantity');
        }

        return response()->json(['message' => 'Đã xóa serial.']);
    }

    /**
     * POST /products/bulk-update-category
     * Chuyển nhóm hàng hàng loạt
     */
    public function bulkUpdateCategory(Request $request)
    {
        $validated = $request->validate([
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'exists:products,id',
            'category_id' => 'required|exists:categories,id',
        ]);

        $count = Product::whereIn('id', $validated['product_ids'])
            ->update(['category_id' => $validated['category_id']]);

        $category = Category::find($validated['category_id']);

        return response()->json([
            'success' => true,
            'message' => "Đã chuyển {$count} sản phẩm sang nhóm \"{$category->name}\".",
        ]);
    }
}
