<?php

namespace App\Http\Controllers;

use App\Models\WorkdaySetting;
use Illuminate\Http\Request;

class WorkdaySettingController extends Controller
{
    private function defaultWeekDays(): array
    {
        return [
            'mon' => true, 'tue' => true, 'wed' => true,
            'thu' => true, 'fri' => true, 'sat' => true, 'sun' => false,
        ];
    }

    public function show(Request $request)
    {
        $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : null;

        $setting = WorkdaySetting::where('branch_id', $branchId)->first();
        if (!$setting) {
            $setting = new WorkdaySetting([
                'branch_id' => $branchId,
                'week_days' => $this->defaultWeekDays(),
                'status' => 'active',
            ]);
        }

        return response()->json(['success' => true, 'data' => $setting]);
    }

    public function upsert(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'week_days' => ['required', 'array'],
            'week_days.mon' => ['required', 'boolean'],
            'week_days.tue' => ['required', 'boolean'],
            'week_days.wed' => ['required', 'boolean'],
            'week_days.thu' => ['required', 'boolean'],
            'week_days.fri' => ['required', 'boolean'],
            'week_days.sat' => ['required', 'boolean'],
            'week_days.sun' => ['required', 'boolean'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);

        $branchId = $data['branch_id'] ?? null;
        $userId = auth()->id();

        $setting = WorkdaySetting::firstOrNew(['branch_id' => $branchId]);
        $setting->branch_id = $branchId;
        $setting->week_days = $data['week_days'];
        $setting->status = $data['status'] ?? ($setting->exists ? $setting->status : 'active');

        if (!$setting->exists) {
            $setting->created_by = $userId;
        }
        $setting->updated_by = $userId;
        $setting->save();

        return response()->json(['success' => true, 'message' => 'Đã lưu ngày làm việc trong tuần', 'data' => $setting]);
    }
}
