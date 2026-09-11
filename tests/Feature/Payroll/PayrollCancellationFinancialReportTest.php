<?php

namespace Tests\Feature\Payroll;

use App\Models\Branch;
use App\Models\CashFlow;
use App\Models\Employee;
use App\Models\EmployeeSalarySetting;
use App\Models\Paysheet;
use App\Models\User;
use App\Services\EmployeeSalaryLedgerService;
use App\Services\SalaryPaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PayrollCancellationFinancialReportTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => str_repeat('k', 32)]);
        $this->actingAs(User::factory()->create(['role_id' => null]));
        $this->travelTo(\Carbon\Carbon::parse('2031-06-15 10:00:00'));
    }

    private function page(string $url, string $key): array
    {
        $value = [];
        $this->get($url)->assertOk()->assertInertia(function (Assert $page) use ($key, &$value) {
            $page->where($key, function ($data) use (&$value) {
                $value = collect($data)->toArray();

                return true;
            });
        });

        return $value;
    }

    public static function cases(): array
    {
        return ['unposted' => [false, 0, false], 'posted unpaid' => [true, 0, false], 'posted partial' => [true, 400, false], 'posted full' => [true, 1000, false], 'cancel next month' => [true, 400, true], 'advance and payment' => [true, 400, false, true]];
    }

    #[DataProvider('cases')]
    public function test_cancellation_updates_report_cashbook_exports_and_visibility(bool $locked, int $paid, bool $nextMonth, bool $withAdvance = false): void
    {
        $branch = Branch::create(['name' => 'Synthetic reporting branch']);
        $employee = Employee::create(['code' => uniqid('QA-E'), 'name' => 'Synthetic report employee', 'is_active' => true, 'branch_id' => $branch->id]);
        EmployeeSalarySetting::create(['employee_id' => $employee->id, 'salary_type' => 'fixed', 'base_salary' => 1000]);
        $sheetId = $this->postJson('/api/paysheets', ['pay_period' => 'monthly', 'period_start' => '2031-06-01', 'period_end' => '2031-06-30', 'scope' => 'custom', 'branch_id' => $branch->id, 'employee_ids' => [$employee->id]])->assertOk()->json('data.id');
        $sheet = Paysheet::findOrFail($sheetId);
        $independent = CashFlow::create(['code' => uniqid('QA-PC'), 'type' => 'payment', 'amount' => 30, 'time' => now(), 'branch_id' => $branch->id, 'category' => 'Synthetic expense', 'status' => 'active']);
        $advance = null;
        if ($withAdvance) {
            $advance = app(\App\Services\SalaryAdvanceService::class)->create($employee, ['branch_id' => $branch->id, 'amount' => 100, 'advance_date' => now(), 'payment_method' => 'cash', 'note' => 'Synthetic advance before posting'], uniqid('qa-advance'));
        }
        if ($locked) {
            $this->putJson('/api/paysheets/'.$sheetId.'/lock')->assertOk();
        }
        $payment = null;
        if ($paid) {
            $payment = app(SalaryPaymentService::class)->pay($sheet->fresh(), [['payslip_id' => $sheet->payslips()->firstOrFail()->id, 'amount' => $paid]], ['payment_method' => 'cash', 'payment_date' => now()], uniqid('qa-payment'))[0];
        }
        $url = '/reports/financial-report?time_mode=custom&date_from=2031-06-01&date_to=2031-06-30&branch_id='.$branch->id;
        $before = $this->page($url, 'report');
        $cashBefore = $this->page('/cash-flows', 'metrics');
        $this->assertEquals($locked ? 1030 : 30, $before['totalExpenses']);
        if ($nextMonth) {
            $this->travelTo(\Carbon\Carbon::parse('2031-07-05 10:00:00'));
        }
        $hash = $this->getJson('/api/paysheets/'.$sheetId.'/cancel-preview')->assertOk()->json('confirmation_hash');
        $payload = ['reason' => 'Synthetic report cancellation', 'confirmation_hash' => $hash];
        $this->putJson('/api/paysheets/'.$sheetId.'/cancel', $payload)->assertOk();
        $after = $this->page($url, 'report');
        $cashAfter = $this->page('/cash-flows', 'metrics');
        $this->assertEquals(30, $after['totalExpenses']);
        $this->assertEquals($locked ? 1000 : 0, $after['netProfit'] - $before['netProfit']);
        $this->assertEquals($paid, $cashBefore['totalPayments'] - $cashAfter['totalPayments']);
        $this->assertEquals($paid, $cashAfter['fundBalance'] - $cashBefore['fundBalance']);
        $this->assertSame($withAdvance ? -100 : 0, app(EmployeeSalaryLedgerService::class)->currentBalance($employee->id));
        if ($advance) {
            $this->assertSame(100, $advance->fresh()->remaining_amount);
            $this->assertSame('active', CashFlow::findOrFail($advance->cash_flow_id)->status);
        }
        $this->assertSame('active', $independent->fresh()->status);
        $this->getJson('/api/paysheets?search='.$sheet->code)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.total_salary', 0);
        $this->getJson('/api/paysheets?status=cancelled&search='.$sheet->code)->assertOk()->assertJsonCount($locked ? 1 : 0, 'data');
        $this->assertStringNotContainsString($sheet->code, $this->get('/paysheets/export?search='.$sheet->code)->assertOk()->streamedContent());
        $history = $this->get('/paysheets/export?status=cancelled&search='.$sheet->code)->assertOk()->streamedContent();
        if ($locked) {
            $this->assertStringContainsString($sheet->code, $history);
            $this->getJson('/api/paysheets/'.$sheetId)->assertOk();
        } else {
            $this->assertStringNotContainsString($sheet->code, $history);
            $this->getJson('/api/paysheets/'.$sheetId)->assertNotFound();
            $this->get('/employees/paysheets/'.$sheetId.'/edit')->assertNotFound();
            $this->get('/paysheets/'.$sheetId.'/print')->assertNotFound();
        }
        if ($payment) {
            $code = CashFlow::withTrashed()->findOrFail($payment->cash_flow_id)->code;
            $this->assertStringNotContainsString($code, $this->get('/cash-flows/export')->assertOk()->streamedContent());
            $this->assertStringContainsString($code, $this->get('/cash-flows/export?status=cancelled')->assertOk()->streamedContent());
        }
        $this->putJson('/api/paysheets/'.$sheetId.'/cancel', $payload)->assertOk();
        $this->assertSame($after, $this->page($url, 'report'));
        $this->assertSame($cashAfter, $this->page('/cash-flows', 'metrics'));
        $this->assertDatabaseHas('paysheets', ['id' => $sheetId, 'status' => 'cancelled']);
    }
}
