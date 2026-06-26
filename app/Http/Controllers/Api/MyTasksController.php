<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskAssignment;
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
}
