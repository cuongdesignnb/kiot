<?php

namespace Tests\Feature\DebtOffsets;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\DebtOffset;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DebtOffsetWorkflowPermissionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('debt.offsets.write_mode', 'workflow');
        config()->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
    }

    public function test_customers_edit_does_not_grant_debt_offset_create_permission(): void
    {
        $user = $this->userWith(['customers.edit']);
        $partner = $this->partner();

        $this->actingAs($user)->postJson("/customers/{$partner->id}/debt-offsets", [
            'amount' => '1000000',
        ], ['Idempotency-Key' => $this->key('unauthorized')])->assertForbidden();

        $this->assertSame(0, DebtOffset::where('customer_id', $partner->id)->count());
    }

    public function test_each_transition_requires_its_own_permission_and_admin_star_is_allowed(): void
    {
        $creator = $this->userWith(['debt_offsets.create']);
        $submitter = $this->userWith(['debt_offsets.submit']);
        $approver = $this->userWith(['debt_offsets.approve']);
        $applier = $this->userWith(['debt_offsets.apply']);
        $reverser = $this->userWith(['debt_offsets.reverse']);
        $viewer = $this->userWith(['debt_offsets.view']);
        $unauthorized = $this->userWith([]);
        $partner = $this->partner();
        $create = $this->actingAs($creator)->postJson("/customers/{$partner->id}/debt-offsets", [
            'amount' => '1000000',
        ], ['Idempotency-Key' => $this->key('create')])->assertCreated();
        $offsetId = $create->json('data.debt_offset.id');
        $token = $create->json('data.debt_offset.version_token');

        $updated = $this->actingAs($creator)->patchJson("/debt-offsets/{$offsetId}", [
            'amount' => '1200000',
            'note' => 'Permission matrix',
            'version_token' => $token,
        ], ['Idempotency-Key' => $this->key('update')])->assertOk();
        $token = $updated->json('data.debt_offset.version_token');

        $this->actingAs($creator)->postJson("/debt-offsets/{$offsetId}/submit", [
            'version_token' => $token,
        ], ['Idempotency-Key' => $this->key('submit-denied')])->assertForbidden();

        $submitted = $this->actingAs($submitter)->postJson("/debt-offsets/{$offsetId}/submit", [
            'version_token' => $token,
        ], ['Idempotency-Key' => $this->key('submit')])->assertOk()->assertJsonPath('data.debt_offset.workflow_status', 'pending_approval');
        $token = $submitted->json('data.debt_offset.version_token');

        $this->actingAs($submitter)->postJson("/debt-offsets/{$offsetId}/approve", [
            'version_token' => $token,
        ], ['Idempotency-Key' => $this->key('approve-denied')])->assertForbidden();

        $approved = $this->actingAs($approver)->postJson("/debt-offsets/{$offsetId}/approve", [
            'version_token' => $token,
        ], ['Idempotency-Key' => $this->key('approve')])->assertOk()->assertJsonPath('data.debt_offset.workflow_status', 'approved');
        $token = $approved->json('data.debt_offset.version_token');

        $this->actingAs($approver)->postJson("/debt-offsets/{$offsetId}/apply", [
            'version_token' => $token,
        ], ['Idempotency-Key' => $this->key('apply-denied')])->assertForbidden();

        $applied = $this->actingAs($applier)->postJson("/debt-offsets/{$offsetId}/apply", [
            'version_token' => $token,
        ], ['Idempotency-Key' => $this->key('apply')])->assertOk()->assertJsonPath('data.debt_offset.workflow_status', 'applied');
        $token = $applied->json('data.debt_offset.version_token');

        $this->actingAs($applier)->postJson("/debt-offsets/{$offsetId}/reverse", [
            'reason' => 'Permission denied check',
            'version_token' => $token,
        ], ['Idempotency-Key' => $this->key('reverse-denied')])->assertForbidden();

        $this->actingAs($reverser)->postJson("/debt-offsets/{$offsetId}/reverse", [
            'reason' => 'Permission matrix reversal',
            'version_token' => $token,
        ], ['Idempotency-Key' => $this->key('reverse')])->assertOk()->assertJsonPath('data.debt_offset.workflow_status', 'reversed');

        $this->actingAs($viewer)->getJson('/debt-offsets')->assertOk();
        $this->actingAs($unauthorized)->getJson('/debt-offsets')->assertForbidden();

        $admin = User::factory()->create(['role_id' => null, 'status' => 'active']);
        $this->actingAs($admin)->getJson('/debt-offsets')->assertOk();
    }

    public function test_reject_and_void_have_dedicated_permissions(): void
    {
        $creator = $this->userWith(['debt_offsets.create']);
        $submitter = $this->userWith(['debt_offsets.submit']);
        $rejecter = $this->userWith(['debt_offsets.reject']);
        $voider = $this->userWith(['debt_offsets.void']);
        $partner = $this->partner();

        $pending = $this->actingAs($creator)->postJson("/customers/{$partner->id}/debt-offsets", [
            'amount' => '500000',
        ], ['Idempotency-Key' => $this->key('reject-create')])->assertCreated();
        $pendingId = $pending->json('data.debt_offset.id');
        $submitted = $this->actingAs($submitter)->postJson("/debt-offsets/{$pendingId}/submit", [
            'version_token' => $pending->json('data.debt_offset.version_token'),
        ], ['Idempotency-Key' => $this->key('reject-submit')])->assertOk();

        $this->actingAs($submitter)->postJson("/debt-offsets/{$pendingId}/reject", [
            'rejection_reason' => 'Denied',
            'version_token' => $submitted->json('data.debt_offset.version_token'),
        ], ['Idempotency-Key' => $this->key('reject-denied')])->assertForbidden();
        $this->actingAs($rejecter)->postJson("/debt-offsets/{$pendingId}/reject", [
            'rejection_reason' => 'Missing evidence',
            'version_token' => $submitted->json('data.debt_offset.version_token'),
        ], ['Idempotency-Key' => $this->key('reject')])->assertOk()->assertJsonPath('data.debt_offset.workflow_status', 'rejected');

        $draft = $this->actingAs($creator)->postJson("/customers/{$partner->id}/debt-offsets", [
            'amount' => '250000',
        ], ['Idempotency-Key' => $this->key('void-create')])->assertCreated();
        $draftId = $draft->json('data.debt_offset.id');
        $this->actingAs($creator)->postJson("/debt-offsets/{$draftId}/void", [
            'reason' => 'Denied',
            'version_token' => $draft->json('data.debt_offset.version_token'),
        ], ['Idempotency-Key' => $this->key('void-denied')])->assertForbidden();
        $this->actingAs($voider)->postJson("/debt-offsets/{$draftId}/void", [
            'reason' => 'No longer needed',
            'version_token' => $draft->json('data.debt_offset.version_token'),
        ], ['Idempotency-Key' => $this->key('void')])->assertOk()->assertJsonPath('data.debt_offset.workflow_status', 'void');
    }

    public function test_branch_scope_is_enforced_from_server_side_partner_data(): void
    {
        Setting::set('customer_manage_by_branch', true);
        $branchA = Branch::create(['name' => 'Branch A']);
        $branchB = Branch::create(['name' => 'Branch B']);
        $user = $this->userWith(['debt_offsets.create'], $branchA->id);
        $partner = $this->partner($branchB->id);

        $this->actingAs($user)->postJson("/customers/{$partner->id}/debt-offsets", [
            'amount' => '1000000',
        ], ['Idempotency-Key' => $this->key('cross-branch')])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'BRANCH_SCOPE_FORBIDDEN');
    }

    public function test_legacy_direct_endpoints_are_guarded_by_workflow_and_disabled_modes(): void
    {
        $user = $this->userWith(['customers.edit']);
        $partner = $this->partner();

        $this->actingAs($user)->postJson("/customers/{$partner->id}/debt-offset", ['amount' => 100000], [])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'LEGACY_DEBT_OFFSET_WRITE_DISABLED');

        config()->set('debt.offsets.write_mode', 'disabled');
        $this->actingAs($user)->postJson("/customers/{$partner->id}/debt-offset", ['amount' => 100000], [])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'DEBT_OFFSET_WORKFLOW_DISABLED');
    }

    public function test_unknown_mode_fails_closed_and_new_workflow_is_disabled_in_legacy_mode(): void
    {
        $creator = $this->userWith(['debt_offsets.create']);
        $partner = $this->partner();

        config()->set('debt.offsets.write_mode', 'unexpected');
        $this->actingAs($creator)->postJson("/customers/{$partner->id}/debt-offsets", ['amount' => '100000'], [
            'Idempotency-Key' => $this->key('unknown'),
        ])->assertForbidden()->assertJsonPath('error_code', 'DEBT_OFFSET_WORKFLOW_DISABLED');

        config()->set('debt.offsets.write_mode', 'legacy');
        $this->actingAs($creator)->postJson("/customers/{$partner->id}/debt-offsets", ['amount' => '100000'], [
            'Idempotency-Key' => $this->key('legacy'),
        ])->assertForbidden()->assertJsonPath('error_code', 'DEBT_OFFSET_WORKFLOW_DISABLED');
    }

    public function test_frontend_navigation_and_create_button_are_gated_by_mode_and_permission(): void
    {
        $layout = file_get_contents(resource_path('js/Layouts/AppLayout.vue'));
        $customers = file_get_contents(resource_path('js/Pages/Customers/Index.vue'));

        $this->assertStringContainsString('page.props.debt_offsets?.write_mode', $layout);
        $this->assertStringContainsString("can('debt_offsets.view')", $layout);
        $this->assertStringContainsString('can("debt_offsets.create")', $customers);
        $this->assertStringContainsString('workflowOffsetEnabled', $customers);
    }

    private function userWith(array $permissions, ?int $branchId = null): User
    {
        $role = Role::create([
            'name' => 'debt-role-'.uniqid(), 'display_name' => 'Debt role',
            'permissions' => $permissions,
        ]);

        return User::factory()->create(['role_id' => $role->id, 'branch_id' => $branchId, 'status' => 'active']);
    }

    private function partner(?int $branchId = null): Customer
    {
        return Customer::create([
            'code' => 'DO-PERM-'.uniqid(), 'name' => 'Permission partner',
            'debt_amount' => '5000000.00', 'supplier_debt_amount' => '5000000.00',
            'is_customer' => true, 'is_supplier' => true, 'status' => 'active', 'branch_id' => $branchId,
        ]);
    }

    private function key(string $suffix): string
    {
        return 'debt-offset-'.$suffix.'-'.str_replace('.', '', uniqid('', true));
    }
}
