<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Product;
use App\Models\Customer;
use App\Models\CashFlow;
use App\Models\SerialImei;
use App\Enums\PaymentMethod;
use App\Enums\PurchaseStatus;
use App\Support\Filters\FilterableIndex;
use App\Services\LockPeriodService;
use App\Services\StockMovementService;
use App\Support\Debt\PartnerDebtDisplayBalance;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use App\Services\DebtOffsetService;

class PurchaseController extends Controller
{
    use FilterableIndex;

    protected function configurePurchaseFilters(): void
    {
        $this->searchable = ['code', 'note'];
        $this->searchableRelations = [
            'supplier' => ['name', 'code', 'phone'],
            'items'    => ['product_name'],
        ];
        $this->sortable = ['code', 'created_at', 'total_amount', 'discount', 'paid_amount', 'debt_amount', 'status', 'purchase_date'];
        $this->dateColumn = \Illuminate\Support\Facades\Schema::hasColumn('purchases', 'purchase_date')
            ? \Illuminate\Support\Facades\DB::raw('COALESCE(purchases.purchase_date, purchases.created_at)')
            : 'created_at';
        $this->creatorColumn = 'employee_id';
        $this->scalarFilters = ['branch_id', 'supplier_id', 'warehouse_id', 'payment_method', 'status'];
    }

    public function index(Request $request)
    {
        $this->configurePurchaseFilters();

        $query = Purchase::with(['supplier:id,code,name', 'items', 'employee:id,name', 'user:id,name'])
            ->when($request->filled('has_debt'), function ($q) use ($request) {
                if ((string) $request->input('has_debt') === '1') {
                    $q->where('debt_amount', '>', 0);
                } else {
                    $q->where(function ($qq) {
                        $qq->whereNull('debt_amount')->orWhere('debt_amount', '<=', 0);
                    });
                }
            })
            ->when($request->filled('sort_by') && in_array($request->sort_by, ['need_pay', 'purchase_date']), function ($q) use ($request) {
                $dir = ($request->sort_dir ?? $request->sort_direction) === 'asc' ? 'asc' : 'desc';
                if ($request->sort_by === 'need_pay') {
                    $q->orderByRaw("(total_amount - COALESCE(discount, 0)) $dir");
                } elseif ($request->sort_by === 'purchase_date') {
                    $expr = \Illuminate\Support\Facades\Schema::hasColumn('purchases', 'purchase_date')
                        ? "COALESCE(purchase_date, created_at) $dir"
                        : "created_at $dir";
                    $q->orderByRaw($expr);
                }
            });

        // Only apply standard sort if not using computed sort
        if (!in_array($request->sort_by, ['need_pay', 'purchase_date'])) {
            $this->applyFilters($query, $request);
        } else {
            // Apply everything except sort
            $originalSortable = $this->sortable;
            $this->sortable = [];
            $this->applyFilters($query, $request);
            $this->sortable = $originalSortable;
        }

        $purchases = $query->paginate(20)->withQueryString();

        // Summary using same filters
        $summaryQuery = Purchase::query();
        $this->applyFilters($summaryQuery, $request);
        if (!$request->filled('status')) {
            $summaryQuery->where('status', '!=', 'cancelled');
        }

        $summary = [
            'total_amount' => (clone $summaryQuery)->sum('total_amount'),
            'total_discount' => (clone $summaryQuery)->sum('discount'),
            'total_paid' => (clone $summaryQuery)->sum('paid_amount'),
            'total_debt' => (clone $summaryQuery)->sum('debt_amount'),
            'total_count' => (clone $summaryQuery)->count(),
            'total_items' => (clone $summaryQuery)->join('purchase_items', 'purchases.id', '=', 'purchase_items.purchase_id')->sum('purchase_items.quantity'),
        ];

        $suppliers = app(\App\Services\PartnerTransactionGuard::class)->availablePartners()
            ->where('is_supplier', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
        $employees = \App\Models\Employee::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);

        return Inertia::render('Purchases/Index', [
            'purchases' => $purchases,
            'filters' => $this->currentFilters($request) + [
                'has_debt' => $request->input('has_debt', ''),
            ],
            'summary' => $summary,
            'suppliers' => $suppliers,
            'employees' => $employees,
            'filterOptions' => [
                'branches' => \App\Models\Branch::select('id', 'name')->get(),
                'statuses' => PurchaseStatus::options(),
                'suppliers' => $suppliers->map(fn($s) => ['value' => $s->id, 'label' => $s->name]),
                'employees' => $employees->map(fn($e) => ['value' => $e->id, 'label' => $e->name]),
                'paymentMethods' => PaymentMethod::basicOptions(),
                'debtOptions' => [
                    ['value' => '1', 'label' => 'Còn nợ NCC'],
                    ['value' => '0', 'label' => 'Đã trả đủ'],
                ],
            ],
        ]);
    }

