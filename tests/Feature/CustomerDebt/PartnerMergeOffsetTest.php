<?php

namespace Tests\Feature\CustomerDebt;

use App\Models\Branch;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\Invoice;
use App\Models\PartnerDebtOperation;
use App\Models\PartnerMerge;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Setting;
use App\Models\SupplierDebtTransaction;
use App\Models\User;
use App\Services\Debt\CanonicalPartnerDebtService;
use App\Services\Debt\DebtOffsetWorkflowService;
use App\Services\DebtOffsetService;
use App\Services\PartnerMergeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PartnerMergeOffsetTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('debt.offsets.write_mode', 'legacy');
        config()->set('debt.mutation.failure_after', '');
    }

    public function test_merge_routes_require_both_edit_permissions_and_preview_the_offset(): void
    {
        $source = $this->partner(true, false);
        $target = $this->partner(false, true);
        $this->receivable($source, 10_000_000);
        $this->payable($target, 6_000_000);

        $this->actingAs($this->userWith(['customers.edit']))
            ->getJson("/customers/{$source->id}/merge-preview?target_id={$target->id}")
            ->assertForbidden();
        $this->actingAs($this->userWith(['customers.edit']))
            ->getJson('/customers/search-for-merge?type=supplier&q='.urlencode($target->code))
            ->assertForbidden();
        $this->actingAs($this->userWith(['suppliers.edit']))
            ->getJson("/customers/{$source->id}/merge-preview?target_id={$target->id}")
            ->assertForbidden();
        $this->actingAs($this->userWith(['suppliers.edit']))
            ->postJson(
                "/customers/{$source->id}/merge",
                ['merge_with_id' => $target->id],
                ['Idempotency-Key' => $this->key('missing-customer-permission')],
            )
            ->assertForbidden();

        $actor = $this->userWith(['customers.edit', 'suppliers.edit']);
        $this->actingAs($actor)
            ->getJson("/customers/{$source->id}/merge-preview?target_id={$target->id}")
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('surviving_partner_id', $target->id)
            ->assertJsonPath('combined.debt_amount', 10_000_000)
            ->assertJsonPath('combined.supplier_debt_amount', 6_000_000)
            ->assertJsonPath('automatic_offset.amount', 6_000_000)
            ->assertJsonPath('after.debt_amount', 4_000_000)
            ->assertJsonPath('after.supplier_debt_amount', 0);

        $this->actingAs($actor)
            ->postJson("/customers/{$source->id}/merge", ['merge_with_id' => $target->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_merge_creates_one_offset_and_replays_without_a_second_mutation(): void
    {
        $actor = $this->userWith(['customers.edit', 'suppliers.edit']);
        $source = $this->partner(true, false);
        $target = $this->partner(false, true);
        $invoice = $this->receivable($source, 10_000_000);
        $purchase = $this->payable($target, 6_000_000);
        $key = $this->key('merge-receivable');

        $response = $this->actingAs($actor)->postJson(
            "/customers/{$source->id}/merge",
            ['merge_with_id' => $target->id],
            ['Idempotency-Key' => $key],
        );
        $response->assertOk()
            ->assertJsonPath('status', 'merged')
            ->assertJsonPath('merge.after.debt_amount', 4_000_000)
            ->assertJsonPath('merge.after.supplier_debt_amount', 0)
            ->assertJsonPath('merge.offset.amount', 6_000_000);

        $offset = DebtOffset::query()->where('customer_id', $target->id)->sole();
        $this->assertTrue($offset->is_auto);
        $this->assertNull($offset->workflow_status);
        $this->assertSame('partner_merge', $offset->source_references['created_via']);
        $this->assertSame($source->id, $offset->source_references['source_partner_id']);
        $this->assertSame($target->id, $offset->source_references['target_partner_id']);
        $mergeEvidence = PartnerMerge::query()
            ->where('source_partner_id', $source->id)
            ->where('target_partner_id', $target->id)
            ->sole();
        $this->assertSame($mergeEvidence->id, $offset->source_references['partner_merge_id']);
        $this->assertStringStartsWith('CB', $offset->code);
        $this->assertSame(1, CashFlow::query()->where('reference_code', $offset->code)->count());
        $this->assertSame(0.0, (float) CashFlow::active()
            ->cashImpacting()
            ->where('reference_code', $offset->code)
            ->sum('amount'));
        $financialViewer = $this->userWith(['cash_flows.view', 'reports.view']);
        $this->actingAs($financialViewer)
            ->get('/cash-flows')
            ->assertInertia(fn ($page) => $page
                ->where('metrics.totalReceipts', fn ($value) => (float) $value === 0.0)
                ->where('metrics.totalPayments', fn ($value) => (float) $value === 0.0)
                ->where('metrics.fundBalance', fn ($value) => (float) $value === 0.0));
        $this->actingAs($financialViewer)
            ->get('/reports/cost-profit')
            ->assertInertia(fn ($page) => $page
                ->where('otherIncome', fn ($value) => (float) $value === 0.0));
        $this->assertSame(1, SupplierDebtTransaction::query()->where('code', $offset->code)->count());
        $this->assertSame('-6000000.00', (string) SupplierDebtTransaction::query()->where('code', $offset->code)->value('amount'));
        $this->assertSame($target->id, $invoice->fresh()->customer_id);
        $this->assertSame($target->id, $purchase->fresh()->supplier_id);
        $this->assertSame('inactive', $source->fresh()->status);
        $this->assertSame($target->id, $source->fresh()->merged_into_id);
        $this->assertSame(4_000_000.0, (float) $target->fresh()->debt_amount);
        $this->assertSame(0.0, (float) $target->fresh()->supplier_debt_amount);
        $this->assertSame(0.0, (float) CustomerDebt::query()
            ->where('customer_id', $target->id)
            ->where('ref_code', $response->json('merge.marker.ref_code'))
            ->where('type', 'merge_marker')
            ->sole()->amount);
        $this->assertSame(4_000_000.0, (float) PartnerMerge::query()
            ->where('source_partner_id', $source->id)
            ->where('target_partner_id', $target->id)
            ->sole()->target_debt_amount_after);
        $mergeOperation = PartnerDebtOperation::query()
            ->where('operation_type', 'debt.mutation.partner_merge')
            ->where('idempotency_key', $key)
            ->sole();
        $this->assertSame('PartnerMerge', $mergeOperation->source_type);
        $this->assertSame($mergeEvidence->id, $mergeOperation->source_id);
        $this->assertCanonicalAligned($source->fresh());
        $this->assertCanonicalAligned($target->fresh());

        $this->actingAs($actor)->postJson(
            "/customers/{$source->id}/merge",
            ['merge_with_id' => $target->id],
            ['Idempotency-Key' => $key],
        )->assertOk()->assertJsonPath('status', 'already_merged');

        $otherTarget = $this->partner(false, true);
        $this->actingAs($actor)->postJson(
            "/customers/{$source->id}/merge",
            ['merge_with_id' => $otherTarget->id],
            ['Idempotency-Key' => $key],
        )->assertStatus(409)->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REUSED');

        $this->actingAs($actor)->postJson(
            "/customers/{$source->id}/merge",
            ['merge_with_id' => $target->id],
            ['Idempotency-Key' => $this->key('merge-new-key')],
        )->assertStatus(409)->assertJsonPath('error_code', 'PARTNER_ALREADY_MERGED');

        $this->assertSame(1, PartnerMerge::query()->where('source_partner_id', $source->id)->count());
        $this->assertSame(1, DebtOffset::query()->where('customer_id', $target->id)->count());
        $this->assertSame(1, CashFlow::query()->where('reference_code', $offset->code)->count());
        $this->assertSame(1, SupplierDebtTransaction::query()->where('code', $offset->code)->count());
    }

    public function test_equal_balances_are_fully_offset_once(): void
    {
        $actor = $this->userWith(['customers.edit', 'suppliers.edit']);
        $this->actingAs($actor);
        $source = $this->partner(true, false);
        $target = $this->partner(false, true);
        $this->receivable($source, 2_500_000);
        $this->payable($target, 2_500_000);

        $result = app(PartnerMergeService::class)->merge($source, $target, $this->key('equal-balances'));

        $this->assertSame(2_500_000.0, $result['automatic_offset']['amount']);
        $this->assertSame(0.0, (float) $target->fresh()->debt_amount);
        $this->assertSame(0.0, (float) $target->fresh()->supplier_debt_amount);
        $this->assertSame(1, DebtOffset::query()->where('customer_id', $target->id)->count());
        $this->assertCanonicalAligned($target->fresh());
    }

    public function test_payable_larger_than_receivable_offsets_and_legacy_cancel_restores_both_sides(): void
    {
        $actor = $this->userWith(['customers.edit', 'suppliers.edit']);
        $this->actingAs($actor);
        $source = $this->partner(false, true);
        $target = $this->partner(true, false);
        $this->payable($source, 9_000_000);
        $this->receivable($target, 4_000_000);

        $mergeKey = $this->key('payable-larger');
        $result = app(PartnerMergeService::class)->merge($source, $target, $mergeKey);
        $this->assertSame(4_000_000.0, $result['automatic_offset']['amount']);
        $this->assertSame(0.0, (float) $target->fresh()->debt_amount);
        $this->assertSame(5_000_000.0, (float) $target->fresh()->supplier_debt_amount);

        $offset = DebtOffset::findOrFail($result['offset']['id']);
        DebtOffsetService::cancelOffset($offset, 'Hoàn phiếu tự sinh', $this->key('legacy-reverse'));

        $this->assertSame(4_000_000.0, (float) $target->fresh()->debt_amount);
        $this->assertSame(9_000_000.0, (float) $target->fresh()->supplier_debt_amount);
        $this->assertSame('inactive', $source->fresh()->status);
        $this->assertSame($target->id, $source->fresh()->merged_into_id);
        $this->assertSame('committed', PartnerDebtOperation::query()
            ->where('operation_type', 'debt.mutation.partner_merge')
            ->where('idempotency_key', $mergeKey)
            ->sole()->status);
        $this->assertCanonicalAligned($target->fresh());
    }

    public function test_workflow_reverse_does_not_reverse_or_undo_the_merge_operation(): void
    {
        config()->set('debt.offsets.write_mode', 'workflow');
        $actor = $this->userWith(['customers.edit', 'suppliers.edit', 'debt_offsets.reverse']);
        $this->actingAs($actor);
        $source = $this->partner(true, false);
        $target = $this->partner(false, true);
        $this->receivable($source, 7_000_000);
        $this->payable($target, 3_000_000);

        $mergeKey = $this->key('workflow-merge');
        $result = app(PartnerMergeService::class)->merge($source, $target, $mergeKey);
        $offset = DebtOffset::findOrFail($result['offset']['id']);
        $this->assertSame('applied', $offset->workflow_status);
        $this->assertNull($offset->apply_operation_id);

        app(DebtOffsetWorkflowService::class)->reverse(
            $offset,
            $actor,
            'Hoàn cấn trừ sau đối soát',
            $offset->versionToken(),
            $this->key('workflow-reverse'),
        );

        $this->assertSame(7_000_000.0, (float) $target->fresh()->debt_amount);
        $this->assertSame(3_000_000.0, (float) $target->fresh()->supplier_debt_amount);
        $this->assertSame('reversed', $offset->fresh()->workflow_status);
        $this->assertSame('cancelled', $offset->fresh()->status);
        $this->assertSame('inactive', $source->fresh()->status);
        $this->assertSame($target->id, $source->fresh()->merged_into_id);
        $this->assertSame('committed', PartnerDebtOperation::query()
            ->where('operation_type', 'debt.mutation.partner_merge')
            ->where('idempotency_key', $mergeKey)
            ->sole()->status);
        $this->assertCanonicalAligned($target->fresh());
    }

    public function test_supplier_allocations_and_soft_deleted_cash_flows_move_to_the_survivor(): void
    {
        $actor = $this->userWith(['customers.edit', 'suppliers.edit']);
        $this->actingAs($actor);
        $source = $this->partner(false, true);
        $target = $this->partner(true, false);
        $this->payable($source, 6_000_000);
        $this->receivable($target, 10_000_000);

        $paidPurchase = Purchase::query()->create([
            'code' => 'PN-PAID-'.Str::uuid(),
            'supplier_id' => $source->id,
            'status' => 'completed',
            'total_amount' => 100_000,
            'paid_amount' => 100_000,
            'debt_amount' => 0,
            'purchase_date' => now(),
        ]);
        $payment = CashFlow::query()->create([
            'code' => 'PCPN-'.Str::uuid(),
            'type' => 'payment',
            'amount' => 100_000,
            'time' => now(),
            'target_type' => 'Nha cung cap',
            'target_id' => $source->id,
            'target_name' => $source->name,
            'reference_type' => 'SupplierPayment',
            'status' => 'completed',
        ]);
        $operation = PartnerDebtOperation::query()->create([
            'operation_uuid' => (string) Str::uuid(),
            'partner_id' => $source->id,
            'operation_type' => 'fixture.supplier_payment',
            'idempotency_key' => $this->key('allocation-operation'),
            'request_hash' => hash('sha256', 'allocation-operation'),
            'status' => 'committed',
            'attempt_count' => 1,
            'initiated_by' => $actor->id,
            'initiated_at' => now(),
            'committed_at' => now(),
        ]);
        $allocationId = DB::table('supplier_payment_allocations')->insertGetId([
            'payment_id' => $payment->id,
            'purchase_id' => $paidPurchase->id,
            'supplier_id' => $source->id,
            'amount' => 100_000,
            'allocation_source' => 'manual',
            'idempotency_key' => $this->key('allocation-row'),
            'operation_id' => $operation->id,
            'allocated_at' => now(),
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $deletedFlow = CashFlow::query()->create([
            'code' => 'PC-DELETED-'.Str::uuid(),
            'type' => 'payment',
            'amount' => 1,
            'time' => now(),
            'target_type' => 'supplier',
            'target_id' => $source->id,
            'target_name' => $source->name,
            'reference_type' => 'Manual',
            'status' => 'active',
        ]);
        $deletedFlow->delete();
        $this->assertCanonicalAligned($source->fresh());

        app(PartnerMergeService::class)->merge($source, $target, $this->key('transfer-evidence'));

        $this->assertSame($target->id, (int) DB::table('supplier_payment_allocations')->where('id', $allocationId)->value('supplier_id'));
        $this->assertSame($target->id, $payment->fresh()->target_id);
        $this->assertSame($target->id, CashFlow::withTrashed()->findOrFail($deletedFlow->id)->target_id);
        $this->assertNotNull(CashFlow::withTrashed()->findOrFail($deletedFlow->id)->deleted_at);
        $this->assertFalse(DB::table('supplier_payment_allocations')->where('supplier_id', $source->id)->exists());
        $this->assertCanonicalAligned($target->fresh());
    }

    public function test_drift_and_disabled_offset_fail_closed_without_mutation(): void
    {
        $actor = $this->userWith(['customers.edit', 'suppliers.edit']);
        $drifted = $this->partner(true, false, null, 500_000, 0);
        $supplier = $this->partner(false, true);

        $this->actingAs($actor)
            ->getJson("/customers/{$drifted->id}/merge-preview?target_id={$supplier->id}")
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'PARTNER_MERGE_DEBT_RECONCILIATION_REQUIRED');

        $alignedCustomer = $this->partner(true, false);
        $alignedSupplier = $this->partner(false, true);
        $this->receivable($alignedCustomer, 2_000_000);
        $this->payable($alignedSupplier, 1_000_000);
        config()->set('debt.offsets.write_mode', 'disabled');

        $this->actingAs($actor)
            ->getJson("/customers/{$alignedCustomer->id}/merge-preview?target_id={$alignedSupplier->id}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'DEBT_OFFSET_DISABLED');

        $this->assertSame(0, PartnerMerge::query()
            ->whereIn('source_partner_id', [$drifted->id, $alignedCustomer->id])
            ->count());
        $this->assertSame(0, DebtOffset::query()
            ->whereIn('customer_id', [$supplier->id, $alignedSupplier->id])
            ->count());
        $this->assertNull($drifted->fresh()->merged_into_id);
        $this->assertNull($alignedCustomer->fresh()->merged_into_id);
    }

    public function test_disabled_offset_mode_still_allows_a_merge_when_one_side_is_zero(): void
    {
        config()->set('debt.offsets.write_mode', 'disabled');
        $actor = $this->userWith(['customers.edit', 'suppliers.edit']);
        $this->actingAs($actor);
        $source = $this->partner(true, false);
        $target = $this->partner(false, true);
        $firstInvoice = $this->receivable($source, 1_200_000);
        $secondInvoice = $this->receivable($source, 800_000);

        $result = app(PartnerMergeService::class)->merge($source, $target, $this->key('disabled-without-offset'));

        $this->assertFalse($result['automatic_offset']['required']);
        $this->assertSame(0.0, $result['automatic_offset']['amount']);
        $this->assertNull($result['offset']);
        $this->assertSame(2_000_000.0, (float) $target->fresh()->debt_amount);
        $this->assertSame(0.0, (float) $target->fresh()->supplier_debt_amount);
        $this->assertSame($target->id, $firstInvoice->fresh()->customer_id);
        $this->assertSame($target->id, $secondInvoice->fresh()->customer_id);
        $this->assertSame(0, DebtOffset::query()->where('customer_id', $target->id)->count());
        $this->assertCanonicalAligned($target->fresh());
    }

    public function test_failure_injection_rolls_back_every_merge_checkpoint(): void
    {
        $actor = $this->userWith(['customers.edit', 'suppliers.edit']);
        $this->actingAs($actor);

        foreach (['document', 'evidence', 'projection'] as $stage) {
            $source = $this->partner(true, false);
            $target = $this->partner(false, true);
            $invoice = $this->receivable($source, 2_000_000);
            $purchase = $this->payable($target, 1_000_000);
            config()->set('debt.mutation.failure_after', $stage);

            try {
                app(PartnerMergeService::class)->merge($source, $target, $this->key("failure-{$stage}"));
                $this->fail("Expected injected failure after {$stage}.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString("after {$stage}", $exception->getMessage());
            } finally {
                config()->set('debt.mutation.failure_after', '');
            }

            $this->assertNull($source->fresh()->merged_into_id);
            $this->assertSame('active', $source->fresh()->status);
            $this->assertSame(2_000_000.0, (float) $source->fresh()->debt_amount);
            $this->assertSame(1_000_000.0, (float) $target->fresh()->supplier_debt_amount);
            $this->assertSame($source->id, $invoice->fresh()->customer_id);
            $this->assertSame($target->id, $purchase->fresh()->supplier_id);
            $this->assertSame(0, PartnerMerge::query()->where('source_partner_id', $source->id)->count());
            $this->assertSame(0, DebtOffset::query()->where('customer_id', $target->id)->count());
        }
    }

    public function test_merge_search_and_preview_respect_branch_scope(): void
    {
        Setting::set('customer_manage_by_branch', true);
        $branchA = Branch::query()->create(['code' => 'BRA-'.uniqid(), 'name' => 'Branch A']);
        $branchB = Branch::query()->create(['code' => 'BRB-'.uniqid(), 'name' => 'Branch B']);
        $actor = $this->userWith(['customers.edit', 'suppliers.edit'], $branchA->id);
        $source = $this->partner(true, false, $branchA->id);
        $target = $this->partner(false, true, $branchB->id);

        $this->actingAs($actor)
            ->getJson('/customers/search-for-merge?type=supplier&q='.urlencode($target->code))
            ->assertOk()
            ->assertExactJson([]);

        $this->actingAs($actor)
            ->getJson("/customers/{$source->id}/merge-preview?target_id={$target->id}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'PARTNER_MERGE_BRANCH_FORBIDDEN');
    }

    public function test_frontend_gates_merge_and_displays_the_automatic_offset_contract(): void
    {
        foreach ([
            resource_path('js/Pages/Customers/Index.vue'),
            resource_path('js/Pages/Suppliers/Index.vue'),
        ] as $file) {
            $contents = file_get_contents($file);
            $this->assertStringContainsString('canMergePartners', $contents);
            $this->assertStringContainsString('can("customers.edit") && can("suppliers.edit")', $contents);
            $this->assertStringContainsString('mergeModal.preview.automatic_offset.amount', $contents);
            $this->assertStringContainsString('Hồ sơ được giữ lại', $contents);
            $this->assertStringContainsString('mergeErrorMessage', $contents);
        }
    }

    private function userWith(array $permissions, ?int $branchId = null): User
    {
        $role = Role::query()->create([
            'name' => 'partner-merge-role-'.uniqid(),
            'display_name' => 'Partner merge role',
            'permissions' => $permissions,
        ]);

        return User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branchId,
            'status' => 'active',
        ]);
    }

    private function partner(
        bool $isCustomer,
        bool $isSupplier,
        ?int $branchId = null,
        int $receivable = 0,
        int $payable = 0,
    ): Customer {
        return Customer::query()->create([
            'code' => 'PARTNER-'.Str::uuid(),
            'name' => 'Partner '.uniqid(),
            'branch_id' => $branchId,
            'debt_amount' => $receivable,
            'supplier_debt_amount' => $payable,
            'total_spent' => 0,
            'total_returns' => 0,
            'total_bought' => 0,
            'is_customer' => $isCustomer,
            'is_supplier' => $isSupplier,
            'status' => 'active',
        ]);
    }

    private function receivable(Customer $customer, int $amount): Invoice
    {
        $invoice = Invoice::query()->create([
            'code' => 'HD-'.Str::uuid(),
            'customer_id' => $customer->id,
            'subtotal' => $amount,
            'total' => $amount,
            'customer_paid' => 0,
            'order_deposit_applied_amount' => 0,
            'status' => 'completed',
            'transaction_date' => now(),
        ]);
        $customer->increment('debt_amount', $amount);
        $customer->refresh();

        return $invoice;
    }

    private function payable(Customer $supplier, int $amount): Purchase
    {
        $purchase = Purchase::query()->create([
            'code' => 'PN-'.Str::uuid(),
            'supplier_id' => $supplier->id,
            'status' => 'completed',
            'total_amount' => $amount,
            'paid_amount' => 0,
            'debt_amount' => $amount,
            'purchase_date' => now(),
        ]);
        $supplier->increment('supplier_debt_amount', $amount);
        $supplier->refresh();

        return $purchase;
    }

    private function assertCanonicalAligned(Customer $partner): void
    {
        $canonical = app(CanonicalPartnerDebtService::class)->calculate($partner);
        $this->assertFalse((bool) $canonical['has_mismatch'], json_encode($canonical['differences']));
    }

    private function key(string $suffix): string
    {
        return $suffix.'-'.Str::uuid();
    }
}
