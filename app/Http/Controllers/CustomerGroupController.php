<?php

namespace App\Http\Controllers;

use App\Models\CustomerGroup;
use Illuminate\Http\Request;

/**
 * Step 24.4A — CustomerGroup CRUD API.
 *
 * In 24.4A: only create/list/update. No auto-assign engine.
 */
class CustomerGroupController extends Controller
{
    /**
     * List active groups for sidebar filter dropdown.
     */
    public function options()
    {
        $groups = CustomerGroup::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'discount_type', 'discount_value', 'note', 'is_active']);

        return response()->json($groups);
    }

    /**
     * Create a new customer group (modal from sidebar).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255|unique:customer_groups,name',
            'code'           => 'nullable|string|max:50|unique:customer_groups,code',
            'discount_type'  => 'nullable|string|in:amount,percent',
            'discount_value' => 'nullable|numeric|min:0',
            'note'           => 'nullable|string',
            'description'    => 'nullable|string',
            'conditions'     => 'nullable|array',
            'update_mode'    => 'nullable|string|in:add_matching,refresh_matching,none',
            'auto_update'    => 'nullable|boolean',
        ]);

        // Percent cap
        if (($validated['discount_type'] ?? null) === 'percent' && ($validated['discount_value'] ?? 0) > 100) {
            return response()->json(['message' => 'Giảm giá phần trăm không được vượt quá 100%.'], 422);
        }

        $validated['created_by'] = auth()->id();
        $validated['is_active'] = true;

        $group = CustomerGroup::create($validated);

        return response()->json([
            'success' => true,
            'group'   => $group,
            'message' => 'Tạo nhóm khách hàng thành công.',
        ]);
    }

    /**
     * Update group info.
     */
    public function update(Request $request, CustomerGroup $customerGroup)
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255|unique:customer_groups,name,' . $customerGroup->id,
            'code'           => 'nullable|string|max:50|unique:customer_groups,code,' . $customerGroup->id,
            'discount_type'  => 'nullable|string|in:amount,percent',
            'discount_value' => 'nullable|numeric|min:0',
            'note'           => 'nullable|string',
            'description'    => 'nullable|string',
            'conditions'     => 'nullable|array',
            'update_mode'    => 'nullable|string|in:add_matching,refresh_matching,none',
            'auto_update'    => 'nullable|boolean',
            'is_active'      => 'nullable|boolean',
        ]);

        if (($validated['discount_type'] ?? null) === 'percent' && ($validated['discount_value'] ?? 0) > 100) {
            return response()->json(['message' => 'Giảm giá phần trăm không được vượt quá 100%.'], 422);
        }

        $customerGroup->update($validated);

        return response()->json([
            'success' => true,
            'group'   => $customerGroup->fresh(),
            'message' => 'Cập nhật nhóm khách hàng thành công.',
        ]);
    }
}
