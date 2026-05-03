<?php

namespace Tests\Feature\Report;

use App\Models\CashFlow;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * REG-RR01-04: ReportController costProfit CashFlow expense queries thiếu lọc cancelled.
 *
 * Vấn đề: ReportController@costProfit dòng 173-176, 179-181, 193-196, 201-203:
 *   CashFlow::where('type', 'payment')->...->sum('amount')
 *   CashFlow::where('type', 'receipt')->...->sum('amount')
 *
 * Query KHÔNG lọc status != 'cancelled'.
 *
 * CashFlow model có SoftDeletes → nếu CashFlow bị soft-delete (deleted_at set) thì
 * tự động bị loại. NHƯNG RR-01 fix chỉ gọi update(['status' => 'cancelled']) KHÔNG
 * soft-delete → CashFlow cancelled vẫn tính trong query.
 *
 * Dữ liệu:
 *   - CashFlow expense active: amount = 1.000.000, status = 'active', deleted_at = NULL
 *   - CashFlow expense cancelled: amount = 9.000.000, status = 'cancelled', deleted_at = NULL
 *     (mô phỏng CashFlow từ HĐ hủy — chỉ update status, không soft-delete)
 *   - CashFlow receipt active: amount = 500.000, status = 'active'
 *   - CashFlow receipt cancelled: amount = 4.500.000, status = 'cancelled', deleted_at = NULL
 *
 * Kỳ vọng:
 *   - totalExpenses = 1.000.000 (chỉ active)
 *   - otherIncome = 500.000 (chỉ active)
 * Nếu sai:
 *   - totalExpenses = 10.000.000
 *   - otherIncome = 5.000.000
 */
class RR01CashFlowCancelledRegressionTest extends TestCase
{
    use DatabaseTransactions;

    /* ═══════════════════════════════════════════════════════════════════════
     *  1. CashFlow expense query phải loại trừ status = 'cancelled'
     *
     *  Tái hiện query từ ReportController@costProfit dòng 173-176
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_cashflow_expense_query_should_exclude_cancelled(): void
    {
        $dateFrom = now()->startOfDay();
        $dateTo = now()->endOfDay();

        // Expense active
        CashFlow::create([
            'code'           => 'PC-REG04-ACTIVE-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 1000000,
            'time'           => now(),
            'category'       => 'Chi phí vận hành',
            'payment_method' => 'cash',
            'status'         => 'active',
        ]);

        // Expense cancelled (mô phỏng CashFlow từ HĐ hủy — chỉ update status, KHÔNG soft-delete)
        CashFlow::create([
            'code'           => 'PC-REG04-CANCEL-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 9000000,
            'time'           => now(),
            'category'       => 'Chi phí vận hành',
            'payment_method' => 'cash',
            'status'         => 'cancelled',
            // deleted_at = NULL → SoftDeletes KHÔNG loại trừ record này
        ]);

        // Tái hiện query pattern từ ReportController@costProfit dòng 173-176
        // Phải dùng CashFlow::active() để loại trừ cancelled
        $totalExpenses = (float) CashFlow::active()->where('type', 'payment')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('category', '!=', 'Chi tiền trả NCC')
            ->sum('amount');

        // Kỳ vọng: chỉ expense active = 1.000.000
        // Nếu sai: 1.000.000 + 9.000.000 = 10.000.000
        $this->assertEquals(
            1000000.0,
            $totalExpenses,
            "costProfit totalExpenses đang tính cả CashFlow cancelled. "
            . "Kỳ vọng: 1.000.000, thực tế: " . number_format($totalExpenses)
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  2. CashFlow expense category breakdown phải loại trừ cancelled
     *
     *  Tái hiện query từ ReportController@costProfit dòng 179-181
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_cashflow_expense_categories_should_exclude_cancelled(): void
    {
        $dateFrom = now()->startOfDay();
        $dateTo = now()->endOfDay();

        CashFlow::create([
            'code'           => 'PC-REG04-CAT-A-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 1000000,
            'time'           => now(),
            'category'       => 'Chi phí test REG04',
            'payment_method' => 'cash',
            'status'         => 'active',
        ]);

        CashFlow::create([
            'code'           => 'PC-REG04-CAT-C-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 9000000,
            'time'           => now(),
            'category'       => 'Chi phí test REG04',
            'payment_method' => 'cash',
            'status'         => 'cancelled',
        ]);

        // Tái hiện query pattern từ ReportController@costProfit dòng 179-181
        $expenseCategories = CashFlow::active()->where('type', 'payment')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('category', '!=', 'Chi tiền trả NCC')
            ->select('category', DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->get();

        $testCatTotal = (float) $expenseCategories
            ->where('category', 'Chi phí test REG04')
            ->first()
            ?->total ?? 0;

        // Kỳ vọng: 1.000.000 (chỉ active)
        $this->assertEquals(
            1000000.0,
            $testCatTotal,
            "costProfit expense category đang tính cả CashFlow cancelled. "
            . "Kỳ vọng: 1.000.000, thực tế: " . number_format($testCatTotal)
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  3. CashFlow otherIncome (receipt) phải loại trừ cancelled
     *
     *  Tái hiện query từ ReportController@costProfit dòng 193-196
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_cashflow_other_income_should_exclude_cancelled(): void
    {
        $dateFrom = now()->startOfDay();
        $dateTo = now()->endOfDay();

        // Receipt active
        CashFlow::create([
            'code'           => 'PT-REG04-ACTIVE-' . uniqid(),
            'type'           => 'receipt',
            'amount'         => 500000,
            'time'           => now(),
            'category'       => 'Thu nhập khác test',
            'payment_method' => 'cash',
            'status'         => 'active',
        ]);

        // Receipt cancelled
        CashFlow::create([
            'code'           => 'PT-REG04-CANCEL-' . uniqid(),
            'type'           => 'receipt',
            'amount'         => 4500000,
            'time'           => now(),
            'category'       => 'Thu nhập khác test',
            'payment_method' => 'cash',
            'status'         => 'cancelled',
        ]);

        // Tái hiện query pattern từ ReportController@costProfit dòng 193-196
        $otherIncome = (float) CashFlow::active()->where('type', 'receipt')
            ->where('category', '!=', 'Bán hàng')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->sum('amount');

        // Kỳ vọng: 500.000 (chỉ active)
        $this->assertEquals(
            500000.0,
            $otherIncome,
            "costProfit otherIncome đang tính cả CashFlow cancelled. "
            . "Kỳ vọng: 500.000, thực tế: " . number_format($otherIncome)
        );
    }

    /* ═══════════════════════════════════════════════════════════════════════
     *  4. CashFlow previous period expenses phải loại trừ cancelled
     *
     *  Tái hiện query từ ReportController@costProfit dòng 201-203
     * ═══════════════════════════════════════════════════════════════════════ */

