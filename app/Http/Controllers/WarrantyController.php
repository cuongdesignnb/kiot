<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Warranty;
use App\Models\Product;
use Carbon\Carbon;
use App\Support\Filters\FilterableIndex;

class WarrantyController extends Controller
{
    use FilterableIndex;

    protected function configureWarrantyFilters(): void
    {
        $this->searchable = ['serial_imei', 'customer_name', 'invoice_code'];
        $this->searchableRelations = ['product' => ['name', 'sku']];
        $this->sortable = ['invoice_code', 'customer_name', 'serial_imei', 'warranty_period', 'purchase_date', 'warranty_end_date', 'created_at'];
        $this->dateColumn = 'purchase_date';
        $this->scalarFilters = [];
    }

    public function index(Request $request)
    {
        // Auto seed dummy data if empty for testing viewing
        if (Warranty::count() === 0) {
            $product1 = Product::where('sku', 'SP007765')->first() ?? Product::first();
            $product2 = Product::where('sku', 'SP008042')->first() ?? Product::skip(1)->first();
            if ($product1 && $product2) {
                Warranty::create([
                    'invoice_code' => 'HD008229.01',
                    'product_id' => $product1->id,
                    'customer_name' => 'Anh Khải',
                    'serial_imei' => null,
                    'warranty_period' => 3,
                    'purchase_date' => Carbon::now()->subMonths(1),
                    'warranty_end_date' => Carbon::now()->addMonths(2),
                ]);
                Warranty::create([
                    'invoice_code' => 'HD008229.01',
                    'product_id' => $product2->id,
                    'customer_name' => 'Anh Khải',
                    'serial_imei' => '3VGMK73',
                    'warranty_period' => 3,
                    'purchase_date' => Carbon::now()->subMonths(1),
                    'warranty_end_date' => Carbon::now()->addMonths(2),
                ]);
            }
        }

        $this->configureWarrantyFilters();

        $query = Warranty::with('product');

        // Handle product-field sorting specially (join products)
        $productSortFields = ['product_sku' => 'sku', 'product_name' => 'name'];
        if ($request->filled('sort_by') && array_key_exists($request->sort_by, $productSortFields)) {
            $dir = in_array($request->input('sort_direction', $request->input('sort_dir')), ['asc', 'desc']) ? $request->input('sort_direction', $request->input('sort_dir')) : 'desc';
            $query->join('products', 'warranties.product_id', '=', 'products.id')
                ->orderBy('products.' . $productSortFields[$request->sort_by], $dir)
                ->select('warranties.*');
            // Remove normal sort by clearing sortable temporarily
            $savedSortable = $this->sortable;
            $this->sortable = [];
            $this->applySearch($query, $request);
            $this->sortable = $savedSortable;
        } else {
            $this->applySearch($query, $request);
            $this->applySort($query, $request);
        }

        // Pseudo status filter
        $status_filter = $request->input('status', 'all');
        if ($status_filter === 'valid') {
            $query->where('warranty_end_date', '>=', Carbon::now());
        } elseif ($status_filter === 'expired') {
            $query->where('warranty_end_date', '<', Carbon::now());
        }

        // Custom date range on purchase_date — piggy-back on canonical date_filter too
        $this->applyDateRange($query, $request);

        // Legacy/custom time_filter (purchase_date) for backward compat
        $time_filter = $request->input('time_filter');
        if ($time_filter === 'this_month') {
            $query->whereMonth('purchase_date', Carbon::now()->month)
                ->whereYear('purchase_date', Carbon::now()->year);
        } elseif ($time_filter === 'custom') {
            if ($request->filled('time_start')) {
                $query->whereDate('purchase_date', '>=', $request->input('time_start'));
            }
            if ($request->filled('time_end')) {
                $query->whereDate('purchase_date', '<=', $request->input('time_end'));
            }
        }

        // Expiration range (warranty_end_date)
        $expiration_filter = $request->input('expiration_filter', 'all');
        if ($expiration_filter === 'custom') {
            if ($request->filled('expiration_start')) {
                $query->whereDate('warranty_end_date', '>=', $request->input('expiration_start'));
            }
            if ($request->filled('expiration_end')) {
                $query->whereDate('warranty_end_date', '<=', $request->input('expiration_end'));
            }
        }

        $warranties = $query->paginate(20)->withQueryString();

        $filterOptions = [
            'statuses' => [
                ['value' => 'valid', 'label' => 'Còn bảo hành'],
                ['value' => 'expired', 'label' => 'Hết bảo hành'],
            ],
        ];

        return Inertia::render('Warranties/Index', [
            'warranties' => $warranties,
            'filters' => array_merge($this->currentFilters($request), $request->only([
                'time_filter', 'time_start', 'time_end',
                'status', 'expiration_filter', 'expiration_start', 'expiration_end',
                'maintenance_filter', 'maintenance_start', 'maintenance_end',
            ])),
            'filterOptions' => $filterOptions,
        ]);
    }

    public function update(Request $request, Warranty $warranty)
    {
        $request->validate([
            'maintenance_note' => 'nullable|string',
            'has_reminder_off' => 'nullable|boolean',
            'warranty_period' => 'nullable|integer',
            'warranty_end_date' => 'nullable|date',
            'serial_imei' => 'nullable|string',
        ]);

        $warranty->update($request->only('maintenance_note', 'has_reminder_off', 'warranty_period', 'warranty_end_date', 'serial_imei'));

        return redirect()->back()->with('success', 'Đã cập nhật thông tin bảo hành!');
    }

    public function export(Request $request)
    {
        $this->configureWarrantyFilters();
        $query = \App\Models\Warranty::with('product');
        $this->applySearch($query, $request);
        $this->applyDateRange($query, $request);
        $this->applySort($query, $request);
        $warranties = $query->get();

        return \App\Services\CsvService::export(
            ['Mã HĐ', 'Mã hàng', 'Tên hàng', 'Serial/IMEI', 'Khách hàng', 'Ngày mua', 'Thời hạn BH', 'Ngày hết BH', 'Ghi chú bảo trì'],
            $warranties->map(fn($w) => [$w->invoice_code, $w->product?->sku, $w->product?->name, $w->serial_imei, $w->customer_name, $w->purchase_date, $w->warranty_period, $w->warranty_end_date, $w->maintenance_note]),
            'bao_hanh.csv'
        );
    }

    public function print(\App\Models\Warranty $warranty)
    {
        $warranty->load('product');
        return view('prints.warranty', compact('warranty'));
    }
}
