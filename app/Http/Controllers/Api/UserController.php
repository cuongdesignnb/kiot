<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
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
            'name'       => 'required|string|max:100',
            'email'      => 'required|email|unique:users,email',
            'password'   => 'required|string|min:6',
            'phone'      => 'nullable|string|max:20',
            'role_id'    => 'nullable|exists:roles,id',
            'branch_id'  => 'nullable|exists:branches,id',
            'status'     => 'nullable|in:active,locked',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
        ]);

        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'phone'     => $data['phone'] ?? null,
            'role_id'   => $data['role_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'status'    => $data['status'] ?? 'active',
        ]);

        if (!empty($data['branch_ids'])) {
            $user->branchAccess()->sync($data['branch_ids']);
        }

        $user->load(['role:id,name,display_name', 'branch:id,name']);
        return response()->json($user, 201);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'email'      => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password'   => 'nullable|string|min:6',
            'phone'      => 'nullable|string|max:20',
            'role_id'    => 'nullable|exists:roles,id',
            'branch_id'  => 'nullable|exists:branches,id',
            'status'     => 'nullable|in:active,locked',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'exists:branches,id',
        ]);

        $user->name      = $data['name'];
        $user->email     = $data['email'];
        $user->phone     = $data['phone'] ?? null;
        $user->role_id   = $data['role_id'] ?? null;
        $user->branch_id = $data['branch_id'] ?? null;
        $user->status    = $data['status'] ?? 'active';
        if (!empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        if (array_key_exists('branch_ids', $data)) {
            $user->branchAccess()->sync($data['branch_ids'] ?? []);
        }

        $user->load(['role:id,name,display_name', 'branch:id,name']);
        return response()->json($user);
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Không thể xóa tài khoản đang đăng nhập.'], 422);
        }
        $user->branchAccess()->detach();
        $user->delete();
        return response()->json(['message' => 'Đã xóa tài khoản.']);
    }
}
