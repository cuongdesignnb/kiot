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

class ProductController extends Controller
{
    public function apiSearch(Request $request)
    {
        $query = Product::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhereHas('serials', function ($sq) use ($search) {
                        $sq->where('serial_number', 'like', "%{$search}%");
                    });
            });
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

    public function index(Request $request)
    {
        $query = Product::with(['category', 'brand']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $products = $query
            ->withCount([
                'serialImeis as total_serial_count' => function ($q) {
                    $q->where('status', 'in_stock');
                },
                'serialImeis as repairing_count' => function ($q) {
                    $q->where('status', 'in_stock')
                      ->whereIn('repair_status', ['not_started', 'repairing']);
                },
                'serialImeis as ready_count' => function ($q) {
                    $q->where('status', 'in_stock')
                      ->where(function ($q2) {
                          $q2->where('repair_status', 'ready')
                             ->orWhereNull('repair_status');
                      });
                },
            ])
            ->when($request->filled('sort_by'), function ($q) use ($request) {
                $allowed = ['sku', 'name', 'retail_price', 'cost_price', 'stock_quantity', 'created_at'];
                $sortBy = in_array($request->sort_by, $allowed) ? $request->sort_by : 'id';
                $dir = $request->sort_direction === 'asc' ? 'asc' : 'desc';
                $q->orderBy($sortBy, $dir);
            }, function ($q) {
                $q->orderBy('id', 'desc');
            })
            ->paginate(50)->withQueryString();

        return Inertia::render('Welcome', [
            'products' => $products,
            'categories' => Category::with('children')->whereNull('parent_id')->orderBy('name')->get(),
            'brands' => Brand::all(),
            'filters' => $request->only('search', 'sort_by', 'sort_direction'),
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
            'redirectBack' => $request->input('redirect_back'),
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
        ]);

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

        // If redirect_back param was provided (e.g., from Purchases/Create or Tasks), redirect there
        $redirectBack = $request->input('redirect_back');
        if ($redirectBack && str_starts_with($redirectBack, '/')) {
            return redirect($redirectBack)->with('success', 'Hàng hóa "' . $product->name . '" được tạo thành công!');
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
        ]);

        $technicianPrice = $validated['technician_price'] ?? null;
        $variants = $validated['variants'] ?? [];
        unset($validated['technician_price'], $validated['variants']);

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
        $costPrice = (float) ($product->cost_price ?? 0);

        // 1. Nhập hàng (Purchases)
        $purchases = \App\Models\PurchaseItem::with(['purchase', 'purchase.supplier'])
            ->where('product_id', $product->id)
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->purchase->created_at,
                    'code' => $item->purchase->code,
                    'doc_type' => 'purchase',
                    'doc_id' => $item->purchase->id,
                    'type' => 'Nhập hàng',
                    'partner' => $item->purchase->supplier->name ?? 'NCC',
                    'sell_price' => (float) ($item->price ?? 0),
                    'cost_price' => (float) ($item->price ?? 0),
                    'change' => $item->quantity,
                    'method' => 'Cộng kho',
                ];
            });
        $transactions = $transactions->concat($purchases);

        // 2. Bán hàng (Invoices)
        $sales = \App\Models\InvoiceItem::with(['invoice', 'invoice.customer'])
            ->where('product_id', $product->id)
            ->get()
            ->map(function ($item) use ($costPrice) {
                return [
                    'date' => $item->invoice->created_at,
                    'code' => $item->invoice->code,
                    'doc_type' => 'invoice',
                    'doc_id' => $item->invoice->id,
                    'type' => 'Bán hàng',
                    'partner' => $item->invoice->customer->name ?? 'Khách lẻ',
                    'sell_price' => (float) ($item->price ?? 0),
                    'cost_price' => $costPrice,
                    'change' => -$item->quantity,
                    'method' => 'Trừ kho',
                ];
            });
        $transactions = $transactions->concat($sales);

        // 3. Trả hàng (Returns)
        $returns = \App\Models\ReturnItem::with(['orderReturn', 'orderReturn.customer'])
            ->where('product_id', $product->id)
            ->get()
            ->map(function ($item) use ($costPrice) {
                return [
                    'date' => $item->orderReturn->created_at,
                    'code' => $item->orderReturn->code,
                    'doc_type' => 'return',
                    'doc_id' => $item->orderReturn->id,
                    'type' => 'Khách trả hàng',
                    'partner' => $item->orderReturn->customer->name ?? 'Khách lẻ',
                    'sell_price' => 0,
                    'cost_price' => $costPrice,
                    'change' => $item->quantity,
                    'method' => 'Cộng kho',
                ];
            });
        $transactions = $transactions->concat($returns);

        // 4. Kiểm kho (Stock Takes)
        $stockTakes = \App\Models\StockTakeItem::with('stockTake')
            ->where('product_id', $product->id)
            ->get()
            ->map(function ($item) use ($costPrice) {
                $diff = $item->actual_quantity - $item->current_quantity;
                return [
                    'date' => $item->stockTake->created_at,
                    'code' => $item->stockTake->code,
                    'doc_type' => 'stock_take',
                    'doc_id' => $item->stockTake->id,
                    'type' => 'Kiểm hàng',
                    'partner' => 'Hệ thống',
                    'sell_price' => 0,
                    'cost_price' => $costPrice,
                    'change' => $diff,
                    'method' => $diff >= 0 ? 'Cộng kho' : 'Trừ kho',
                ];
            });
        $transactions = $transactions->concat($stockTakes);

        // 5. Xuất hủy (Damage)
        $damages = \App\Models\DamageItem::with('damage')
            ->where('product_id', $product->id)
            ->get()
            ->map(function ($item) use ($costPrice) {
                return [
                    'date' => $item->damage->created_at,
                    'code' => $item->damage->code,
                    'doc_type' => 'damage',
                    'doc_id' => $item->damage->id,
                    'type' => 'Xuất hủy',
                    'partner' => 'Hệ thống',
                    'sell_price' => 0,
                    'cost_price' => $costPrice,
                    'change' => -$item->quantity,
                    'method' => 'Trừ kho',
                ];
            });
        $transactions = $transactions->concat($damages);

        // 6. Chuyển kho (Transfers)
        $transfers = \App\Models\StockTransferItem::with(['stockTransfer', 'stockTransfer.toBranch'])
            ->where('product_id', $product->id)
            ->get()
            ->map(function ($item) use ($costPrice) {
                return [
                    'date' => $item->stockTransfer->created_at,
                    'code' => $item->stockTransfer->code,
                    'doc_type' => 'transfer',
                    'doc_id' => $item->stockTransfer->id,
                    'type' => 'Chuyển kho',
                    'partner' => $item->stockTransfer->toBranch->name ?? 'Kho khác',
                    'sell_price' => 0,
                    'cost_price' => $costPrice,
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

        if ($request->filled('status') && $request->status !== 'all') {
            $status = $request->status;

            // Filter by repair status
            if ($status === 'repairing') {
                $query->where('status', 'in_stock')
                      ->whereIn('repair_status', ['not_started', 'repairing']);
            } elseif ($status === 'ready') {
                $query->where('status', 'in_stock')
                      ->where(function ($q) {
                          $q->where('repair_status', 'ready')
                             ->orWhereNull('repair_status');
                      });
            } else {
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

        switch ($type) {
            case 'invoice':
                $doc = \App\Models\Invoice::with(['items.product', 'customer'])->find($id);
                if (!$doc) return response()->json(['error' => 'Not found'], 404);
                return response()->json([
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
                    'items' => $doc->items->map(fn($i) => [
                        'product_code' => $i->product->sku ?? '',
                        'product_name' => $i->product->name ?? '',
                        'has_serial' => $i->product->has_serial ?? false,
                        'quantity' => $i->quantity,
                        'price' => (float) $i->price,
                        'discount' => (float) ($i->discount ?? 0),
                        'sell_price' => (float) ($i->price - ($i->discount ?? 0)),
                        'subtotal' => (float) $i->subtotal,
                    ]),
                    'subtotal' => (float) $doc->subtotal,
                    'discount' => (float) ($doc->discount ?? 0),
                    'total' => (float) $doc->total,
                    'customer_paid' => (float) ($doc->customer_paid ?? 0),
                ]);

            case 'purchase':
                $doc = \App\Models\Purchase::with(['items.product', 'supplier', 'user'])->find($id);
                if (!$doc) return response()->json(['error' => 'Not found'], 404);
                return response()->json([
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

            default:
                return response()->json(['error' => 'Unknown document type'], 400);
        }
    }

    /**
     * Check if a product has related transaction data.
     */
    private function productHasTransactions(int $productId): array
    {
        $relations = [];

        if (\App\Models\InvoiceItem::where('product_id', $productId)->exists()) {
            $relations[] = 'hóa đơn bán hàng';
        }
        if (\App\Models\PurchaseItem::where('product_id', $productId)->exists()) {
            $relations[] = 'phiếu nhập hàng';
        }
        if (\Illuminate\Support\Facades\DB::table('order_items')->where('product_id', $productId)->exists()) {
            $relations[] = 'đơn hàng';
        }
        if (\Illuminate\Support\Facades\DB::table('return_items')->where('product_id', $productId)->exists()) {
            $relations[] = 'phiếu trả hàng';
        }
        if (SerialImei::where('product_id', $productId)->exists()) {
            $relations[] = 'serial/IMEI';
        }
        if (\Illuminate\Support\Facades\DB::table('task_parts')->where('product_id', $productId)->exists()) {
            $relations[] = 'công việc sửa chữa';
        }
        if (\Illuminate\Support\Facades\DB::table('stock_transfer_items')->where('product_id', $productId)->exists()) {
            $relations[] = 'chuyển kho';
        }
        if (\Illuminate\Support\Facades\DB::table('damage_items')->where('product_id', $productId)->exists()) {
            $relations[] = 'xuất hủy';
        }
        if (\Illuminate\Support\Facades\DB::table('stock_take_items')->where('product_id', $productId)->exists()) {
            $relations[] = 'kiểm kho';
        }

        return $relations;
    }

    public function destroy(Product $product)
    {
        $relations = $this->productHasTransactions($product->id);

        if (!empty($relations)) {
            // Has transactions → deactivate instead of delete
            $product->update(['is_active' => false]);
            $list = implode(', ', $relations);
            return redirect()->back()->with('warning', "Hàng hóa \"{$product->name}\" đang có phát sinh ({$list}) nên không thể xóa. Đã chuyển sang trạng thái Ngừng kinh doanh.");
        }

        $product->delete();
        return redirect()->back()->with('success', "Đã xoá hàng hóa \"{$product->name}\"!");
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'integer|exists:products,id',
        ]);

        $products = Product::whereIn('id', $request->product_ids)->get();
        $deleted = 0;
        $deactivated = 0;
        $deactivatedNames = [];

        foreach ($products as $product) {
            $relations = $this->productHasTransactions($product->id);

            if (!empty($relations)) {
                $product->update(['is_active' => false]);
                $deactivated++;
                $deactivatedNames[] = $product->name;
            } else {
                $product->delete();
                $deleted++;
            }
        }

        $messages = [];
        if ($deleted > 0) {
            $messages[] = "Đã xóa {$deleted} hàng hóa";
        }
        if ($deactivated > 0) {
            $names = implode(', ', array_slice($deactivatedNames, 0, 3));
            if ($deactivated > 3) $names .= '...';
            $messages[] = "{$deactivated} hàng hóa có phát sinh giao dịch ({$names}) đã chuyển sang Ngừng kinh doanh";
        }

        $msg = implode('. ', $messages) . '.';
        $type = $deactivated > 0 ? 'warning' : 'success';

        return redirect()->back()->with($type, $msg);
    }

    public function export(Request $request)
    {
        $products = Product::with(['category', 'brand'])
            ->when($request->search, fn($q, $s) => $q->where('name', 'LIKE', "%{$s}%")->orWhere('sku', 'LIKE', "%{$s}%"))
            ->orderBy('id', 'desc')->get();

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
        ]);

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
            ]);
            $created++;
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
        ]);

        $serial->update($data);

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

        $serial->delete();

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
