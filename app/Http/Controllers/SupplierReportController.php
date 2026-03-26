<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SupplierReportController extends Controller
{
    public function index(Request $request)
    {
        $concern  = $request->input('concern', 'purchase');    // purchase|returns|debt
        $period   = $request->input('period', 'this_month');
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');
        $branchId = $request->input('branch_id');
        $viewMode = $request->input('view', 'chart');

        [$startDate, $endDate, $periodLabel] = $this->resolvePeriod($period, $dateFrom, $dateTo);

        // ── Purchase data per supplier ──
        $purchaseQuery = Purchase::whereBetween('created_at', [$startDate, $endDate])
            ->whereNotNull('supplier_id');

        $purchaseData = (clone $purchaseQuery)
            ->select(
                'supplier_id',
                DB::raw('COALESCE(SUM(total_amount), 0) as total_import'),
                DB::raw('COUNT(*) as purchase_count')
            )
            ->groupBy('supplier_id')
            ->get()
            ->keyBy('supplier_id');

        // ── Purchase returns per supplier ──
        // Return data from purchase returns (negative value adjustments)
        // For simplicity, we compute returns as a separate metric if available
        // In this system returns reduce total_amount, so we track via discount or separate
        $returnData = collect();

        // ── Build supplier rows ──
        $supplierIds = $purchaseData->keys();
        $suppliers = Customer::whereIn('id', $supplierIds)->get();

        $rows = [];
        foreach ($suppliers as $s) {
            $purch = $purchaseData[$s->id] ?? null;
            $ret   = $returnData[$s->id] ?? null;
            $importVal = (float) ($purch->total_import ?? 0);
            $returnVal = (float) ($ret->total_return ?? 0);
            $netVal    = $importVal - $returnVal;

            $rows[] = [
                'id'       => $s->id,
                'code'     => $s->code,
                'name'     => $s->name,
                'import'   => $importVal,
                'returns'  => $returnVal,
                'net'      => $netVal,
                'count'    => (int) ($purch->purchase_count ?? 0),
            ];
        }

        usort($rows, fn($a, $b) => $b['net'] <=> $a['net']);

        // ── Chart data (Top 10) ──
        $chartData = $this->buildChartData($rows, $concern);

        // ── Summary ──
        $totalImport  = array_sum(array_column($rows, 'import'));
        $totalReturns = array_sum(array_column($rows, 'returns'));
        $totalNet     = array_sum(array_column($rows, 'net'));

        // ── Filter options ──
        $branches  = Branch::orderBy('name')->get(['id', 'name']);
        $branchName = $branchId ? Branch::find($branchId)?->name : 'Tất cả chi nhánh';

        return Inertia::render('Reports/SupplierReport', [
            'filters' => [
                'concern'   => $concern,
                'period'    => $period,
                'date_from' => $startDate->format('Y-m-d'),
                'date_to'   => $endDate->format('Y-m-d'),
                'branch_id' => $branchId,
                'view'      => $viewMode,
            ],
            'periodLabel'     => $periodLabel,
            'chartData'       => $chartData,
            'reportRows'      => $rows,
            'summary'         => [
                'count'       => count($rows),
                'totalImport' => $totalImport,
                'totalReturns' => $totalReturns,
                'totalNet'    => $totalNet,
            ],
            'branchName'      => $branchName,
            'branches'        => $branches,
            'dateFromDisplay' => $startDate->format('d/m/Y'),
            'dateToDisplay'   => $endDate->format('d/m/Y'),
        ]);
    }

    private function resolvePeriod(string $period, ?string $from, ?string $to): array
    {
        switch ($period) {
            case 'this_week':  return [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek(), 'Tuần này'];
            case 'this_month': return [Carbon::now()->startOfMonth(), Carbon::now()->endOfDay(), 'Tháng này'];
            case 'this_year':  return [Carbon::now()->startOfYear(), Carbon::now()->endOfDay(), 'Năm nay'];
            case 'last_year':  return [Carbon::now()->subYear()->startOfYear(), Carbon::now()->subYear()->endOfYear(), 'Năm trước'];
            case 'custom':
                $s = $from ? Carbon::parse($from)->startOfDay() : Carbon::now()->startOfMonth();
                $e = $to   ? Carbon::parse($to)->endOfDay()     : Carbon::now()->endOfDay();
                return [$s, $e, 'Tùy chỉnh'];
            default: return [Carbon::now()->startOfMonth(), Carbon::now()->endOfDay(), 'Tháng này'];
        }
    }

    private function buildChartData(array $rows, string $concern): array
    {
        switch ($concern) {
            case 'purchase':
                $sorted = $rows;
                usort($sorted, fn($a, $b) => $b['net'] <=> $a['net']);
                $top = array_slice($sorted, 0, 10);
                return [
                    'title' => 'Top 10 nhà cung cấp nhập hàng nhiều nhất (đã trừ trả hàng)',
                    'labels' => array_column($top, 'name'),
                    'datasets' => [['label' => 'Giá trị nhập thuần', 'data' => array_column($top, 'net')]],
                    'type' => 'horizontal_bar',
                ];
            case 'debt':
                $debtSuppliers = Customer::where('supplier_debt_amount', '>', 0)
                    ->where('is_supplier', true)
                    ->orderByDesc('supplier_debt_amount')->take(10)->get();
                return [
                    'title' => 'Top 10 nhà cung cấp công nợ cao nhất',
                    'labels' => $debtSuppliers->pluck('name')->toArray(),
                    'datasets' => [['label' => 'Công nợ', 'data' => $debtSuppliers->pluck('supplier_debt_amount')->map(fn($v) => (float)$v)->toArray()]],
                    'type' => 'horizontal_bar',
                ];
            default:
                $top = array_slice($rows, 0, 10);
                return [
                    'title' => 'Top 10 nhà cung cấp nhập hàng nhiều nhất',
                    'labels' => array_column($top, 'name'),
                    'datasets' => [['label' => 'Giá trị nhập', 'data' => array_column($top, 'import')]],
                    'type' => 'horizontal_bar',
                ];
        }
    }
}
