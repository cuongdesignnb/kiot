<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * STEP 24.0C — Audit Log Viewer.
 *
 * Routes:
 *   - GET /activity-logs            (Inertia page)
 *   - GET /api/activity-logs        (paginated JSON)
 *   - GET /api/activity-logs/action-types (label/icon map)
 *
 * Middleware: permission:system.audit.view
 */
class ActivityLogController extends Controller
{
    /**
     * Inertia page.
     */
    public function index()
    {
        $employees = Employee::where('is_active', true)
            ->select('id', 'name', 'code')
            ->orderBy('name')
            ->get();

        return Inertia::render('ActivityLogs/Index', [
            'employees' => $employees,
        ]);
    }

    /**
     * JSON paginated logs.
     */
    public function api(Request $request)
    {
        $request->validate([
            'action'       => 'nullable|string|max:100',
            'user_id'      => 'nullable|integer',
            'employee_id'  => 'nullable|integer',
            'subject_type' => 'nullable|string|max:255',
            'subject_id'   => 'nullable|integer',
            'from'         => 'nullable|date',
            'to'           => 'nullable|date',
            'search'       => 'nullable|string|max:200',
            'q'            => 'nullable|string|max:200',
            'per_page'     => 'nullable|integer|min:1|max:200',
            'page'         => 'nullable|integer|min:1',
        ]);

        $perPage = (int) ($request->input('per_page') ?? 30);

        $query = ActivityLog::with([
            'user:id,name,email',
            'employee:id,name,code',
        ])->latest('id');

        if ($action = $request->input('action')) {
            $query->where('action', $action);
        }
        if ($userId = $request->input('user_id')) {
            $query->where('user_id', $userId);
        }
        if ($empId = $request->input('employee_id')) {
            $query->where('employee_id', $empId);
        }
        if ($subjectType = $request->input('subject_type')) {
            $query->where('subject_type', $subjectType);
        }
        if ($subjectId = $request->input('subject_id')) {
            $query->where('subject_id', $subjectId);
        }
        if ($from = $request->input('from')) {
            $query->where('created_at', '>=', $from . ' 00:00:00');
        }
        if ($to = $request->input('to')) {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }
        $search = $request->input('search') ?: $request->input('q');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('action', 'like', "%{$search}%");
            });
        }

        $page = $query->paginate($perPage);

        // Transform để có action_label / action_icon sẵn
        $page->getCollection()->transform(function (ActivityLog $log) {
            $arr = $log->toArray();
            $arr['action_label'] = $log->action_label;
            $arr['action_icon']  = $log->action_icon;
            return $arr;
        });

        return response()->json($page);
    }

    /**
     * Map action key → label/icon cho UI dropdown filter.
     */
    public function actionTypes()
    {
        $map = [];
        foreach (ActivityLog::ACTION_LABELS as $key => $label) {
            $map[$key] = [
                'label' => $label,
                'icon'  => ActivityLog::ACTION_ICONS[$key] ?? '📝',
            ];
        }
        return response()->json($map);
    }
}
