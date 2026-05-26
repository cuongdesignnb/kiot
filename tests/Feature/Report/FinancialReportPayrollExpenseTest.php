<?php
 
namespace Tests\Feature\Report;
 
use App\Models\Branch;
use App\Models\CashFlow;
use App\Models\Paysheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
 
class FinancialReportPayrollExpenseTest extends TestCase
{
    use DatabaseTransactions;
 
    private User $admin;
    private Carbon $startDate;
    private Carbon $endDate;
 
    protected function setUp(): void
    {
        parent::setUp();
 
        $this->admin = User::create([
            'name'     => 'Admin Report Test',
            'email'    => 'admin-report-' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role_id'  => null, // admin
        ]);
 
        $this->startDate = Carbon::now()->startOfMonth();
        $this->endDate = Carbon::now()->endOfMonth();
    }
 
    /**
     * Case 1 — Bảng lương calculated được cộng vào Chi phí (6)
     */
    public function test_calculated_paysheet_included_in_expenses(): void
    {
        Paysheet::create([
            'code'                  => 'BL-' . uniqid(),
            'name'                  => 'Bảng lương test',
            'period_start'          => $this->startDate->toDateString(),
            'period_end'            => $this->endDate->toDateString(),
            'status'                => 'calculated',
            'total_salary'          => 20000000,
            'standard_working_days' => 26,
            'employee_count'        => 5,
        ]);
 
        $this->actingAs($this->admin);
        $response = $this->get('/reports/financial-report?' . http_build_query([
            'time_mode' => 'custom',
            'date_from' => $this->startDate->format('Y-m-d'),
            'date_to'   => $this->endDate->format('Y-m-d'),
        ]));
 
        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('Reports/FinancialReport')
                ->where('report.totalExpenses', 20000000)
                ->where('report.expensesByCategory.0.name', 'Chi lương nhân viên')
                ->where('report.expensesByCategory.0.amount', 20000000)
        );
    }
 
    /**
     * Case 2 — Bảng lương locked được cộng vào Chi phí (6)
     */
    public function test_locked_paysheet_included_in_expenses(): void
    {
        Paysheet::create([
            'code'                  => 'BL-' . uniqid(),
            'name'                  => 'Bảng lương locked test',
            'period_start'          => $this->startDate->toDateString(),
            'period_end'            => $this->endDate->toDateString(),
            'status'                => 'locked',
            'total_salary'          => 15000000,
            'standard_working_days' => 26,
            'employee_count'        => 3,
        ]);
 
        $this->actingAs($this->admin);
        $response = $this->get('/reports/financial-report?' . http_build_query([
            'time_mode' => 'custom',
            'date_from' => $this->startDate->format('Y-m-d'),
            'date_to'   => $this->endDate->format('Y-m-d'),
        ]));
 
        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('Reports/FinancialReport')
                ->where('report.totalExpenses', 15000000)
                ->where('report.expensesByCategory.0.name', 'Chi lương nhân viên')
                ->where('report.expensesByCategory.0.amount', 15000000)
        );
    }
 
    /**
     * Case 3 — Bảng lương cancelled không được tính
     */
    public function test_cancelled_paysheet_excluded(): void
    {
        Paysheet::create([
            'code'                  => 'BL-' . uniqid(),
            'name'                  => 'Bảng lương cancelled test',
            'period_start'          => $this->startDate->toDateString(),
            'period_end'            => $this->endDate->toDateString(),
            'status'                => 'cancelled',
            'total_salary'          => 99999999,
            'standard_working_days' => 26,
            'employee_count'        => 3,
        ]);
 
        $this->actingAs($this->admin);
        $response = $this->get('/reports/financial-report?' . http_build_query([
            'time_mode' => 'custom',
            'date_from' => $this->startDate->format('Y-m-d'),
            'date_to'   => $this->endDate->format('Y-m-d'),
        ]));
 
        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('Reports/FinancialReport')
                ->where('report.totalExpenses', 0)
        );
    }
 
    /**
     * Case 4 — Bảng lương calculating không được tính
     */
    public function test_calculating_paysheet_excluded(): void
    {
        Paysheet::create([
            'code'                  => 'BL-' . uniqid(),
            'name'                  => 'Bảng lương calculating test',
            'period_start'          => $this->startDate->toDateString(),
            'period_end'            => $this->endDate->toDateString(),
            'status'                => 'calculating',
            'total_salary'          => 88888888,
            'standard_working_days' => 26,
            'employee_count'        => 3,
        ]);
 
        $this->actingAs($this->admin);
        $response = $this->get('/reports/financial-report?' . http_build_query([
            'time_mode' => 'custom',
            'date_from' => $this->startDate->format('Y-m-d'),
            'date_to'   => $this->endDate->format('Y-m-d'),
        ]));
 
        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('Reports/FinancialReport')
                ->where('report.totalExpenses', 0)
        );
    }
 
    /**
     * Case 5 — Đã thanh toán lương nhưng không double count CashFlow
     */
    public function test_paid_salary_prevents_double_count(): void
    {
        $code = 'BL-' . uniqid();
        Paysheet::create([
            'code'                  => $code,
            'name'                  => 'Bảng lương paid test',
            'period_start'          => $this->startDate->toDateString(),
            'period_end'            => $this->endDate->toDateString(),
            'status'                => 'calculated',
            'total_salary'          => 20000000,
            'standard_working_days' => 26,
            'employee_count'        => 3,
        ]);
 
        // Tạo cash flow thanh toán lương
        CashFlow::create([
            'code'           => 'PC-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 20000000,
            'time'           => now(),
            'category'       => 'Chi lương nhân viên',
            'reference_type' => 'paysheet',
            'reference_code' => $code,
            'payment_method' => 'cash',
            'status'         => 'active',
        ]);
 
        $this->actingAs($this->admin);
        $response = $this->get('/reports/financial-report?' . http_build_query([
            'time_mode' => 'custom',
            'date_from' => $this->startDate->format('Y-m-d'),
            'date_to'   => $this->endDate->format('Y-m-d'),
        ]));
 
        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('Reports/FinancialReport')
                ->where('report.totalExpenses', 20000000)
                ->where('report.expensesByCategory.0.name', 'Chi lương nhân viên')
                ->where('report.expensesByCategory.0.amount', 20000000)
        );
    }
 
    /**
     * Case 6 — Filter chi nhánh đúng
     */
    public function test_branch_filtering_on_payroll_expenses(): void
    {
        $branchA = Branch::create(['name' => 'Chi nhánh A', 'address' => 'Địa chỉ A']);
        $branchB = Branch::create(['name' => 'Chi nhánh B', 'address' => 'Địa chỉ B']);
 
        Paysheet::create([
            'code'                  => 'BL-A-' . uniqid(),
            'name'                  => 'Bảng lương A',
            'period_start'          => $this->startDate->toDateString(),
            'period_end'            => $this->endDate->toDateString(),
            'status'                => 'calculated',
            'total_salary'          => 10000000,
            'branch_id'             => $branchA->id,
            'standard_working_days' => 26,
            'employee_count'        => 2,
        ]);
 
        Paysheet::create([
            'code'                  => 'BL-B-' . uniqid(),
            'name'                  => 'Bảng lương B',
            'period_start'          => $this->startDate->toDateString(),
            'period_end'            => $this->endDate->toDateString(),
            'status'                => 'calculated',
            'total_salary'          => 30000000,
            'branch_id'             => $branchB->id,
            'standard_working_days' => 26,
            'employee_count'        => 3,
        ]);
 
        $this->actingAs($this->admin);
        $response = $this->get('/reports/financial-report?' . http_build_query([
            'time_mode' => 'custom',
            'date_from' => $this->startDate->format('Y-m-d'),
            'date_to'   => $this->endDate->format('Y-m-d'),
            'branch_id' => $branchA->id,
        ]));
 
        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('Reports/FinancialReport')
                ->where('report.totalExpenses', 10000000)
                ->where('report.expensesByCategory.0.name', 'Chi lương nhân viên')
                ->where('report.expensesByCategory.0.amount', 10000000)
        );
    }
 
    /**
     * Case 7 — Custom range không lấy bảng lương nằm ngoài kỳ
     */
    public function test_custom_range_filters_out_of_period_paysheets(): void
    {
        $prevMonthStart = Carbon::now()->subMonth()->startOfMonth();
        $prevMonthEnd = Carbon::now()->subMonth()->endOfMonth();
 
        Paysheet::create([
            'code'                  => 'BL-PREV-' . uniqid(),
            'name'                  => 'Bảng lương tháng trước',
            'period_start'          => $prevMonthStart->toDateString(),
            'period_end'            => $prevMonthEnd->toDateString(),
            'status'                => 'calculated',
            'total_salary'          => 10000000,
            'standard_working_days' => 26,
            'employee_count'        => 2,
        ]);
 
        $this->actingAs($this->admin);
        $response = $this->get('/reports/financial-report?' . http_build_query([
            'time_mode' => 'custom',
            'date_from' => $this->startDate->format('Y-m-d'),
            'date_to'   => $this->endDate->format('Y-m-d'),
        ]));
 
        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('Reports/FinancialReport')
                ->where('report.totalExpenses', 0)
        );
    }
 
    /**
     * Case 8 — Chi phí thật khác vẫn được tính
     */
    public function test_active_real_expense_still_included_with_payroll(): void
    {
        Paysheet::create([
            'code'                  => 'BL-' . uniqid(),
            'name'                  => 'Bảng lương test',
            'period_start'          => $this->startDate->toDateString(),
            'period_end'            => $this->endDate->toDateString(),
            'status'                => 'calculated',
            'total_salary'          => 20000000,
            'standard_working_days' => 26,
            'employee_count'        => 2,
        ]);
 
        CashFlow::create([
            'code'           => 'PC-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 5000000,
            'time'           => now(),
            'category'       => 'Quảng cáo',
            'payment_method' => 'cash',
            'status'         => 'active',
        ]);
 
        $this->actingAs($this->admin);
        $response = $this->get('/reports/financial-report?' . http_build_query([
            'time_mode' => 'custom',
            'date_from' => $this->startDate->format('Y-m-d'),
            'date_to'   => $this->endDate->format('Y-m-d'),
        ]));
 
        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('Reports/FinancialReport')
                ->where('report.totalExpenses', 25000000)
        );
    }
 
    /**
     * Case 9 — Trả hàng và lương không xung đột
     */
    public function test_payroll_and_order_return_do_not_conflict(): void
    {
        Paysheet::create([
            'code'                  => 'BL-' . uniqid(),
            'name'                  => 'Bảng lương test',
            'period_start'          => $this->startDate->toDateString(),
            'period_end'            => $this->endDate->toDateString(),
            'status'                => 'calculated',
            'total_salary'          => 20000000,
            'standard_working_days' => 26,
            'employee_count'        => 2,
        ]);
 
        CashFlow::create([
            'code'           => 'PC-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 35800000,
            'time'           => now(),
            'category'       => 'Chi tiền trả hàng khách',
            'reference_type' => 'OrderReturn',
            'payment_method' => 'cash',
            'status'         => 'active',
        ]);
 
        $this->actingAs($this->admin);
        $response = $this->get('/reports/financial-report?' . http_build_query([
            'time_mode' => 'custom',
            'date_from' => $this->startDate->format('Y-m-d'),
            'date_to'   => $this->endDate->format('Y-m-d'),
        ]));
 
        $response->assertOk();
        $response->assertInertia(fn ($page) =>
            $page->component('Reports/FinancialReport')
                ->where('report.totalExpenses', 20000000)
        );
    }
}
