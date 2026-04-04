<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceRepair;
use App\Models\Employee;
use App\Models\Product;
use App\Models\SerialImei;
use App\Services\RepairService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DeviceRepairController extends Controller
{
    protected RepairService $service;

    public function __construct(RepairService $service)
    {
        $this->service = $service;
    }

    /**
     * Danh sách phiếu sửa chữa.
     */
    public function index(Request $request)
    {
        $query = DeviceRepair::with(['product:id,name,sku', 'serialImei:id,serial_number,repair_status', 'assignedEmployee:id,name', 'branch:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('assigned_employee_id')) {
            $query->where('assigned_employee_id', $request->assigned_employee_id);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('created_at', [$request->from, $request->to . ' 23:59:59']);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('code', 'like', "%{$s}%")
                    ->orWhereHas('serialImei', fn($q2) => $q2->where('serial_number', 'like', "%{$s}%"))
                    ->orWhereHas('product', fn($q2) => $q2->where('name', 'like', "%{$s}%"));
            });
        }

        $query->when($request->filled('sort_by'), function ($q) use ($request) {
            $allowed = ['code', 'status', 'deadline', 'original_price', 'parts_cost', 'total_cost', 'created_at'];
            $sortBy = in_array($request->sort_by, $allowed) ? $request->sort_by : 'created_at';
            $dir = $request->sort_direction === 'asc' ? 'asc' : 'desc';
            $q->orderBy($sortBy, $dir);
        }, function ($q) {
            $q->latest();
        });

        return response()->json($query->paginate($request->per_page ?? 20));
    }

    /**
     * Chi tiết phiếu + linh kiện.
     */
    public function show(DeviceRepair $deviceRepair)
    {
        $deviceRepair->load([
            'product:id,name,sku,image',
            'serialImei:id,serial_number,repair_status,cost_price',
            'assignedEmployee:id,name',
            'branch:id,name',
            'parts.product:id,name,sku',
            'creator:id,name',
        ]);

        return response()->json($deviceRepair);
    }

    /**
     * Tạo phiếu sửa chữa.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'serial_imei_id'    => 'required|exists:serial_imeis,id',
            'issue_description' => 'nullable|string|max:2000',
            'branch_id'         => 'nullable|exists:branches,id',
            'notes'             => 'nullable|string|max:2000',
            'deadline'          => 'nullable|date',
        ]);
        $data['created_by'] = $request->user()?->id;

        $repair = $this->service->createRepair($data);
        $repair->load(['product:id,name,sku', 'serialImei:id,serial_number']);

        return response()->json($repair, 201);
    }

    /**
     * Cập nhật thông tin phiếu.
     */
    public function update(Request $request, DeviceRepair $deviceRepair)
    {
        $data = $request->validate([
            'issue_description' => 'nullable|string|max:2000',
            'branch_id'         => 'nullable|exists:branches,id',
            'notes'             => 'nullable|string|max:2000',
        ]);

        $deviceRepair->update($data);

        return response()->json($deviceRepair->fresh());
    }

    /**
     * Giao NV.
     */
    public function assign(Request $request, DeviceRepair $deviceRepair)
    {
        $data = $request->validate([
            'assigned_employee_id' => 'required|exists:employees,id',
        ]);

        $repair = $this->service->assignEmployee($deviceRepair, $data['assigned_employee_id']);

        return response()->json($repair->load('assignedEmployee:id,name'));
    }

    /**
     * Xuất linh kiện lắp máy.
     */
    public function addPart(Request $request, DeviceRepair $deviceRepair)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity'    => 'required|integer|min:1',
            'notes'      => 'nullable|string|max:500',
        ]);

        try {
            $part = $this->service->addPart(
                $deviceRepair,
                $data['product_id'],
                $data['quantity'],
                $data['notes'] ?? null,
                $request->user()?->id
            );
            $part->load('product:id,name,sku');

            // Reload repair để trả về costs mới
            $deviceRepair->refresh();

            return response()->json([
                'part'   => $part,
                'repair' => $deviceRepair->only(['parts_cost', 'total_cost']),
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Gỡ linh kiện.
     */
    public function removePart(DeviceRepair $deviceRepair, int $partId)
    {
        $part = $deviceRepair->parts()->findOrFail($partId);
        $this->service->removePart($part);
        $deviceRepair->refresh();

        return response()->json([
            'message' => 'Đã gỡ linh kiện.',
            'repair'  => $deviceRepair->only(['parts_cost', 'total_cost']),
        ]);
    }

    /**
     * Đánh dấu hoàn thành.
     */
    public function complete(DeviceRepair $deviceRepair)
    {
        $repair = $this->service->markCompleted($deviceRepair);

        return response()->json($repair);
    }

    /**
     * Báo cáo năng suất NV.
     */
    public function performance(Request $request)
    {
        $request->validate([
            'month' => 'required|integer|between:1,12',
            'year'  => 'required|integer|min:2020',
        ]);

        $from = Carbon::create($request->year, $request->month, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $employees = Employee::whereHas('deviceRepairs', function ($q) use ($from, $to) {
            $q->whereBetween('assigned_at', [$from, $to]);
        })->get();

        $results = [];
        foreach ($employees as $emp) {
            $perf = $this->service->getEmployeePerformance($emp->id, $from->toDateString(), $to->toDateString());
            $results[] = array_merge(['employee_id' => $emp->id, 'employee_name' => $emp->name], $perf);
        }

        return response()->json($results);
    }

    /**
     * Tìm serial/IMEI để tạo phiếu sửa chữa.
     */
    public function searchSerials(Request $request)
    {
        $q = $request->get('q', '');
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $serials = SerialImei::with('product:id,name,sku,cost_price')
            ->where('serial_number', 'like', '%' . $q . '%')
            ->limit(10)
            ->get(['id', 'serial_number', 'product_id', 'status', 'cost_price', 'repair_status']);

        return response()->json($serials);
    }

    /**
     * Tìm sản phẩm (linh kiện) để xuất vào phiếu sửa.
     */
    public function searchProducts(Request $request)
    {
        $q = $request->get('q', '');
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $products = Product::where(function ($query) use ($q) {
                $query->where('name', 'like', '%' . $q . '%')
                      ->orWhere('sku', 'like', '%' . $q . '%');
            })
            ->limit(10)
            ->get(['id', 'name', 'sku', 'cost_price', 'stock_quantity']);

        return response()->json($products);
    }
}