    public function create(Request $request)
    {
        // HOTFIX 24.19 — only active suppliers may be picked when
        // creating a purchase. Deactivated rows stay on /suppliers
        // (admin view) but the Nhập hàng selector must hide them so
        // operators can't open new debt against a stopped vendor.
        // Customer.status defaults to 'active' (migration
        // 2026_02_28_063352_add_supplier_fields_to_customers_table);
        // we also accept NULL to be tolerant of any pre-default rows.
        $suppliers = app(\App\Services\PartnerTransactionGuard::class)->availablePartners()
            ->where('is_supplier', true)
            ->get()
            ->map(fn (Customer $supplier) => $this->withSupplierDebtDisplayAliases($supplier));

        $purchaseOrderInfo = null;
        if ($request->has('purchase_order_id')) {
            $po = \App\Models\PurchaseOrder::with('items.product')->find($request->purchase_order_id);
            if ($po) {
                $purchaseOrderInfo = [
                    'supplier_id' => $po->supplier_id,
                    'discount' => collect($po->items)->sum('discount') + $po->discount,
                    'items' => $po->items->map(function ($item) {
                        return [
                            'product_id' => $item->product_id,
                            'name' => $item->product ? $item->product->name : '',
                            'sku' => $item->product ? $item->product->sku : '',
                            'quantity' => $item->qty,
                            'price' => $item->price,
                            'discount' => 0,
                            'stock_quantity' => $item->product ? $item->product->stock_quantity : 0,
                        ];
                    })
                ];
            }
        }

        // Check if any active price book enables retail/technician price columns
        $priceBooks = \App\Models\PriceBook::where('is_active', true)->get();
        $showRetailPrice = $priceBooks->contains('enable_retail_price', true);
        $showTechnicianPrice = $priceBooks->contains('enable_technician_price', true);

        $sellerResolver = new \App\Support\Reports\SellerResolver();
        return Inertia::render('Purchases/Create', [
            'suppliers' => $suppliers,
            'employees' => $sellerResolver->buildInvoiceSellerOptions(),
            'categories' => \App\Models\Category::with('children')->whereNull('parent_id')->orderBy('name')->get(),
            'brands' => \App\Models\Brand::all(),
            'purchaseCode' => 'PN' . date('YmdHis'),
            'purchaseOrderInfo' => $purchaseOrderInfo,
            'showRetailPrice' => $showRetailPrice,
            'showTechnicianPrice' => $showTechnicianPrice,
            'bankAccounts' => \App\Models\BankAccount::where('status', 'active')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'supplier_id' => 'required|exists:customers,id',
            'employee_id' => 'nullable|string',
            'purchase_date' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:0',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.retail_price' => 'nullable|numeric|min:0',
            'items.*.technician_price' => 'nullable|numeric|min:0',
            'items.*.serials' => 'nullable|array',
            'items.*.serials.*' => 'string|max:100',
            'items.*.warranty_months' => 'nullable|integer|min:0',
            'discount' => 'nullable|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'note' => 'nullable|string',
            'payment_method' => 'nullable|string|in:cash,transfer',
            'bank_account_info' => 'nullable|string',
            'other_costs' => 'nullable|array',
            'other_costs.*.name' => 'required_with:other_costs|string|max:255',
            'other_costs.*.amount' => 'required_with:other_costs|numeric|min:0',
        ]);
        app(\App\Services\PartnerTransactionGuard::class)->assertCanTransact(
            (int) $request->supplier_id,
            'supplier_id'
        );

        // ── Step 23.3: Validate serial cho hàng has_serial khi nhập ──
        // BUG-1: count(serials) phải === quantity (không cho phiếu nhập 5 mà chỉ liệt 2 serial).
        // BUG-2: chống trùng serial trong cùng request (chống user dán nhầm 2 lần).
        // EXISTING: chống serial đã tồn tại trong DB (giữ nguyên).
        $globalSeenSerials = [];
        foreach ($request->items as $i => $item) {
            $product = Product::find($item['product_id']);
            if ($product && $product->isService()) {
                return back()->withErrors([
                    "items.{$i}.product_id" => "Dịch vụ \"{$product->name}\" không quản lý tồn kho nên không thể nhập hàng.",
                ]);
            }
            if (!$product || !$product->has_serial) continue;

            $serials = array_values(array_filter(array_map(
                fn($s) => $this->normalizeSerial(is_string($s) ? $s : ''),
                (array) ($item['serials'] ?? [])
            ), fn($s) => $s !== ''));
            $qty = (int) ($item['quantity'] ?? 0);

            if (count($serials) === 0) {
                return back()->withErrors(["items.{$i}.serials" => "S\u1ea3n ph\u1ea9m \"{$product->name}\" y\u00eau c\u1ea7u nh\u1eadp s\u1ed1 Serial/IMEI."]);
            }
            if (count($serials) !== $qty) {
                return back()->withErrors(["items.{$i}.serials" => "S\u1ea3n ph\u1ea9m \"{$product->name}\" c\u1ea7n nh\u1eadp \u0111\u1ee7 {$qty} serial (\u0111ang nh\u1eadp " . count($serials) . ")."]);
            }
            // Duplicate trong cùng item
            if (count($serials) !== count(array_unique($serials))) {
                return back()->withErrors(["items.{$i}.serials" => "S\u1ea3n ph\u1ea9m \"{$product->name}\" c\u00f3 serial b\u1ecb tr\u00f9ng trong c\u00f9ng phi\u1ebfu nh\u1eadp."]);
            }
            // Duplicate cross-item
            foreach ($serials as $sn) {
                if (isset($globalSeenSerials[$sn])) {
                    return back()->withErrors(["items.{$i}.serials" => "Serial/IMEI \"{$sn}\" bị trùng trong phiếu nhập hiện tại."]);
                }
                $globalSeenSerials[$sn] = true;
            }
            // Đã tồn tại trong DB
            $existing = SerialImei::whereIn('serial_number', $serials)->first();
            if ($existing) {
                return back()->withErrors(["items.{$i}.serials" => "Serial/IMEI \"{$existing->serial_number}\" \u0111\u00e3 t\u1ed3n t\u1ea1i trong h\u1ec7 th\u1ed1ng."]);
            }
        }

        try {
            DB::beginTransaction();
            app(\App\Services\PartnerTransactionGuard::class)->assertCanTransact(
                (int) $request->supplier_id,
                'supplier_id'
            );

            // Parse employee_id / virtual admin user
            $employeeIdInput = $request->employee_id;
            $dbEmployeeId = null;
            $dbUserId = auth()->id();

            if ($employeeIdInput) {
                if (preg_match('/^employee:(\d+)$/', $employeeIdInput, $matches)) {
                    $dbEmployeeId = (int) $matches[1];
                    if (!\App\Models\Employee::where('is_active', true)->where('id', $dbEmployeeId)->exists()) {
                        return back()->withErrors(['employee_id' => 'Nhân viên không hợp lệ hoặc đã ngưng hoạt động.']);
                    }
                } elseif (preg_match('/^admin_user:(\d+)$/', $employeeIdInput, $matches)) {
                    $dbUserId = (int) $matches[1];
                    $dbEmployeeId = null;
                    $adminUser = \App\Models\User::find($dbUserId);
                    if (!$adminUser || ($adminUser->status ?? 'active') !== 'active' || !$adminUser->isAdmin()) {
                        return back()->withErrors(['employee_id' => 'Tài khoản admin không hợp lệ.']);
                    }
                } elseif (is_numeric($employeeIdInput)) {
                    $dbEmployeeId = (int) $employeeIdInput;
                    if (!\App\Models\Employee::where('is_active', true)->where('id', $dbEmployeeId)->exists()) {
                        return back()->withErrors(['employee_id' => 'Nhân viên không hợp lệ hoặc đã ngưng hoạt động.']);
                    }
                } else {
                    return back()->withErrors(['employee_id' => 'Người nhập không hợp lệ.']);
                }
            }

            // Lock period check
            $txDate = $request->purchase_date ? \Carbon\Carbon::parse($request->purchase_date) : now();
            app(LockPeriodService::class)->assertNotLocked($txDate, 'purchase_create');

            $total_amount = collect($request->items)->sum(function ($item) {
                return $item['quantity'] * $item['price'] - ($item['discount'] ?? 0);
            });

            $discount = $request->discount ?? 0;

            // Chi phí nhập khác
            $otherCosts = collect($request->other_costs ?? [])
                ->map(fn($c) => [
                    'name' => trim((string)($c['name'] ?? '')),
                    'amount' => round((float)($c['amount'] ?? 0), 2),
                ])
                ->filter(fn($c) => $c['name'] !== '' && $c['amount'] > 0)
                ->values()
                ->all();

            $otherCostsTotal = collect($otherCosts)->sum('amount');

            $pay_amount = $total_amount - $discount + $otherCostsTotal; // Total to pay
            $paid_amount = $request->paid_amount ?? 0;
            $debt_amount = $pay_amount - $paid_amount; // Current debt for this order

            $purchase = Purchase::create([
                'code' => $request->code ?? 'PN' . time(),
                'supplier_id' => $request->supplier_id,
                'user_id' => $dbUserId,
                'employee_id' => $dbEmployeeId,
                'total_amount' => $total_amount,
                'discount' => $discount,
                'other_costs' => !empty($otherCosts) ? $otherCosts : null,
                'other_costs_total' => $otherCostsTotal,
                'paid_amount' => $paid_amount,
                'debt_amount' => $debt_amount,
                'note' => $request->note,
                'status' => $request->status ?? 'completed',
                'purchase_date' => $request->purchase_date ?? now(),
                'payment_method' => $request->payment_method ?? 'cash',
                'bank_account_info' => $request->bank_account_info,
            ]);

            foreach ($request->items as $item) {
                $product = Product::find($item['product_id']);

                $warrantyMonths = $item['warranty_months'] ?? 0;
                $warrantyExpiresAt = $warrantyMonths > 0
                    ? ($purchase->purchase_date ?? now())->copy()->addMonths($warrantyMonths)->toDateString()
                    : null;

                // Phân bổ phí nhập (other_costs) theo tỉ lệ subtotal của dòng hiện tại
                $itemSubtotal = $item['quantity'] * $item['price'] - ($item['discount'] ?? 0);
                $allocatedFee = ($otherCostsTotal > 0 && $total_amount > 0)
                    ? ($otherCostsTotal * $itemSubtotal / $total_amount)
                    : 0.0;
                $unitCostAllocated = $item['quantity'] > 0
                    ? round(($itemSubtotal + $allocatedFee) / $item['quantity'], 2)
                    : (float) $item['price'];

                // Add item
                $purchase->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->sku,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'discount' => $item['discount'] ?? 0,
                    'subtotal' => $itemSubtotal,
                    'unit_cost_allocated' => $unitCostAllocated,
                    'warranty_months' => $warrantyMonths,
                    'warranty_expires_at' => $warrantyExpiresAt,
                ]);

                if ($purchase->status === 'completed') {
                    // BQ DI ĐỘNG: gọi service áp dụng nhập hàng (cập nhật stock + cost_price + inventory_total_cost)
                    \App\Services\MovingAvgCostingService::applyPurchase(
                        $product,
                        (int) $item['quantity'],
                        (float) $unitCostAllocated
                    );
                    $product->refresh();

                    // Update retail_price if provided
                    if (isset($item['retail_price']) && $item['retail_price'] > 0) {
                        $product->retail_price = $item['retail_price'];
                        $product->save();
                    }

                    // Update technician_price in active price books if provided
                    if (isset($item['technician_price']) && $item['technician_price'] > 0) {
                        $activeBooks = \App\Models\PriceBook::where('is_active', true)
                            ->where('enable_technician_price', true)->get();
                        foreach ($activeBooks as $book) {
                            \App\Models\PriceBookProduct::updateOrCreate(
                                ['price_book_id' => $book->id, 'product_id' => $product->id],
                                ['technician_price' => $item['technician_price'], 'price' => $item['retail_price'] ?? $product->retail_price ?? 0]
                            );
                        }
                    }

                    // Create Serial/IMEI records for products with serial tracking
                    if ($product->has_serial && !empty($item['serials'])) {
                        foreach ($item['serials'] as $serialNumber) {
                            SerialImei::create([
                                'product_id' => $product->id,
                                'serial_number' => $this->normalizeSerial($serialNumber),
                                'status' => 'in_stock',
                                'purchase_id' => $purchase->id,
                                'cost_price' => $unitCostAllocated,
                                'original_cost' => $unitCostAllocated,
                            ]);
                        }
                    }

                    // Sync stock_quantity với serial in_stock count (audit, không đụng cost)
                    if ($product->has_serial) {
                        $product->recomputeFromSerials();
                    }

                    // Phase 4 — Ghi sổ cái tồn kho
                    StockMovementService::record(
                        $product,
                        StockMovementService::TYPE_IN_PURCHASE,
                        (int) $item['quantity'],
                        (float) $unitCostAllocated,
                        $purchase,
                        [
                            'branch_id' => $purchase->branch_id ?? null,
                            'ref_code' => $purchase->code,
                            'moved_at' => $purchase->purchase_date ?? now(),
                            'note' => 'Nhập hàng từ phiếu ' . $purchase->code,
                        ]
                    );
                }
            }

