<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_user_endpoints_require_sanctum_authentication(): void
    {
        $role = $this->roleWith([]);
        $target = User::factory()->create(['role_id' => $role->id]);

        $this->getJson('/api/users')->assertUnauthorized();
        $this->postJson('/api/users', $this->createPayload($role))->assertUnauthorized();
        $this->putJson("/api/users/{$target->id}", $this->updatePayload($target))->assertUnauthorized();
        $this->deleteJson("/api/users/{$target->id}")->assertUnauthorized();
    }

    public function test_user_view_permission_can_index_without_secret_fields_but_cannot_mutate(): void
    {
        $viewer = $this->userWith(['users.view']);
        $role = $this->roleWith([]);
        $target = User::factory()->create(['role_id' => $role->id, 'remember_token' => 'hidden-token']);

        $response = $this->actingAs($viewer, 'web')->getJson('/api/users')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $target->id);
        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('password', $row);
        $this->assertArrayNotHasKey('remember_token', $row);

        $this->actingAs($viewer)->postJson('/api/users', $this->createPayload($role))->assertForbidden();
        $this->actingAs($viewer)->putJson("/api/users/{$target->id}", $this->updatePayload($target))->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/users/{$target->id}")->assertForbidden();
    }

    public function test_user_create_permission_can_create_normal_user_only(): void
    {
        $creator = $this->userWith(['users.create']);
        $role = $this->roleWith([]);

        $response = $this->actingAs($creator)->postJson('/api/users', $this->createPayload($role))
            ->assertCreated()
            ->assertJsonPath('role_id', $role->id);

        $created = User::findOrFail($response->json('id'));
        $this->assertTrue(Hash::check('secret123', $created->password));
        $this->assertArrayNotHasKey('password', $response->json());
        $this->assertArrayNotHasKey('remember_token', $response->json());
    }

    public function test_user_edit_permission_can_update_normal_user_and_hash_password(): void
    {
        $editor = $this->userWith(['users.edit']);
        $role = $this->roleWith([]);
        $target = User::factory()->create(['role_id' => $role->id, 'phone' => '0900000000']);

        $response = $this->actingAs($editor)->putJson("/api/users/{$target->id}", $this->updatePayload($target, [
            'name' => 'Updated normal user',
            'password' => 'new-secret-123',
        ]))->assertOk()->assertJsonPath('name', 'Updated normal user');

        $target->refresh();
        $this->assertTrue(Hash::check('new-secret-123', $target->password));
        $this->assertSame('0900000000', $target->phone);
        $this->assertArrayNotHasKey('password', $response->json());
        $this->assertArrayNotHasKey('remember_token', $response->json());
    }

    public function test_user_delete_permission_can_delete_normal_user_only(): void
    {
        $deletor = $this->userWith(['users.delete']);
        $target = User::factory()->create(['role_id' => $this->roleWith([])->id]);

        $this->actingAs($deletor)->deleteJson("/api/users/{$target->id}")->assertOk();
        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_non_admin_cannot_create_or_update_role_id_null_admin(): void
    {
        $creator = $this->userWith(['users.create']);
        $editor = $this->userWith(['users.edit']);
        $target = User::factory()->create(['role_id' => $this->roleWith([])->id]);

        $this->actingAs($creator)->postJson('/api/users', $this->createPayload(null))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ADMIN_PRIVILEGE_ESCALATION_FORBIDDEN');
        $this->actingAs($editor)->putJson("/api/users/{$target->id}", $this->updatePayload($target, [
            'role_id' => null,
        ]))->assertForbidden()->assertJsonPath('error_code', 'ADMIN_PRIVILEGE_ESCALATION_FORBIDDEN');

        $this->assertNotNull($target->fresh()->role_id);
    }

    public function test_non_admin_cannot_assign_wildcard_or_higher_permission_role(): void
    {
        $creator = $this->userWith(['users.create']);
        $editor = $this->userWith(['users.edit']);
        $wildcard = $this->roleWith(['*']);
        $higher = $this->roleWith(['users.delete']);
        $target = User::factory()->create(['role_id' => $this->roleWith([])->id]);

        $this->actingAs($creator)->postJson('/api/users', $this->createPayload($wildcard))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ADMIN_PRIVILEGE_ESCALATION_FORBIDDEN');
        $this->actingAs($creator)->postJson('/api/users', $this->createPayload($higher))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ADMIN_PRIVILEGE_ESCALATION_FORBIDDEN');
        $this->actingAs($editor)->putJson("/api/users/{$target->id}", $this->updatePayload($target, [
            'role_id' => $wildcard->id,
        ]))->assertForbidden()->assertJsonPath('error_code', 'ADMIN_PRIVILEGE_ESCALATION_FORBIDDEN');
    }

    public function test_non_admin_cannot_edit_or_delete_admin_or_higher_privilege_user(): void
    {
        $manager = $this->userWith(['users.edit', 'users.delete']);
        $nullAdmin = $this->admin();
        $wildcardAdmin = User::factory()->create(['role_id' => $this->roleWith(['*'])->id]);
        $higher = User::factory()->create(['role_id' => $this->roleWith(['users.create'])->id]);

        foreach ([$nullAdmin, $wildcardAdmin, $higher] as $target) {
            $this->actingAs($manager)->putJson("/api/users/{$target->id}", $this->updatePayload($target))
                ->assertForbidden()
                ->assertJsonPath('error_code', 'ADMIN_PRIVILEGE_ESCALATION_FORBIDDEN');
            $this->actingAs($manager)->deleteJson("/api/users/{$target->id}")
                ->assertForbidden()
                ->assertJsonPath('error_code', 'ADMIN_PRIVILEGE_ESCALATION_FORBIDDEN');
        }
    }

    public function test_user_cannot_delete_or_lock_self(): void
    {
        $actor = $this->userWith(['users.edit', 'users.delete']);

        $this->actingAs($actor)->deleteJson("/api/users/{$actor->id}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'SELF_DELETE_FORBIDDEN');
        $this->actingAs($actor)->putJson("/api/users/{$actor->id}", $this->updatePayload($actor, [
            'status' => 'locked',
        ]))->assertForbidden()->assertJsonPath('error_code', 'SELF_LOCK_FORBIDDEN');

        $this->assertSame('active', $actor->fresh()->status);
        $this->assertNull($actor->fresh()->deleted_at);
    }

    public function test_non_admin_cannot_increase_their_own_role(): void
    {
        $actor = $this->userWith(['users.edit']);
        $elevated = $this->roleWith(['users.edit', 'users.delete']);

        $this->actingAs($actor)->putJson("/api/users/{$actor->id}", $this->updatePayload($actor, [
            'role_id' => $elevated->id,
        ]))->assertForbidden()->assertJsonPath('error_code', 'ADMIN_PRIVILEGE_ESCALATION_FORBIDDEN');
    }

    public function test_branch_access_validation_and_sync_remain_correct(): void
    {
        $admin = $this->admin();
        $role = $this->roleWith([]);
        $branchA = Branch::create(['name' => 'Security branch A']);
        $branchB = Branch::create(['name' => 'Security branch B']);

        $created = $this->actingAs($admin)->postJson('/api/users', $this->createPayload($role, [
            'branch_id' => $branchA->id,
            'branch_ids' => [$branchA->id, $branchB->id],
        ]))->assertCreated();
        $user = User::findOrFail($created->json('id'));
        $this->assertEqualsCanonicalizing(
            [$branchA->id, $branchB->id],
            $user->branchAccess()->pluck('branches.id')->all()
        );

        $this->actingAs($admin)->putJson("/api/users/{$user->id}", $this->updatePayload($user, [
            'branch_ids' => [$branchB->id],
        ]))->assertOk();
        $this->assertSame([$branchB->id], $user->branchAccess()->pluck('branches.id')->all());

        $this->actingAs($admin)->putJson("/api/users/{$user->id}", $this->updatePayload($user, [
            'branch_ids' => [99999999],
        ]))->assertUnprocessable()->assertJsonValidationErrors('branch_ids.0');
        $this->actingAs($admin)->putJson("/api/users/{$user->id}", $this->updatePayload($user, [
            'role_id' => 99999999,
        ]))->assertUnprocessable()->assertJsonValidationErrors('role_id');
    }

    public function test_admin_wildcard_can_manage_admin_and_normal_users_except_self_lock_delete(): void
    {
        $admin = User::factory()->create(['role_id' => $this->roleWith(['*'])->id, 'status' => 'active']);
        $normalRole = $this->roleWith([]);

        $created = $this->actingAs($admin)->postJson('/api/users', $this->createPayload(null))
            ->assertCreated()
            ->assertJsonPath('role_id', null);
        $otherAdmin = User::findOrFail($created->json('id'));

        $this->actingAs($admin)->putJson("/api/users/{$otherAdmin->id}", $this->updatePayload($otherAdmin, [
            'role_id' => $normalRole->id,
        ]))->assertOk()->assertJsonPath('role_id', $normalRole->id);
        $this->actingAs($admin)->deleteJson("/api/users/{$otherAdmin->id}")->assertOk();
    }

    public function test_user_write_permissions_are_strictly_isolated(): void
    {
        $role = $this->roleWith([]);
        $target = User::factory()->create(['role_id' => $role->id]);
        $creator = $this->userWith(['users.create']);
        $editor = $this->userWith(['users.edit']);

        $this->actingAs($creator)->putJson("/api/users/{$target->id}", $this->updatePayload($target))
            ->assertForbidden();
        $this->actingAs($editor)->postJson('/api/users', $this->createPayload($role))
            ->assertForbidden();
        $this->actingAs($editor)->deleteJson("/api/users/{$target->id}")
            ->assertForbidden();
    }

    public function test_sanctum_stateful_api_accepts_authenticated_web_guard_session(): void
    {
        $actor = $this->userWith(['roles.view', 'users.view']);

        $this->actingAs($actor, 'web')->getJson('/api/roles')->assertOk();
        $this->actingAs($actor, 'web')->getJson('/api/users')->assertOk();
        $this->assertSame($actor->id, auth('web')->id());
    }

    private function roleWith(array $permissions): Role
    {
        return Role::create([
            'name' => 'api-user-role-'.uniqid(),
            'display_name' => 'API user role',
            'permissions' => $permissions,
            'is_system' => false,
        ]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'role_id' => $this->roleWith($permissions)->id,
            'status' => 'active',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role_id' => null, 'status' => 'active']);
    }

    private function createPayload(?Role $role, array $overrides = []): array
    {
        return [
            'name' => 'Created API user',
            'email' => uniqid().'@example.test',
            'password' => 'secret123',
            'phone' => '0912345678',
            'role_id' => $role?->id,
            'status' => 'active',
            'branch_ids' => [],
            ...$overrides,
        ];
    }

    private function updatePayload(User $user, array $overrides = []): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'branch_id' => $user->branch_id,
            'status' => $user->status ?? 'active',
            ...$overrides,
        ];
    }
}
