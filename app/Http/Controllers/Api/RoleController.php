<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::withCount('users')->orderBy('is_system', 'desc')->orderBy('id')->get();
        return response()->json($roles);
    }

    public function show(Role $role)
    {
        $role->loadCount('users');
        return response()->json($role);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'         => 'required|string|max:50|unique:roles,name',
            'display_name' => 'required|string|max:100',
            'description'  => 'nullable|string|max:255',
            'permissions'  => 'required|array',
            'permissions.*' => 'string',
        ]);

        $role = Role::create($data);
        return response()->json($role, 201);
    }

    public function update(Request $request, Role $role)
    {
        $data = $request->validate([
            'display_name' => 'required|string|max:100',
            'description'  => 'nullable|string|max:255',
            'permissions'  => 'required|array',
            'permissions.*' => 'string',
        ]);

        $role->update($data);
        return response()->json($role);
    }

    public function destroy(Role $role)
    {
        if ($role->is_system) {
            return response()->json(['message' => 'Không thể xóa vai trò hệ thống.'], 422);
        }
        if ($role->users()->count() > 0) {
            return response()->json(['message' => 'Vai trò đang được gán cho người dùng, không thể xóa.'], 422);
        }
        $role->delete();
        return response()->json(['message' => 'Đã xóa vai trò.']);
    }

    /**
     * Return the full permissions map for the role editor UI.
     */
    public function permissionsMap()
    {
        return response()->json(Role::getPermissionsMap());
    }

    /**
     * Clone a role.
     */
    public function duplicate(Role $role)
    {
        $new = Role::create([
            'name'         => $role->name . '_copy_' . time(),
            'display_name' => $role->display_name . ' (Bản sao)',
            'description'  => $role->description,
            'permissions'  => $role->permissions,
            'is_system'    => false,
        ]);
        return response()->json($new, 201);
    }
}
