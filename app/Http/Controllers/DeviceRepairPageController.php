<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Branch;
use App\Models\Employee;

class DeviceRepairPageController extends Controller
{
    public function index()
    {
        return Inertia::render('Repairs/Index', [
            'branches'  => Branch::select('id', 'name')->get(),
            'employees' => Employee::where('is_active', true)->select('id', 'name')->get(),
        ]);
    }

    public function show($id)
    {
        return Inertia::render('Repairs/Show', [
            'repairId'  => (int) $id,
            'employees' => Employee::where('is_active', true)->select('id', 'name')->get(),
        ]);
    }

    public function performance()
    {
        return Inertia::render('Repairs/Performance', [
            'employees' => Employee::where('is_active', true)->select('id', 'name')->get(),
        ]);
    }
}
