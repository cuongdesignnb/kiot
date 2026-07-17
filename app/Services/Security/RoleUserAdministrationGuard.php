<?php

namespace App\Services\Security;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

class RoleUserAdministrationGuard
{
    public function assertCanCreateRole(User $actor, array $permissions): void
    {
        $this->assertCanGrantPermissions($actor, $permissions);
    }

    public function assertCanUpdateRole(User $actor, Role $role, array $permissions): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if ($role->is_system || $role->hasPermission('*')) {
            $this->denyAdminEscalation('Chỉ Admin được sửa vai trò hệ thống hoặc vai trò Admin.');
        }

        $this->assertCanGrantPermissions($actor, $role->permissions ?? []);
        $this->assertCanGrantPermissions($actor, $permissions);
    }

    public function assertCanDeleteRole(User $actor, Role $role): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if ($role->is_system || $role->hasPermission('*')) {
            $this->denyAdminEscalation('Chỉ Admin được quản lý vai trò hệ thống hoặc vai trò Admin.');
        }

        $this->assertCanGrantPermissions($actor, $role->permissions ?? []);
    }

    public function assertCanDuplicateRole(User $actor, Role $role): void
    {
        $this->assertCanGrantPermissions($actor, $role->permissions ?? []);
    }

    public function assertCanCreateUser(User $actor, ?Role $assignedRole): void
    {
        $this->assertCanAssignRole($actor, $assignedRole);
    }

    public function assertCanUpdateUser(
        User $actor,
        User $target,
        ?Role $assignedRole,
        bool $roleChanged,
        string $status
    ): void {
        if ((int) $actor->id === (int) $target->id && $status === 'locked') {
            $this->deny('SELF_LOCK_FORBIDDEN', 'Không thể khóa tài khoản đang đăng nhập.');
        }

        $this->assertCanManageUser($actor, $target);

        if (! $roleChanged) {
            return;
        }

        $this->assertCanAssignRole($actor, $assignedRole);

        if ((int) $actor->id === (int) $target->id && ! $actor->isAdmin()) {
            $this->assertCanGrantPermissions($actor, $assignedRole?->permissions ?? ['*']);
        }
    }

    public function assertCanDeleteUser(User $actor, User $target): void
    {
        if ((int) $actor->id === (int) $target->id) {
            $this->deny('SELF_DELETE_FORBIDDEN', 'Không thể xóa tài khoản đang đăng nhập.');
        }

        $this->assertCanManageUser($actor, $target);
    }

    private function assertCanManageUser(User $actor, User $target): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if ($target->isAdmin()) {
            $this->denyAdminEscalation('Chỉ Admin được quản lý tài khoản Admin.');
        }

        $this->assertCanGrantPermissions($actor, $target->role?->permissions ?? []);
    }

    private function assertCanAssignRole(User $actor, ?Role $role): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if ($role === null || $role->hasPermission('*')) {
            $this->denyAdminEscalation('Chỉ Admin được gán tài khoản hoặc vai trò Admin.');
        }

        $this->assertCanGrantPermissions($actor, $role->permissions ?? []);
    }

    private function assertCanGrantPermissions(User $actor, array $permissions): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        foreach ($permissions as $permission) {
            if ($permission === '*' || ! $actor->hasPermission($permission)) {
                $this->denyAdminEscalation('Không thể cấp quyền cao hơn quyền của tài khoản thao tác.');
            }
        }
    }

    private function denyAdminEscalation(string $message): never
    {
        $this->deny('ADMIN_PRIVILEGE_ESCALATION_FORBIDDEN', $message);
    }

    private function deny(string $errorCode, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'error_code' => $errorCode,
        ], 403));
    }
}
