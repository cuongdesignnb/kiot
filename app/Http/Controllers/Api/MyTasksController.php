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
}
