<?php

namespace Tests\Feature\DebtOffsets;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\DebtOffset;
use App\Models\PartnerDebtOperation;
use App\Models\PartnerDebtOperationParticipant;
use App\Models\PartnerDebtOutboxEvent;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use App\Services\Debt\DebtOffsetFailureInjector;
use App\Services\Debt\DebtOffsetWorkflowService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class DebtOffsetWorkflowFailureInjectionTest extends TestCase
{
    use DatabaseTransactions;

    public static function failurePoints(): array
    {
        return array_map(fn (string $point): array => [$point], [
            'AFTER_PARTNER_BALANCE_UPDATE',
            'AFTER_CASH_FLOW_CREATE',
            'AFTER_SUPPLIER_TRANSACTION_CREATE',
            'AFTER_OPERATION_PARTICIPANT_CREATE',
            'BEFORE_OUTBOX_CREATE',
            'BEFORE_OPERATION_COMMIT',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('debt.offsets.write_mode', 'workflow');
    }

    /** @dataProvider failurePoints */
    public function test_apply_failure_rolls_back_every_side_effect(string $point): void
    {
        [$partner, $offset, $actor] = $this->approvedOffset();
        $service = $this->serviceFailingAt($point);

        try {
            $service->apply($offset, $actor, $offset->versionToken(), $this->key('apply-'.$point));
            $this->fail('Failure injection must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('INJECTED_'.$point, $exception->getMessage());
        }

        $this->assertSame('5000000.00', $partner->fresh()->debt_amount);
        $this->assertSame('5000000.00', $partner->fresh()->supplier_debt_amount);
        $this->assertSame('approved', $offset->fresh()->workflow_status);
        $this->assertSame(0, CashFlow::where('reference_code', $offset->code)->count());
        $this->assertSame(0, SupplierDebtTransaction::where('code', $offset->code)->count());
        $this->assertSame(0, PartnerDebtOperation::where('operation_type', 'debt_offset.apply')->where('source_id', $offset->id)->count());
        $this->assertSame(0, PartnerDebtOperationParticipant::whereHas('operation', fn ($q) => $q
            ->where('operation_type', 'debt_offset.apply')
            ->where('source_id', $offset->id))->count());
        $this->assertSame(0, PartnerDebtOutboxEvent::whereHas('operation', fn ($q) => $q
            ->where('operation_type', 'debt_offset.apply')
            ->where('source_id', $offset->id))->count());
        $this->assertSame(0, ActivityLog::where('action', ActivityLog::ACTION_DEBT_OFFSET_APPLY)
            ->where('subject_id', $offset->id)->count());
    }

    /** @dataProvider failurePoints */
    public function test_reverse_failure_rolls_back_every_side_effect(string $point): void
    {
        [$partner, $offset, $actor, $service] = $this->approvedOffset(true);
        $service->apply($offset, $actor, $offset->versionToken(), $this->key('apply-before-reverse'));
        $offset->refresh();
        $service = $this->serviceFailingAt($point);

        try {
            $service->reverse($offset, $actor, 'Kiểm tra rollback', $offset->versionToken(), $this->key('reverse-'.$point));
            $this->fail('Failure injection must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('INJECTED_'.$point, $exception->getMessage());
        }

        $this->assertSame('3500000.00', $partner->fresh()->debt_amount);
        $this->assertSame('3500000.00', $partner->fresh()->supplier_debt_amount);
        $this->assertSame('applied', $offset->fresh()->workflow_status);
        $this->assertSame(0, DebtOffset::where('reverses_debt_offset_id', $offset->id)->count());
        $this->assertSame(0, PartnerDebtOperation::where('operation_type', 'debt_offset.reverse')
            ->where('source_id', $offset->id)->count());
        $this->assertSame(0, CashFlow::where('reference_type', 'DebtOffsetReversal')
            ->where('target_id', $partner->id)->count());
        $this->assertSame(0, ActivityLog::where('action', ActivityLog::ACTION_DEBT_OFFSET_REVERSE)
            ->where('subject_id', $offset->id)->count());
    }

    private function approvedOffset(bool $returnService = false): array
    {
        $requester = $this->user('requester');
        $approver = $this->user('approver');
        $actor = $this->user('actor');
        $partner = Customer::create([
            'code' => 'DO-FI-'.uniqid(), 'name' => 'Failure injection partner',
            'debt_amount' => '5000000.00', 'supplier_debt_amount' => '5000000.00',
            'is_customer' => true, 'is_supplier' => true, 'status' => 'active',
        ]);
        $service = app(DebtOffsetWorkflowService::class);
        $draft = $service->createDraft($partner, $requester, '1500000', null, $this->key('create'));
        $offset = DebtOffset::findOrFail($draft['debt_offset']['id']);
        $submitted = $service->submit($offset, $requester, $offset->versionToken(), $this->key('submit'));
        $offset = DebtOffset::findOrFail($submitted['debt_offset']['id']);
        $approved = $service->approve($offset, $approver, $offset->versionToken(), $this->key('approve'));
        $result = [$partner, DebtOffset::findOrFail($approved['debt_offset']['id']), $actor];
        if ($returnService) {
            $result[] = $service;
        }

        return $result;
    }

    private function serviceFailingAt(string $point): DebtOffsetWorkflowService
    {
        $this->app->instance(DebtOffsetFailureInjector::class, new class($point) extends DebtOffsetFailureInjector
        {
            public function __construct(private readonly string $point) {}

            public function hit(string $point): void
            {
                if ($point === $this->point) {
                    throw new RuntimeException('INJECTED_'.$point);
                }
            }
        });

        return $this->app->make(DebtOffsetWorkflowService::class);
    }

    private function user(string $name): User
    {
        return User::create([
            'name' => $name, 'email' => $name.'-'.uniqid().'@test.local',
            'password' => bcrypt('password'), 'role_id' => null, 'status' => 'active',
        ]);
    }

    private function key(string $suffix): string
    {
        return 'debt-offset-'.$suffix.'-'.str_replace('.', '', uniqid('', true));
    }
}
