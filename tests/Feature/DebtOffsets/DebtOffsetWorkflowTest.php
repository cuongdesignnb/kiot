<?php

namespace Tests\Feature\DebtOffsets;

use App\Exceptions\DebtOffsetWorkflowException;
use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\DebtOffset;
use App\Models\PartnerDebtOperation;
use App\Models\PartnerDebtOperationParticipant;
use App\Models\PartnerDebtOutboxEvent;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use App\Services\CustomerDebtDocumentTimelineService;
use App\Services\Debt\DebtOffsetWorkflowService;
use App\Services\DebtOffsetService;
use App\Services\PartnerDebtLedgerService;
use App\Services\SupplierDebtDocumentTimelineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DebtOffsetWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('debt.offsets.write_mode', 'workflow');
        config()->set('debt.offsets.require_distinct_approver', true);
        config()->set('debt.offsets.require_distinct_applier', false);
    }

    public function test_create_submit_approve_and_apply_are_atomic_and_evidenced(): void
    {
        $requester = $this->user('requester');
        $approver = $this->user('approver');
        $applier = $this->user('applier');
        $partner = $this->partner('10000000.00', '6000000.00');
        $service = app(DebtOffsetWorkflowService::class);

        $draft = $service->createDraft($partner, $requester, '4000000.00', 'Đối soát hai chiều', $this->key('create'));
        $offset = DebtOffset::findOrFail($draft['debt_offset']['id']);

        $this->assertSame('draft', $offset->workflow_status);
        $this->assertSame('10000000.00', $partner->fresh()->debt_amount);
        $this->assertSame('6000000.00', $partner->fresh()->supplier_debt_amount);
        $this->assertSame(0, CashFlow::where('reference_code', $offset->code)->count());
        $this->assertSame(0, SupplierDebtTransaction::where('code', $offset->code)->count());

        $submitted = $service->submit($offset, $requester, $offset->versionToken(), $this->key('submit'));
        $offset = DebtOffset::findOrFail($submitted['debt_offset']['id']);
        $approved = $service->approve($offset, $approver, $offset->versionToken(), $this->key('approve'));
        $offset = DebtOffset::findOrFail($approved['debt_offset']['id']);

        $this->assertSame('10000000.00', $partner->fresh()->debt_amount);
        $this->assertSame('6000000.00', $partner->fresh()->supplier_debt_amount);

        $applied = $service->apply($offset, $applier, $offset->versionToken(), $this->key('apply'));
        $offset = DebtOffset::findOrFail($applied['debt_offset']['id']);
        $partner->refresh();

        $this->assertSame('applied', $offset->workflow_status);
        $this->assertSame('6000000.00', $partner->debt_amount);
        $this->assertSame('2000000.00', $partner->supplier_debt_amount);
        $this->assertSame(1, CashFlow::where('reference_code', $offset->code)->count());
        $this->assertSame(1, SupplierDebtTransaction::where('code', $offset->code)->count());
        $this->assertSame('-4000000.00', (string) SupplierDebtTransaction::where('code', $offset->code)->value('amount'));

        $applyOperation = PartnerDebtOperation::where('operation_type', 'debt_offset.apply')->firstOrFail();
        $this->assertSame('committed', $applyOperation->status);
        $this->assertSame(1, PartnerDebtOperationParticipant::where('operation_id', $applyOperation->id)->count());
        $this->assertSame(1, PartnerDebtOutboxEvent::where('operation_id', $applyOperation->id)->count());
        $this->assertSame(1, ActivityLog::where('action', ActivityLog::ACTION_DEBT_OFFSET_APPLY)->where('subject_id', $offset->id)->count());
        $participant = PartnerDebtOperationParticipant::where('operation_id', $applyOperation->id)->firstOrFail();
        $this->assertSame('-4000000.00', $participant->customer_delta);
        $this->assertSame('-4000000.00', $participant->supplier_delta);
    }

    public function test_every_non_financial_transition_has_zero_effect_evidence(): void
    {
        $requester = $this->user('requester');
        $approver = $this->user('approver');
        $partner = $this->partner();
        $service = app(DebtOffsetWorkflowService::class);

        $draft = $service->createDraft($partner, $requester, '1000000', null, $this->key('create'));
        $offset = DebtOffset::findOrFail($draft['debt_offset']['id']);
        $updated = $service->updateDraft($offset, $requester, '1200000', 'updated', $offset->versionToken(), $this->key('update'));
        $offset = DebtOffset::findOrFail($updated['debt_offset']['id']);
        $submitted = $service->submit($offset, $requester, $offset->versionToken(), $this->key('submit'));
        $offset = DebtOffset::findOrFail($submitted['debt_offset']['id']);
        $service->approve($offset, $approver, $offset->versionToken(), $this->key('approve'));

        $participants = PartnerDebtOperationParticipant::whereHas('operation', fn ($query) => $query->whereIn('operation_type', [
            'debt_offset.create_draft', 'debt_offset.update_draft', 'debt_offset.submit', 'debt_offset.approve',
        ])->where('source_id', $offset->id))->get();
        $this->assertCount(4, $participants);
        foreach ($participants as $participant) {
            $this->assertSame('none', $participant->effect_role);
            $this->assertSame('0.00', $participant->customer_delta);
            $this->assertSame('0.00', $participant->supplier_delta);
        }
    }

    public function test_non_applied_workflow_offsets_do_not_appear_in_financial_timelines(): void
    {
        $partner = $this->partner();
        $requester = $this->user('timeline-draft-requester');
        $created = app(DebtOffsetWorkflowService::class)->createDraft(
            $partner,
            $requester,
            '1000000.00',
            null,
            $this->key('timeline-draft'),
        );
        $code = $created['debt_offset']['code'];

        $customerDocument = app(CustomerDebtDocumentTimelineService::class)->build($partner->fresh());
        $supplierDocument = app(SupplierDebtDocumentTimelineService::class)->build($partner->fresh());
        $partnerLedger = app(PartnerDebtLedgerService::class);

        $this->assertCount(0, collect($customerDocument['entries'])->where('code', $code));
        $this->assertCount(0, collect($supplierDocument['entries'])->where('code', $code));
        $this->assertCount(0, collect($partnerLedger->buildCustomerNetLedger($partner->fresh())['entries'])->where('code', $code));
        $this->assertCount(0, collect($partnerLedger->buildSupplierPayableLedger($partner->fresh())['entries'])->where('code', $code));
    }

    public function test_self_approval_reject_and_void_follow_state_machine_without_balance_changes(): void
    {
        $requester = $this->user('requester');
        $approver = $this->user('approver');
        $partner = $this->partner();
        $service = app(DebtOffsetWorkflowService::class);

        $draft = $service->createDraft($partner, $requester, '1000000', null, $this->key('create-a'));
        $offset = DebtOffset::findOrFail($draft['debt_offset']['id']);
        $submitted = $service->submit($offset, $requester, $offset->versionToken(), $this->key('submit-a'));
        $offset = DebtOffset::findOrFail($submitted['debt_offset']['id']);

        try {
            $service->approve($offset, $requester, $offset->versionToken(), $this->key('self-approve'));
            $this->fail('Requester must not self-approve.');
        } catch (DebtOffsetWorkflowException $exception) {
            $this->assertSame('SELF_APPROVAL_FORBIDDEN', $exception->errorCode);
        }

        $rejected = $service->reject($offset, $approver, 'Không đủ chứng từ', $offset->versionToken(), $this->key('reject'));
        $this->assertSame('rejected', $rejected['debt_offset']['workflow_status']);
        $this->assertSame('5000000.00', $partner->fresh()->debt_amount);
        $this->assertSame('5000000.00', $partner->fresh()->supplier_debt_amount);

        $draftB = $service->createDraft($partner, $requester, '500000', null, $this->key('create-b'));
        $offsetB = DebtOffset::findOrFail($draftB['debt_offset']['id']);
        $voided = $service->void($offsetB, $requester, 'Không tiếp tục', $offsetB->versionToken(), $this->key('void'));
        $this->assertSame('void', $voided['debt_offset']['workflow_status']);
        $this->assertSame(0, CashFlow::whereIn('reference_code', [$offset->code, $offsetB->code])->count());
    }

    public function test_idempotent_replay_does_not_repeat_financial_effect_and_key_reuse_is_rejected(): void
    {
        [$service, $partner, $offset, $applier] = $this->approvedOffset('1500000');
        $token = $offset->versionToken();
        $key = $this->key('apply');

        $first = $service->apply($offset, $applier, $token, $key);
        $second = $service->apply($offset->fresh(), $applier, $token, $key);

        $this->assertFalse($first['idempotent_replay']);
        $this->assertTrue($second['idempotent_replay']);
        $this->assertSame('3500000.00', $partner->fresh()->debt_amount);
        $this->assertSame(1, CashFlow::where('reference_code', $offset->code)->count());
        $this->assertSame(1, PartnerDebtOperation::where('operation_type', 'debt_offset.apply')->where('idempotency_key', $key)->count());

        $reusedKey = $this->key('create-reuse');
        $other = $this->user('other');
        $draft = $service->createDraft($partner, $other, '100000', 'one', $reusedKey);
        $this->assertFalse($draft['idempotent_replay']);
        $draftReplay = $service->createDraft($partner, $other, '100000', 'one', $reusedKey);
        $this->assertTrue($draftReplay['idempotent_replay']);
        $this->assertSame($draft['debt_offset']['id'], $draftReplay['debt_offset']['id']);
        $this->assertSame(1, DebtOffset::query()->where('idempotency_key', $reusedKey)->count());
        try {
            $service->createDraft($partner, $this->user('other2'), '200000', 'two', $reusedKey);
            $this->fail('An idempotency key cannot be reused for a different payload.');
        } catch (DebtOffsetWorkflowException $exception) {
            $this->assertSame('IDEMPOTENCY_KEY_REUSED', $exception->errorCode);
        }
    }

    public function test_stale_balance_blocks_apply_without_side_effect(): void
    {
        [$service, $partner, $offset, $applier] = $this->approvedOffset('4000000');
        $partner->forceFill(['debt_amount' => '3000000.00'])->save();

        try {
            $service->apply($offset, $applier, $offset->versionToken(), $this->key('apply-stale'));
            $this->fail('Apply must revalidate locked current balances.');
        } catch (DebtOffsetWorkflowException $exception) {
            $this->assertSame('OFFSET_AMOUNT_EXCEEDS_CURRENT_BALANCE', $exception->errorCode);
        }

        $this->assertSame('approved', $offset->fresh()->workflow_status);
        $this->assertSame(0, CashFlow::where('reference_code', $offset->code)->count());
        $this->assertSame(0, PartnerDebtOperation::where('operation_type', 'debt_offset.apply')
            ->where('source_id', $offset->id)->count());
    }

    public function test_reverse_restores_balances_once_and_reversal_voucher_cannot_be_reversed(): void
    {
        [$service, $partner, $offset, $applier] = $this->approvedOffset('2000000');
        $applied = $service->apply($offset, $applier, $offset->versionToken(), $this->key('apply'));
        $offset = DebtOffset::findOrFail($applied['debt_offset']['id']);
        $token = $offset->versionToken();
        $key = $this->key('reverse');

        $first = $service->reverse($offset, $applier, 'Sai chứng từ', $token, $key);
        $second = $service->reverse($offset->fresh(), $applier, 'Sai chứng từ', $token, $key);
        $reversal = DebtOffset::findOrFail($first['reversal_voucher']['id']);

        $this->assertFalse($first['idempotent_replay']);
        $this->assertTrue($second['idempotent_replay']);
        $this->assertSame('5000000.00', $partner->fresh()->debt_amount);
        $this->assertSame('5000000.00', $partner->fresh()->supplier_debt_amount);
        $this->assertSame('reversed', $offset->fresh()->workflow_status);
        $this->assertSame(1, DebtOffset::where('reverses_debt_offset_id', $offset->id)->count());
        $this->assertSame(1, CashFlow::where('reference_type', 'DebtOffsetReversal')->where('reference_code', $reversal->code)->count());

        try {
            $current = $offset->fresh();
            $service->reverse($current, $applier, 'Second reversal', $current->versionToken(), $this->key('reverse-again'));
            $this->fail('A second reversal with another key must be rejected.');
        } catch (DebtOffsetWorkflowException $exception) {
            $this->assertSame('OFFSET_ALREADY_REVERSED', $exception->errorCode);
        }

        try {
            $service->reverse($reversal, $applier, 'Không hợp lệ', $reversal->versionToken(), $this->key('reverse-reversal'));
            $this->fail('A reversal voucher cannot be reversed.');
        } catch (DebtOffsetWorkflowException $exception) {
            $this->assertSame('REVERSAL_OF_REVERSAL_FORBIDDEN', $exception->errorCode);
        }
    }

    public function test_apply_and_reverse_appear_exactly_once_in_customer_and_supplier_timelines(): void
    {
        [$service, $partner, $offset, $applier] = $this->approvedOffset('2000000');
        $applied = $service->apply($offset, $applier, $offset->versionToken(), $this->key('timeline-apply'));
        $offset = DebtOffset::findOrFail($applied['debt_offset']['id']);

        $this->assertTimelineCodeOccursOnce($partner, $offset->code);

        $reversed = $service->reverse(
            $offset,
            $applier,
            'Kiểm tra timeline exact-once',
            $offset->versionToken(),
            $this->key('timeline-reverse'),
        );
        $reversalCode = $reversed['reversal_voucher']['code'];

        $this->assertTimelineCodeOccursOnce($partner->fresh(), $offset->code);
        $this->assertTimelineCodeOccursOnce($partner->fresh(), $reversalCode);

        $syntheticLegacyCode = 'HCB'.str_pad((string) $offset->id, 6, '0', STR_PAD_LEFT);
        $customerDocument = app(CustomerDebtDocumentTimelineService::class)->build($partner->fresh());
        $supplierDocument = app(SupplierDebtDocumentTimelineService::class)->build($partner->fresh());
        $this->assertCount(0, collect($customerDocument['entries'])->where('code', $syntheticLegacyCode));
        $this->assertCount(0, collect($supplierDocument['entries'])->where('code', $syntheticLegacyCode));
    }

    public function test_legacy_active_can_be_reversed_but_cancelled_cannot(): void
    {
        $actor = $this->user('reverser');
        $partner = $this->partner();
        $legacy = DebtOffset::create([
            'code' => 'CB-LEGACY-'.uniqid(), 'customer_id' => $partner->id, 'amount' => '1000000.00',
            'receivable_before' => '6000000.00', 'payable_before' => '6000000.00',
            'receivable_after' => '5000000.00', 'payable_after' => '5000000.00',
            'status' => 'active', 'is_auto' => false,
        ]);

        app(DebtOffsetWorkflowService::class)->reverse($legacy, $actor, 'Đảo legacy', $legacy->versionToken(), $this->key('legacy-reverse'));
        $this->assertSame('6000000.00', $partner->fresh()->debt_amount);
        $operation = PartnerDebtOperation::where('operation_type', 'debt_offset.reverse')->firstOrFail();
        $this->assertTrue((bool) ($operation->metadata['legacy_source'] ?? false));

        $cancelled = DebtOffset::create([
            'code' => 'CB-LEGACY-CANCELLED-'.uniqid(), 'customer_id' => $partner->id, 'amount' => '100000.00',
            'status' => 'cancelled', 'is_auto' => false,
        ]);
        try {
            app(DebtOffsetWorkflowService::class)->reverse($cancelled, $actor, 'Đảo lại', $cancelled->versionToken(), $this->key('legacy-cancelled'));
            $this->fail('Cancelled legacy offset cannot be reversed.');
        } catch (DebtOffsetWorkflowException $exception) {
            $this->assertSame('OFFSET_ALREADY_REVERSED', $exception->errorCode);
        }
    }

    public function test_legacy_service_fails_closed_outside_legacy_mode(): void
    {
        $partner = $this->partner();
        try {
            DebtOffsetService::manualOffset($partner, 100000);
            $this->fail('Legacy write must be disabled in workflow mode.');
        } catch (DebtOffsetWorkflowException $exception) {
            $this->assertSame('LEGACY_DEBT_OFFSET_WRITE_DISABLED', $exception->errorCode);
        }

        config()->set('debt.offsets.write_mode', 'disabled');
        try {
            DebtOffsetService::offsetDebts($partner);
            $this->fail('All writes must be disabled in disabled mode.');
        } catch (DebtOffsetWorkflowException $exception) {
            $this->assertSame('DEBT_OFFSET_WORKFLOW_DISABLED', $exception->errorCode);
        }
    }

    private function approvedOffset(string $amount): array
    {
        $requester = $this->user('requester-'.uniqid());
        $approver = $this->user('approver-'.uniqid());
        $applier = $this->user('applier-'.uniqid());
        $partner = $this->partner();
        $service = app(DebtOffsetWorkflowService::class);
        $draft = $service->createDraft($partner, $requester, $amount, null, $this->key('create-'.uniqid()));
        $offset = DebtOffset::findOrFail($draft['debt_offset']['id']);
        $submitted = $service->submit($offset, $requester, $offset->versionToken(), $this->key('submit-'.uniqid()));
        $offset = DebtOffset::findOrFail($submitted['debt_offset']['id']);
        $approved = $service->approve($offset, $approver, $offset->versionToken(), $this->key('approve-'.uniqid()));

        return [$service, $partner, DebtOffset::findOrFail($approved['debt_offset']['id']), $applier];
    }

    private function assertTimelineCodeOccursOnce(Customer $partner, string $code): void
    {
        $customerDocument = app(CustomerDebtDocumentTimelineService::class)->build($partner->fresh());
        $supplierDocument = app(SupplierDebtDocumentTimelineService::class)->build($partner->fresh());
        $partnerLedger = app(PartnerDebtLedgerService::class);
        $customerLedger = $partnerLedger->buildCustomerNetLedger($partner->fresh());
        $supplierLedger = $partnerLedger->buildSupplierPayableLedger($partner->fresh());

        $customerDocumentRows = collect($customerDocument['entries'])->where('code', $code)->values();
        $supplierDocumentRows = collect($supplierDocument['entries'])->where('code', $code)->values();
        $customerLedgerRows = collect($customerLedger['entries'])->where('code', $code)->values();
        $supplierLedgerRows = collect($supplierLedger['entries'])->where('code', $code)->values();

        $this->assertCount(1, $customerDocumentRows, json_encode($customerDocumentRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertCount(1, $supplierDocumentRows, json_encode($supplierDocumentRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertCount(1, $customerLedgerRows, json_encode($customerLedgerRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertCount(1, $supplierLedgerRows, json_encode($supplierLedgerRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function partner(string $receivable = '5000000.00', string $payable = '5000000.00'): Customer
    {
        return Customer::create([
            'code' => 'DO-'.uniqid(), 'name' => 'Dual role test',
            'debt_amount' => $receivable, 'supplier_debt_amount' => $payable,
            'is_customer' => true, 'is_supplier' => true, 'status' => 'active',
        ]);
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
