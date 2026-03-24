<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Models\Employee;
use App\Models\SerialImei;
use App\Models\Product;
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
            'serialImei:id,serial_number,repair_status,product_id',
            'serialImei.product:id,name,sku',
            'assignedEmployee:id,name',
            'branch:id,name',
            'category:id,name,color',
            'assignments.employee:id,name',
        ])->latest();

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

        return response()->json($query->paginate($request->per_page ?? 20));
    }

    /**
     * Chi tiết công việc.
     */
    public function show(Request $request, Task $task)
    {
        $user = $request->user();

        // Nhân viên (không có quyền tasks.view) chỉ xem được task được giao cho mình
        if ($user && !$user->hasPermission('tasks.view')) {
            $employee = $user->employee;
            if (!$employee || !$task->assignments()->where('employee_id', $employee->id)->exists()) {
                return response()->json(['message' => 'Bạn không có quyền xem công việc này.'], 403);
            }
        }

        $task->load([
            'product:id,name,sku,image',
            'serialImei:id,serial_number,repair_status,cost_price',
            'assignedEmployee:id,name',
            'branch:id,name',
            'category:id,name,color,type',
            'parts.product:id,name,sku,stock_quantity',
            'creator:id,name',
            'assignments.employee:id,name',
            'assignments.assigner:id,name',
            'comments.user:id,name',
        ]);

        return response()->json($task);
    }

    /**
     * Tạo công việc.
     */
    public function store(Request $request)
    {
        $type = $request->input('type', Task::TYPE_GENERAL);

        if ($type === Task::TYPE_REPAIR) {
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
        $data['created_by'] = $request->user()?->id;

        $task = $this->service->createTask($data);
        $task->load(['product:id,name,sku', 'serialImei:id,serial_number', 'category:id,name,color']);

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
     * - Pending: ai cũng huỷ được
     * - In_progress: chỉ người nhận việc mới huỷ được
     * - Completed/Cancelled: không huỷ được
     */
    public function destroy(Request $request, Task $task)
    {
        // Không cho huỷ việc đã hoàn thành hoặc đã huỷ
        if ($task->status === Task::STATUS_COMPLETED) {
            return response()->json(['message' => 'Không thể huỷ công việc đã hoàn thành.'], 422);
        }
        if ($task->status === Task::STATUS_CANCELLED) {
            return response()->json(['message' => 'Công việc đã được huỷ trước đó.'], 422);
        }

        // Nếu đang sửa (in_progress) → chỉ người nhận việc mới huỷ được
        if ($task->status === Task::STATUS_IN_PROGRESS) {
            $user = $request->user();
            $isAssigned = false;

            if ($user) {
                // Kiểm tra user có phải là nhân viên được giao không
                $employee = \App\Models\Employee::where('user_id', $user->id)->first();
                if ($employee) {
                    $isAssigned = $task->assigned_employee_id === $employee->id
                        || $task->assignments()->where('employee_id', $employee->id)->exists();
                }
            }

            if (!$isAssigned) {
                return response()->json([
                    'message' => 'Công việc đang thực hiện, chỉ người nhận việc mới có thể huỷ.'
                ], 403);
            }
        }

        $this->service->cancelTask($task, $request->user()?->id);

        return response()->json(['message' => 'Đã huỷ công việc. Serial đã được thu hồi về trạng thái sẵn bán. Tất cả linh kiện đã hoàn trả về kho.']);
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
            'product_id'     => 'required|exists:products,id',
            'quantity'       => 'required|integer|min:1',
            'notes'          => 'nullable|string|max:500',
            'allow_negative' => 'nullable|boolean',
        ]);

        try {
            $part = $this->service->addPart(
                $task,
                $data['product_id'],
                $data['quantity'],
                $data['notes'] ?? null,
                $request->user()?->id,
                !empty($data['allow_negative'])
            );
            $part->load('product:id,name,sku,stock_quantity');
            $task->refresh();

            $product = Product::find($data['product_id']);
            $warning = null;
            if ($product && $product->stock_quantity < 0) {
                $warning = "⚠ Linh kiện \"{$product->name}\" tồn kho âm: {$product->stock_quantity}. Cần nhập thêm hàng.";
            }

            return response()->json([
                'part' => $part,
                'task' => $task->only(['parts_cost', 'total_cost']),
                'warning' => $warning,
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
     * Hoàn thành công việc.
     */
    public function complete(Request $request, Task $task)
    {
        $result = $this->service->markCompleted($task, $request->user()?->id);
        return response()->json($result);
    }

    /**
     * Bóc linh kiện từ máy — nhập vào tồn kho.
     */
    public function disassemblePart(Request $request, Task $task)
    {
        if (!$task->is_repair) {
            return response()->json(['message' => 'Chỉ phiếu sửa chữa mới có thể bóc linh kiện.'], 422);
        }

        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity'   => 'required|integer|min:1',
            'unit_cost'  => 'nullable|numeric|min:0',
            'notes'      => 'nullable|string|max:500',
        ]);

        try {
            $part = $this->service->disassemblePart(
                $task,
                $data['product_id'],
                $data['quantity'],
                isset($data['unit_cost']) ? (float) $data['unit_cost'] : null,
                $data['notes'] ?? null,
                $request->user()?->id
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
            'employee_id' => 'nullable|exists:employees,id',
        ]);

        $from = Carbon::create($request->year, $request->month, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();

        $employeeQuery = Employee::where('is_active', true);

        // Filter by employee_id if provided
        if ($request->filled('employee_id')) {
            $employeeQuery->where('id', $request->employee_id);
        } else {
            // Only show employees who have assignments in the period
            $employeeQuery->whereHas('taskAssignments', function ($q) use ($from, $to) {
                $q->whereBetween('assigned_at', [$from, $to->copy()->endOfDay()]);
            });
        }

        $employees = $employeeQuery->get();

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

        $serials = SerialImei::with('product:id,name,sku,cost_price')
            ->where('serial_number', 'like', '%' . $q . '%')
            ->limit(10)
            ->get(['id', 'serial_number', 'product_id', 'status', 'cost_price', 'repair_status']);

        return response()->json($serials);
    }

    /**
     * Tìm sản phẩm (linh kiện).
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

    /**
     * Lấy danh sách serial/IMEI tồn kho (in_stock) theo product_id — dùng cho batch repair.
     */
    public function productSerials(Request $request)
    {
        $productId = $request->get('product_id');
        if (!$productId) return response()->json([]);

        $serials = SerialImei::where('product_id', $productId)
            ->where('status', 'in_stock')
            ->whereNull('repair_status')
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
            $task = $this->service->createTask($taskData);

            if (!empty($data['employee_ids'])) {
                $this->service->assignEmployees($task, $data['employee_ids'], $request->user()?->id, $assignerName);
            }

            $createdTasks[] = $task->id;
        }

        return response()->json([
            'message' => 'Đã tạo ' . count($createdTasks) . ' phiếu sửa chữa',
            'task_ids' => $createdTasks,
        ], 201);
    }
}
