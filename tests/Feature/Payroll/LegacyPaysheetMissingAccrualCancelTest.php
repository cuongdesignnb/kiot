<?php

namespace Tests\Feature\Payroll;

use App\Models\Branch;
use App\Models\CashFlow;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\Paysheet;
use App\Models\PaysheetPayment;
use App\Models\Payslip;
use App\Models\User;
use App\Services\EmployeeSalaryLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LegacyPaysheetMissingAccrualCancelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Employee $employeeA;

    private Employee $employeeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role_id' => null]));
        $this->branch = Branch::create(['name' => 'P0 Legacy Missing Accrual Branch']);
        $this->employeeA = $this->employee('NV-P0-LEGACY-A', 'Legacy Employee A');
        $this->employeeB = $this->employee('NV-P0-LEGACY-B', 'Legacy Employee B');
    }

    public function test_can_cancel_legacy_locked_paysheet_without_payroll_accrual_when_no_active_payment(): void
    {
        [$paysheet, $slips] = $this->legacyPaysheet();

        $this->postJson("/api/paysheets/{$paysheet->id}/cancel", [
            'reason' => 'Huy bang luong legacy thieu accrual',
        ])
            ->assertOk()
            ->assertJsonPath('mode', 'legacy_missing_payroll_accrual')
            ->assertJsonPath('reversed_entries_count', 2);

        $this->assertSame('cancelled', $paysheet->fresh()->status);
        $this->assertSame(0, (int) $paysheet->fresh()->total_remaining);
        $this->assertSame(2, EmployeeSalaryLedgerEntry::where('paysheet_id', $paysheet->id)->where('type', EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL)->count());
        $this->assertSame(2, EmployeeSalaryLedgerEntry::where('paysheet_id', $paysheet->id)->where('type', EmployeeSalaryLedgerEntry::TYPE_CANCEL_REVERSE)->count());

        foreach ($slips as $slip) {
            $net = (int) EmployeeSalaryLedgerEntry::where('payslip_id', $slip->id)->where('is_effective', true)->sum('amount');
            $this->assertSame(0, $net);
            $this->assertSame(0, (int) $slip->fresh()->remaining);
        }
        $this->assertSame(0, (int) $this->employeeA->fresh()->salary_balance_cache);
        $this->assertSame(0, (int) $this->employeeB->fresh()->salary_balance_cache);
    }

    public function test_cannot_cancel_legacy_missing_accrual_paysheet_when_active_payment_exists(): void
    {
        [$paysheet, $slips] = $this->legacyPaysheet();
        PaysheetPayment::create([
            'code' => 'TTPL-LEGACY-ACTIVE',
            'paysheet_id' => $paysheet->id,
            'payslip_id' => $slips[0]->id,
            'employee_id' => $slips[0]->employee_id,
            'amount' => 100_000,
            'status' => 'active',
            'method' => 'cash',
            'paid_at' => now(),
            'idempotency_key' => 'legacy-active-payment',
        ]);

        $this->postJson("/api/paysheets/{$paysheet->id}/cancel", [
            'reason' => 'Huy bang luong legacy thieu accrual',
        ])->assertStatus(422);

        $this->assertSame('locked', $paysheet->fresh()->status);
        $this->assertSame(0, EmployeeSalaryLedgerEntry::where('paysheet_id', $paysheet->id)->count());
    }

    public function test_legacy_missing_accrual_cancel_is_idempotent(): void
    {
        [$paysheet] = $this->legacyPaysheet();
        $payload = ['reason' => 'Huy bang luong legacy thieu accrual'];

        $this->postJson("/api/paysheets/{$paysheet->id}/cancel", $payload)->assertOk();
        $this->postJson("/api/paysheets/{$paysheet->id}/cancel", $payload)->assertOk();

        $this->assertSame(2, EmployeeSalaryLedgerEntry::where('paysheet_id', $paysheet->id)->where('type', EmployeeSalaryLedgerEntry::TYPE_PAYROLL_ACCRUAL)->count());
        $this->assertSame(2, EmployeeSalaryLedgerEntry::where('paysheet_id', $paysheet->id)->where('type', EmployeeSalaryLedgerEntry::TYPE_CANCEL_REVERSE)->count());
        $this->assertSame(0, (int) EmployeeSalaryLedgerEntry::where('paysheet_id', $paysheet->id)->where('is_effective', true)->sum('amount'));
    }

    public function test_audit_paysheet_cancel_reports_legacy_mode_for_missing_payroll_accrual(): void
    {
        [$paysheet] = $this->legacyPaysheet();

        Artisan::call('payroll:audit-paysheet-cancel', [
            'paysheet_code' => $paysheet->code,
            '--format' => 'json',
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('yes', $payload['can_cancel']);
        $this->assertSame('legacy_missing_payroll_accrual', $payload['mode']);
        $this->assertSame('legacy_can_cancel_no_active_payment', $payload['reason']);
        $this->assertTrue($payload['requires_legacy_zero_net_reversal']);
    }

    public function test_audit_paysheet_cancel_blocks_legacy_missing_accrual_when_active_payment_exists(): void
    {
        [$paysheet, $slips] = $this->legacyPaysheet();
        PaysheetPayment::create([
            'code' => 'TTPL-LEGACY-ACTIVE-AUDIT',
            'paysheet_id' => $paysheet->id,
            'payslip_id' => $slips[0]->id,
            'employee_id' => $slips[0]->employee_id,
            'amount' => 100_000,
            'status' => 'active',
            'method' => 'cash',
            'paid_at' => now(),
            'idempotency_key' => 'legacy-active-payment-audit',
        ]);

        Artisan::call('payroll:audit-paysheet-cancel', [
            'paysheet_code' => $paysheet->code,
            '--format' => 'json',
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('no', $payload['can_cancel']);
        $this->assertSame('blocked', $payload['mode']);
        $this->assertSame('has_active_payment', $payload['reason']);
    }

    public function test_cancelled_legacy_paysheet_is_excluded_from_payroll_expense(): void
    {
        [$paysheet] = $this->legacyPaysheet();

        $this->postJson("/api/paysheets/{$paysheet->id}/cancel", [
            'reason' => 'Huy bang luong legacy thieu accrual',
        ])->assertOk();

        $this->assertSame(0, CashFlow::count());
        $this->get($this->financialReportUrl())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/FinancialReport')
                ->where('report.totalExpenses', 0));
    }

    public function test_legacy_cancel_does_not_change_employee_effective_salary_balance_net(): void
    {
        app(EmployeeSalaryLedgerService::class)->append($this->employeeA, [
            'code' => 'OPEN-LEGACY-A',
            'type' => EmployeeSalaryLedgerEntry::TYPE_OPENING_BALANCE,
            'reference_type' => 'opening_balance',
            'reference_id' => $this->employeeA->id,
            'amount' => 500_000,
            'event_at' => now()->subDay(),
            'idempotency_key' => 'opening-legacy-a',
        ]);
        [$paysheet] = $this->legacyPaysheet();

        $this->postJson("/api/paysheets/{$paysheet->id}/cancel", [
            'reason' => 'Huy bang luong legacy thieu accrual',
        ])->assertOk();

        $this->assertSame(500_000, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employeeA->id));
        $this->assertSame(500_000, (int) $this->employeeA->fresh()->salary_balance_cache);
        $this->assertSame(0, app(EmployeeSalaryLedgerService::class)->currentBalance($this->employeeB->id));
        $this->assertSame(0, (int) $this->employeeB->fresh()->salary_balance_cache);
    }

    private function legacyPaysheet(): array
    {
        $paysheet = Paysheet::create([
            'code' => 'BL-P0-LEGACY-MISSING-ACCRUAL',
            'name' => 'Legacy missing accrual',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'branch_id' => $this->branch->id,
            'status' => 'locked',
            'payment_status' => 'unpaid',
            'locked_at' => now()->subDay(),
            'total_salary' => 3_000_000,
            'total_paid' => 0,
            'total_remaining' => 3_000_000,
            'employee_count' => 2,
        ]);
        $slips = [
            Payslip::create([
                'code' => 'PL-P0-LEGACY-A',
                'paysheet_id' => $paysheet->id,
                'employee_id' => $this->employeeA->id,
                'total_salary' => 1_000_000,
                'paid_amount' => 0,
                'remaining' => 1_000_000,
                'payment_status' => 'unpaid',
            ]),
            Payslip::create([
                'code' => 'PL-P0-LEGACY-B',
                'paysheet_id' => $paysheet->id,
                'employee_id' => $this->employeeB->id,
                'total_salary' => 2_000_000,
                'paid_amount' => 0,
                'remaining' => 2_000_000,
                'payment_status' => 'unpaid',
            ]),
        ];

        return [$paysheet, $slips];
    }

    private function employee(string $code, string $name): Employee
    {
        return Employee::create([
            'code' => $code,
            'name' => $name,
            'branch_id' => $this->branch->id,
            'salary_balance_cache' => 0,
            'is_active' => true,
        ]);
    }

    private function financialReportUrl(): string
    {
        return '/reports/financial-report?'.http_build_query([
            'time_mode' => 'custom',
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->endOfMonth()->toDateString(),
        ]);
    }
}
