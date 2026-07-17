<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\Security\RoleUserAdministrationGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private readonly RoleUserAdministrationGuard $guard) {}

    public function index(Request $request)
    {
        $query = User::with(['role:id,name,display_name', 'branch:id,name'])
            ->select('id', 'name', 'email', 'phone', 'role_id', 'branch_id', 'status', 'created_at');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%")
                    ->orWhere('phone', 'like', "%{$s}%");
            });
        }

        if ($request->filled('role_id')) {
            $query->where('role_id', $request->role_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderByDesc('id')->paginate($request->per_page ?? 20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:20',
            'role_id' => 'nullable|exists:roles,id',
            'branch_id' => 'nullable|exists:branches,id',
            'status' => 'nullable|in:active,locked',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
        ]);

        $assignedRole = $this->resolveRole($data['role_id'] ?? null);
        $this->guard->assertCanCreateUser($request->user(), $assignedRole);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'phone' => $data['phone'] ?? null,
            'role_id' => $assignedRole?->id,
            'branch_id' => $data['branch_id'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        if (array_key_exists('branch_ids', $data)) {
            $user->branchAccess()->sync($data['branch_ids'] ?? []);
        }

        $user->load(['role:id,name,display_name', 'branch:id,name']);

        return response()->json($user, 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => 'nullable|string|min:6',
            'phone' => 'nullable|string|max:20',
            'role_id' => 'nullable|exists:roles,id',
            'branch_id' => 'nullable|exists:branches,id',
            'status' => 'nullable|in:active,locked',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
        ]);

        $roleId = array_key_exists('role_id', $data) ? $data['role_id'] : $user->role_id;
        $assignedRole = $this->resolveRole($roleId);
        $roleChanged = $this->roleChanged($user->role_id, $assignedRole?->id);
        $status = $data['status'] ?? $user->status ?? 'active';

        $this->guard->assertCanUpdateUser(
            $request->user(),
            $user,
            $assignedRole,
            $roleChanged,
            $status
        );

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->phone = array_key_exists('phone', $data) ? $data['phone'] : $user->phone;
        $user->role_id = $assignedRole?->id;
        $user->branch_id = array_key_exists('branch_id', $data) ? $data['branch_id'] : $user->branch_id;
        $user->status = $status;
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        if (array_key_exists('branch_ids', $data)) {
            $user->branchAccess()->sync($data['branch_ids'] ?? []);
        }

        $user->load(['role:id,name,display_name', 'branch:id,name']);

        return response()->json($user);
    }

    public function destroy(Request $request, User $user)
    {
        $this->guard->assertCanDeleteUser($request->user(), $user);

        $user->branchAccess()->detach();
        $user->delete();

        return response()->json(['message' => 'Đã xóa tài khoản.']);
    }

    private function resolveRole(int|string|null $roleId): ?Role
    {
        return $roleId === null ? null : Role::query()->findOrFail((int) $roleId);
    }

    private function roleChanged(int|string|null $currentRoleId, ?int $assignedRoleId): bool
    {
        return $currentRoleId === null
            ? $assignedRoleId !== null
            : (int) $currentRoleId !== $assignedRoleId;
    }
}
