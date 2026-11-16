<?php

namespace App\Http\Controllers;

use App\Services\SalesControls\BelowCostSaleService;
use App\Services\SalesControls\SalesControlService;
use App\Support\Security\CostVisibility;
use Illuminate\Http\Request;

/**
 * Batch 10 đợt 4 — item 32. Read-only check the POS / invoice edit screens
 * call before saving so they can warn "Giá bán thấp hơn giá vốn". It never
 * blocks the sale. Users without cost_profit.view get the flag only, never
 * the cost value.
 */
class SalesControlController extends Controller
{
    public function belowCostCheck(Request $request, BelowCostSaleService $belowCost, SalesControlService $controls)
    {
        $validated = $request->validate([
            'items' => 'required|array|max:200',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'nullable|numeric',
            'items.*.qty' => 'nullable|numeric',
            'items.*.price' => 'nullable|numeric',
            'items.*.discount' => 'nullable|numeric',
            'items.*.serial_ids' => 'nullable|array',
            'items.*.serial_ids.*' => 'integer',
        ]);

        $canViewCost = CostVisibility::canView($request->user());
        $lines = array_map(function (array $line) use ($canViewCost) {
            if (! $canViewCost) {
                $line['cost_price'] = null;
            }
            $line['message'] = 'Giá bán thấp hơn giá vốn';

            return $line;
        }, $belowCost->preview($validated['items']));

        return response()->json([
            'enabled' => $controls->belowCostWarningEnabled(),
            'can_view_cost' => $canViewCost,
            'has_below_cost' => $lines !== [],
            'lines' => $lines,
        ]);
    }
}
