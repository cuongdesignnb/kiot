<?php

namespace App\Support\Reports;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\StockTransfer;
use App\Models\Task;
use App\Models\User;
use App\Models\Warranty;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * STEP 24.1 — Operational Dashboard metrics.
 *
 * Read-only service. KHÔNG mutate DB. Mọi method trả array gọn.
 * Dùng count/sum query và limit list 5-10. Không loop toàn bộ dataset.
 */
class OperationalDashboardService
{
    /** Action keys được coi là rủi ro cao (Step 24.1). */
    public const HIGH_RISK_ACTIONS = [
        ActivityLog::ACTION_INVOICE_CANCEL,
        ActivityLog::ACTION_RETURN_CANCEL,
        ActivityLog::ACTION_PURCHASE_DELETE,
        ActivityLog::ACTION_PURCHASE_RETURN_CANCEL,
        ActivityLog::ACTION_DAMAGE_CANCEL,
        ActivityLog::ACTION_STOCKTAKE_COMPLETE,
        ActivityLog::ACTION_STOCKTAKE_CANCEL,
        ActivityLog::ACTION_TRANSFER_CANCEL,
        ActivityLog::ACTION_PART_DISASSEMBLE,
        ActivityLog::ACTION_TASK_COMPLETE,
        ActivityLog::ACTION_TASK_WARRANTY_ATTACH,
        ActivityLog::ACTION_WARRANTY_UPDATE,
        ActivityLog::ACTION_CUSTOMER_DEBT_ADJUST,
    ];

    public function getSerialControl(): array
    {
        $countsByStatus = SerialImei::select('status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        $latestInTransit = SerialImei::with('product:id,name,sku')
            ->where('status', 'in_transit')
            ->latest('updated_at')
            ->limit(10)
            ->get(['id', 'serial_number', 'product_id', 'status', 'updated_at'])
            ->map(fn ($s) => [
                'id'            => $s->id,
                'serial_number' => $s->serial_number,
                'product_name'  => $s->product?->name,
                'product_sku'   => $s->product?->sku,
                'updated_at'    => $s->updated_at,
            ]);

        return [
            'in_transit_count'        => (int) ($countsByStatus['in_transit'] ?? 0),
            'used_for_repair_count'   => (int) ($countsByStatus['used_for_repair'] ?? 0),
            'dismantled_count'        => (int) ($countsByStatus['dismantled'] ?? 0),
            'defective_count'         => (int) ($countsByStatus['defective'] ?? 0),
            'returning_count'         => (int) ($countsByStatus['returning'] ?? 0),
            'returned_count'          => (int) ($countsByStatus['returned'] ?? 0),
            'latest_in_transit'       => $latestInTransit,
        ];
    }

    public function getStockTransferControl(): array
    {
        $now = Carbon::now();
        $h24 = $now->copy()->subHours(24);
        $h72 = $now->copy()->subHours(72);

        $transferring = StockTransfer::where('status', 'transferring');

        $transferringCount = (clone $transferring)->count();
        $over24h = (clone $transferring)
            ->where(function ($q) use ($h24) {
                $q->where('sent_date', '<', $h24)
                  ->orWhere(function ($q2) use ($h24) {
                      $q2->whereNull('sent_date')->where('created_at', '<', $h24);
                  });
            })->count();
        $over72h = (clone $transferring)
            ->where(function ($q) use ($h72) {
                $q->where('sent_date', '<', $h72)
                  ->orWhere(function ($q2) use ($h72) {
                      $q2->whereNull('sent_date')->where('created_at', '<', $h72);
                  });
            })->count();

        // Serial transfer in_transit: count items with serial_ids non-empty in transferring transfers
        $serialInTransitCount = StockTransfer::where('status', 'transferring')
            ->whereHas('items', function ($q) {
                $q->whereNotNull('serial_ids');
            })
            ->count();

        $latest = StockTransfer::with(['fromBranch:id,name', 'toBranch:id,name'])
            ->where('status', 'transferring')
            ->latest('id')
            ->limit(10)
            ->get(['id', 'code', 'from_branch_id', 'to_branch_id', 'sent_date', 'created_at', 'total_quantity'])
            ->map(function ($t) use ($now) {
                $base = $t->sent_date ?: $t->created_at;
                $age = $base ? $now->diffInHours($base) : null;
                return [
                    'id'            => $t->id,
                    'code'          => $t->code,
                    'from_branch'   => $t->fromBranch?->name,
                    'to_branch'     => $t->toBranch?->name,
                    'sent_date'     => $t->sent_date,
                    'created_at'    => $t->created_at,
                    'total_quantity'=> (int) $t->total_quantity,
                    'age_hours'     => $age !== null ? (int) $age : null,
                ];
            });

        return [
            'transferring_count'             => $transferringCount,
            'transferring_over_24h_count'    => $over24h,
            'transferring_over_72h_count'    => $over72h,
            'serial_transfer_in_transit_count' => $serialInTransitCount,
            'latest_transferring_transfers'  => $latest,
        ];
    }

    public function getRepairControl(): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();

        $repairBase = Task::where('type', Task::TYPE_REPAIR)
            ->whereNotIn('status', [Task::STATUS_COMPLETED, Task::STATUS_CANCELLED]);

        $externalOpen = (clone $repairBase)->where('external', true)->count();
        $internalOpen = (clone $repairBase)->where('external', false)->count();

        $completedThisMonth = Task::where('type', Task::TYPE_REPAIR)
            ->where('status', Task::STATUS_COMPLETED)
            ->where('completed_at', '>=', $startOfMonth)
            ->count();

        $repairDebtTotal = (float) Task::where('type', Task::TYPE_REPAIR)
            ->where('external', true)
            ->where('debt_amount', '>', 0)
            ->sum('debt_amount');

        $warrantyCoveredThisMonth = (float) Task::where('type', Task::TYPE_REPAIR)
            ->where('status', Task::STATUS_COMPLETED)
            ->where('completed_at', '>=', $startOfMonth)
            ->sum('warranty_covered_amount');

        $pendingWarrantyAttach = Task::where('type', Task::TYPE_REPAIR)
            ->where('external', true)
            ->whereNull('warranty_id')
            ->whereNotIn('status', [Task::STATUS_COMPLETED, Task::STATUS_CANCELLED])
            ->count();

        $latestOpen = (clone $repairBase)
            ->latest('id')
            ->limit(10)
            ->get(['id', 'code', 'title', 'external', 'status', 'sub_status', 'customer_name', 'created_at'])
            ->map(fn ($t) => [
                'id'            => $t->id,
                'code'          => $t->code,
                'title'         => $t->title,
                'external'      => (bool) $t->external,
                'status'        => $t->status,
                'sub_status'    => $t->sub_status,
                'customer_name' => $t->customer_name,
                'created_at'    => $t->created_at,
            ]);

        return [
            'external_open_count'        => $externalOpen,
            'internal_open_count'        => $internalOpen,
            'completed_this_month_count' => $completedThisMonth,
            'repair_debt_total'          => $repairDebtTotal,
            'warranty_covered_this_month'=> $warrantyCoveredThisMonth,
            'pending_warranty_attach_count' => $pendingWarrantyAttach,
            'latest_open_repairs'        => $latestOpen,
        ];
    }

