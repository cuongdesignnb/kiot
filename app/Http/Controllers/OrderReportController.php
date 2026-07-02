<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OrderReportController extends Controller
{
    public function index(Request $request)
    {
        // ── Filters ──
        $concern       = $request->input('concern', 'product');       // product|time|status
        $period        = $request->input('period', 'this_month');
        $dateFrom      = $request->input('date_from');
        $dateTo        = $request->input('date_to');
        $deliveryFrom  = $request->input('delivery_from');
        $deliveryTo    = $request->input('delivery_to');
        $branchId      = $request->input('branch_id');
        $status        = $request->input('status');
        $customerId    = $request->input('customer_id');
        $viewMode      = $request->input('view', 'chart');

        // ── Resolve date range ──
        [$startDate, $endDate, $periodLabel, $groupBy] = $this->resolvePeriod($period, $dateFrom, $dateTo);

        // ── Base order query ──
        $orderQuery = Order::whereBetween('created_at', [$startDate, $endDate]);
        if ($branchId)   $orderQuery->where('branch_id', $branchId);
        if ($status)     $orderQuery->where('status', $status);
        if ($customerId) $orderQuery->where('customer_id', $customerId);

        // Delivery date filter
        if ($deliveryFrom) {
            $orderQuery->where('expected_delivery_date', '>=', Carbon::parse($deliveryFrom)->startOfDay());
        }
        if ($deliveryTo) {
            $orderQuery->where('expected_delivery_date', '<=', Carbon::parse($deliveryTo)->endOfDay());
        }

        // ── Build data based on concern ──
        $chartData = [];

        switch ($concern) {
            case 'product':
                $chartData = $this->buildProductChart($orderQuery);
                break;
            case 'time':
                $chartData = $this->buildTimeSeries($orderQuery, $startDate, $endDate, $groupBy);
                break;
            case 'status':
                $chartData = $this->buildStatusChart($orderQuery);
                break;
        }

        // ── Filter options ──
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $statuses = [
            'draft' => 'Phiếu tạm',
            'confirmed' => 'Đã xác nhận',
            'delivering' => 'Đang giao hàng',
            'completed' => 'Hoàn thành',
            'cancelled' => 'Đã hủy',
        ];
        $branchName = $branchId ? Branch::find($branchId)?->name : 'Tất cả chi nhánh';

        return Inertia::render('Reports/OrderReport', [
            'filters' => [
                'concern'       => $concern,
                'period'        => $period,
                'date_from'     => $startDate->format('Y-m-d'),
                'date_to'       => $endDate->format('Y-m-d'),
                'delivery_from' => $deliveryFrom,
                'delivery_to'   => $deliveryTo,
                'branch_id'     => $branchId,
                'status'        => $status,
                'customer_id'   => $customerId,
                'view'          => $viewMode,
            ],
            'periodLabel' => $periodLabel,
            'chartData'   => $chartData,
            'branchName'  => $branchName,
            'branches'    => $branches,
            'statuses'    => $statuses,
        ]);
    }

    // ═══════════════════════════════════════
    // Period resolver (same logic as SalesReport)
    // ═══════════════════════════════════════
    private function resolvePeriod(string $period, ?string $customFrom, ?string $customTo): array
    {
        switch ($period) {
            case 'this_week':
                $s = Carbon::now()->startOfWeek(); $e = Carbon::now()->endOfWeek();
                return [$s, $e, 'Tuần này', 'day'];
            case 'this_month':
                $s = Carbon::now()->startOfMonth(); $e = Carbon::now()->endOfDay();
                return [$s, $e, 'Tháng này', 'day'];
            case 'this_year':
                $s = Carbon::now()->startOfYear(); $e = Carbon::now()->endOfDay();
                return [$s, $e, 'Năm nay', 'month'];
            case 'last_year':
                $s = Carbon::now()->subYear()->startOfYear(); $e = Carbon::now()->subYear()->endOfYear();
                return [$s, $e, 'Năm trước', 'month'];
            case 'custom':
                $s = $customFrom ? Carbon::parse($customFrom)->startOfDay() : Carbon::now()->startOfMonth();
                $e = $customTo ? Carbon::parse($customTo)->endOfDay() : Carbon::now()->endOfDay();
                $g = $s->diffInDays($e) > 90 ? 'month' : 'day';
                return [$s, $e, 'Tùy chỉnh', $g];
            default:
                $s = Carbon::now()->startOfMonth(); $e = Carbon::now()->endOfDay();
                return [$s, $e, 'Tháng này', 'day'];
        }
    }

    // ═══════════════════════════════════════
    // Concern: Hàng hóa → Top 10 sản phẩm đặt nhiều nhất
    // ═══════════════════════════════════════
    private function buildProductChart($orderQuery)
    {
        $orderIds = (clone $orderQuery)->pluck('id');

        $topProducts = OrderItem::whereIn('order_id', $orderIds)
            ->select(
                'product_id',
                DB::raw('SUM(qty) as total_qty'),
                DB::raw('SUM(subtotal) as total_value')
            )
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get();

        $labels = [];
        $qtyData = [];
        $valueData = [];

        foreach ($topProducts as $item) {
            $product = Product::find($item->product_id);
            $labels[] = $product ? $product->name : 'SP #' . $item->product_id;
            $qtyData[] = (int) $item->total_qty;
            $valueData[] = (float) $item->total_value;
        }

        return [
            'title' => 'Top 10 hàng hóa được đặt nhiều nhất',
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Số lượng đặt', 'data' => $qtyData],
            ],
            'total' => array_sum($qtyData),
            'type' => 'horizontal_bar',
        ];
    }

    // ═══════════════════════════════════════
    // Concern: Thời gian → Đơn đặt hàng theo thời gian
    // ═══════════════════════════════════════
    private function buildTimeSeries($orderQuery, $start, $end, $groupBy)
    {
        $format = $groupBy === 'month' ? '%m-%Y' : '%d-%m-%Y';
        $phpFormat = $groupBy === 'month' ? 'm-Y' : 'd-m-Y';

        $orders = (clone $orderQuery)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '{$format}') as period"),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('COALESCE(SUM(total_payment), 0) as total_value')
            )
            ->groupBy('period')
            ->pluck('total_value', 'period');

        $orderCounts = (clone $orderQuery)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '{$format}') as period"),
                DB::raw('COUNT(*) as order_count')
            )
            ->groupBy('period')
            ->pluck('order_count', 'period');

        $labels = [];
        $valueData = [];
        $countData = [];
        $current = $start->copy();
        while ($current <= $end) {
            $key = $current->format($phpFormat);
            $labels[] = $groupBy === 'month' ? $current->format('m/Y') : $current->format('d/m');
            $valueData[] = (float) ($orders[$key] ?? 0);
            $countData[] = (int) ($orderCounts[$key] ?? 0);
            $groupBy === 'month' ? $current->addMonth() : $current->addDay();
        }

        return [
            'title' => 'Giá trị đặt hàng theo thời gian',
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Giá trị đặt hàng', 'data' => $valueData],
            ],
            'total' => array_sum($valueData),
            'type' => 'bar',
        ];
    }

    // ═══════════════════════════════════════
    // Concern: Trạng thái → Đơn theo trạng thái
    // ═══════════════════════════════════════
    private function buildStatusChart($orderQuery)
    {
        $statusMap = [
            'draft' => 'Phiếu tạm',
            'confirmed' => 'Đã xác nhận',
            'delivering' => 'Đang giao hàng',
            'completed' => 'Hoàn thành',
            'cancelled' => 'Đã hủy',
        ];

        $data = (clone $orderQuery)
            ->select('status', DB::raw('COUNT(*) as cnt'), DB::raw('COALESCE(SUM(total_payment), 0) as total_value'))
            ->groupBy('status')
            ->get();

        $labels = [];
        $countData = [];
        $valueData = [];

        foreach ($data as $row) {
            $labels[] = $statusMap[$row->status] ?? $row->status;
            $countData[] = (int) $row->cnt;
            $valueData[] = (float) $row->total_value;
        }

        return [
            'title' => 'Đơn đặt hàng theo trạng thái',
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Số đơn', 'data' => $countData],
            ],
            'total' => array_sum($countData),
            'type' => 'bar',
        ];
    }
}