            if ($purchase->status === 'completed') {
                // Update Supplier Debt & Total Bought
                $supplier = Customer::find($request->supplier_id);
                if ($supplier) {
                    // Auto-enable dual-role: buying from a customer makes them also a supplier
                    if ($supplier->is_customer && !$supplier->is_supplier) {
                        $supplier->is_supplier = true;
                    }

                    $supplier->supplier_debt_amount += $debt_amount;
                    $supplier->total_bought += $total_amount;
                    $supplier->save();
                }

                // Create Cash Flow if paid > 0 (Chi tiền trả NCC)
                if ($paid_amount > 0) {
                    CashFlow::create([
                        'code' => 'PC' . date('YmdHis'),
                        'type' => 'payment', // chi
                        'amount' => $paid_amount,
                        'time' => now(),
                        'category' => 'Chi tiền trả NCC',
                        'target_type' => 'Nhà cung cấp',
                        'target_name' => $supplier->name ?? 'Nhà cung cấp',
                        'reference_type' => 'Purchase',
                        'reference_code' => $purchase->code,
                        'description' => 'Chi tiền trả NCC cho phiếu ' . $purchase->code
                    ]);
                }

                // Note: Không gọi DebtOffsetService - unified ledger view tự xử lý bù trừ
            }

            DB::commit();

            // Step 24.0: audit log purchase create
            \App\Models\ActivityLog::log(
                \App\Models\ActivityLog::ACTION_PURCHASE_CREATE,
                "Tạo phiếu nhập hàng {$purchase->code}",
                $purchase,
                [
                    'total' => (float) ($purchase->total_amount ?? 0),
                    'paid_amount' => (float) ($purchase->paid_amount ?? 0),
                    'debt_amount' => (float) ($purchase->debt_amount ?? 0),
                    'status' => $purchase->status,
                ]
            );

