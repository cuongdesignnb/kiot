<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * Lịch sử thao tác — admin xem tất cả, nhân viên xem của mình.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = ActivityLog::with(['user:id,name', 'employee:id,name'])
            ->latest();

        // Nhân viên thường chỉ xem của mình
        if ($user && !$user->hasPermission('activities.view')) {
            $employee = $user->employee;
            if ($employee) {
                $query->where('employee_id', $employee->id);
            } else {
                $query->where('user_id', $user->id);
            }
        }

        // Filters
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('created_at', [$request->from, $request->to . ' 23:59:59']);
        }
        if ($request->filled('search')) {
            $query->where('description', 'like', '%' . $request->search . '%');
        }

        return response()->json($query->paginate($request->per_page ?? 30));
    }

    /**
     * Lấy danh sách action types có sẵn.
     */
    public function actionTypes()
    {
        return response()->json(ActivityLog::ACTION_LABELS);
    }
}
