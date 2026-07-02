<?php

namespace Tests\Feature\Report;

use App\Models\CashFlow;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FinancialReportPnlCashFlowExclusionTest extends TestCase
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
     * Case 1 & 2 — Khách trả hàng (có và không dấu) không vào Chi phí hay Chi phí khác
     */
    public function test_order_return_exclusion_from_expenses(): void
    {
        // Chi tiền trả hàng khách (có dấu)
        CashFlow::create([
            'code'           => 'PC-RET1-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 35800000,
            'time'           => now(),
            'category'       => 'Chi tiền trả hàng khách',
            'reference_type' => 'OrderReturn',
            'payment_method' => 'cash',
            'status'         => 'active',
        ]);

        // Chi tien tra hang khach (không dấu)
        CashFlow::create([
            'code'           => 'PC-RET2-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 12000000,
            'time'           => now(),
            'category'       => 'Chi tien tra hang khach',
            'reference_type' => 'OrderReturn',
            'payment_method' => 'cash',
            'status'         => 'active',
        ]);

        // Chi trả hàng khách (không dấu khác)
        CashFlow::create([
            'code'           => 'PC-RET3-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 5000000,
            'time'           => now(),
            'category'       => 'Chi tra hang khach',
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
                ->where('report.totalExpenses', 0)
                ->where('report.totalOtherExpenses', 0)
        );
    }

    /**
     * Case 3 — Trả hàng NCC không vào Thu nhập khác
     */
    public function test_purchase_return_exclusion_from_other_income(): void
    {
        CashFlow::create([
            'code'           => 'PT-PR1-' . uniqid(),
            'type'           => 'receipt',
            'amount'         => 6800000,
            'time'           => now(),
            'category'       => 'Thu tiền NCC trả hàng',
            'reference_type' => 'PurchaseReturn',
            'payment_method' => 'cash',
            'status'         => 'active',
        ]);

        CashFlow::create([
            'code'           => 'PT-PR2-' . uniqid(),
            'type'           => 'receipt',
            'amount'         => 3000000,
            'time'           => now(),
            'category'       => 'Thu tien NCC tra hang',
            'reference_type' => 'PurchaseReturn',
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
                ->where('report.totalOtherIncome', 0)
        );
    }

    /**
     * Case 4 — Đối trừ công nợ không vào Thu nhập khác
     */
    public function test_debt_offset_exclusion_from_other_income(): void
    {
        CashFlow::create([
            'code'           => 'PT-DO1-' . uniqid(),
            'type'           => 'receipt',
            'amount'         => 17710000,
            'time'           => now(),
            'category'       => 'Đối trừ công nợ',
            'reference_type' => 'DebtOffset',
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
                ->where('report.totalOtherIncome', 0)
        );
    }

    /**
     * Case 5 — Hủy đối trừ công nợ không vào Chi phí
     */
    public function test_debt_offset_cancel_exclusion_from_expenses(): void
    {
        CashFlow::create([
            'code'           => 'PC-DOC1-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 17710000,
            'time'           => now(),
            'category'       => 'Hủy đối trừ công nợ',
            'reference_type' => 'DebtOffsetCancel',
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
                ->where('report.totalExpenses', 0)
                ->where('report.totalOtherExpenses', 0)
        );
    }

    /**
     * Case 6 — CashFlow cancelled không tính
     */
    public function test_cancelled_cash_flow_exclusion(): void
    {
        CashFlow::create([
            'code'           => 'PC-CANCEL1-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 9000000,
            'time'           => now(),
            'category'       => 'Quảng cáo',
            'payment_method' => 'cash',
            'status'         => 'cancelled',
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
     * Case 7 — Chi phí thật vẫn được tính
     */
    public function test_active_real_expense_included(): void
    {
        CashFlow::create([
            'code'           => 'PC-REAL1-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 8717290,
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
                ->where('report.totalExpenses', 8717290)
        );
    }

    /**
     * Case 8 — Chi phí khác exact match vẫn được tính ở mục 9
     */
    public function test_active_real_other_expense_included_in_other_expenses(): void
    {
        CashFlow::create([
            'code'           => 'PC-REAL2-' . uniqid(),
            'type'           => 'payment',
            'amount'         => 1000000,
            'time'           => now(),
            'category'       => 'Chi phí khác',
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
                ->where('report.totalExpenses', 0)
                ->where('report.totalOtherExpenses', 1000000)
        );
    }
}
