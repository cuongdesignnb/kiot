<?php

namespace Tests\Feature\Console;

use App\Console\Commands\CheckPartnerDebtInvariantsCommand;
use App\Services\Debt\PartnerDebtInvariantChecker;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Mockery;
use Tests\TestCase;

class CheckPartnerDebtInvariantsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(Kernel::class)->registerCommand(
            $this->app->make(CheckPartnerDebtInvariantsCommand::class),
        );
    }

    public function test_command_requires_the_read_only_gate(): void
    {
        $this->artisan('debt:check-invariants')
            ->expectsOutputToContain('Please pass --dry-run')
            ->assertExitCode(2);
    }

    public function test_clean_scan_succeeds_without_alert(): void
    {
        Log::shouldReceive('warning')->never();
        $checker = Mockery::mock(PartnerDebtInvariantChecker::class);
        $checker->shouldReceive('scan')->once()->with([], null, 'all', 'all')->andReturn($this->scanResult());
        $this->app->instance(PartnerDebtInvariantChecker::class, $checker);

        $this->artisan('debt:check-invariants', ['--dry-run' => true])
            ->expectsOutputToContain('Total checked: 1')
            ->expectsOutputToContain('Material drift: 0')
            ->assertExitCode(0);
    }

    public function test_drift_scan_logs_an_alert_and_returns_failure(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with(
                'Debt integrity drift detected',
                Mockery::on(fn (array $context): bool => $context['partner_ids'] === [210]),
            );
        $checker = Mockery::mock(PartnerDebtInvariantChecker::class);
        $checker->shouldReceive('scan')->once()->with([210], 1, 'dual', 'active')->andReturn($this->scanResult([
            'partner_id' => 210,
            'partner_code' => 'NCC210',
            'role' => 'dual_role',
            'invariant_status' => PartnerDebtInvariantChecker::STATUS_DRIFT,
            'difference' => 3_800_000.0,
            'root_cause' => 'CUSTOMER_STORED_VS_DOCUMENT',
            'risk_level' => 'HIGH',
            'drift_detected' => true,
            'audit_error' => null,
        ]));
        $this->app->instance(PartnerDebtInvariantChecker::class, $checker);

        $this->artisan('debt:check-invariants', [
            '--dry-run' => true,
            '--partner-id' => [210],
            '--role' => 'dual',
            '--status' => 'active',
            '--limit' => '1',
        ])->expectsOutputToContain('Material drift: 1')->assertExitCode(1);
    }

    public function test_technical_warning_does_not_trigger_material_drift_exit_code(): void
    {
        Log::shouldReceive('warning')->never();
        $checker = Mockery::mock(PartnerDebtInvariantChecker::class);
        $checker->shouldReceive('scan')->once()->with([], null, 'all', 'all')->andReturn($this->scanResult([
            'partner_id' => 1,
            'partner_code' => 'KH001',
            'role' => 'customer_only',
            'invariant_status' => PartnerDebtInvariantChecker::STATUS_TECHNICAL,
            'difference' => 0.0,
            'root_cause' => 'TARGET_TYPE_ALIAS_SUSPECT',
            'risk_level' => 'MEDIUM',
            'drift_detected' => false,
            'audit_error' => null,
        ]));
        $this->app->instance(PartnerDebtInvariantChecker::class, $checker);

        $this->artisan('debt:check-invariants', ['--dry-run' => true])
            ->expectsOutputToContain('Technical warnings: 1')
            ->expectsOutputToContain('Material drift: 0')
            ->assertExitCode(0);
    }

    public function test_audit_error_returns_system_error_exit_code(): void
    {
        Log::shouldReceive('error')->once()->with(
            'Debt integrity scan completed with audit errors',
            Mockery::type('array'),
        );
        $checker = Mockery::mock(PartnerDebtInvariantChecker::class);
        $checker->shouldReceive('scan')->once()->andReturn($this->scanResult([
            'partner_id' => 1,
            'partner_code' => 'KH001',
            'role' => 'customer_only',
            'invariant_status' => PartnerDebtInvariantChecker::STATUS_ERROR,
            'difference' => 0.0,
            'root_cause' => 'AUDIT_ERROR',
            'risk_level' => 'CRITICAL',
            'drift_detected' => false,
            'audit_error' => 'Synthetic failure',
        ]));
        $this->app->instance(PartnerDebtInvariantChecker::class, $checker);

        $this->artisan('debt:check-invariants', ['--dry-run' => true])
            ->expectsOutputToContain('Audit errors: 1')
            ->assertExitCode(2);
    }

    public function test_command_exception_returns_system_error_exit_code(): void
    {
        Log::shouldReceive('error')->once()->with('Debt integrity scan failed', Mockery::type('array'));
        $checker = Mockery::mock(PartnerDebtInvariantChecker::class);
        $checker->shouldReceive('scan')->once()->andThrow(new \RuntimeException('Synthetic failure'));
        $this->app->instance(PartnerDebtInvariantChecker::class, $checker);

        $this->artisan('debt:check-invariants', ['--dry-run' => true])
            ->expectsOutputToContain('Debt integrity scan failed')
            ->assertExitCode(2);
    }

    public function test_invalid_filters_return_input_error_exit_code(): void
    {
        $this->artisan('debt:check-invariants', ['--dry-run' => true, '--role' => 'invalid'])
            ->expectsOutputToContain('Invalid --role')
            ->assertExitCode(2);
        $this->artisan('debt:check-invariants', ['--dry-run' => true, '--status' => 'deleted'])
            ->expectsOutputToContain('Invalid --status')
            ->assertExitCode(2);
        $this->artisan('debt:check-invariants', ['--dry-run' => true, '--limit' => '0'])
            ->expectsOutputToContain('Invalid --limit')
            ->assertExitCode(2);
        $this->artisan('debt:check-invariants', ['--dry-run' => true, '--partner-id' => ['abc']])
            ->expectsOutputToContain('Invalid --partner-id')
            ->assertExitCode(2);
    }

    public function test_command_has_no_apply_option(): void
    {
        $command = $this->app->make(CheckPartnerDebtInvariantsCommand::class);

        $this->assertFalse($command->getDefinition()->hasOption('apply'));
    }

    public function test_daily_schedule_has_timezone_environment_and_overlap_guards(): void
    {
        $schedule = new Schedule(config('app.timezone'));
        ScheduleFacade::swap($schedule);
        require base_path('routes/console.php');

        $event = collect($schedule->events())->first(
            fn ($event): bool => str_contains((string) $event->command, 'debt:check-invariants'),
        );

        $this->assertNotNull($event, json_encode(
            collect($schedule->events())->pluck('command')->all(),
            JSON_UNESCAPED_SLASHES,
        ));
        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertSame(config('app.timezone'), $event->timezone);
        $this->assertSame(['production'], $event->environments);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(180, $event->expiresAt);
        $this->assertTrue($event->onOneServer);
    }

    private function scanResult(?array $drift = null): array
    {
        $clean = [
            'partner_id' => 1,
            'partner_code' => 'KH001',
            'role' => 'customer_only',
            'invariant_status' => PartnerDebtInvariantChecker::STATUS_OK,
            'difference' => 0.0,
            'root_cause' => 'OK',
            'risk_level' => 'OK',
            'drift_detected' => false,
            'audit_error' => null,
        ];
        $row = $drift ?? $clean;
        $rows = [$row];
        $status = $row['invariant_status'];

        return [
            'checked_at' => now()->toIso8601String(),
            'total_checked' => 1,
            'matched' => $status === PartnerDebtInvariantChecker::STATUS_OK ? 1 : 0,
            'drift_detected' => $status === PartnerDebtInvariantChecker::STATUS_DRIFT ? 1 : 0,
            'insufficient_evidence' => $status === PartnerDebtInvariantChecker::STATUS_INSUFFICIENT ? 1 : 0,
            'technical_warnings' => $status === PartnerDebtInvariantChecker::STATUS_TECHNICAL ? 1 : 0,
            'audit_errors' => $status === PartnerDebtInvariantChecker::STATUS_ERROR ? 1 : 0,
            'rows' => $rows,
        ];
    }
}
