<?php

namespace Tests\Feature\Payroll;

use App\Models\Branch;
use App\Models\CashFlow;
use App\Models\Employee;
use App\Models\EmployeeSalaryLedgerEntry;
use App\Models\PaysheetPayment;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceApplication;
use App\Models\User;
use App\Services\EmployeeSalaryLedgerService;
use App\Services\PayrollReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PayrollQaApiTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role_id' => null]));
        $branch = Branch::create(['name' => 'QA Payroll']);
        $this->employee = Employee::create([
            'code' => 'NV-QA',
            'name' => 'QA Employee',
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }

    public function test_keyword_filter_does_not_change_financial_summary(): void
    {
        $ledger = app(EmployeeSalaryLedgerService::class);
        $ledger->append($this->employee, [
            'code' => 'MATCH-001',
            'type' => 'adjustment_increase',
            'amount' => 2_000_000,
            'event_at' => now(),
            'reason' => 'Điều chỉnh theo biên bản kiểm tra',
        ]);
        $ledger->append($this->employee, [
            'code' => 'OTHER-001',
            'type' => 'adjustment_decrease',
            'amount' => -500_000,
            'event_at' => now(),
            'reason' => 'Điều chỉnh giảm theo xác nhận',
        ]);

        $response = $this->getJson("/api/employees/{$this->employee->id}/salary-ledger?keyword=MATCH")
            ->assertOk()
            ->assertJsonPath('summary.net_change', 1_500_000)
            ->assertJsonPath('filtered_summary.filtered_net_change', 2_000_000);

        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_employee_ledger_expand_endpoint_is_read_only_and_uses_effective_entries(): void
    {
        $ledger = app(EmployeeSalaryLedgerService::class);
        $effective = $ledger->append($this->employee, [
            'code' => 'SDDK-NV-QA-20260615',
            'type' => 'opening_balance',
            'amount' => 50_000_000,
            'event_at' => '2026-06-15 00:00:00',
            'note' => 'Số dư lương chuyển đổi từ hệ thống KiotViet',
            'idempotency_key' => 'expand-ui-read-only-test',
        ]);
        EmployeeSalaryLedgerEntry::create([
            'employee_id' => $this->employee->id,
            'branch_id' => $this->employee->branch_id,
            'code' => 'IGNORED-001',
            'type' => 'manual_adjustment',
            'amount' => 99_000_000,
            'balance_after' => 149_000_000,
            'is_effective' => false,
            'status' => 'valid',
            'event_at' => '2026-06-15 01:00:00',
        ]);

        $before = [
            'ledger' => EmployeeSalaryLedgerEntry::count(),
            'cash_flows' => CashFlow::count(),
            'payments' => PaysheetPayment::count(),
            'advances' => SalaryAdvance::count(),
            'applications' => SalaryAdvanceApplication::count(),
        ];

        $response = $this->getJson("/api/employees/{$this->employee->id}/salary-ledger")
            ->assertOk()
            ->assertJsonPath('employee.code', 'NV-QA')
            ->assertJsonPath('summary.current_balance', 50_000_000)
            ->assertJsonPath('summary.total_increase', 50_000_000)
            ->assertJsonPath('summary.total_decrease', 0)
            ->assertJsonPath('summary.entry_count', 1)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $effective->id);

        $this->assertSame($before, [
            'ledger' => EmployeeSalaryLedgerEntry::count(),
            'cash_flows' => CashFlow::count(),
            'payments' => PaysheetPayment::count(),
            'advances' => SalaryAdvance::count(),
            'applications' => SalaryAdvanceApplication::count(),
        ]);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_opening_balance_dry_run_reports_candidate_without_writing_ledger(): void
    {
        $this->employee->update(['balance' => 50_000_000]);

        $exitCode = Artisan::call('payroll:migrate-salary-ledger', [
            '--legacy-balance' => 'opening',
            '--go-live-date' => '2026-06-14',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('DRY-RUN', $output);
        $this->assertStringContainsString('NV-QA', $output);
        $this->assertStringContainsString('50000000', str_replace(',', '', $output));
        $this->assertStringContainsString(
            'payroll-opening-balance:employee:'.$this->employee->id.':legacy-balance:50000000:go-live:2026-06-14',
            $output
        );

        $this->assertDatabaseCount('employee_salary_ledger_entries', 0);
        $this->assertSame(0, (int) $this->employee->fresh()->salary_balance_cache);
    }

    public function test_opening_balance_apply_uses_approved_note_and_rebuilds_cache_without_fake_documents(): void
    {
        $this->employee->update(['balance' => 50_000_000]);
        $cashFlowCount = \App\Models\CashFlow::count();

        $exitCode = Artisan::call('payroll:migrate-salary-ledger', [
            '--legacy-balance' => 'opening',
            '--go-live-date' => '2026-06-14',
            '--apply' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('employee_salary_ledger_entries', [
            'employee_id' => $this->employee->id,
            'type' => 'opening_balance',
            'amount' => 50_000_000,
            'balance_after' => 50_000_000,
            'is_effective' => true,
            'idempotency_key' => 'payroll-opening-balance:employee:'.$this->employee->id.':legacy-balance:50000000:go-live:2026-06-14',
            'note' => 'Số dư lương chuyển đổi từ hệ thống KiotViet',
        ]);
        $this->assertSame(50_000_000, (int) $this->employee->fresh()->salary_balance_cache);
        $this->assertDatabaseCount('paysheet_payments', 0);
        $this->assertSame($cashFlowCount, \App\Models\CashFlow::count());

        Artisan::call('payroll:migrate-salary-ledger', [
            '--legacy-balance' => 'opening',
            '--go-live-date' => '2026-06-14',
            '--apply' => true,
        ]);

        $this->assertSame(
            1,
            EmployeeSalaryLedgerEntry::where(
                'idempotency_key',
                'payroll-opening-balance:employee:'.$this->employee->id.':legacy-balance:50000000:go-live:2026-06-14'
            )->count()
        );
    }

    public function test_detail_advance_list_export_and_date_policy_endpoints(): void
    {
        $entry = app(EmployeeSalaryLedgerService::class)->append($this->employee, [
            'code' => 'DETAIL-001',
            'type' => 'adjustment_increase',
            'amount' => 100_000,
            'event_at' => now(),
            'reason' => 'Điều chỉnh kiểm thử detail',
        ]);

        $this->getJson("/api/employee-salary-ledger-entries/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('ledger_entry.id', $entry->id);
        $this->getJson("/api/employees/{$this->employee->id}/salary-advances")
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'last_page']);
        $this->get("/api/employees/{$this->employee->id}/salary-ledger/export")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->getJson('/api/payroll/date-policy')
            ->assertOk()
            ->assertJsonPath('backdate_limit_days', 30);
    }

    public function test_reconciliation_api_and_command_are_read_only_and_consistent(): void
    {
        app(EmployeeSalaryLedgerService::class)->append($this->employee, [
            'code' => 'AUDIT-001',
            'type' => 'adjustment_increase',
            'amount' => 300_000,
            'event_at' => now(),
            'reason' => 'Điều chỉnh cho kiểm thử audit',
        ]);
        $this->employee->updateQuietly(['salary_balance_cache' => 1]);
        $before = EmployeeSalaryLedgerEntry::count();

        $serviceReport = app(PayrollReconciliationService::class)->audit(['employee' => $this->employee->id]);
        $apiReport = $this->getJson("/api/payroll/reconciliation?employee={$this->employee->id}")
            ->assertOk()
            ->json();
        $this->assertSame($serviceReport['data'][0]['primary_status'], $apiReport['data'][0]['primary_status']);
        $this->assertContains('CACHE_MISMATCH', $apiReport['data'][0]['issues']);
        $this->assertSame('HIGH', $apiReport['data'][0]['severity']);
        $this->assertArrayHasKey('missing_cache_count', $apiReport['summary']);
        $this->assertArrayHasKey('outstanding_advance_count', $apiReport['summary']);

        $this->artisan('payroll:audit-salary-ledger', [
            '--employee' => (string) $this->employee->id,
            '--format' => 'json',
        ])->assertSuccessful();

        $this->assertSame($before, EmployeeSalaryLedgerEntry::count());
        $this->assertSame(1, (int) $this->employee->fresh()->salary_balance_cache);
    }

    public function test_advance_with_applied_amount_is_reported_and_cannot_be_cancelled(): void
    {
        $advance = SalaryAdvance::create([
            'code' => 'TU-QA',
            'employee_id' => $this->employee->id,
            'branch_id' => $this->employee->branch_id,
            'amount' => 1_000_000,
            'applied_amount' => 500_000,
            'remaining_amount' => 500_000,
            'advance_date' => now(),
            'payment_method' => 'cash',
            'status' => 'partially_applied',
            'note' => 'QA advance',
        ]);

        $this->postJson("/api/salary-advances/{$advance->id}/cancel", [
            'reason' => 'Không được hủy khoản đã phân bổ',
            'cancel_date' => now()->toDateTimeString(),
        ])->assertStatus(422);
    }
}
