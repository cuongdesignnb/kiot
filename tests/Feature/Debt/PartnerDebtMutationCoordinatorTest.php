<?php

namespace Tests\Feature\Debt;

use App\Models\Customer;
use App\Models\PartnerDebtOperation;
use App\Models\PartnerDebtOperationParticipant;
use App\Services\Debt\CanonicalPartnerDebtService;
use App\Services\Debt\PartnerDebtMutationCoordinator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PartnerDebtMutationCoordinatorTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $canonical = Mockery::mock(CanonicalPartnerDebtService::class);
        $canonical->shouldReceive('calculate')->andReturnUsing(fn (Customer $partner): array => [
            'customer_receivable' => (float) $partner->debt_amount,
            'supplier_payable' => (float) $partner->supplier_debt_amount,
            'source_version' => 'coordinator-test',
        ]);
        $this->app->instance(CanonicalPartnerDebtService::class, $canonical);
    }

    public function test_same_key_and_payload_replays_without_a_second_write(): void
    {
        $partner = $this->partner();
        $coordinator = app(PartnerDebtMutationCoordinator::class);
        $key = 'coordinator-replay-'.str_repeat('a', 20);
        $writes = 0;
        $mutation = function (Customer $locked) use (&$writes): array {
            $writes++;
            $locked->increment('debt_amount', 125_000);

            return ['status' => 'created'];
        };

        $first = $coordinator->execute($partner->id, 'test_replay', hash('sha256', 'same'), $mutation, $key);
        $second = $coordinator->execute($partner->id, 'test_replay', hash('sha256', 'same'), $mutation, $key);

        $this->assertSame(['status' => 'created'], $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $writes);
        $this->assertSame(125_000.0, (float) $partner->fresh()->debt_amount);
        $this->assertSame(1, PartnerDebtOperation::where('idempotency_key', $key)->count());
        $this->assertSame(1, PartnerDebtOperationParticipant::where('partner_id', $partner->id)->count());
    }

    public function test_same_key_with_a_different_payload_is_rejected(): void
    {
        $partner = $this->partner();
        $coordinator = app(PartnerDebtMutationCoordinator::class);
        $key = 'coordinator-conflict-'.str_repeat('b', 20);
        $coordinator->execute(
            $partner->id,
            'test_conflict',
            hash('sha256', 'first'),
            fn () => ['status' => 'created'],
            $key,
        );

        $this->expectException(ValidationException::class);
        $coordinator->execute(
            $partner->id,
            'test_conflict',
            hash('sha256', 'second'),
            fn () => ['status' => 'must-not-run'],
            $key,
        );
    }

    public function test_fault_before_commit_rolls_back_projection_and_operation_log(): void
    {
        $partner = $this->partner();
        config()->set('debt.mutation.failure_after', 'before_commit');

        try {
            app(PartnerDebtMutationCoordinator::class)->execute(
                $partner->id,
                'test_rollback',
                hash('sha256', 'rollback'),
                function (Customer $locked): void {
                    $locked->increment('debt_amount', 250_000);
                },
                'coordinator-rollback-'.str_repeat('c', 20),
            );
            $this->fail('Injected failure must abort the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('after before_commit', $exception->getMessage());
        } finally {
            config()->set('debt.mutation.failure_after');
        }

        $this->assertSame(0.0, (float) $partner->fresh()->debt_amount);
        $this->assertSame(0, PartnerDebtOperation::where('partner_id', $partner->id)->count());
        $this->assertSame(0, PartnerDebtOperationParticipant::where('partner_id', $partner->id)->count());
    }

    private function partner(): Customer
    {
        return Customer::query()->create([
            'code' => 'COORD-'.uniqid(),
            'name' => 'Coordinator test partner',
            'phone' => '09'.random_int(10000000, 99999999),
            'is_customer' => true,
            'is_supplier' => true,
            'debt_amount' => 0,
            'supplier_debt_amount' => 0,
            'status' => 'active',
        ]);
    }
}
