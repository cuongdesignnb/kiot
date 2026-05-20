<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\Employee;
use App\Models\SerialImei;
use App\Models\Product;
use App\Models\Warranty;
use App\Services\ProductSearchService;
use App\Services\TaskService;
use Illuminate\Http\Request;
use Carbon\Carbon;

class TaskController extends Controller
{
    protected TaskService $service;

    public function __construct(TaskService $service)
    {
        $this->service = $service;
    }

    /**
     * Danh sách công việc.
     */
    public function index(Request $request)
    {
        $query = Task::with([
            'product:id,name,sku',
            'serialImei:id,serial_number,status,repair_status,cost_price,product_id,invoice_id,sold_at,purchase_return_id',
            'serialImei.product:id,name,sku',
            'assignedEmployee:id,name',
            'branch:id,name',
            'category:id,name,color',
            'assignments.employee:id,name',
        ]);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('assigned_employee_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('assigned_employee_id', $request->assigned_employee_id)
                    ->orWhereHas('assignments', fn($q2) => $q2->where('employee_id', $request->assigned_employee_id));
            });
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
                    ->orWhere('title', 'like', "%{$s}%")
                    ->orWhereHas('serialImei', fn($q2) => $q2->where('serial_number', 'like', "%{$s}%"))
                    ->orWhereHas('product', fn($q2) => $q2->where('name', 'like', "%{$s}%"));
            });
        }

        $query->when($request->filled('sort_by'), function ($q) use ($request) {
            $allowed = ['code', 'title', 'type', 'priority', 'status', 'progress', 'deadline', 'created_at'];
            $sortBy = in_array($request->sort_by, $allowed) ? $request->sort_by : 'created_at';
            $dir = $request->sort_direction === 'asc' ? 'asc' : 'desc';
            $q->orderBy($sortBy, $dir);
        }, function ($q) {
            $q->latest();
        });

        return response()->json($query->paginate($request->per_page ?? 20));
    }

    /**
     * Chi tiết công việc.
     *
     * Step 23.8F: include customer/warranty/invoice cho external repair UI.
     */
    public function show(Task $task)
    {
        $task->load([
            'product:id,name,sku,image,has_serial',
            'serialImei:id,serial_number,status,repair_status,cost_price,product_id,invoice_id,sold_at,purchase_return_id',
            'assignedEmployee:id,name',
            'branch:id,name',
            'category:id,name,color,type',
            'parts.product:id,name,sku,has_serial',
            'creator:id,name',
            'assignments.employee:id,name',
            'assignments.assigner:id,name',
            'comments.user:id,name',
            'customer:id,name,phone,code',
            'warranty:id,invoice_code,product_id,serial_imei,purchase_date,warranty_end_date,warranty_period',
            'warranty.product:id,name,sku',
            'invoice:id,code,total,customer_paid,status',
        ]);

        // Step 23.8F: tính warranty_valid + available_for_disassembly cho UI hiển thị
        $extras = [
            'warranty_valid' => $task->warranty?->warranty_end_date
                ? \Carbon\Carbon::parse($task->warranty->warranty_end_date)->endOfDay()->gte(now())
                : false,
            'available_for_disassembly' => $task->is_repair && $task->serial_imei_id
                ? max(0,
                    (float) $task->original_cost
                    + (float) $task->parts()->where('direction', 'export')->sum('total_cost')
                    - (float) $task->parts()->where('direction', 'import')->sum('total_cost')
                )
                : null,
        ];

        return response()->json(array_merge($task->toArray(), $extras));
    }

    /**
     * Tạo công việc.
     */
    public function store(Request $request)
    {
        $type = $request->input('type', Task::TYPE_GENERAL);
        $isExternal = $request->boolean('external', false);

        // Step 24.0B: external repair cần permission tách `tasks.create_external` (fallback `tasks.create`).
        if ($type === Task::TYPE_REPAIR && $isExternal) {
            $user = $request->user();
            if ($user && !$user->hasAnyPermission(['tasks.create_external', 'tasks.create'])) {
                return response()->json([
                    'message' => 'Bạn không có quyền tạo phiếu sửa chữa khách ngoài.',
                ], 403);
            }
        }

        if ($type === Task::TYPE_REPAIR && $isExternal) {
            // External repair — no serial required
            $data = $request->validate([
                'customer_id'       => 'nullable|exists:customers,id',
                'customer_name'     => 'nullable|string|max:255',
                'customer_phone'    => 'nullable|string|max:30',
                'product_id'        => 'nullable|exists:products,id',
                'issue_description' => 'required|string|max:2000',
                'title'             => 'nullable|string|max:255',
                'category_id'       => 'nullable|exists:task_categories,id',
                'priority'          => 'nullable|in:low,normal,high,urgent',
                'branch_id'         => 'nullable|exists:branches,id',
                'notes'             => 'nullable|string|max:2000',
                'deadline'          => 'nullable|date',
                'received_at'       => 'nullable|date',
            ]);

            // Must have customer_id or customer_name
            if (empty($data['customer_id']) && empty($data['customer_name'])) {
                return response()->json([
                    'message' => 'Phải có thông tin khách hàng (customer_id hoặc customer_name).',
                    'errors'  => ['customer_name' => ['Vui lòng nhập tên khách hàng.']],
                ], 422);
            }
        } elseif ($type === Task::TYPE_REPAIR) {
            $data = $request->validate([
                'serial_imei_id'    => 'required|exists:serial_imeis,id',
                'issue_description' => 'nullable|string|max:2000',
                'title'             => 'nullable|string|max:255',
                'category_id'       => 'nullable|exists:task_categories,id',
                'priority'          => 'nullable|in:low,normal,high,urgent',
                'branch_id'         => 'nullable|exists:branches,id',
                'notes'             => 'nullable|string|max:2000',
                'deadline'          => 'nullable|date',
            ]);
        } else {
            $data = $request->validate([
                'title'             => 'required|string|max:255',
                'description'       => 'nullable|string|max:2000',
                'category_id'       => 'nullable|exists:task_categories,id',
                'priority'          => 'nullable|in:low,normal,high,urgent',
                'branch_id'         => 'nullable|exists:branches,id',
                'notes'             => 'nullable|string|max:2000',
                'deadline'          => 'nullable|date',
            ]);
        }

        $data['type'] = $type;
        $data['external'] = $isExternal;
        $data['created_by'] = $request->user()?->id;

        try {
            $task = $this->service->createTask($data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $task->load(['product:id,name,sku', 'serialImei:id,serial_number', 'category:id,name,color', 'customer:id,name,phone']);

        return response()->json($task, 201);
    }

    /**
     * Cập nhật công việc.
     */
    public function update(Request $request, Task $task)
    {
        $data = $request->validate([
            'title'             => 'nullable|string|max:255',
            'issue_description' => 'nullable|string|max:2000',
            'category_id'       => 'nullable|exists:task_categories,id',
            'priority'          => 'nullable|in:low,normal,high,urgent',
            'branch_id'         => 'nullable|exists:branches,id',
            'notes'             => 'nullable|string|max:2000',
            'deadline'          => 'nullable|date',
        ]);

        $task->update($data);

        return response()->json($task->fresh('category:id,name,color'));
    }

    /**
     * Xóa / hủy công việc.
     */
    public function destroy(Task $task)
    {
        if ($task->status === Task::STATUS_COMPLETED) {
            return response()->json(['message' => 'Không thể xóa công việc đã hoàn thành.'], 422);
        }

        $this->service->changeStatus($task, Task::STATUS_CANCELLED, request()->user()?->id);

        return response()->json(['message' => 'Đã hủy công việc.']);
    }

    /**
     * Giao nhân viên (multi-assign).
     */
    public function assign(Request $request, Task $task)
    {
        $data = $request->validate([
            'employee_ids'   => 'required|array|min:1',
            'employee_ids.*' => 'exists:employees,id',
        ]);

        $result = $this->service->assignEmployees(
            $task,
            $data['employee_ids'],
            $request->user()?->id
        );

        return response()->json($result);
    }

    /**
     * Xuất linh kiện (repair only).
     */
    public function addPart(Request $request, Task $task)
    {
        if (!$task->is_repair) {
            return response()->json(['message' => 'Chỉ phiếu sửa chữa mới có linh kiện.'], 422);
        }

        $data = $request->validate([
            'product_id'   => 'required|exists:products,id',
            'quantity'     => 'required|integer|min:1',
            'notes'        => 'nullable|string|max:500',
            'serial_ids'   => 'nullable|array',
            'serial_ids.*' => 'integer|exists:serial_imeis,id',
        ]);

        try {
            $part = $this->service->addPart(
                $task,
                $data['product_id'],
                $data['quantity'],
                $data['notes'] ?? null,
                $request->user()?->id,
                $data['serial_ids'] ?? null
            );
            $part->load('product:id,name,sku');
            $task->refresh();

            return response()->json([
                'part' => $part,
                'task' => $task->only(['parts_cost', 'total_cost']),
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Gỡ linh kiện.
     */
    public function removePart(Task $task, int $partId)
    {
        $part = $task->parts()->findOrFail($partId);
        $this->service->removePart($part);
        $task->refresh();

        return response()->json([
            'message' => 'Đã gỡ linh kiện.',
            'task'    => $task->only(['parts_cost', 'total_cost']),
        ]);
    }


    /**
     * HOTFIX 24.11B — Rollback a disassembled part (direction='import').
     * Separate endpoint from removePart() so the export and import flows
     * keep their own guards and cannot be confused.
     */
    public function rollbackDisassemblyPart(Request $request, Task $task, int $partId)
    {
        $part = $task->parts()->findOrFail($partId);

        try {
            $this->service->rollbackDisassembledPart($part, $request->user()?->id);
            $task->refresh();

            return response()->json([
                'message' => 'Đã hoàn tác bóc linh kiện.',
                'task'    => $task->only(['parts_cost', 'total_cost']),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Bóc linh kiện từ máy — nhập vào tồn kho. (Step 23.8E hardening)
     */
    public function disassemblePart(Request $request, Task $task)
    {
        if (!$task->is_repair) {
            return response()->json(['message' => 'Chỉ phiếu sửa chữa mới có thể bóc linh kiện.'], 422);
        }

        $data = $request->validate([
            'product_id'      => 'required|exists:products,id',
            'quantity'        => 'required|integer|min:1',
            'unit_cost'       => 'nullable|numeric|min:0',
            'notes'           => 'nullable|string|max:500',
            'serial_numbers'  => 'nullable|array',
            'serial_numbers.*'=> 'string|max:100',
        ]);

        try {
            $part = $this->service->disassemblePart(
                $task,
                $data['product_id'],
                $data['quantity'],
                isset($data['unit_cost']) ? (float) $data['unit_cost'] : null,
                $data['notes'] ?? null,
                $request->user()?->id,
                $data['serial_numbers'] ?? null
            );
            $part->load('product:id,name,sku');
            $task->refresh();

            return response()->json([
                'part' => $part,
                'task' => $task->only(['parts_cost', 'total_cost']),
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Cập nhật tiến độ.
     */
    public function updateProgress(Request $request, Task $task)
    {
        $data = $request->validate([
            'progress' => 'required|integer|min:0|max:100',
        ]);

        $result = $this->service->updateProgress($task, $data['progress']);
        return response()->json($result);
    }

    /**
     * Thêm bình luận.
     */
    public function addComment(Request $request, Task $task)
    {
        $data = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $comment = $this->service->addComment($task, $request->user()->id, $data['body']);
        return response()->json($comment, 201);
    }

    /**
     * Danh sách danh mục.
     */
    public function categories(Request $request)
    {
        $query = TaskCategory::where('is_active', true);
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        return response()->json($query->orderBy('name')->get());
    }

    /**
     * Báo cáo năng suất.
     */
    public function performance(Request $request)
    {
        $request->validate([
            'month' => 'required|integer|between:1,12',
            'year'  => 'required|integer|min:2020',
        ]);

        $from = Carbon::create($request->year, $request->month, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        // Lấy nhân viên có assignment trong khoảng thời gian (dùng task_assignments thay vì assigned_employee_id)
        $employees = Employee::whereHas('taskAssignments', function ($q) use ($from, $to) {
            $q->whereHas('task', fn($tq) => $tq->whereBetween('created_at', [$from, $to]));
        })->get();

        // Nếu lọc theo 1 NV cụ thể
        if ($request->filled('employee_id')) {
            $emp = Employee::find($request->employee_id);
            if ($emp) $employees = collect([$emp]);
        }

        $results = [];
        foreach ($employees as $emp) {
            $perf = $this->service->getEmployeePerformance($emp->id, $from->toDateString(), $to->toDateString());
            $results[] = array_merge(['employee_id' => $emp->id, 'employee_name' => $emp->name], $perf);
        }

        return response()->json($results);
    }

    /**
     * Tìm serial/IMEI.
     */
    public function searchSerials(Request $request)
    {
        $q = $request->get('q', '');
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        // Chỉ trả serial in_stock VÀ không có task active (pending/in_progress)
        $serials = SerialImei::with('product:id,name,sku,cost_price')
            ->where('serial_number', 'like', '%' . $q . '%')
            ->where('status', 'in_stock')
            ->whereDoesntHave('tasks', function ($tq) {
                $tq->whereIn('status', ['pending', 'in_progress']);
            })
            ->limit(10)
            ->get(['id', 'serial_number', 'product_id', 'status', 'cost_price', 'repair_status']);

        return response()->json($serials);
    }

    /**
     * Tìm sản phẩm (linh kiện).
     */
    public function searchProducts(Request $request, ProductSearchService $productSearch)
    {
        $q = $request->get('q', '');
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $query = Product::query();
        $productSearch->apply($query, $q, [
            'include_serials' => true,
            'serial_relation' => 'serials',
        ]);
        $productSearch->applyScore($query, $q);

        $products = $query
            ->limit(10)
            ->get(['id', 'name', 'sku', 'cost_price', 'stock_quantity']);

        return response()->json($products);
    }

    /**
     * Lấy danh sách serial/IMEI tồn kho (in_stock) theo product_id — dùng cho batch repair.
     */
    public function productSerials(Request $request)
    {
        $productId = $request->get('product_id');
        if (!$productId) return response()->json([]);

        $serials = SerialImei::where('product_id', $productId)
            ->where('status', 'in_stock')
            ->whereDoesntHave('tasks', function ($tq) {
                $tq->whereIn('status', ['pending', 'in_progress']);
            })
            ->get(['id', 'serial_number', 'product_id', 'status', 'cost_price']);

        return response()->json($serials);
    }

    /**
     * Tạo batch repair tasks cho nhiều serial cùng 1 sản phẩm.
     */
    public function batchCreateRepair(Request $request)
    {
        $data = $request->validate([
            'serial_imei_ids'    => 'required|array|min:1',
            'serial_imei_ids.*'  => 'exists:serial_imeis,id',
            'issue_description'  => 'nullable|string|max:2000',
            'title'              => 'nullable|string|max:255',
            'category_id'        => 'nullable|exists:task_categories,id',
            'priority'           => 'nullable|in:low,normal,high,urgent',
            'branch_id'          => 'nullable|exists:branches,id',
            'notes'              => 'nullable|string|max:2000',
            'deadline'           => 'nullable|date',
            'employee_ids'       => 'nullable|array',
            'employee_ids.*'     => 'exists:employees,id',
        ]);

        $createdTasks = [];
        $errors = [];
        $assignerName = $request->user()?->name ?? 'Hệ thống';

        foreach ($data['serial_imei_ids'] as $serialId) {
            $taskData = [
                'type' => Task::TYPE_REPAIR,
                'serial_imei_id' => $serialId,
                'issue_description' => $data['issue_description'] ?? null,
                'title' => $data['title'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'branch_id' => $data['branch_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'deadline' => $data['deadline'] ?? null,
                'created_by' => $request->user()?->id,
            ];

            try {
                $task = $this->service->createTask($taskData);
                if (!empty($data['employee_ids'])) {
                    $this->service->assignEmployees($task, $data['employee_ids'], $request->user()?->id, $assignerName);
                }
                $createdTasks[] = $task->id;
            } catch (\InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (empty($createdTasks) && !empty($errors)) {
            return response()->json(['message' => implode('; ', $errors)], 422);
        }

        return response()->json([
            'message' => 'Đã tạo ' . count($createdTasks) . ' phiếu sửa chữa' . (count($errors) ? ', ' . count($errors) . ' lỗi' : ''),
            'task_ids' => $createdTasks,
            'errors' => $errors,
        ], 201);
    }

    /**
     * Hoàn thành sửa chữa.
     *
     * External: tạo invoice + cashflow + debt.
     * Internal: markCompleted (không tạo invoice).
     */
    public function complete(Request $request, Task $task)
    {
        // External repair — full completion flow
        if ($task->external && $task->type === Task::TYPE_REPAIR) {
            // Step 24.0B: external repair cần permission tách `tasks.complete_external` (fallback `tasks.complete`).
            $user = $request->user();
            if ($user && !$user->hasAnyPermission(['tasks.complete_external', 'tasks.complete'])) {
                return response()->json([
                    'message' => 'Bạn không có quyền hoàn thành phiếu sửa chữa khách ngoài.',
                ], 403);
            }

            $data = $request->validate([
                'labor_fee'       => 'required|numeric|min:0',
                'paid_amount'     => 'required|numeric|min:0',
                'payment_method'  => 'nullable|string',
                'note'            => 'nullable|string|max:1000',
                'part_prices'     => 'nullable|array',
                'part_prices.*'   => 'numeric|min:0',
                'warranty_policy' => 'nullable|in:none,free_labor,free_parts,full_free',
            ]);

            // Step 24.0B: warranty policy free_* cần permission `tasks.apply_warranty_policy`.
            $policy = $data['warranty_policy'] ?? 'none';
            if ($policy !== 'none' && $user && !$user->hasPermission('tasks.apply_warranty_policy')) {
                return response()->json([
                    'message' => 'Bạn không có quyền áp chính sách miễn phí bảo hành.',
                ], 403);
            }

            try {
                $task = $this->service->completeExternalRepair($task, $data);
                return response()->json([
                    'message'    => 'Đã hoàn thành sửa chữa.',
                    'task'       => $task,
                    'invoice_id' => $task->invoice_id,
                ]);
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        // Internal repair or general task — simple completion
        try {
            $task = $this->service->markCompleted($task, $request->user()?->id);
            return response()->json([
                'message' => 'Đã hoàn thành công việc.',
                'task'    => $task,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Step 23.8D: Tra cứu warranty theo serial_imei hoặc invoice_code.
     */
    public function lookupWarranty(Request $request)
    {
        $data = $request->validate([
            'serial_imei'  => 'nullable|string|max:100',
            'invoice_code' => 'nullable|string|max:100',
        ]);

        if (empty($data['serial_imei']) && empty($data['invoice_code'])) {
            return response()->json([
                'message' => 'Cần serial_imei hoặc invoice_code.',
            ], 422);
        }

        $query = Warranty::with('product:id,name,sku');
        if (!empty($data['serial_imei'])) {
            $query->where('serial_imei', $data['serial_imei']);
        }
        if (!empty($data['invoice_code'])) {
            $query->where('invoice_code', $data['invoice_code']);
        }

        $warranties = $query->latest('id')->limit(20)->get()->map(function (Warranty $w) {
            $valid = $w->warranty_end_date ? $w->warranty_end_date->endOfDay()->gte(now()) : false;
            return [
                'id'                => $w->id,
                'invoice_code'      => $w->invoice_code,
                'product_id'        => $w->product_id,
                'product_name'      => $w->product?->name,
                'product_sku'       => $w->product?->sku,
                'serial_imei'       => $w->serial_imei,
                'customer_name'     => $w->customer_name,
                'warranty_period'   => $w->warranty_period,
                'purchase_date'     => $w->purchase_date,
                'warranty_end_date' => $w->warranty_end_date,
                'valid'             => $valid,
                'status_label'      => $valid ? 'Còn hạn' : 'Hết hạn',
            ];
        });

        return response()->json($warranties);
    }

    /**
     * Step 23.8D: Gắn warranty vào task sửa chữa khách ngoài.
     */
    public function attachWarranty(Request $request, Task $task)
    {
        $data = $request->validate([
            'warranty_id' => 'required|exists:warranties,id',
        ]);

        $warranty = Warranty::find($data['warranty_id']);

        try {
            $task = $this->service->attachWarranty($task, $warranty);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $task->load('warranty');
        $valid = $task->warranty?->warranty_end_date
            ? $task->warranty->warranty_end_date->endOfDay()->gte(now())
            : false;

        return response()->json([
            'message'        => 'Đã gắn bảo hành vào phiếu sửa chữa.',
            'task'           => $task,
            'warranty_valid' => $valid,
        ]);
    }

    /**
     * HOTFIX 24.18 — operator-triggered "đã lắp lại xong" restore for a
     * serial that got stuck at status=dismantled + repair_status=ready.
     *
     * Thin wrapper around TaskService::restoreReassembledSerial — that
     * method is the place all safety guards live. Surface RuntimeException
     * messages as 422 so the FE can show the cause inline.
     */
    public function restoreReassembledSerial(Request $request, SerialImei $serial)
    {
        try {
            $result = $this->service->restoreReassembledSerial($serial->id, $request->user()?->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $serial = $result['serial']->fresh();
        $serial->load('product:id,stock_quantity,name,sku');

        return response()->json([
            'message'  => $result['restored']
                ? 'Đã hoàn nguyên serial về kho.'
                : 'Serial đã ở trạng thái sẵn bán — không cần hoàn nguyên.',
            'restored' => $result['restored'],
            'reason'   => $result['reason'] ?? null,
            'serial'   => $serial,
        ]);
    }
}