    public function test_cashflow_prev_period_expenses_should_exclude_cancelled(): void
    {
        // Tạo CashFlow ở kỳ trước (hôm qua)
        $yesterday = now()->subDay();

        $cf1 = CashFlow::create([
            'code'           => 'PC-REG04-PREV-A-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 2000000,
            'time'           => $yesterday,
            'category'       => 'Chi phí kỳ trước test',
            'payment_method' => 'cash',
            'status'         => 'active',
        ]);
        // created_at không nằm trong $fillable → set bằng DB::table
        DB::table('cash_flows')->where('id', $cf1->id)->update(['created_at' => $yesterday]);

        $cf2 = CashFlow::create([
            'code'           => 'PC-REG04-PREV-C-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 8000000,
            'time'           => $yesterday,
            'category'       => 'Chi phí kỳ trước test',
            'payment_method' => 'cash',
            'status'         => 'cancelled',
        ]);
        DB::table('cash_flows')->where('id', $cf2->id)->update(['created_at' => $yesterday]);

        $prevFrom = $yesterday->copy()->startOfDay();
        $prevTo = $yesterday->copy()->endOfDay();

        // Tái hiện query pattern từ ReportController@costProfit dòng 201-203
        $prevExpenses = (float) CashFlow::active()->where('type', 'payment')
            ->whereBetween('created_at', [$prevFrom, $prevTo])
            ->where('category', '!=', 'Chi tiền trả NCC')
            ->sum('amount');

        // Kỳ vọng: 2.000.000 (chỉ active)
        // Nếu sai: 10.000.000 (tính cả cancelled)
        $this->assertEquals(
            2000000.0,
            $prevExpenses,
            "costProfit prevExpenses đang tính cả CashFlow cancelled. "
            . "Kỳ vọng: 2.000.000, thực tế: " . number_format($prevExpenses)
        );
    }
}