            return redirect()->route('purchases.index')->with('success', 'Tạo đơn nhập hàng thành công!');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Có lỗi xảy ra: ' . $e->getMessage());
        }
    }

    public function show(Purchase $purchase)
    {
        $purchase->load(['supplier', 'items.product', 'user', 'employee']);

        // Fix quantity for serial products (old bug: saved as 0)
        $recalcTotal = false;
        foreach ($purchase->items as $item) {
            if ($item->product && $item->product->has_serial) {
                $serialCount = SerialImei::where('purchase_id', $purchase->id)
                    ->where('product_id', $item->product_id)->count();
                if ($item->quantity == 0 && $serialCount > 0) {
                    $item->quantity = $serialCount;
                    $item->subtotal = ($item->quantity * $item->price) - $item->discount;
                    $item->save();
                    $recalcTotal = true;
                }
            }
        }
        if ($recalcTotal) {
            $purchase->total_amount = $purchase->items->sum('subtotal');
            $purchase->debt_amount = ($purchase->total_amount - $purchase->discount) - $purchase->paid_amount;
            $purchase->save();
            $purchase->refresh();
            $purchase->load(['supplier', 'items.product', 'user', 'employee']);
        }

        // Load serials for each item (after save, to avoid dirty attributes)
        foreach ($purchase->items as $item) {
            if ($item->product && $item->product->has_serial) {
                $item->setRelation('serials', SerialImei::where('purchase_id', $purchase->id)
                    ->where('product_id', $item->product_id)
                    ->get(['id', 'serial_number', 'status']));
            } else {
                $item->setRelation('serials', collect([]));
            }
        }

        // Load payment history (cash flows)
        $purchase->cash_flows = CashFlow::where('reference_code', $purchase->code)
            ->where('reference_type', 'Purchase')
            ->orderBy('created_at', 'desc')
            ->get();

        // Load purchase returns for this purchase
        $purchaseReturns = PurchaseReturn::with(['items', 'user', 'employee'])
            ->where('purchase_id', $purchase->id)
            ->where('status', 'completed')
            ->get();

        // Calculate returned qty per product
        $returnedQty = PurchaseReturnItem::whereHas('purchaseReturn', function ($q) use ($purchase) {
            $q->where('purchase_id', $purchase->id)->where('status', 'completed');
        })->selectRaw('product_id, SUM(quantity) as total_returned')
            ->groupBy('product_id')->pluck('total_returned', 'product_id');

        foreach ($purchase->items as $item) {
            $item->returned_qty = $returnedQty[$item->product_id] ?? 0;
        }

        $sellerResolver = new \App\Support\Reports\SellerResolver();
        return Inertia::render('Purchases/Show', [
            'purchase' => $purchase,
            'purchaseReturns' => $purchaseReturns,
            'bankAccounts' => \App\Models\BankAccount::where('status', 'active')->get(),
            'employees' => $sellerResolver->buildInvoiceSellerOptions(),
        ]);
    }

    public function edit(Purchase $purchase)
    {
        if ($purchase->status === 'cancelled') {
            return redirect()->route('purchases.show', $purchase)->with('error', 'Không thể sửa phiếu nhập đã hủy.');
        }

        $purchase->load(['supplier', 'items.product', 'user', 'employee']);
        foreach ($purchase->items as $item) {
            if ($item->product && $item->product->has_serial) {
                $item->setRelation('serials', SerialImei::where('purchase_id', $purchase->id)
                    ->where('product_id', $item->product_id)
                    ->orderBy('serial_number')
                    ->get(['id', 'serial_number', 'status', 'product_id', 'purchase_id']));
                $item->quantity = $item->serials->count();
            } else {
                $item->setRelation('serials', collect([]));
            }
        }

        $suppliers = app(\App\Services\PartnerTransactionGuard::class)->availablePartners()
            ->where('is_supplier', true)
            ->get()
            ->map(fn (Customer $supplier) => $this->withSupplierDebtDisplayAliases($supplier));
        if ($purchase->supplier && !$suppliers->contains('id', $purchase->supplier_id)) {
            $suppliers->push($this->withSupplierDebtDisplayAliases($purchase->supplier));
        }

        $priceBooks = \App\Models\PriceBook::where('is_active', true)->get();
        $sellerResolver = new \App\Support\Reports\SellerResolver();

        return Inertia::render('Purchases/Edit', [
            'purchase' => $purchase,
            'suppliers' => $suppliers->values(),
            'employees' => $sellerResolver->buildInvoiceSellerOptions(),
            'showRetailPrice' => $priceBooks->contains('enable_retail_price', true),
            'showTechnicianPrice' => $priceBooks->contains('enable_technician_price', true),
            'bankAccounts' => \App\Models\BankAccount::where('status', 'active')->get(),
        ]);
    }

    public function update(Request $request, Purchase $purchase)
    {
        $validated = $request->validate([
            'supplier_id' => 'sometimes|exists:customers,id',
            'note' => 'nullable|string|max:1000',
            'purchase_date' => 'required|date',
            'discount' => 'nullable|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'payment_method' => 'required|string|in:cash,transfer',
            'bank_account_info' => 'nullable|string',
            'employee_id' => 'nullable|string',
            'status' => 'nullable|string|in:draft,completed',
            'items' => 'sometimes|array|min:1',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0',
            'items.*.price' => 'required_with:items|numeric|min:0',
            'items.*.retail_price' => 'nullable|numeric|min:0',
            'items.*.technician_price' => 'nullable|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.serials' => 'nullable|array',
            'items.*.warranty_months' => 'nullable|integer|min:0',
            'other_costs' => 'nullable|array',
            'other_costs.*.name' => 'required_with:other_costs|string|max:255',
            'other_costs.*.amount' => 'required_with:other_costs|numeric|min:0',
        ]);

        if ($purchase->status === 'cancelled') {
            return back()->withErrors(['purchase' => 'Không thể sửa phiếu nhập đã hủy.']);
        }

        $validated['supplier_id'] = $validated['supplier_id'] ?? $purchase->supplier_id;

        try {
            app(\App\Services\PartnerTransactionGuard::class)->assertCanTransact(
                (int) $validated['supplier_id'],
                'supplier_id'
            );

            app(LockPeriodService::class)->assertNotLocked($purchase->purchase_date ?? $purchase->created_at, 'purchase_update');
            app(LockPeriodService::class)->assertNotLocked($validated['purchase_date'], 'purchase_update');
        } catch (\App\Exceptions\LockPeriodException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        try {
            DB::beginTransaction();

            $purchase = Purchase::with(['items.product', 'supplier'])->lockForUpdate()->findOrFail($purchase->id);
            $oldStatus = $purchase->status;
            $newStatus = $validated['status'] ?? $purchase->status;
            if ($oldStatus === 'completed' && $newStatus !== 'completed') {
                throw new \RuntimeException('Không thể chuyển phiếu nhập đã hoàn thành về phiếu tạm.');
            }
            $wasStocked = $oldStatus === 'completed';
            $willBeStocked = $newStatus === 'completed';
            $actor = $this->resolvePurchaseActor($validated['employee_id'] ?? null, $purchase);
            $oldEmployeeId = $purchase->employee_id;
            $oldUserId = $purchase->user_id;
            $oldNote = $purchase->note;
            $oldSupplierId = (int) $purchase->supplier_id;
            $newSupplierId = (int) $validated['supplier_id'];
            $oldTotalAmount = (float) $purchase->total_amount;
            $oldPaidAmount = (float) $purchase->paid_amount;
            $oldDebt = (float) $purchase->debt_amount;
            $oldPurchaseDate = $purchase->purchase_date ?? $purchase->created_at;
            $oldItems = $purchase->items->keyBy('product_id');
            $oldSerialsByProduct = SerialImei::where('purchase_id', $purchase->id)
                ->get()
                ->groupBy('product_id')
                ->map(fn($rows) => $rows->keyBy(fn($serial) => $this->normalizeSerial($serial->serial_number)));

            $itemPayload = $validated['items'] ?? $oldItems->map(function ($oldItem) use ($oldSerialsByProduct) {
                return [
                    'product_id' => $oldItem->product_id,
                    'quantity' => $oldItem->quantity,
                    'price' => $oldItem->price,
                    'retail_price' => $oldItem->product?->retail_price ?? 0,
                    'technician_price' => $oldItem->product?->technician_price ?? 0,
                    'discount' => $oldItem->discount ?? 0,
                    'serials' => $oldSerialsByProduct->get($oldItem->product_id, collect())->keys()->all(),
                    'warranty_months' => $oldItem->warranty_months ?? 0,
                ];
            })->values()->all();
            $normalizedItems = $this->normalizePurchaseUpdateItems($itemPayload);
            $otherCosts = collect($validated['other_costs'] ?? [])
                ->map(fn($c) => [
                    'name' => trim((string)($c['name'] ?? '')),
                    'amount' => round((float)($c['amount'] ?? 0), 2),
                ])
                ->filter(fn($c) => $c['name'] !== '' && $c['amount'] > 0)
                ->values()
                ->all();
            $otherCostsTotal = collect($otherCosts)->sum('amount');
            $discount = (float) ($validated['discount'] ?? 0);
            $paidAmount = (float) ($validated['paid_amount'] ?? 0);
            $totalAmount = 0.0;
            $changedItems = [];
            $changedSerials = [];
            $productSerialFlags = Product::whereIn('id', collect($normalizedItems)->pluck('product_id'))
                ->pluck('has_serial', 'id');
            $goodsTotalForAllocation = collect($normalizedItems)->sum(function ($item) use ($productSerialFlags) {
                $quantity = $productSerialFlags->get($item['product_id'])
                    ? count(array_unique($item['serials']))
                    : (int) $item['quantity'];

                return max(0, ($quantity * (float) $item['price']) - (float) $item['discount']);
            });

            foreach ($normalizedItems as $itemData) {
                $product = Product::lockForUpdate()->findOrFail($itemData['product_id']);
                if ($product->isService()) {
                    throw new \RuntimeException("Dịch vụ \"{$product->name}\" không quản lý tồn kho nên không thể nhập hàng.");
                }
                $oldItem = $oldItems->get($product->id);
                $oldQty = ($wasStocked && $oldItem) ? (int) $oldItem->quantity : 0;
                $oldPrice = $oldItem ? (float) $oldItem->price : 0.0;
                $oldUnitCost = $oldItem ? (float) ($oldItem->unit_cost_allocated ?? $oldItem->price) : 0.0;
                $oldDiscount = $oldItem ? (float) $oldItem->discount : 0.0;
                $oldWarranty = $oldItem ? (int) ($oldItem->warranty_months ?? 0) : 0;
                $allocationQty = $product->has_serial
                    ? count(array_unique($itemData['serials']))
                    : (int) $itemData['quantity'];
                $lineSubtotalForAllocation = ($allocationQty * (float) $itemData['price']) - (float) $itemData['discount'];
                if ($lineSubtotalForAllocation < -0.01) {
                    throw new \RuntimeException("Chiết khấu sản phẩm \"{$product->name}\" không được lớn hơn thành tiền dòng hàng.");
                }
                $allocatedOtherCost = $goodsTotalForAllocation > 0
                    ? ($otherCostsTotal * max(0, $lineSubtotalForAllocation) / $goodsTotalForAllocation)
                    : 0.0;
                $unitCostAllocated = $allocationQty > 0
                    ? (max(0, $lineSubtotalForAllocation) + $allocatedOtherCost) / $allocationQty
                    : (float) $itemData['price'];
                $costChanged = abs($unitCostAllocated - $oldUnitCost) >= 0.0001;

                if ($product->has_serial) {
                    $serials = array_values(array_unique($itemData['serials']));
                    $newQty = $willBeStocked ? count($serials) : 0;
                    $oldSerials = $oldSerialsByProduct->get($product->id, collect());
                    $oldSerialNumbers = $wasStocked ? $oldSerials->keys()->all() : [];
                    $serialsAdded = array_values(array_diff($serials, $oldSerialNumbers));
                    $serialsRemoved = array_values(array_diff($oldSerialNumbers, $serials));
                    $hasLockedSerial = $oldSerials->contains(fn($serial) => $serial->status !== 'in_stock');

                    if ($willBeStocked && count($serials) === 0) {
                        throw new \RuntimeException("Sản phẩm \"{$product->name}\" quản lý Serial/IMEI cần nhập đủ số serial theo số lượng.");
                    }
                    if ($hasLockedSerial && ((float) $itemData['price'] !== $oldPrice || $costChanged || !empty($serialsRemoved))) {
                        throw new \RuntimeException("Không thể sửa đơn giá hoặc xóa serial vì hàng trong phiếu đã phát sinh giao dịch sau nhập.");
                    }
                    foreach ($serialsRemoved as $serialNumber) {
                        $serial = $oldSerials->get($serialNumber);
                        if (!$serial || $serial->status !== 'in_stock') {
                            throw new \RuntimeException("Không thể xóa Serial/IMEI \"{$serialNumber}\" vì serial này đã phát sinh giao dịch sau nhập.");
                        }
                    }
                    foreach ($serialsAdded as $serialNumber) {
                        $exists = SerialImei::where('serial_number', $serialNumber)->first();
                        if ($exists) {
                            throw new \RuntimeException("Serial/IMEI \"{$serialNumber}\" đã tồn tại trong hệ thống.");
                        }
                    }

                    $addedSerialRows = [];
                    if ($willBeStocked) {
                        foreach ($serialsAdded as $serialNumber) {
                            $addedSerialRows[] = SerialImei::create([
                                'product_id' => $product->id,
                                'serial_number' => $serialNumber,
                                'status' => 'in_stock',
                                'purchase_id' => $purchase->id,
                                'cost_price' => $unitCostAllocated,
                                'original_cost' => $unitCostAllocated,
                            ]);
                        }
                    }

                    if ($willBeStocked) {
                        $deltaQty = $newQty - $oldQty;
                        if ($deltaQty > 0) {
                            \App\Services\MovingAvgCostingService::applyPurchase($product, $deltaQty, $unitCostAllocated);
                        } elseif ($deltaQty < 0) {
                            if ((int) $product->stock_quantity < abs($deltaQty)) {
                                throw new \RuntimeException('Không thể giảm số lượng vì hàng đã được bán/xuất hoặc tồn kho hiện tại không đủ để điều chỉnh.');
                            }
                            \App\Services\MovingAvgCostingService::applyPurchaseReturn($product, abs($deltaQty), $oldUnitCost);
                        } elseif ($costChanged && !$hasLockedSerial) {
                            $costDelta = $newQty * ($unitCostAllocated - $oldUnitCost);
                            \App\Services\MovingAvgCostingService::applyRepairAdjustment($product, $costDelta);
                        }
                        $product->refresh();
                        foreach ($addedSerialRows as $serial) {
                            StockMovementService::record($product, StockMovementService::TYPE_ADJUST_IN, 1, $unitCostAllocated, $purchase, [
                                'serial_imei_id' => $serial->id,
                                'ref_code' => $purchase->code,
                                'moved_at' => $validated['purchase_date'],
                                'note' => 'Sửa phiếu nhập: thêm serial ' . $serial->serial_number,
                            ]);
                        }
                        foreach ($serialsRemoved as $serialNumber) {
                            $serial = $oldSerials->get($serialNumber);
                            StockMovementService::record($product, StockMovementService::TYPE_ADJUST_OUT, 1, $oldUnitCost, $purchase, [
                                'serial_imei_id' => $serial->id,
                                'ref_code' => $purchase->code,
                                'moved_at' => $validated['purchase_date'],
                                'note' => 'Sửa phiếu nhập: xóa serial ' . $serialNumber,
                            ]);
                            $serial->delete();
                        }
                        foreach ($oldSerials as $serial) {
                            if (in_array($this->normalizeSerial($serial->serial_number), $serials, true)) {
                                $serial->forceFill([
                                    'cost_price' => $unitCostAllocated,
                                    'original_cost' => $unitCostAllocated,
                                    'warranty_expires_at' => $itemData['warranty_months'] > 0
                                        ? \Carbon\Carbon::parse($validated['purchase_date'])->copy()->addMonths($itemData['warranty_months'])
                                        : null,
                                ])->save();
                            }
                        }
                        $product->recomputeFromSerials();
                    }

                    $itemQty = count($serials);
                    $changedSerials[] = [
                        'product_id' => $product->id,
                        'added' => $serialsAdded,
                        'removed' => $serialsRemoved,
                    ];
                } else {
                    $newQty = $willBeStocked ? (int) $itemData['quantity'] : 0;
                    if ($willBeStocked && $newQty <= 0) {
                        throw new \RuntimeException("Số lượng sản phẩm \"{$product->name}\" phải lớn hơn 0.");
                    }
                    if ($willBeStocked && $oldItem && $costChanged && (int) $product->stock_quantity < $oldQty) {
                        throw new \RuntimeException('Không thể sửa đơn giá nhập vì hàng trong phiếu đã phát sinh giao dịch sau nhập. Vui lòng tạo phiếu điều chỉnh giá vốn nếu cần.');
                    }
                    if ($willBeStocked) {
                        $deltaQty = $newQty - $oldQty;
                        if ($deltaQty > 0) {
                            \App\Services\MovingAvgCostingService::applyPurchase($product, $deltaQty, $unitCostAllocated);
                            StockMovementService::record($product, StockMovementService::TYPE_ADJUST_IN, $deltaQty, $unitCostAllocated, $purchase, [
                                'ref_code' => $purchase->code,
                                'moved_at' => $validated['purchase_date'],
                                'note' => 'Sửa phiếu nhập: tăng số lượng',
                            ]);
                        } elseif ($deltaQty < 0) {
                            if ((int) $product->stock_quantity < abs($deltaQty)) {
                                throw new \RuntimeException('Không thể giảm số lượng vì hàng đã được bán/xuất hoặc tồn kho hiện tại không đủ để điều chỉnh.');
                            }
                            \App\Services\MovingAvgCostingService::applyPurchaseReturn($product, abs($deltaQty), $oldUnitCost);
                            StockMovementService::record($product, StockMovementService::TYPE_ADJUST_OUT, abs($deltaQty), $oldUnitCost, $purchase, [
                                'ref_code' => $purchase->code,
                                'moved_at' => $validated['purchase_date'],
                                'note' => 'Sửa phiếu nhập: giảm số lượng',
                            ]);
                        } elseif ($oldItem && $costChanged) {
                            $costDelta = $newQty * ($unitCostAllocated - $oldUnitCost);
                            \App\Services\MovingAvgCostingService::applyRepairAdjustment($product, $costDelta);
                        }
                    }
                    $itemQty = (int) $itemData['quantity'];
                }

                if ((float) ($itemData['retail_price'] ?? 0) > 0) {
                    $product->retail_price = $itemData['retail_price'];
                }
                if ((float) ($itemData['technician_price'] ?? 0) > 0) {
                    $product->technician_price = $itemData['technician_price'];
                }
                $product->save();

                $itemSubtotal = ($itemQty * (float) $itemData['price']) - (float) $itemData['discount'];
                $totalAmount += $itemSubtotal;
                $warrantyExpiresAt = $itemData['warranty_months'] > 0
                    ? \Carbon\Carbon::parse($validated['purchase_date'])->copy()->addMonths($itemData['warranty_months'])->toDateString()
                    : null;

                $purchaseItem = $oldItem ?: new PurchaseItem(['purchase_id' => $purchase->id]);
                $purchaseItem->fill([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->sku,
                    'quantity' => $itemQty,
                    'price' => $itemData['price'],
                    'discount' => $itemData['discount'],
                    'subtotal' => $itemSubtotal,
                    'unit_cost_allocated' => $unitCostAllocated,
                    'warranty_months' => $itemData['warranty_months'],
                    'warranty_expires_at' => $warrantyExpiresAt,
                ])->save();

                $changedItems[] = [
                    'product_id' => $product->id,
                    'old_quantity' => $oldItem?->quantity,
                    'new_quantity' => $itemQty,
                    'old_price' => $oldPrice,
                    'new_price' => (float) $itemData['price'],
                    'old_discount' => $oldDiscount,
                    'new_discount' => (float) $itemData['discount'],
                    'old_warranty_months' => $oldWarranty,
                    'new_warranty_months' => (int) $itemData['warranty_months'],
                ];
            }

            $newProductIds = collect($normalizedItems)->pluck('product_id')->all();
            foreach ($oldItems as $oldItem) {
                if (in_array($oldItem->product_id, $newProductIds, true)) {
                    continue;
                }
                $product = Product::lockForUpdate()->find($oldItem->product_id);
                if (!$product) {
                    continue;
                }
                if ($wasStocked) {
                    if ($product->has_serial) {
                        $serials = SerialImei::where('purchase_id', $purchase->id)->where('product_id', $product->id)->get();
                        foreach ($serials as $serial) {
                            if ($serial->status !== 'in_stock') {
                                throw new \RuntimeException("Không thể xóa Serial/IMEI \"{$serial->serial_number}\" vì serial này đã phát sinh giao dịch sau nhập.");
                            }
                        }
                        \App\Services\MovingAvgCostingService::applyPurchaseReturn($product, (int) $oldItem->quantity, (float) ($oldItem->unit_cost_allocated ?? $oldItem->price));
                        $product->refresh();
                        foreach ($serials as $serial) {
                            StockMovementService::record($product, StockMovementService::TYPE_ADJUST_OUT, 1, (float) ($oldItem->unit_cost_allocated ?? $oldItem->price), $purchase, [
                                'serial_imei_id' => $serial->id,
                                'ref_code' => $purchase->code,
                                'moved_at' => $validated['purchase_date'],
                                'note' => 'Sửa phiếu nhập: xóa dòng serial',
                            ]);
                            $serial->delete();
                        }
                        $product->recomputeFromSerials();
                    } else {
                        if ((int) $product->stock_quantity < (int) $oldItem->quantity) {
                            throw new \RuntimeException('Không thể xóa dòng hàng vì tồn kho hiện tại không đủ để điều chỉnh.');
                        }
                        \App\Services\MovingAvgCostingService::applyPurchaseReturn($product, (int) $oldItem->quantity, (float) ($oldItem->unit_cost_allocated ?? $oldItem->price));
                        StockMovementService::record($product, StockMovementService::TYPE_ADJUST_OUT, (int) $oldItem->quantity, (float) ($oldItem->unit_cost_allocated ?? $oldItem->price), $purchase, [
                            'ref_code' => $purchase->code,
                            'moved_at' => $validated['purchase_date'],
                            'note' => 'Sửa phiếu nhập: xóa dòng hàng',
                        ]);
                    }
                }
                $oldItem->delete();
                $changedItems[] = [
                    'product_id' => $product->id,
                    'old_quantity' => $oldItem->quantity,
                    'new_quantity' => 0,
                    'old_price' => (float) $oldItem->price,
                    'new_price' => 0,
                    'removed' => true,
                ];
            }

            $payAmount = $totalAmount - $discount + $otherCostsTotal;
            $computedDebtAmount = $payAmount - $paidAmount;
            if ($payAmount < 0) {
                throw new \RuntimeException('Tổng tiền phiếu nhập không hợp lệ.');
            }
            $storedPaidAmount = $willBeStocked ? $paidAmount : 0.0;
            $storedDebtAmount = $willBeStocked ? $computedDebtAmount : 0.0;
            $oldLedgerDebt = $wasStocked ? $oldDebt : 0.0;
            $newLedgerDebt = $willBeStocked ? $storedDebtAmount : 0.0;
            $oldLedgerTotal = $wasStocked ? $oldTotalAmount : 0.0;
            $newLedgerTotal = $willBeStocked ? $totalAmount : 0.0;
            $oldLedgerPaid = $wasStocked ? $oldPaidAmount : 0.0;
            $newLedgerPaid = $willBeStocked ? $storedPaidAmount : 0.0;

            $purchase->update([
                'supplier_id' => $newSupplierId,
                'note' => $validated['note'] ?? null,
                'purchase_date' => $validated['purchase_date'],
                'total_amount' => $totalAmount,
                'discount' => $discount,
                'other_costs' => !empty($otherCosts) ? $otherCosts : null,
                'other_costs_total' => $otherCostsTotal,
                'paid_amount' => $storedPaidAmount,
                'debt_amount' => $storedDebtAmount,
                'payment_method' => $validated['payment_method'],
                'bank_account_info' => $validated['bank_account_info'] ?? null,
                'employee_id' => $actor['employee_id'],
                'user_id' => $actor['user_id'],
                'status' => $newStatus,
            ]);

            if ($oldSupplierId !== $newSupplierId) {
                if ($oldSupplier = Customer::lockForUpdate()->find($oldSupplierId)) {
                    $oldSupplier->supplier_debt_amount -= $oldLedgerDebt;
                    $oldSupplier->total_bought -= $oldLedgerTotal;
                    $oldSupplier->save();
                }
                if ($newSupplier = Customer::lockForUpdate()->find($newSupplierId)) {
                    $newSupplier->supplier_debt_amount += $newLedgerDebt;
                    $newSupplier->total_bought += $newLedgerTotal;
                    if ($newSupplier->is_customer && !$newSupplier->is_supplier) {
                        $newSupplier->is_supplier = true;
                    }
                    $newSupplier->save();
                }
            } elseif ($purchase->supplier) {
                $debtDiff = $newLedgerDebt - $oldLedgerDebt;
                $totalDiff = $newLedgerTotal - $oldLedgerTotal;
                $purchase->supplier->supplier_debt_amount += $debtDiff;
                $purchase->supplier->total_bought += $totalDiff;
                $purchase->supplier->save();
            }

            $purchase->unsetRelation('supplier');
            $purchase->load('supplier');

            $paymentChanged = abs($newLedgerPaid - $oldLedgerPaid) >= 0.01 || $oldSupplierId !== $newSupplierId;
            if ($paymentChanged) {
                $cashFlows = CashFlow::where('reference_type', 'Purchase')
                    ->where('reference_code', $purchase->code)
                    ->where(function ($q) {
                        $q->whereNull('status')->orWhere('status', '!=', 'cancelled');
                    })
                    ->orderBy('id')
                    ->get();
                foreach ($cashFlows as $cashFlow) {
                    app(LockPeriodService::class)->assertNotLocked($cashFlow->time ?? $cashFlow->created_at, 'purchase_payment_update');
                }

                if ($newLedgerPaid > 0) {
                    $cashFlow = $cashFlows->first() ?: new CashFlow([
                        'code' => 'PC' . date('YmdHis') . rand(10, 99),
                        'type' => 'payment',
                        'reference_type' => 'Purchase',
                        'reference_code' => $purchase->code,
                    ]);
                    $cashFlow->forceFill([
                        'amount' => $newLedgerPaid,
                        'time' => now(),
                        'category' => 'Chi tiền trả NCC',
                        'target_type' => 'Nhà cung cấp',
                        'target_name' => $purchase->supplier->name ?? 'Nhà cung cấp',
                        'description' => 'Cập nhật tiền trả NCC cho phiếu ' . $purchase->code,
                        'status' => null,
                        'deleted_at' => null,
                    ])->save();

                    foreach ($cashFlows->skip(1) as $extraFlow) {
                        $extraFlow->forceFill([
                            'status' => 'cancelled',
                            'cancel_reason' => 'Gộp phiếu chi khi sửa phiếu nhập',
                            'cancelled_by' => auth()->id(),
                            'cancelled_at' => now(),
                        ])->save();
                        $extraFlow->delete();
                    }
                } else {
                    foreach ($cashFlows as $cashFlow) {
                        $cashFlow->forceFill([
                            'status' => 'cancelled',
                            'cancel_reason' => 'Sửa phiếu nhập về tiền trả NCC bằng 0',
                            'cancelled_by' => auth()->id(),
                            'cancelled_at' => now(),
                        ])->save();
                        $cashFlow->delete();
                    }
                }
            }

            \App\Models\ActivityLog::log(
                \App\Models\ActivityLog::ACTION_PURCHASE_UPDATE,
                "Cập nhật phiếu nhập {$purchase->code}",
                $purchase,
                [
                    'old_total_amount' => $oldTotalAmount,
                    'new_total_amount' => $totalAmount,
                    'old_paid_amount' => $oldPaidAmount,
                    'new_paid_amount' => $storedPaidAmount,
                    'old_debt_amount' => $oldDebt,
                    'new_debt_amount' => $storedDebtAmount,
                    'changed_items' => $changedItems,
                    'changed_serials' => $changedSerials,
                    'changed_payment' => $paymentChanged,
                    'changed_employee' => $oldEmployeeId !== $actor['employee_id'] || $oldUserId !== $actor['user_id'],
                    'changed_note' => $oldNote !== ($validated['note'] ?? null),
                    'old_purchase_date' => optional($oldPurchaseDate)->toDateTimeString(),
                    'new_purchase_date' => \Carbon\Carbon::parse($validated['purchase_date'])->toDateTimeString(),
                    'old_supplier_id' => $oldSupplierId,
                    'new_supplier_id' => $newSupplierId,
                ]
            );

            DB::commit();
            return redirect()->route('purchases.show', $purchase->id)->with('success', 'Cập nhật phiếu nhập hàng thành công!');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Có lỗi xảy ra: ' . $e->getMessage());
        }
    }

    public function destroy(Request $request, Purchase $purchase)
    {
        $validated = $request->validate([
            'cancel_reason' => 'required|string|min:5|max:500',
        ], [
            'cancel_reason.required' => 'Vui lòng nhập lý do hủy phiếu nhập.',
            'cancel_reason.min' => 'Lý do hủy phải có ít nhất 5 ký tự.',
        ]);
        $cancelReason = trim($validated['cancel_reason']);

        // Lock period check
        app(LockPeriodService::class)->assertNotLocked($purchase->purchase_date ?? $purchase->created_at, 'purchase_cancel');

        if ($purchase->status === 'cancelled') {
            return back()->with('error', 'Phiếu này đã bị hủy trước đó.');
        }

        if ($purchase->status !== 'completed') {
            $purchase->update([
                'status' => 'cancelled',
                'cancel_reason' => $cancelReason,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
            ]);
            return redirect()->route('purchases.index')->with('success', 'Đã hủy phiếu nhập.');
        }


        try {
            DB::beginTransaction();

            $costingMethod = \App\Models\Setting::get('inventory_costing_method', 'average');

            // Reverse stock & cost price changes
            foreach ($purchase->items as $item) {
                $product = Product::find($item->product_id);
                if (!$product) continue;

                // Check if serial products have been sold
                if ($product->has_serial) {
                    $soldSerials = SerialImei::where('purchase_id', $purchase->id)
                        ->where('product_id', $item->product_id)
                        ->where('status', '!=', 'in_stock')
                        ->count();
                    if ($soldSerials > 0) {
                        DB::rollBack();
                        return back()->with('error', "Không thể hủy: sản phẩm \"{$product->name}\" đã có {$soldSerials} serial đã bán/sử dụng.");
                    }
                }

                if (!$product->has_serial && (float) $product->stock_quantity < (float) $item->quantity) {
                    throw new \Exception('Không thể hủy phiếu nhập vì hàng đã được bán/xuất hoặc tồn kho hiện tại không đủ để đảo phiếu.');
                }

                // BQ DI ĐỘNG: rút khỏi tồn theo cost lúc nhập (snapshot unit_cost_allocated)
                $unitCostAtPurchase = (float) ($item->unit_cost_allocated ?: $item->price);
                \App\Services\MovingAvgCostingService::applyPurchaseReturn(
                    $product,
                    (int) $item->quantity,
                    $unitCostAtPurchase
                );
                $product->refresh();

                // Delete serials
                SerialImei::where('purchase_id', $purchase->id)
                    ->where('product_id', $item->product_id)
                    ->delete();

                if ($product->has_serial) {
                    $product->recomputeFromSerials();
                }

                // Phase 4 — Ghi sổ cái: hoàn nhập (out)
                StockMovementService::record(
                    $product,
                    StockMovementService::TYPE_OUT_PURCHASE_RETURN,
                    (int) $item->quantity,
                    $unitCostAtPurchase,
                    $purchase,
                    [
                        'branch_id' => $purchase->branch_id ?? null,
                        'ref_code' => $purchase->code,
                        'moved_at' => now(),
                        'note' => 'Hủy phiếu nhập ' . $purchase->code,
                    ]
                );
            }
            if ($purchase->supplier) {
                $purchase->supplier->supplier_debt_amount -= $purchase->debt_amount;
                $purchase->supplier->total_bought -= $purchase->total_amount;
                $purchase->supplier->save();
            }

            // Cancel related cash flows (payments to supplier)
            $relatedCashFlows = CashFlow::withTrashed()
                ->where('reference_type', 'Purchase')
                ->where('reference_code', $purchase->code)
                ->where(function ($q) {
                    $q->whereNull('status')->orWhere('status', '!=', 'cancelled');
                })
                ->get();
            foreach ($relatedCashFlows as $cashFlow) {
                $cashFlow->forceFill([
                    'status' => 'cancelled',
                    'cancel_reason' => $cancelReason,
                    'cancelled_by' => auth()->id(),
                    'cancelled_at' => now(),
                ])->save();
                if (!$cashFlow->trashed()) {
                    $cashFlow->delete();
                }
            }

            // Giữ items cho audit trail (không xóa)
            $purchase->status = 'cancelled';
            $purchase->cancel_reason = $cancelReason;
            $purchase->cancelled_by = auth()->id();
            $purchase->cancelled_at = now();
            $purchase->save();

            DB::commit();

            // Step 24.0: audit log purchase cancel
            \App\Models\ActivityLog::log(
                \App\Models\ActivityLog::ACTION_PURCHASE_DELETE,
                "Hủy phiếu nhập hàng {$purchase->code}",
                $purchase,
                ['total' => (float) ($purchase->total ?? 0)]
            );

            return redirect()->route('purchases.index')->with('success', 'Đã hủy phiếu nhập hàng. Tồn kho, giá vốn và công nợ đã được hoàn lại.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Lỗi: ' . $e->getMessage());
        }
    }

    public function export(Request $request)
    {
        $this->configurePurchaseFilters();
        $query = Purchase::with('supplier');
        $this->applyFilters($query, $request);
        if (!$request->filled('status')) {
            $query->where('status', 'completed');
        }
        $purchases = $query->get();

        return \App\Services\CsvService::export(
            ['Mã nhập hàng', 'Thời gian', 'Nhà cung cấp', 'Tổng cộng', 'Giảm giá', 'Đã trả NCC', 'Còn nợ NCC', 'Trạng thái', 'Ghi chú'],
            $purchases->map(fn($p) => [$p->code, $p->created_at?->format('d/m/Y H:i'), $p->supplier?->name, $p->total_amount, $p->discount, $p->paid_amount, $p->debt_amount, $p->status, $p->note]),
            'nhap_hang.csv'
        );
    }

    public function print(\App\Models\Purchase $purchase)
    {
        $purchase->load(['items.product', 'supplier']);
        return view('prints.purchase', compact('purchase'));
    }

    public function detail(Purchase $purchase)
    {
        $purchase->load(['supplier', 'items.product', 'user', 'employee']);

        return response()->json([
            'id' => $purchase->id,
            'code' => $purchase->code,
            'status' => $purchase->status,
            'status_label' => $purchase->status === 'completed' ? 'Đã nhập hàng' : ($purchase->status === 'returned' ? 'Đã trả hàng' : ($purchase->status === 'cancelled' ? 'Đã hủy' : ucfirst($purchase->status))),
            'purchase_date' => $purchase->purchase_date ? $purchase->purchase_date->format('d/m/Y H:i') : ($purchase->created_at ? $purchase->created_at->format('d/m/Y H:i') : ''),
            'user_name' => $purchase->user->name ?? 'Admin',
            'employee_name' => $purchase->employee->name ?? null,
            'supplier_name' => $purchase->supplier->name ?? '',
            'supplier_code' => $purchase->supplier->code ?? '',
            'note' => $purchase->note,
            'total_amount' => $purchase->total_amount,
            'discount' => $purchase->discount,
            'paid_amount' => $purchase->paid_amount,
            'debt_amount' => $purchase->debt_amount,
            'payment_method' => $purchase->payment_method,
            'items' => $purchase->items->map(fn($item) => [
                'product_code' => $item->product->code ?? '',
                'product_name' => $item->product->name ?? '',
                'quantity' => $item->quantity,
                'price' => $item->price,
                'discount' => $item->discount ?? 0,
                'subtotal' => $item->subtotal,
            ]),
        ]);
    }

    private function resolvePurchaseActor(?string $employeeIdInput, Purchase $purchase): array
    {
        $dbEmployeeId = $purchase->employee_id;
        $dbUserId = $purchase->user_id ?: auth()->id();

        if ($employeeIdInput === null || $employeeIdInput === '') {
            return ['employee_id' => null, 'user_id' => auth()->id()];
        }

        if (preg_match('/^employee:(\d+)$/', $employeeIdInput, $matches)) {
            $dbEmployeeId = (int) $matches[1];
            if (!\App\Models\Employee::where('is_active', true)->where('id', $dbEmployeeId)->exists()) {
                throw new \RuntimeException('Nhân viên không hợp lệ hoặc đã ngưng hoạt động.');
            }
            return ['employee_id' => $dbEmployeeId, 'user_id' => auth()->id()];
        }

        if (preg_match('/^admin_user:(\d+)$/', $employeeIdInput, $matches)) {
            $dbUserId = (int) $matches[1];
            $adminUser = \App\Models\User::find($dbUserId);
            if (!$adminUser || ($adminUser->status ?? 'active') !== 'active' || !$adminUser->isAdmin()) {
                throw new \RuntimeException('Tài khoản admin không hợp lệ.');
            }
            return ['employee_id' => null, 'user_id' => $dbUserId];
        }

        if (is_numeric($employeeIdInput)) {
            $dbEmployeeId = (int) $employeeIdInput;
            if (!\App\Models\Employee::where('is_active', true)->where('id', $dbEmployeeId)->exists()) {
                throw new \RuntimeException('Nhân viên không hợp lệ hoặc đã ngưng hoạt động.');
            }
            return ['employee_id' => $dbEmployeeId, 'user_id' => auth()->id()];
        }

        throw new \RuntimeException('Người nhập không hợp lệ.');
    }

    private function normalizePurchaseUpdateItems(array $items): array
    {
        $normalized = [];
        $seenProducts = [];

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new \RuntimeException('Dữ liệu hàng hóa không hợp lệ.');
            }
            if (isset($seenProducts[$productId])) {
                throw new \RuntimeException('Không được nhập trùng một hàng hóa trong cùng phiếu nhập.');
            }
            $seenProducts[$productId] = true;

            $serials = array_values(array_filter(array_map(
                fn($serial) => $this->normalizeSerial(is_string($serial) ? $serial : ''),
                (array) ($item['serials'] ?? [])
            ), fn($serial) => $serial !== ''));

            if (count($serials) !== count(array_unique($serials))) {
                throw new \RuntimeException('Serial/IMEI bị trùng trong phiếu nhập hiện tại.');
            }

            $normalized[] = [
                'product_id' => $productId,
                'quantity' => (float) ($item['quantity'] ?? 0),
                'price' => round((float) ($item['price'] ?? 0), 2),
                'retail_price' => round((float) ($item['retail_price'] ?? 0), 2),
                'technician_price' => round((float) ($item['technician_price'] ?? 0), 2),
                'discount' => round((float) ($item['discount'] ?? 0), 2),
                'serials' => $serials,
                'warranty_months' => (int) ($item['warranty_months'] ?? 0),
            ];
        }

        return $normalized;
    }

    private function normalizeSerial(?string $serial): string
    {
        $serial = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', (string) $serial) ?? '';

        return strtoupper(trim($serial));
    }

    private function withSupplierDebtDisplayAliases(Customer $supplier): Customer
    {
        foreach (PartnerDebtDisplayBalance::aliases($supplier) as $key => $value) {
            $supplier->{$key} = $value;
        }

        return $supplier;
    }
}
