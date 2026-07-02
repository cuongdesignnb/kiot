<?php

namespace App\Http\Controllers;

use App\Models\PayrollSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PayrollSettingController extends Controller
{
    private function resolveBranchId(Request $request): ?int
    {
        return $request->filled('branch_id') ? $request->integer('branch_id') : null;
    }

    public function show(Request $request)
    {
        $branchId = $this->resolveBranchId($request);

        $setting = PayrollSetting::where('branch_id', $branchId)->first();

        if (!$setting) {
            return response()->json([
                'success' => true,
                'data' => [
                    'branch_id' => $branchId,
                    'pay_cycle' => 'monthly',
                    'start_day' => 26,
                    'end_day' => 25,
                    'start_in_prev_month' => true,
                    'pay_day' => 5,
                    'default_recalculate_timekeeping' => true,
                    'auto_generate_enabled' => false,
                    'late_half_day_enabled' => false,
                    'late_half_day_threshold' => 120,
                    'late_penalty_enabled' => false,
                    'late_penalty_tiers' => [],
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
            'pay_cycle' => ['nullable', 'string', Rule::in(['monthly', 'weekly', 'biweekly'])],
            'start_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'end_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'start_in_prev_month' => ['nullable', 'boolean'],
            'pay_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'default_recalculate_timekeeping' => ['nullable', 'boolean'],
            'auto_generate_enabled' => ['nullable', 'boolean'],
            'late_half_day_enabled' => ['nullable', 'boolean'],
            'late_half_day_threshold' => ['nullable', 'integer', 'min:1', 'max:480'],
            'late_penalty_enabled' => ['nullable', 'boolean'],
            'late_penalty_tiers' => ['nullable', 'array'],
            'late_penalty_tiers.*.minutes' => ['required', 'integer', 'min:1', 'max:480'],
            'late_penalty_tiers.*.amount' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $userId = $request->user()?->id;

        $setting = PayrollSetting::updateOrCreate(
            ['branch_id' => $branchId],
            [
                'branch_id' => $branchId,
                'pay_cycle' => $data['pay_cycle'] ?? 'monthly',
                'start_day' => $data['start_day'] ?? 26,
                'end_day' => $data['end_day'] ?? 25,
                'start_in_prev_month' => $data['start_in_prev_month'] ?? true,
                'pay_day' => $data['pay_day'] ?? 5,
                'default_recalculate_timekeeping' => $data['default_recalculate_timekeeping'] ?? true,
                'auto_generate_enabled' => $data['auto_generate_enabled'] ?? false,
                'late_half_day_enabled' => $data['late_half_day_enabled'] ?? false,
                'late_half_day_threshold' => $data['late_half_day_threshold'] ?? 120,
                'late_penalty_enabled' => $data['late_penalty_enabled'] ?? false,
                'late_penalty_tiers' => $data['late_penalty_tiers'] ?? [],
                'status' => $data['status'] ?? 'active',
                'updated_by' => $userId,
            ]
        );

        if (!$setting->created_by) {
            $setting->created_by = $userId;
            $setting->save();
        }

        return response()->json(['success' => true, 'message' => 'Đã lưu thiết lập tính lương', 'data' => $setting]);
    }
}
