<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RepairPerformanceTier;
use Illuminate\Http\Request;

class RepairPerformanceTierController extends Controller
{
    public function index()
    {
        return response()->json(
            RepairPerformanceTier::orderBy('sort_order')->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'min_percent'    => 'required|integer|min:0|max:100',
            'max_percent'    => 'required|integer|min:0|max:100|gte:min_percent',
            'salary_percent' => 'required|integer|min:0|max:200',
            'label'          => 'nullable|string|max:50',
            'sort_order'     => 'nullable|integer',
        ]);

        $tier = RepairPerformanceTier::create($data);

        return response()->json($tier, 201);
    }

    public function update(Request $request, RepairPerformanceTier $tier)
    {
        $data = $request->validate([
            'min_percent'    => 'required|integer|min:0|max:100',
            'max_percent'    => 'required|integer|min:0|max:100|gte:min_percent',
            'salary_percent' => 'required|integer|min:0|max:200',
            'label'          => 'nullable|string|max:50',
            'sort_order'     => 'nullable|integer',
        ]);

        $tier->update($data);

        return response()->json($tier);
    }

    public function destroy(RepairPerformanceTier $tier)
    {
        $tier->delete();

        return response()->json(['message' => 'Đã xóa.']);
    }
}
