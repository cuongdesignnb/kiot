<?php

namespace App\Support\Reports;

use App\Models\CashFlow;
use App\Models\Paysheet;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Receipts and payments that count in profit reports (Báo cáo tài chính,
 * Chi phí & Lợi nhuận). Money that only moves value already counted elsewhere
 * is left out: supplier payments (in cost of goods), customer refunds (in
 * returns), debt offsets and adjustments, transfers between funds, payroll
 * slips (payroll is added from locked paysheets), and sales/debt collections
 * (in revenue).
 */
final class PnlCashFlows
{
    public const EXCLUDED_REFERENCE_TYPES = [
        'OrderReturn',
        'PurchaseReturn',
        'DebtOffset',
        'DebtOffsetCancel',
        'paysheet',
        'Paysheet',
        'PaysheetPayment',
    ];

    public const EXCLUDED_CATEGORIES = [
        'Chi tiền trả hàng khách',
        'Chi tien tra hang khach',
        'Chi trả hàng khách',
        'Chi tra hang khach',

        'Thu tiền NCC trả hàng',
        'Thu tien NCC tra hang',
        'NCC hoàn tiền trả hàng',
        'NCC hoan tien tra hang',

        'Đối trừ công nợ',
        'Doi tru cong no',
        'Hủy đối trừ công nợ',
        'Huy doi tru cong no',

        'Chi lương nhân viên',
        'Chi luong nhan vien',
        'Lương nhân viên',
        'Luong nhan vien',
        'Thanh toán lương',
        'Thanh toan luong',
        'Tạm ứng lương',
        'Tam ung luong',

        'Chi tiền trả NCC',
        'Chi thanh toán NCC',
        'Chi thanh toan NCC',
        'Chi tiền trả NCC hàng',
        'Chi tien tra NCC hang',

        'Điều chỉnh công nợ',
        'Dieu chinh cong no',
        'Chuyển/Rút',
        'Chuyen/Rut',
        'Chuyển quỹ nội bộ',
        'Chuyen quy noi bo',
        '',
    ];

    /** Receipts that are sales or debt collections: already in revenue. */
    public const SALES_RECEIPT_CATEGORIES = [
        'Thu tiền khách trả',
        'Thu tien khach tra',
        'Thu nợ khách hàng',
        'Thu no khach hang',
        'Thu tiền khách hàng',
        'Thu tien khach hang',
        'Thu tiền sửa chữa',
        'Thu tien sua chua',
        'Bán hàng',
        'Ban hang',
        '',
    ];

    /** Expense categories shown as "Chi phí khác" rather than operating expenses. */
    public const OTHER_EXPENSE_CATEGORIES = [
        'Chi phí khác',
        'Chi phi khac',
        'Khác',
        'Khac',
    ];

    public static function base(string $type, Carbon $startDate, Carbon $endDate, $branchId = null): Builder
    {
        $timeColumn = Schema::hasColumn('cash_flows', 'time') ? 'time' : 'created_at';

        $query = CashFlow::active()
            ->nonPayrollForExpense()
            ->where('type', $type)
            ->whereBetween($timeColumn, [$startDate, $endDate])
            ->where(fn ($q) => $q->whereNull('reference_type')->orWhereNotIn('reference_type', self::EXCLUDED_REFERENCE_TYPES))
            ->where(fn ($q) => $q->whereNull('category')->orWhereNotIn('category', self::EXCLUDED_CATEGORIES));

        if (Schema::hasColumn('cash_flows', 'accounting_result')) {
            $query->where('accounting_result', true);
        }
        if ($branchId && Schema::hasColumn('cash_flows', 'branch_id')) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    /** Operating expenses (payments other than "Chi phí khác"). */
    public static function expenses(Carbon $startDate, Carbon $endDate, $branchId = null): Builder
    {
        return self::base('payment', $startDate, $endDate, $branchId)
            ->where(fn ($q) => $q->whereNull('category')->orWhereNotIn('category', self::OTHER_EXPENSE_CATEGORIES));
    }

    /** Income that is not a sale or a debt collection. */
    public static function otherIncome(Carbon $startDate, Carbon $endDate, $branchId = null): Builder
    {
        return self::base('receipt', $startDate, $endDate, $branchId)
            ->where(fn ($q) => $q->whereNull('category')->orWhereNotIn('category', self::SALES_RECEIPT_CATEGORIES));
    }

    public static function otherExpenses(Carbon $startDate, Carbon $endDate, $branchId = null): Builder
    {
        return self::base('payment', $startDate, $endDate, $branchId)
            ->whereIn('category', self::OTHER_EXPENSE_CATEGORIES);
    }

    public static function payrollQuery(Carbon $startDate, Carbon $endDate, $branchId = null): Builder
    {
        $query = Paysheet::query()
            ->where('status', 'locked')
            ->whereDate('period_start', '>=', $startDate->toDateString())
            ->whereDate('period_end', '<=', $endDate->toDateString());

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    public static function payroll(Carbon $startDate, Carbon $endDate, $branchId = null): float
    {
        return (float) self::payrollQuery($startDate, $endDate, $branchId)->sum('total_salary');
    }
}
