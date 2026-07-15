<?php

namespace Tests\Feature\Console;

use App\Console\Commands\CheckPartnerDebtInvariantsCommand;
use App\Services\Debt\PartnerDebtInvariantChecker;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Log;
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
            ->assertExitCode(1);
    }

    public function test_clean_scan_succeeds_without_alert(): void
    {
        Log::shouldReceive('warning')->never();
        $checker = Mockery::mock(PartnerDebtInvariantChecker::class);
        $checker->shouldReceive('scan')->once()->with([], null)->andReturn($this->scanResult());
        $this->app->instance(PartnerDebtInvariantChecker::class, $checker);

        $this->artisan('debt:check-invariants', ['--dry-run' => true])
            ->expectsOutputToContain('Total checked: 1')
            ->expectsOutputToContain('Drift detected: 0')
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
        $checker->shouldReceive('scan')->once()->with([210], 1)->andReturn($this->scanResult([
            'partner_id' => 210,
            'partner_code' => 'NCC210',
            'role' => 'dual_role',
            'difference' => 3_800_000.0,
            'root_cause' => 'CUSTOMER_STORED_VS_DOCUMENT',
            'risk_level' => 'HIGH',
            'drift_detected' => true,
        ]));
        $this->app->instance(PartnerDebtInvariantChecker::class, $checker);

        $this->artisan('debt:check-invariants', [
            '--dry-run' => true,
            '--partner-id' => [210],
            '--limit' => '1',
        ])->expectsOutputToContain('Drift detected: 1')->assertExitCode(1);

    }

    public function test_command_has_no_apply_option(): void
    {
        $command = $this->app->make(CheckPartnerDebtInvariantsCommand::class);

        $this->assertFalse($command->getDefinition()->hasOption('apply'));
    }

    private function scanResult(?array $drift = null): array
    {
        $clean = [
            'partner_id' => 1,
            'partner_code' => 'KH001',
            'role' => 'customer_only',
            'difference' => 0.0,
            'root_cause' => 'OK',
            'risk_level' => 'OK',
            'drift_detected' => false,
            'audit_error' => null,
        ];
        $rows = [$drift ?? $clean];

        return [
            'checked_at' => now()->toIso8601String(),
            'total_checked' => 1,
            'matched' => $drift === null ? 1 : 0,
            'drift_detected' => $drift === null ? 0 : 1,
            'audit_errors' => 0,
            'rows' => $rows,
        ];
    }
}
