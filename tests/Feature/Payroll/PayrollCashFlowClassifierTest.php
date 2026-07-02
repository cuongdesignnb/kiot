<?php

namespace Tests\Feature\Payroll;

use App\Models\CashFlow;
use App\Services\PayrollCashFlowClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollCashFlowClassifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_scope_keeps_legacy_null_status_and_excludes_cancelled_or_deleted_rows(): void
    {
        $legacy = CashFlow::create($this->cashFlowData(['code' => 'CF-LEGACY', 'status' => null]));
        CashFlow::create($this->cashFlowData(['code' => 'CF-CANCELLED', 'status' => 'cancelled']));
        $deleted = CashFlow::create($this->cashFlowData(['code' => 'CF-DELETED', 'status' => 'active']));
        $deleted->delete();

        $this->assertSame([$legacy->id], CashFlow::withTrashed()->active()->pluck('id')->all());
    }

    public function test_payroll_classifier_uses_structured_fields_and_expense_scope_excludes_payroll_flows(): void
    {
        $payment = CashFlow::create($this->cashFlowData([
            'code' => 'CF-PAYROLL',
            'reference_type' => 'paysheet_payment',
            'category' => 'Chi lương nhân viên',
        ]));
        $advance = CashFlow::create($this->cashFlowData([
            'code' => 'CF-ADVANCE',
            'reference_type' => 'salary_advance',
            'category' => 'Tạm ứng lương',
        ]));
        $ordinary = CashFlow::create($this->cashFlowData([
            'code' => 'CF-OTHER',
            'reference_type' => 'manual_expense',
            'category' => 'Chi phí điện',
        ]));
        CashFlow::create($this->cashFlowData([
            'code' => 'CF-TEXT-ONLY',
            'reference_type' => 'manual_expense',
            'category' => 'Chi phí điện',
            'description' => 'Thanh toán lương trong ghi chú',
        ]));

        $this->assertEqualsCanonicalizing(
            [$payment->id, $advance->id],
            CashFlow::payrollRelated()->pluck('id')->all()
        );
        $this->assertContains($ordinary->id, CashFlow::nonPayrollForExpense()->pluck('id')->all());
        $this->assertTrue(app(PayrollCashFlowClassifier::class)->isPayrollRelated($advance));
    }

    private function cashFlowData(array $overrides = []): array
    {
        return array_merge([
            'code' => 'CF-'.uniqid(),
            'type' => 'payment',
            'amount' => 100000,
            'time' => now(),
            'category' => 'Chi phí khác',
            'reference_type' => null,
            'description' => null,
            'status' => 'active',
        ], $overrides);
    }
}