    public function getWarrantyControl(): array
    {
        $today = Carbon::today();
        $in30 = $today->copy()->addDays(30);
        $in7  = $today->copy()->addDays(7);

        $valid = Warranty::where('warranty_end_date', '>=', $today)->count();
        $expired = Warranty::where('warranty_end_date', '<', $today)->count();
        $expiring30 = Warranty::whereBetween('warranty_end_date', [$today, $in30])->count();
        $expiring7  = Warranty::whereBetween('warranty_end_date', [$today, $in7])->count();
        $unknown = Warranty::whereNull('warranty_end_date')->count();

        $latestExpiring = Warranty::with('product:id,name,sku')
            ->whereBetween('warranty_end_date', [$today, $in30])
            ->orderBy('warranty_end_date')
            ->limit(10)
            ->get(['id', 'invoice_code', 'product_id', 'serial_imei', 'customer_name', 'warranty_end_date'])
            ->map(fn ($w) => [
                'id'                => $w->id,
                'invoice_code'      => $w->invoice_code,
                'product_name'      => $w->product?->name,
                'serial_imei'       => $w->serial_imei,
                'customer_name'     => $w->customer_name,
                'warranty_end_date' => $w->warranty_end_date,
            ]);

        return [
            'valid_count'              => $valid,
            'expired_count'            => $expired,
            'expiring_30_days_count'   => $expiring30,
            'expiring_7_days_count'    => $expiring7,
            'unknown_count'            => $unknown,
            'latest_expiring_warranties' => $latestExpiring,
        ];
    }

