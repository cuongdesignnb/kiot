<?php

namespace Tests\Feature\Payroll;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeSalarySetting;
use App\Models\EmployeeWorkSchedule;
use App\Models\Paysheet;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\TimekeepingRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmployeeRetirementTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => str_repeat('k', 32)]);
        $this->actingAs(User::factory()->create(['role_id' => null]));
    }

    private function employee(bool $active = true): Employee
    {
        return Employee::create(['code' => 'QA-RET-'.uniqid(), 'name' => 'Synthetic retirement employee', 'is_active' => $active]);
    }

    public function test_delete_retires_even_employee_without_history_and_replay_does_not_log_twice(): void
    {
        $employee = $this->employee();
        $before = $employee->getRawOriginal();
        $this->delete('/employees/'.$employee->id)->assertRedirect()->assertSessionHasNoErrors();
        $fresh = $employee->fresh();
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->is_active);
        foreach ($before as $field => $value) {
            if (! in_array($field, ['is_active', 'updated_at'], true)) {
                $this->assertSame($value, $fresh->getRawOriginal($field));
            }
        }
        $this->delete('/employees/'.$employee->id)->assertRedirect();
        $logs = ActivityLog::where('action', 'employee_retire')->where('subject_id', $employee->id)->get();
        $this->assertCount(1, $logs);
        $this->assertSame(auth()->id(), $logs->first()->user_id);
        $this->assertTrue($logs->first()->properties['history_preserved']);
    }

    public function test_retirement_preserves_payroll_payments_sources_and_balance(): void
    {
        $employee = $this->employee();
        $employee->update(['salary_balance_cache' => 500]);
        EmployeeSalarySetting::create(['employee_id' => $employee->id, 'salary_type' => 'fixed', 'base_salary' => 1000]);
        TimekeepingRecord::create(['employee_id' => $employee->id, 'work_date' => '2031-06-03', 'slot' => 1, 'attendance_type' => 'work', 'work_units' => 1]);
        foreach (['locked', 'calculated'] as $status) {
            $sheet = Paysheet::create(['code' => 'QA-S-'.uniqid(), 'name' => 'Synthetic sheet', 'period_start' => '2031-06-01', 'period_end' => '2031-06-30', 'status' => $status]);
            Payslip::create(['code' => 'QA-P-'.uniqid(), 'paysheet_id' => $sheet->id, 'employee_id' => $employee->id, 'total_salary' => 1000, 'paid_amount' => 200, 'remaining' => 800]);
        }
        $hashes = function () {
            $out = [];
            foreach (['paysheets', 'payslips', 'paysheet_payments', 'employee_salary_ledger_entries', 'cash_flows', 'employee_salary_settings', 'timekeeping_records', 'employee_work_schedules', 'salary_advances', 'salary_advance_applications'] as $table) {
                $out[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()));
            }

            return $out;
        };
        $before = $hashes();
        $this->delete('/employees/'.$employee->id)->assertRedirect()->assertSessionHas('success', fn ($message) => str_contains($message, 'số dư'));
        $this->assertSame($before, $hashes());
        $this->assertSame(500, $employee->fresh()->salary_balance_cache);
    }

    public function test_retired_employee_is_excluded_from_all_and_explicit_new_payroll(): void
    {
        $active = $this->employee();
        $retired = $this->employee();
        EmployeeSalarySetting::create(['employee_id' => $active->id, 'salary_type' => 'fixed', 'base_salary' => 1000]);
        $this->delete('/employees/'.$retired->id)->assertRedirect();
        foreach (['all', 'custom'] as $scope) {
            $response = $this->postJson('/api/paysheets', ['pay_period' => 'monthly', 'period_start' => '2031-06-01', 'period_end' => '2031-06-30', 'scope' => $scope, 'employee_ids' => [$active->id, $retired->id]]);
            $response->assertSuccessful();
            $sheetId = $response->json('data.id');
            $this->assertDatabaseHas('payslips', ['paysheet_id' => $sheetId, 'employee_id' => $active->id]);
            $this->assertDatabaseMissing('payslips', ['paysheet_id' => $sheetId, 'employee_id' => $retired->id]);
        }
    }

    public function test_employee_filters_default_active_and_keep_retired_searchable(): void
    {
        $active = $this->employee();
        $retired = $this->employee(false);
        $this->get('/employees?search='.$retired->code)->assertInertia(fn (Assert $page) => $page->component('Employees/Index')->has('employees.data', 0)->where('filters.is_active', '1'));
        $this->get('/employees?is_active=0&search='.$retired->code)->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)->where('employees.data.0.id', $retired->id)->where('filters.is_active', '0'));
        $this->get('/employees?is_active=all&search='.$active->code)->assertInertia(fn (Assert $page) => $page->has('employees.data', 1)->where('filters.is_active', 'all'));
        $response = $this->get('/employees/export?is_active=0&search='.$retired->code);
        $response->assertSuccessful();
        $this->assertStringContainsString($retired->code, $response->streamedContent());
        $this->assertStringNotContainsString($active->code, $response->streamedContent());
    }

    public function test_attendance_default_hides_retired_but_history_can_be_requested(): void
    {
        $employee = $this->employee();
        $schedule = EmployeeWorkSchedule::create(['employee_id' => $employee->id, 'work_date' => '2031-06-03', 'slot' => 1, 'status' => 'planned']);
        $this->delete('/employees/'.$employee->id)->assertRedirect();
        $this->getJson('/api/employee-schedules?employee_id='.$employee->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/employee-schedules?include_inactive=1&employee_id='.$employee->id)->assertOk()->assertJsonPath('data.0.id', $schedule->id);
        $this->assertDatabaseHas('employee_work_schedules', ['id' => $schedule->id]);
    }

    public function test_retirement_requires_existing_delete_permission(): void
    {
        $employee = $this->employee();
        $role = Role::create(['name' => 'qa-retirement-viewer-'.uniqid(), 'display_name' => 'Synthetic viewer', 'permissions' => ['employees.view']]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));
        $this->delete('/employees/'.$employee->id)->assertForbidden();
        $this->assertTrue($employee->fresh()->is_active);
    }

    public function test_failed_audit_rolls_back_retirement(): void
    {
        $employee = $this->employee();
        $event = 'eloquent.creating: '.ActivityLog::class;
        Event::listen($event, function () {
            throw new \RuntimeException('Synthetic audit failure');
        });
        try {
            app(\App\Http\Controllers\EmployeeController::class)->destroy($employee);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertTrue($employee->fresh()->is_active);
        $this->assertSame(0, ActivityLog::where('action', 'employee_retire')->where('subject_id', $employee->id)->count());
    }
}
