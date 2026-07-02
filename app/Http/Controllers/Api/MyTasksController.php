<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCategory;
use App\Services\ProductSearchService;
use App\Services\TaskAccessService;
use App\Services\TaskService;
use Illuminate\Http\Request;

class MyTasksController extends Controller
{
    protected TaskService $service;
    protected TaskAccessService $taskAccess;

    public function __construct(TaskService $service, TaskAccessService $taskAccess)
    {
        $this->service = $service;
        $this->taskAccess = $taskAccess;
    }

    /**
     * Danh sách công việc của nhân viên hiện tại.
     */
    public function index(Request $request)
    {
        $employee = $this->taskAccess->activeEmployeeFor($request->user());
        if (!$employee) {
            return response()->json(['message' => 'Tài khoản chưa liên kết nhân viên active.'], 403);
        }

        $query = Task::with([
            'category:id,name,color',
            'branch:id,name',
            'creator:id,name',
            'product:id,name,sku',
            'serialImei:id,serial_number,product_id,repair_status',
            'serialImei.product:id,name,sku',
            'assignments' => fn($q) => $q->where('employee_id', $employee->id),
        ])
        ->whereHas('assignments', fn($q) => $q->where('employee_id', $employee->id))
        ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        $paginated = $query->paginate($request->per_page ?? 20);

        // Map assignment_status + assignment_id vào mỗi task cho frontend
        $paginated->getCollection()->transform(function ($task) use ($employee) {
            $myAssignment = $task->assignments->first();
            $task->assignment_id = $myAssignment?->id;
            $task->assignment_status = $myAssignment?->status;
            return $task;
        });

        return response()->json($paginated);
    }

    /**
     * Tạo công việc cá nhân cho nhân viên hiện tại.
     */
    public function store(Request $request)
    {
        $employee = $this->taskAccess->activeEmployeeFor($request->user());
        if (!$employee) {
            return response()->json(['message' => 'Tài khoản chưa liên kết nhân viên active.'], 403);
        }

        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'category_id' => 'nullable|exists:task_categories,id',
            'priority'    => 'nullable|in:low,normal,high,urgent',
            'notes'       => 'nullable|string|max:2000',
            'deadline'    => 'nullable|date',
        ]);

        $data['type'] = Task::TYPE_GENERAL;
        $data['created_by'] = $request->user()->id;
        $data['creator_employee_id'] = $employee->id;

        try {
            $task = $this->service->createTask($data);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $task->load(['category:id,name,color']);

        return response()->json($task, 201);
    }

    /**
     * Danh mục tối thiểu cho form tự tạo việc.
     */
    public function categories(Request $request)
    {
        $this->ensureSelfCreateEmployee($request);

        return response()->json(
            TaskCategory::where('is_active', true)
                ->where('type', Task::TYPE_GENERAL)
                ->orderBy('name')
                ->get(['id', 'name', 'color', 'type'])
        );
    }

    /**
     * Tìm hàng hóa read-only cho form self-create nếu sau này cần gắn ngữ cảnh.
     */
    public function searchProducts(Request $request, ProductSearchService $productSearch)
    {
        $this->ensureSelfCreateEmployee($request);

        $q = $request->get('q', '');
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $query = Product::where('is_active', true)->where('type', '!=', 'service');
        $productSearch->apply($query, $q, [
            'include_serials' => true,
            'serial_relation' => 'serials',
        ]);
        $productSearch->applyScore($query, $q);

        return response()->json(
            $query->limit(10)->get(['id', 'name', 'sku', 'stock_quantity'])
        );
    }

    /**
     * Tìm serial read-only cho form self-create nếu sau này cần gắn ngữ cảnh.
     */
    public function searchSerials(Request $request)
    {
        $this->ensureSelfCreateEmployee($request);

        $q = $request->get('q', '');
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $serials = SerialImei::with('product:id,name,sku')
            ->where('serial_number', 'like', '%' . $q . '%')
            ->where('status', 'in_stock')
            ->whereDoesntHave('tasks', function ($tq) {
                $tq->whereIn('status', [Task::STATUS_PENDING, Task::STATUS_IN_PROGRESS]);
            })
            ->limit(10)
            ->get(['id', 'serial_number', 'product_id', 'status', 'repair_status']);

        return response()->json($serials);
    }

    /**
     * Nhận/từ chối công việc.
     */
    public function respond(Request $request, TaskAssignment $assignment)
    {
        $employee = $this->taskAccess->activeEmployeeFor($request->user());
        if (!$employee || $assignment->employee_id !== $employee->id) {
            return response()->json(['message' => 'Không có quyền.'], 403);
        }

        $data = $request->validate([
            'status' => 'required|in:accepted,rejected',
            'notes'  => 'nullable|string|max:500',
        ]);

        $result = $this->service->respondToAssignment($assignment, $data['status'], $data['notes'] ?? null);

        return response()->json($result);
    }

    /**
     * Cập nhật tiến độ.
     */
    public function updateProgress(Request $request, Task $task)
    {
        $employee = $this->taskAccess->activeEmployeeFor($request->user());
        if (!$employee) {
            return response()->json(['message' => 'Tài khoản chưa liên kết nhân viên active.'], 403);
        }

        // Verify employee is assigned to this task
        $isAssigned = $task->assignments()->where('employee_id', $employee->id)->exists();
        if (!$isAssigned) {
            return response()->json(['message' => 'Bạn không được giao công việc này.'], 403);
        }

        $data = $request->validate([
            'progress' => 'required|integer|min:0|max:100',
        ]);

        $result = $this->service->updateProgress($task, $data['progress']);
        return response()->json($result);
    }

    /**
     * Nhận tất cả công việc đang chờ (pending) của nhân viên.
     */
    public function acceptAll(Request $request)
    {
        $employee = $this->taskAccess->activeEmployeeFor($request->user());
        if (!$employee) {
            return response()->json(['message' => 'Tài khoản chưa liên kết nhân viên active.'], 403);
        }

        // Lấy tất cả assignments pending của NV này, mà task chưa completed/cancelled
        $pendingAssignments = TaskAssignment::where('employee_id', $employee->id)
            ->where('status', 'pending')
            ->whereHas('task', fn($q) => $q->whereNotIn('status', ['completed', 'cancelled']))
            ->get();

        $accepted = 0;
        foreach ($pendingAssignments as $assignment) {
            try {
                $this->service->respondToAssignment($assignment, 'accepted');
                $accepted++;
            } catch (\Exception $e) {
                // Skip errors, continue
            }
        }

        return response()->json([
            'message' => "Đã nhận {$accepted} công việc.",
            'accepted' => $accepted,
        ]);
    }

    private function ensureSelfCreateEmployee(Request $request): void
    {
        $employee = $this->taskAccess->activeEmployeeFor($request->user());
        if (!$employee) {
            abort(403, 'Tài khoản chưa liên kết nhân viên active.');
        }
    }
}