    public function getInventoryRisk(): array
    {
        $negativeStockCount = Product::where('stock_quantity', '<', 0)->count();
        $negativeStockProducts = Product::where('stock_quantity', '<', 0)
            ->orderBy('stock_quantity')
            ->limit(10)
            ->get(['id', 'sku', 'name', 'stock_quantity'])
            ->map(fn ($p) => [
                'id'             => $p->id,
                'sku'            => $p->sku,
                'name'           => $p->name,
                'stock_quantity' => (int) $p->stock_quantity,
            ]);

        // Serial mismatch: products has_serial=true có stock_quantity != count(in_stock serials)
        // Lấy max 50 product has_serial gần đây để check, không scan toàn bộ
        $serialProducts = Product::where('has_serial', true)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'sku', 'name', 'stock_quantity']);

        $mismatchProducts = [];
        if ($serialProducts->isNotEmpty()) {
            $ids = $serialProducts->pluck('id')->all();
            $serialCounts = SerialImei::whereIn('product_id', $ids)
                ->where('status', 'in_stock')
                ->select('product_id', DB::raw('COUNT(*) as cnt'))
                ->groupBy('product_id')
                ->pluck('cnt', 'product_id')
                ->all();
            foreach ($serialProducts as $p) {
                $serialCount = (int) ($serialCounts[$p->id] ?? 0);
                if ((int) $p->stock_quantity !== $serialCount) {
                    $mismatchProducts[] = [
                        'id'             => $p->id,
                        'sku'            => $p->sku,
                        'name'           => $p->name,
                        'stock_quantity' => (int) $p->stock_quantity,
                        'serial_count'   => $serialCount,
                        'diff'           => (int) $p->stock_quantity - $serialCount,
                    ];
                }
            }
        }
        $mismatchCount = count($mismatchProducts);
        $mismatchTop10 = array_slice($mismatchProducts, 0, 10);

        return [
            'negative_stock_count'         => $negativeStockCount,
            'negative_stock_products'      => $negativeStockProducts,
            'serial_stock_mismatch_count'  => $mismatchCount,
            'serial_mismatch_products'     => $mismatchTop10,
        ];
    }

    public function getFinanceControl(): array
    {
        $today = Carbon::today();

        $totalCustomerDebt = (float) Customer::where('debt_amount', '>', 0)->sum('debt_amount');
        $totalSupplierDebt = (float) Customer::where('is_supplier', true)
            ->where('supplier_debt_amount', '>', 0)
            ->sum('supplier_debt_amount');

        $repairDebt = (float) Task::where('type', Task::TYPE_REPAIR)
            ->where('external', true)
            ->where('debt_amount', '>', 0)
            ->sum('debt_amount');

        $cashReceiptsToday = (float) CashFlow::where('type', 'receipt')
            ->whereDate('time', $today)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'cancelled');
            })
            ->sum('amount');
        $cashPaymentsToday = (float) CashFlow::where('type', 'payment')
            ->whereDate('time', $today)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '!=', 'cancelled');
            })
            ->sum('amount');

        return [
            'total_customer_debt' => $totalCustomerDebt,
            'total_supplier_debt' => $totalSupplierDebt,
            'repair_debt_total'   => $repairDebt,
            'cash_receipts_today' => $cashReceiptsToday,
            'cash_payments_today' => $cashPaymentsToday,
            'net_cash_today'      => $cashReceiptsToday - $cashPaymentsToday,
        ];
    }

    public function getHighRiskActivities(?User $user): array
    {
        $canView = $user && $user->hasPermission('system.audit.view');

        if (!$canView) {
            // Trả count tổng nhưng KHÔNG trả chi tiết.
            $today = Carbon::today();
            $oneWeek = Carbon::now()->subDays(7);
            return [
                'visible'         => false,
                'count_today'     => (int) ActivityLog::whereIn('action', self::HIGH_RISK_ACTIONS)
                    ->whereDate('created_at', $today)->count(),
                'count_7_days'    => (int) ActivityLog::whereIn('action', self::HIGH_RISK_ACTIONS)
                    ->where('created_at', '>=', $oneWeek)->count(),
                'latest_logs'     => [],
            ];
        }

        $today = Carbon::today();
        $oneWeek = Carbon::now()->subDays(7);

        $latest = ActivityLog::with(['user:id,name', 'employee:id,name,code'])
            ->whereIn('action', self::HIGH_RISK_ACTIONS)
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn ($log) => [
                'id'            => $log->id,
                'action'        => $log->action,
                'action_label'  => $log->action_label,
                'action_icon'   => $log->action_icon,
                'description'   => $log->description,
                'user_name'     => $log->user?->name,
                'subject_type'  => $log->subject_type,
                'subject_id'    => $log->subject_id,
                'ip_address'    => $log->ip_address,
                'created_at'    => $log->created_at,
            ]);

        return [
            'visible'      => true,
            'count_today'  => (int) ActivityLog::whereIn('action', self::HIGH_RISK_ACTIONS)
                ->whereDate('created_at', $today)->count(),
            'count_7_days' => (int) ActivityLog::whereIn('action', self::HIGH_RISK_ACTIONS)
                ->where('created_at', '>=', $oneWeek)->count(),
            'latest_logs'  => $latest,
        ];
    }
}
