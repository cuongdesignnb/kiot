<?php

namespace Tests\Feature\CustomerDebt;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\DebtOffset;
use App\Models\Invoice;
use App\Models\PartnerDebtOperation;
use App\Models\PartnerMerge;
use App\Models\Purchase;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use App\Services\Debt\CanonicalPartnerDebtService;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PartnerMergeConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('debt.offsets.write_mode', 'legacy');
        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    }

    public function test_two_processes_with_the_same_key_create_one_merge_and_one_offset(): void
    {
        $source = $this->partner(true, false);
        $target = $this->partner(false, true);
        $this->receivable($source, 10_000_000);
        $this->payable($target, 6_000_000);
        $actor = User::factory()->create(['status' => 'active']);
        $key = 'partner-merge-concurrent-'.Str::uuid();

        $results = $this->runConcurrentMerge($source, $target, $actor, $key);

        $this->assertCount(2, collect($results)->where('outcome', 'success'));
        $this->assertEqualsCanonicalizing(
            ['merged', 'already_merged'],
            collect($results)->pluck('status')->all(),
        );
        $this->assertSame(1, PartnerMerge::query()->where('source_partner_id', $source->id)->count());
        $this->assertSame(1, DebtOffset::query()->where('customer_id', $target->id)->count());
        $offset = DebtOffset::query()->where('customer_id', $target->id)->sole();
        $this->assertSame(1, CashFlow::query()->where('reference_code', $offset->code)->count());
        $this->assertSame(1, SupplierDebtTransaction::query()->where('code', $offset->code)->count());
        $this->assertSame(1, PartnerDebtOperation::query()
            ->where('operation_type', 'debt.mutation.partner_merge')
            ->where('idempotency_key', $key)
            ->count());
        $this->assertSame(4_000_000.0, (float) $target->fresh()->debt_amount);
        $this->assertSame(0.0, (float) $target->fresh()->supplier_debt_amount);
        $this->assertSame('inactive', $source->fresh()->status);
        $this->assertFalse((bool) app(CanonicalPartnerDebtService::class)->calculate($target->fresh())['has_mismatch']);
    }

    private function runConcurrentMerge(Customer $source, Customer $target, User $actor, string $key): array
    {
        $barrierDirectory = storage_path('framework/testing/partner-merge-concurrency-'.Str::uuid());
        if (! is_dir(dirname($barrierDirectory))) {
            mkdir(dirname($barrierDirectory), 0777, true);
        }
        mkdir($barrierDirectory, 0777, true);

        $processes = [];
        try {
            foreach (range(0, 1) as $index) {
                $workerName = 'worker-'.$index;
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/PartnerMergeWorker.php'),
                    base_path(),
                    (string) $source->id,
                    (string) $target->id,
                    (string) $actor->id,
                    $key,
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
                $this->assertMatchesRegularExpression('/PARTNER_MERGE_WORKER_RESULT=(\{.+\})/', $process->getOutput());
                preg_match('/PARTNER_MERGE_WORKER_RESULT=(\{.+\})/', $process->getOutput(), $matches);
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
            'DEBT_OFFSET_WRITE_MODE' => 'legacy',
            'DB_CONNECTION' => $connection,
            'DB_HOST' => (string) $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
            'LOG_CHANNEL' => 'null',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ];
    }

    private function partner(bool $isCustomer, bool $isSupplier): Customer
    {
        return Customer::query()->create([
            'code' => 'PARTNER-CONCURRENT-'.Str::uuid(),
            'name' => 'Concurrent partner '.Str::uuid(),
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'total_spent' => 0,
            'total_returns' => 0,
            'total_bought' => 0,
            'is_customer' => $isCustomer,
            'is_supplier' => $isSupplier,
            'status' => 'active',
        ]);
    }

    private function receivable(Customer $customer, int $amount): void
    {
        Invoice::query()->create([
            'code' => 'HD-CONCURRENT-'.Str::uuid(),
            'customer_id' => $customer->id,
            'subtotal' => $amount,
            'total' => $amount,
            'customer_paid' => 0,
            'order_deposit_applied_amount' => 0,
            'status' => 'completed',
            'transaction_date' => now(),
        ]);
        $customer->increment('debt_amount', $amount);
    }

    private function payable(Customer $supplier, int $amount): void
    {
        Purchase::query()->create([
            'code' => 'PN-CONCURRENT-'.Str::uuid(),
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => $amount,
            'paid_amount' => 0,
            'debt_amount' => $amount,
            'purchase_date' => now(),
        ]);
        $supplier->increment('supplier_debt_amount', $amount);
    }
}
