<?php

namespace Tests\Feature\DebtOffsets;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\DebtOffset;
use App\Models\PartnerDebtOperation;
use App\Models\PartnerDebtOutboxEvent;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use App\Services\Debt\DebtOffsetWorkflowService;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DebtOffsetWorkflowConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('debt.offsets.write_mode', 'workflow');
        config()->set('debt.offsets.require_distinct_approver', true);
        config()->set('debt.offsets.require_distinct_applier', false);
        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    }

    public function test_two_processes_applying_the_same_offset_create_one_financial_effect(): void
    {
        $partner = $this->partner('10000000.00', '6000000.00');
        $offset = $this->approvedOffset($partner, '4000000.00');
        $actor = $this->user('same-offset-applier');

        $results = $this->runConcurrentApply([
            [$offset, $actor, $offset->versionToken(), $this->key('same-offset-a')],
            [$offset, $actor, $offset->versionToken(), $this->key('same-offset-b')],
        ]);

        $this->assertCount(1, collect($results)->where('outcome', 'success'));
        $this->assertContains(
            collect($results)->firstWhere('outcome', 'workflow_error')['error_code'] ?? null,
            ['STALE_DEBT_OFFSET_VERSION', 'INVALID_DEBT_OFFSET_TRANSITION'],
        );
        $this->assertSame('6000000.00', $partner->fresh()->debt_amount);
        $this->assertSame('2000000.00', $partner->fresh()->supplier_debt_amount);
        $this->assertSame(1, CashFlow::query()->where('reference_code', $offset->code)->count());
        $this->assertSame(1, SupplierDebtTransaction::query()->where('code', $offset->code)->count());
        $this->assertSame(1, PartnerDebtOperation::query()
            ->where('operation_type', 'debt_offset.apply')
            ->where('source_id', $offset->id)
            ->count());
        $this->assertSame(1, PartnerDebtOutboxEvent::query()
            ->where('event_type', 'debt_offset.applied')
            ->where('aggregate_id', $offset->id)
            ->count());
    }

    public function test_two_offsets_for_the_same_partner_cannot_overdraw_balances(): void
    {
        $partner = $this->partner('5000000.00', '5000000.00');
        $offsetA = $this->approvedOffset($partner, '4000000.00');
        $offsetB = $this->approvedOffset($partner, '4000000.00');
        $actor = $this->user('same-partner-applier');

        $results = $this->runConcurrentApply([
            [$offsetA, $actor, $offsetA->versionToken(), $this->key('same-partner-a')],
            [$offsetB, $actor, $offsetB->versionToken(), $this->key('same-partner-b')],
        ]);

        $this->assertCount(1, collect($results)->where('outcome', 'success'));
        $this->assertSame(
            'OFFSET_AMOUNT_EXCEEDS_CURRENT_BALANCE',
            collect($results)->firstWhere('outcome', 'workflow_error')['error_code'] ?? null,
        );
        $this->assertSame('1000000.00', $partner->fresh()->debt_amount);
        $this->assertSame('1000000.00', $partner->fresh()->supplier_debt_amount);
        $this->assertSame(1, CashFlow::query()->whereIn('reference_code', [$offsetA->code, $offsetB->code])->count());
        $this->assertSame(1, SupplierDebtTransaction::query()->whereIn('code', [$offsetA->code, $offsetB->code])->count());
        $this->assertSame(1, DebtOffset::query()
            ->whereIn('id', [$offsetA->id, $offsetB->id])
            ->where('workflow_status', 'applied')
            ->count());
        $this->assertSame(1, DebtOffset::query()
            ->whereIn('id', [$offsetA->id, $offsetB->id])
            ->where('workflow_status', 'approved')
            ->count());
    }

    public function test_concurrent_approve_and_reject_commit_only_one_decision(): void
    {
        $partner = $this->partner('5000000.00', '5000000.00');
        $offset = $this->pendingOffset($partner, '1000000.00');
        $approver = $this->user('concurrent-approver');
        $rejecter = $this->user('concurrent-rejecter');

        $results = $this->runConcurrentDecision([
            [$offset, $approver, 'approve', $offset->versionToken(), $this->key('decision-approve')],
            [$offset, $rejecter, 'reject', $offset->versionToken(), $this->key('decision-reject')],
        ]);

        $this->assertCount(1, collect($results)->where('outcome', 'success'));
        $this->assertContains(
            collect($results)->firstWhere('outcome', 'workflow_error')['error_code'] ?? null,
            ['STALE_DEBT_OFFSET_VERSION', 'INVALID_DEBT_OFFSET_TRANSITION'],
        );
        $this->assertContains($offset->fresh()->workflow_status, ['approved', 'rejected']);
        $this->assertSame('5000000.00', $partner->fresh()->debt_amount);
        $this->assertSame('5000000.00', $partner->fresh()->supplier_debt_amount);
        $this->assertSame(1, PartnerDebtOperation::query()
            ->where('source_id', $offset->id)
            ->whereIn('operation_type', ['debt_offset.approve', 'debt_offset.reject'])
            ->count());
        $this->assertSame(1, PartnerDebtOutboxEvent::query()
            ->where('aggregate_id', $offset->id)
            ->whereIn('event_type', ['debt_offset.approved', 'debt_offset.rejected'])
            ->count());
        $this->assertSame(0, CashFlow::query()->where('reference_code', $offset->code)->count());
        $this->assertSame(0, SupplierDebtTransaction::query()->where('code', $offset->code)->count());
    }

    /** @param array<int, array{DebtOffset, User, string, string}> $commands */
    private function runConcurrentApply(array $commands): array
    {
        $barrierDirectory = storage_path('framework/testing/debt-offset-concurrency-'.Str::uuid());
        if (! is_dir(dirname($barrierDirectory))) {
            mkdir(dirname($barrierDirectory), 0777, true);
        }
        mkdir($barrierDirectory, 0777, true);

        $processes = [];
        try {
            foreach ($commands as $index => [$offset, $actor, $versionToken, $idempotencyKey]) {
                $workerName = 'worker-'.$index;
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/DebtOffsetApplyWorker.php'),
                    base_path(),
                    (string) $offset->id,
                    (string) $actor->id,
                    $versionToken,
                    $idempotencyKey,
                    $barrierDirectory,
                    $workerName,
                ], base_path(), $this->workerEnvironment());
                $process->setTimeout(30);
                $process->start();
                $processes[$workerName] = $process;
            }

            $deadline = microtime(true) + 15;
            foreach (array_keys($processes) as $workerName) {
                while (! is_file($barrierDirectory.'/'.$workerName.'.ready')) {
                    if (microtime(true) >= $deadline) {
                        $this->fail('Worker did not reach the concurrency barrier: '.$workerName);
                    }
                    usleep(20_000);
                }
            }
            file_put_contents($barrierDirectory.'/release', 'release');

            $results = [];
            foreach ($processes as $workerName => $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $workerName.' failed: '.$process->getErrorOutput());
                $this->assertMatchesRegularExpression('/DEBT_OFFSET_WORKER_RESULT=(\{.+\})/', $process->getOutput());
                preg_match('/DEBT_OFFSET_WORKER_RESULT=(\{.+\})/', $process->getOutput(), $matches);
                $results[] = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($barrierDirectory.'/*') ?: [] as $path) {
                unlink($path);
            }
            if (is_dir($barrierDirectory)) {
                rmdir($barrierDirectory);
            }
        }
    }

    /** @param array<int, array{DebtOffset, User, string, string, string}> $commands */
    private function runConcurrentDecision(array $commands): array
    {
        $barrierDirectory = storage_path('framework/testing/debt-offset-decision-'.Str::uuid());
        if (! is_dir(dirname($barrierDirectory))) {
            mkdir(dirname($barrierDirectory), 0777, true);
        }
        mkdir($barrierDirectory, 0777, true);

        $processes = [];
        try {
            foreach ($commands as $index => [$offset, $actor, $action, $versionToken, $idempotencyKey]) {
                $workerName = 'worker-'.$index;
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/DebtOffsetDecisionWorker.php'),
                    base_path(),
                    (string) $offset->id,
                    (string) $actor->id,
                    $action,
                    $versionToken,
                    $idempotencyKey,
                    $barrierDirectory,
                    $workerName,
                ], base_path(), $this->workerEnvironment());
                $process->setTimeout(30);
                $process->start();
                $processes[$workerName] = $process;
            }

            $deadline = microtime(true) + 15;
            foreach (array_keys($processes) as $workerName) {
                while (! is_file($barrierDirectory.'/'.$workerName.'.ready')) {
                    if (microtime(true) >= $deadline) {
                        $this->fail('Worker did not reach the decision barrier: '.$workerName);
                    }
                    usleep(20_000);
                }
            }
            file_put_contents($barrierDirectory.'/release', 'release');

            $results = [];
            foreach ($processes as $workerName => $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $workerName.' failed: '.$process->getErrorOutput());
                $this->assertMatchesRegularExpression('/DEBT_OFFSET_WORKER_RESULT=(\{.+\})/', $process->getOutput());
                preg_match('/DEBT_OFFSET_WORKER_RESULT=(\{.+\})/', $process->getOutput(), $matches);
                $results[] = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($barrierDirectory.'/*') ?: [] as $path) {
                unlink($path);
            }
            if (is_dir($barrierDirectory)) {
                rmdir($barrierDirectory);
            }
        }
    }

    private function workerEnvironment(): array
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}");

        return [
            'APP_BASE_PATH' => base_path(),
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DEBT_OFFSET_WRITE_MODE' => 'workflow',
            'DB_CONNECTION' => $connection,
            'DB_HOST' => (string) $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
        ];
    }

    private function approvedOffset(Customer $partner, string $amount): DebtOffset
    {
        $service = app(DebtOffsetWorkflowService::class);
        $offset = $this->pendingOffset($partner, $amount);
        $approver = $this->user('approver');
        $approved = $service->approve($offset, $approver, $offset->versionToken(), $this->key('approve'));

        return DebtOffset::query()->findOrFail($approved['debt_offset']['id']);
    }

    private function pendingOffset(Customer $partner, string $amount): DebtOffset
    {
        $service = app(DebtOffsetWorkflowService::class);
        $requester = $this->user('requester');
        $created = $service->createDraft($partner, $requester, $amount, null, $this->key('create'));
        $offset = DebtOffset::query()->findOrFail($created['debt_offset']['id']);
        $submitted = $service->submit($offset, $requester, $offset->versionToken(), $this->key('submit'));

        return DebtOffset::query()->findOrFail($submitted['debt_offset']['id']);
    }

    private function partner(string $receivable, string $payable): Customer
    {
        return Customer::query()->create([
            'code' => 'DO-CONCURRENT-'.Str::uuid(),
            'name' => 'Concurrent dual-role partner',
            'debt_amount' => $receivable,
            'supplier_debt_amount' => $payable,
            'is_customer' => true,
            'is_supplier' => true,
            'status' => 'active',
        ]);
    }

    private function user(string $name): User
    {
        return User::query()->create([
            'name' => $name.' '.Str::uuid(),
            'email' => $name.'-'.Str::uuid().'@test.local',
            'password' => bcrypt('password'),
            'role_id' => null,
            'status' => 'active',
        ]);
    }

    private function key(string $suffix): string
    {
        return 'debt-offset-'.$suffix.'-'.Str::uuid();
    }
}
