<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Role::updateOrCreate(
            ['name' => 'employee_self_service'],
            [
                'display_name' => 'Nhân viên tự phục vụ',
                'description' => 'Chỉ xem, tạo và cập nhật tiến độ công việc của chính nhân viên.',
                'permissions' => [
                    'tasks.self.view',
                    'tasks.self.create',
                    'tasks.self.progress',
                ],
                'is_system' => true,
            ]
        );
    }

    public function down(): void
    {
        $role = Role::where('name', 'employee_self_service')
            ->where('is_system', true)
            ->first();

        if ($role && !$role->users()->exists()) {
            $role->delete();
        }
    }
};
