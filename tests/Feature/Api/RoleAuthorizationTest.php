<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_role_endpoints_require_sanctum_authentication(): void
    {
        $role = $this->roleWith(['dashboard.view']);
        $payload = $this->rolePayload(['dashboard.view']);

        $this->getJson('/api/roles/permissions-map')->assertUnauthorized();
        $this->getJson('/api/roles')->assertUnauthorized();
        $this->getJson("/api/roles/{$role->id}")->assertUnauthorized();
        $this->postJson('/api/roles', $payload)->assertUnauthorized();
        $this->putJson("/api/roles/{$role->id}", $payload)->assertUnauthorized();
        $this->deleteJson("/api/roles/{$role->id}")->assertUnauthorized();
        $this->postJson("/api/roles/{$role->id}/duplicate")->assertUnauthorized();
    }

    public function test_role_view_permission_can_read_but_cannot_mutate(): void
    {
        $viewer = $this->userWith(['roles.view']);
        $role = $this->roleWith(['dashboard.view']);

        $this->actingAs($viewer, 'web')->getJson('/api/roles')->assertOk();
        $this->actingAs($viewer, 'web')->getJson("/api/roles/{$role->id}")
            ->assertOk()
            ->assertJsonPath('id', $role->id);
        $this->actingAs($viewer, 'web')->getJson('/api/roles/permissions-map')
            ->assertOk()
            ->assertJsonStructure(['Tổng quan']);

        $this->actingAs($viewer, 'web')->postJson('/api/roles', $this->rolePayload(['dashboard.view']))
            ->assertForbidden();
        $this->actingAs($viewer, 'web')->putJson("/api/roles/{$role->id}", $this->rolePayload(['dashboard.view']))
            ->assertForbidden();
        $this->actingAs($viewer, 'web')->deleteJson("/api/roles/{$role->id}")
            ->assertForbidden();
        $this->actingAs($viewer, 'web')->postJson("/api/roles/{$role->id}/duplicate")
            ->assertForbidden();
    }

    public function test_role_create_permission_can_create_and_duplicate_but_cannot_update(): void
    {
        $creator = $this->userWith(['roles.create', 'dashboard.view']);
        $source = $this->roleWith(['dashboard.view']);

        $this->actingAs($creator)->postJson('/api/roles', $this->rolePayload(['dashboard.view']))
            ->assertCreated();
        $this->actingAs($creator)->postJson("/api/roles/{$source->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('permissions.0', 'dashboard.view');
        $this->actingAs($creator)->putJson("/api/roles/{$source->id}", $this->rolePayload(['dashboard.view']))
            ->assertForbidden();
    }

    public function test_role_edit_and_delete_permissions_are_isolated(): void
    {
        $editor = $this->userWith(['roles.edit', 'dashboard.view']);
        $deletor = $this->userWith(['roles.delete']);
        $editable = $this->roleWith(['dashboard.view']);
        $deletable = $this->roleWith([]);

        $this->actingAs($editor)->putJson("/api/roles/{$editable->id}", [
            'display_name' => 'Updated role',
            'description' => 'Updated safely',
            'permissions' => ['dashboard.view'],
        ])->assertOk()->assertJsonPath('display_name', 'Updated role');
        $this->actingAs($editor)->deleteJson("/api/roles/{$editable->id}")->assertForbidden();

        $this->actingAs($deletor)->deleteJson("/api/roles/{$deletable->id}")->assertOk();
        $this->assertSoftDeletedOrMissingRole($deletable);
        $this->actingAs($deletor)->putJson("/api/roles/{$editable->id}", $this->rolePayload(['dashboard.view']))
            ->assertForbidden();
    }

    public function test_unknown_permission_is_rejected_without_persisting_role(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/roles', $this->rolePayload(['not.a.real.permission']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('permissions.0');

        $this->assertDatabaseMissing('roles', ['display_name' => 'API test role']);
    }

    public function test_non_admin_cannot_assign_wildcard_or_permissions_above_their_own(): void
    {
        $creator = $this->userWith(['roles.create']);
        $editor = $this->userWith(['roles.edit']);
        $normal = $this->roleWith([]);
        $wildcard = $this->roleWith(['*']);

        $this->actingAs($creator)->postJson('/api/roles', $this->rolePayload(['*']))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ADMIN_PRIVILEGE_ESCALATION_FORBIDDEN');
        $this->actingAs($creator)->postJson('/api/roles', $this->rolePayload(['users.delete']))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ADMIN_PRIVILEGE_ESCALATION_FORBIDDEN');
        $this->actingAs($editor)->putJson("/api/roles/{$normal->id}", $this->rolePayload(['*']))
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ADMIN_PRIVILEGE_ESCALATION_FORBIDDEN');
        $this->actingAs($creator)->postJson("/api/roles/{$wildcard->id}/duplicate")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'ADMIN_PRIVILEGE_ESCALATION_FORBIDDEN');
    }

    public function test_non_admin_cannot_edit_wildcard_system_or_higher_privilege_roles(): void
    {
        $editor = $this->userWith(['roles.edit', 'dashboard.view']);
        $wildcard = $this->roleWith(['*']);
        $system = $this->roleWith(['dashboard.view'], ['is_system' => true]);
        $higher = $this->roleWith(['users.delete']);

        foreach ([$wildcard, $system, $higher] as $role) {
            $this->actingAs($editor)->putJson("/api/roles/{$role->id}", $this->rolePayload(['dashboard.view']))
                ->assertForbidden()
                ->assertJsonPath('error_code', 'ADMIN_PRIVILEGE_ESCALATION_FORBIDDEN');
        }
    }

    public function test_non_admin_cannot_delete_admin_system_or_higher_privilege_roles(): void
    {
        $deletor = $this->userWith(['roles.delete']);
        $wildcard = $this->roleWith(['*']);
        $system = $this->roleWith([], ['is_system' => true]);
        $higher = $this->roleWith(['users.delete']);

        foreach ([$wildcard, $system, $higher] as $role) {
            $this->actingAs($deletor)->deleteJson("/api/roles/{$role->id}")
                ->assertForbidden()
                ->assertJsonPath('error_code', 'ADMIN_PRIVILEGE_ESCALATION_FORBIDDEN');
        }
    }

    public function test_admin_can_manage_wildcard_and_system_roles_but_cannot_delete_system_role(): void
    {
        $admin = $this->wildcardAdmin();
        $wildcard = $this->roleWith(['*']);
        $system = $this->roleWith(['dashboard.view'], ['is_system' => true]);

        $this->actingAs($admin)->postJson('/api/roles', $this->rolePayload(['*']))
            ->assertCreated()
            ->assertJsonPath('permissions.0', '*');
        $this->actingAs($admin)->putJson("/api/roles/{$wildcard->id}", $this->rolePayload(['*', 'dashboard.view']))
            ->assertOk();
        $this->actingAs($admin)->putJson("/api/roles/{$system->id}", $this->rolePayload(['dashboard.view']))
            ->assertOk();
        $this->assertSame($system->name, $system->fresh()->name);
        $this->actingAs($admin)->deleteJson("/api/roles/{$system->id}")
            ->assertUnprocessable();
    }

    public function test_role_mutation_persists_exact_allowed_permissions_and_ignores_is_system_input(): void
    {
        $admin = $this->admin();
        $permissions = ['dashboard.view', 'roles.view'];

        $response = $this->actingAs($admin)->postJson('/api/roles', [
            ...$this->rolePayload($permissions),
            'is_system' => true,
        ])->assertCreated();

        $role = Role::findOrFail($response->json('id'));
        $this->assertSame($permissions, $role->permissions);
        $this->assertFalse($role->is_system);

        $this->actingAs($admin)->putJson("/api/roles/{$role->id}", [
            ...$this->rolePayload(array_reverse($permissions)),
            'is_system' => true,
        ])->assertOk();

        $this->assertSame(array_reverse($permissions), $role->fresh()->permissions);
        $this->assertFalse($role->fresh()->is_system);
    }

    public function test_role_and_settings_permissions_do_not_cross_authorize_admin_apis(): void
    {
        $rolesViewer = $this->userWith(['roles.view']);
        $usersViewer = $this->userWith(['users.view']);
        $settingsManager = $this->userWith(['settings.view', 'settings.manage']);

        $this->actingAs($rolesViewer)->getJson('/api/users')->assertForbidden();
        $this->actingAs($usersViewer)->getJson('/api/roles')->assertForbidden();
        $this->actingAs($settingsManager)->getJson('/api/roles')->assertForbidden();
        $this->actingAs($settingsManager)->postJson('/api/roles', $this->rolePayload(['dashboard.view']))
            ->assertForbidden();
        $this->actingAs($settingsManager)->postJson('/api/users', $this->userPayload())
            ->assertForbidden();
    }

    private function roleWith(array $permissions, array $attributes = []): Role
    {
        return Role::create([
            'name' => 'api-role-'.uniqid(),
            'display_name' => 'API role',
            'permissions' => $permissions,
            'is_system' => false,
            ...$attributes,
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

    private function wildcardAdmin(): User
    {
        return User::factory()->create([
            'role_id' => $this->roleWith(['*'])->id,
            'status' => 'active',
        ]);
    }

    private function rolePayload(array $permissions): array
    {
        return [
            'name' => 'api-created-'.uniqid(),
            'display_name' => 'API test role',
            'description' => 'Security test',
            'permissions' => $permissions,
        ];
    }

    private function userPayload(): array
    {
        return [
            'name' => 'API user',
            'email' => uniqid().'@example.test',
            'password' => 'secret123',
            'role_id' => null,
        ];
    }

    private function assertSoftDeletedOrMissingRole(Role $role): void
    {
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }
}
