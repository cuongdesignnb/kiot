<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class UserController extends Controller
{
    /**
     * Danh sách người dùng.
     */
    public function index(Request $request)
    {
        $query = User::with(['role:id,name', 'branch:id,name', 'employee:id,user_id,name,code']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('role_id')) {
            $query->where('role_id', $request->role_id);
        }

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $users = $query->latest()->paginate(20)->withQueryString();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => Role::select('id', 'name')->orderBy('name')->get(),
            'branches' => Branch::select('id', 'name')->orderBy('name')->get(),
            'employees' => Employee::whereNull('user_id')->select('id', 'name', 'code')->orderBy('name')->get(),
            'filters' => $request->only('search', 'status', 'role_id', 'branch_id'),
        ]);
    }

    /**
     * Tạo người dùng mới.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:6',
            'role_id' => 'nullable|exists:roles,id',
            'branch_id' => 'nullable|exists:branches,id',
            'employee_id' => 'nullable|exists:employees,id',
            'status' => 'nullable|in:active,inactive',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'role_id' => $validated['role_id'] ?? null,
            'branch_id' => $validated['branch_id'] ?? null,
            'status' => $validated['status'] ?? 'active',
        ]);

        // Link to employee if specified
        if (!empty($validated['employee_id'])) {
            Employee::where('id', $validated['employee_id'])->update(['user_id' => $user->id]);
        }

        return redirect()->back()->with('success', 'Tạo người dùng thành công.');
    }

    /**
     * Cập nhật thông tin người dùng.
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'role_id' => 'nullable|exists:roles,id',
            'branch_id' => 'nullable|exists:branches,id',
            'employee_id' => 'nullable|exists:employees,id',
            'status' => 'nullable|in:active,inactive',
        ]);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'role_id' => $validated['role_id'] ?? null,
            'branch_id' => $validated['branch_id'] ?? null,
            'status' => $validated['status'] ?? $user->status,
        ]);

        // Update employee link
        // First unlink old employee
        Employee::where('user_id', $user->id)->update(['user_id' => null]);
        // Then link new employee if specified
        if (!empty($validated['employee_id'])) {
            Employee::where('id', $validated['employee_id'])->update(['user_id' => $user->id]);
        }

        return redirect()->back()->with('success', 'Cập nhật người dùng thành công.');
    }

    /**
     * Đổi mật khẩu.
     */
    public function changePassword(Request $request, User $user)
    {
        $validated = $request->validate([
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return redirect()->back()->with('success', 'Đổi mật khẩu thành công.');
    }

    /**
     * Toggle trạng thái active/inactive.
     */
    public function toggleStatus(User $user)
    {
        // Don't allow disabling yourself
        if ($user->id === auth()->id()) {
            return redirect()->back()->with('error', 'Không thể ngừng hoạt động tài khoản của chính bạn.');
        }

        $newStatus = $user->status === 'active' ? 'inactive' : 'active';
        $user->update(['status' => $newStatus]);

        $msg = $newStatus === 'active' ? 'Đã kích hoạt tài khoản.' : 'Đã ngừng hoạt động tài khoản.';
        return redirect()->back()->with('success', $msg);
    }

    /**
     * Xóa người dùng.
     */
    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return redirect()->back()->with('error', 'Không thể xóa tài khoản của chính bạn.');
        }

        // Unlink employee
        Employee::where('user_id', $user->id)->update(['user_id' => null]);

        $user->delete();

        return redirect()->back()->with('success', 'Xóa người dùng thành công.');
    }
}
