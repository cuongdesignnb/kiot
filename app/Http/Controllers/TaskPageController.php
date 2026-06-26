<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TaskCategory;
use App\Services\TaskAccessService;
use Illuminate\Http\Request;

class TaskPageController extends Controller
{
    public function index()
    {
        return Inertia::render('Tasks/Index', [
            'branches'   => Branch::select('id', 'name')->get(),
            'employees'  => Employee::where('is_active', true)->select('id', 'name')->get(),
            'categories' => TaskCategory::where('is_active', true)->select('id', 'name', 'color', 'type')->get(),
        ]);
    }

    public function show(Request $request, TaskAccessService $taskAccess, $id)
    {
        $task = Task::findOrFail($id);
        abort_unless($taskAccess->canViewTask($request->user(), $task), 403, 'Bạn không có quyền xem công việc này.');

        return Inertia::render('Tasks/Show', [
            'taskId'     => (int) $id,
            'employees'  => Employee::where('is_active', true)->select('id', 'name')->get(),
            'categories' => TaskCategory::where('is_active', true)->select('id', 'name', 'color', 'type')->get(),
        ]);
    }

    public function performance()
    {
        return Inertia::render('Tasks/Performance', [
            'employees' => Employee::where('is_active', true)->select('id', 'name')->get(),
        ]);
    }

    public function myTasks()
    {
        return Inertia::render('Tasks/MyTasks');
    }
}
