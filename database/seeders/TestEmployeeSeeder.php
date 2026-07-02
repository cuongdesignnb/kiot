<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class TestEmployeeSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Tạo branch nếu chưa có
        $branch = Branch::first() ?? Branch::create([
            'name' => 'Chi nhánh chính',
        ]);

        // 2) Tạo Role "Quản lý công việc" với đầy đủ quyền tasks + repairs + employees
        $role = Role::firstOrCreate(
            ['name' => 'task_manager'],
            [
                'display_name' => 'Quản lý công việc',
                'description'  => 'Quyền quản lý công việc, sửa chữa, nhân viên',
                'permissions'  => [
                    'dashboard.view',
                    'products.view',
                    'employees.view',
                    'tasks.view',
                    'tasks.create',
                    'tasks.assign',
                    'tasks.complete',
                    'tasks.manage_parts',
                    'tasks.manage_categories',
                    'tasks.performance',
                    'repairs.view',
                    'repairs.create',
                    'repairs.assign',
                    'repairs.complete',
                    'repairs.manage_parts',
                    'repair_performance.view',
                    'cashbook.view',
                ],
            ]
        );

        // 3) Tạo User
        $user = User::firstOrCreate(
            ['email' => 'nhanvien@kiot.test'],
            [
                'name'      => 'Nhân Viên Test',
                'password'  => 'kiot2026',
                'role_id'   => $role->id,
                'branch_id' => $branch->id,
                'status'    => 'active',
            ]
        );

        // Cập nhật role nếu user đã tồn tại
        if (!$user->wasRecentlyCreated) {
            $user->update(['role_id' => $role->id, 'branch_id' => $branch->id]);
        }

        // 4) Tạo Employee liên kết với User
        $employee = Employee::firstOrCreate(
            ['email' => 'nhanvien@kiot.test'],
            [
                'user_id'   => $user->id,
                'code'      => 'NV-TEST-001',
                'name'      => 'Nhân Viên Test',
                'phone'     => '0901234567',
                'branch_id' => $branch->id,
                'is_active' => true,
            ]
        );

        // Đảm bảo employee link đúng user
        if (!$employee->user_id) {
            $employee->update(['user_id' => $user->id]);
        }

        $this->command->info("✅ Test employee created:");
        $this->command->info("   Email: nhanvien@kiot.test");
        $this->command->info("   Password: kiot2026");
        $this->command->info("   Role: {$role->display_name}");
        $this->command->info("   Branch: {$branch->name}");
        $this->command->info("   Employee: {$employee->name} ({$employee->code})");
    }
}
