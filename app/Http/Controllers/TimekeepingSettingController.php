<?php

namespace App\Http\Controllers;

use App\Models\TimekeepingSetting;
use Illuminate\Http\Request;

class TimekeepingSettingController extends Controller
{
    private function resolveBranchId(Request $request): ?int
    {
        return $request->filled('branch_id') ? $request->integer('branch_id') : null;
    }

    public function show(Request $request)
    {
        $branchId = $this->resolveBranchId($request);

        $setting = TimekeepingSetting::where('branch_id', $branchId)->first();

        if (!$setting) {
            return response()->json([
                'success' => true,
                'data' => [
                    'branch_id' => $branchId,
                    'standard_hours_per_day' => 8,
                    'use_shift_allowances' => true,
                    'late_grace_minutes' => 0,
                    'early_grace_minutes' => 0,
                    'allow_multiple_shifts_one_inout' => false,
                    'enforce_shift_checkin_window' => false,
                    'ot_rounding_minutes' => 0,
                    'ot_after_minutes' => 0,
                    'status' => 'active',
                ],
            ]);
        }

        return response()->json(['success' => true, 'data' => $setting]);
    }

    public function upsert(Request $request)
    {
        $branchId = $this->resolveBranchId($request);

        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'standard_hours_per_day' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'use_shift_allowances' => ['nullable', 'boolean'],
            'late_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:300'],
            'early_grace_minutes' => ['nullable', 'integer', 'min:0', 'max:300'],
            'allow_multiple_shifts_one_inout' => ['nullable', 'boolean'],
            'enforce_shift_checkin_window' => ['nullable', 'boolean'],
            'ot_rounding_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'ot_after_minutes' => ['nullable', 'integer', 'min:0', 'max:300'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $userId = $request->user()?->id;

        $setting = TimekeepingSetting::updateOrCreate(
            ['branch_id' => $branchId],
            [
                'branch_id' => $branchId,
                'standard_hours_per_day' => $data['standard_hours_per_day'] ?? 8,
                'use_shift_allowances' => $data['use_shift_allowances'] ?? true,
                'late_grace_minutes' => $data['late_grace_minutes'] ?? 0,
                'early_grace_minutes' => $data['early_grace_minutes'] ?? 0,
                'allow_multiple_shifts_one_inout' => $data['allow_multiple_shifts_one_inout'] ?? false,
                'enforce_shift_checkin_window' => $data['enforce_shift_checkin_window'] ?? false,
                'ot_rounding_minutes' => $data['ot_rounding_minutes'] ?? 0,
                'ot_after_minutes' => $data['ot_after_minutes'] ?? 0,
                'status' => $data['status'] ?? 'active',
                'updated_by' => $userId,
            ]
        );

        if (!$setting->created_by) {
            $setting->created_by = $userId;
            $setting->save();
        }

        return response()->json(['success' => true, 'message' => 'Đã lưu thiết lập chấm công', 'data' => $setting]);
    }
}
