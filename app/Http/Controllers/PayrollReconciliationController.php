<?php

namespace App\Http\Controllers;

use App\Services\CsvService;
use App\Services\PayrollReconciliationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PayrollReconciliationController extends Controller
{
    public function page()
    {
        return Inertia::render('Employees/PayrollReconciliation');
    }

    public function index(Request $request, PayrollReconciliationService $service)
    {
        return response()->json($service->audit($this->filters($request)));
    }

    public function export(Request $request, PayrollReconciliationService $service)
    {
        $report = $service->audit($this->filters($request));
        $rows = collect($report['data'])->map(fn ($row) => [
            $row['employee_code'],
            $row['employee_name'],
            $row['branch'],
            $row['salary_balance_cache'],
            $row['ledger_balance'],
            $row['difference'],
            $row['legacy_balance'],
            implode('|', $row['issues']),
            $row['primary_status'],
            $row['suggested_action'],
        ]);

        return CsvService::export([
            'Mã nhân viên', 'Nhân viên', 'Chi nhánh', 'Cache', 'Ledger',
            'Chênh lệch', 'Legacy balance', 'Issues', 'Trạng thái', 'Đề xuất',
        ], $rows, 'payroll-reconciliation-'.now()->format('Ymd-His').'.csv');
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'section' => 'nullable|in:all,cache,payments,advances,legacy',
            'branch' => 'nullable|integer|exists:branches,id',
            'employee' => 'nullable|string|max:100',
        ]);
    }
}
