<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Warranty;
use App\Models\Product;
use Carbon\Carbon;

class WarrantyController extends Controller
{
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

        $query = Warranty::with('product')
            ->when($request->filled('sort_by'), function ($q) use ($request) {
                $allowed = ['invoice_code', 'customer_name', 'serial_imei', 'warranty_period', 'purchase_date', 'warranty_end_date', 'created_at'];
                $productSortFields = ['product_sku' => 'sku', 'product_name' => 'name'];
                $dir = $request->sort_direction === 'asc' ? 'asc' : 'desc';

                if (array_key_exists($request->sort_by, $productSortFields)) {
                    $q->join('products', 'warranties.product_id', '=', 'products.id')
                        ->orderBy('products.' . $productSortFields[$request->sort_by], $dir)
                        ->select('warranties.*');
                } elseif (in_array($request->sort_by, $allowed)) {
                    $q->orderBy($request->sort_by, $dir);
                } else {
                    $q->orderBy('id', 'desc');
                }
            }, function ($q) {
                $q->orderBy('id', 'desc');
            });

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            })->orWhere('serial_imei', 'like', "%{$search}%")
                ->orWhere('customer_name', 'like', "%{$search}%")
                ->orWhere('invoice_code', 'like', "%{$search}%");
        }

        // Filters mapping
        $time_filter = $request->input('time_filter', 'all');
        if ($time_filter === 'this_month') {
            $query->whereMonth('purchase_date', Carbon::now()->month)
                ->whereYear('purchase_date', Carbon::now()->year);
        } elseif ($time_filter === 'custom') {
            if ($request->filled('time_start')) {
                $query->whereDate('purchase_date', '>=', clone new Carbon($request->input('time_start')));
            }
            if ($request->filled('time_end')) {
                $query->whereDate('purchase_date', '<=', clone new Carbon($request->input('time_end')));
            }
        }

        $status_filter = $request->input('status', 'all');
        if ($status_filter === 'valid') {
            $query->where('warranty_end_date', '>=', Carbon::now());
        } elseif ($status_filter === 'expired') {
            $query->where('warranty_end_date', '<', Carbon::now());
        }

        $expiration_filter = $request->input('expiration_filter', 'all');
        if ($expiration_filter === 'custom') {
            if ($request->filled('expiration_start')) {
                $query->whereDate('warranty_end_date', '>=', clone new Carbon($request->input('expiration_start')));
            }
            if ($request->filled('expiration_end')) {
                $query->whereDate('warranty_end_date', '<=', clone new Carbon($request->input('expiration_end')));
            }
        }

        $maintenance_filter = $request->input('maintenance_filter', 'all');
        // Simple logic for maintenance, as it requires another date field

        $warranties = $query->paginate(20)->withQueryString();

        return Inertia::render('Warranties/Index', [
            'warranties' => $warranties,
            'filters' => array_merge($request->only([
                'search',
                'time_filter',
                'time_start',
                'time_end',
                'status',
                'expiration_filter',
                'expiration_start',
                'expiration_end',
                'maintenance_filter',
                'maintenance_start',
                'maintenance_end'
            ]), [
                'sort_by' => $request->sort_by,
                'sort_direction' => $request->sort_direction,
            ]),
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
        $warranties = \App\Models\Warranty::with('product')
            ->when($request->search, fn($q, $s) => $q->whereHas('product', fn($pq) => $pq->where('name', 'LIKE', "%{$s}%")->orWhere('sku', 'LIKE', "%{$s}%")))
            ->orderBy('id', 'desc')->get();

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
