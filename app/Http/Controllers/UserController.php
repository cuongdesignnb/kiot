<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use App\Models\Branch;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use App\Support\Filters\FilterableIndex;

class UserController extends Controller
{
    use FilterableIndex;

    protected function configureUserFilters(): void
    {
        $this->searchable = ['name', 'email', 'phone'];
        $this->sortable = ['name', 'email', 'phone', 'status', 'created_at'];
        $this->dateColumn = 'created_at';
        $this->scalarFilters = ['status', 'role_id', 'branch_id'];
    }

    /**
     * Danh sách người dùng.
     */
    public function index(Request $request)
    {
        $this->configureUserFilters();

        $query = User::with(['role:id,name', 'branch:id,name', 'employee:id,user_id,name,code']);

        $this->applyFilters($query, $request);

        $users = $query->paginate(20)->withQueryString();

        $filterOptions = [
            'roles' => Role::select('id', 'name')->orderBy('name')->get(),
            'branches' => Branch::select('id', 'name')->orderBy('name')->get(),
            'statuses' => [
                ['value' => 'active', 'label' => 'Đang hoạt động'],
                ['value' => 'inactive', 'label' => 'Ngừng hoạt động'],
            ],
        ];

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => $filterOptions['roles'],
            'branches' => $filterOptions['branches'],
            'employees' => Employee::whereNull('user_id')->select('id', 'name', 'code')->orderBy('name')->get(),
            'filters' => $this->currentFilters($request),
            'filterOptions' => $filterOptions,
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
