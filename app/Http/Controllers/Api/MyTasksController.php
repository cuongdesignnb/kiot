<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Services\TaskService;
use Illuminate\Http\Request;

class MyTasksController extends Controller
{
    protected TaskService $service;

    public function __construct(TaskService $service)
    {
        $this->service = $service;
    }

    /**
     * Danh sách công việc của nhân viên hiện tại.
     */
    public function index(Request $request)
    {
        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json(['data' => [], 'message' => 'Tài khoản chưa liên kết nhân viên.']);
        }

        $query = Task::with([
            'category:id,name,color',
            'branch:id,name',
            'creator:id,name',
            'product:id,name,sku',
            'serialImei:id,serial_number,product_id',
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
        $employee = $request->user()->employee;
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
     * Nhận tất cả công việc đang chờ.
     */
    public function acceptAll(Request $request)
    {
        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json(['message' => 'Tài khoản chưa liên kết nhân viên.'], 422);
        }

        $pendingAssignments = TaskAssignment::where('employee_id', $employee->id)
            ->where('status', TaskAssignment::STATUS_PENDING)
            ->get();

        $count = 0;
        foreach ($pendingAssignments as $assignment) {
            $this->service->respondToAssignment($assignment, 'accepted');
            $count++;
        }

        return response()->json([
            'message' => "Đã nhận {$count} công việc.",
            'accepted_count' => $count,
        ]);
    }

    /**
     * Cập nhật tiến độ.
     */
    public function updateProgress(Request $request, Task $task)
    {
        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json(['message' => 'Không có quyền.'], 403);
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
     * Nhân viên thêm ghi chú tiến độ (work log).
     */
    public function addNote(Request $request, Task $task)
    {
        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json(['message' => 'Không có quyền.'], 403);
        }

        $isAssigned = $task->assignments()->where('employee_id', $employee->id)->exists();
        if (!$isAssigned) {
            return response()->json(['message' => 'Bạn không được giao công việc này.'], 403);
        }

        $data = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $comment = \App\Models\TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
            'body'    => $data['body'],
        ]);

        $comment->load('user:id,name');

        return response()->json($comment, 201);
    }

    /**
     * Lấy danh sách ghi chú tiến độ.
     */
    public function getNotes(Task $task, Request $request)
    {
        $employee = $request->user()->employee;
        if (!$employee) {
            return response()->json(['data' => []]);
        }

        $isAssigned = $task->assignments()->where('employee_id', $employee->id)->exists();
        if (!$isAssigned) {
            return response()->json(['message' => 'Bạn không được giao công việc này.'], 403);
        }

        $comments = $task->comments()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $comments]);
    }
}
