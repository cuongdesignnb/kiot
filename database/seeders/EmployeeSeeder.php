<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Add sample departments
        DB::table('departments')->insert([
            ['name' => 'Kinh doanh', 'description' => 'Phòng kinh doanh'],
            ['name' => 'Kỹ thuật', 'description' => 'Phòng kỹ thuật và IT'],
            ['name' => 'Kế toán', 'description' => 'Phòng kế toán'],
            ['name' => 'Nhân sự', 'description' => 'Khối nhân sự'],
        ]);

        // Add sample Job Titles
        DB::table('job_titles')->insert([
            ['name' => 'Giám đốc', 'description' => 'Giám đốc chi nhánh'],
            ['name' => 'Trưởng phòng', 'description' => 'Trưởng các phòng ban'],
            ['name' => 'Nhân viên Bán hàng', 'description' => 'Nhân viên bán hàng'],
            ['name' => 'Kỹ thuật viên', 'description' => 'Nhân viên bảo hành/sửa chữa'],
        ]);

        // Add sample employees
        DB::table('employees')->insert([
            [
                'code' => 'NV00001',
                'name' => 'Trần Văn Tiến',
                'phone' => '0987654321',
                'email' => 'tientv@example.com',
                'cccd' => '001090123456',
                'branch_id' => 1, // Assumes there is a branch ID 1
                'department_id' => 1, // Kinh doanh
                'job_title_id' => 3, // Bán hàng
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'NV00002',
                'name' => 'Nguyễn Thị Hoa',
                'phone' => '0912345678',
                'email' => 'hoant@example.com',
                'cccd' => '001090654321',
                'branch_id' => 1,
                'department_id' => 3, // Kế toán
                'job_title_id' => 2, // Trưởng phòng
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'NV00003',
                'name' => 'Lê Minh Tuấn',
                'phone' => '0909090909',
                'email' => 'tuanlm@example.com',
                'cccd' => '001090999888',
                'branch_id' => 1,
                'department_id' => 2, // Kỹ thuật
                'job_title_id' => 4, // Kỹ thuật viên
                'is_active' => false, // Inactive employee test
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        // Add sample salary templates
        DB::table('salary_templates')->insert([
            ['name' => 'Lương cứng (Thử việc)', 'type' => 'fixed', 'base_salary' => 5000000, 'description' => 'Dành cho NV thử việc'],
            ['name' => 'Lương Kỷ thuật viên', 'type' => 'fixed', 'base_salary' => 10000000, 'description' => 'Lương cứng kỹ thuật'],
            ['name' => 'Lương Bán hàng (+HH)', 'type' => 'monthly_commission', 'base_salary' => 6000000, 'description' => 'Lương cơ bản + Hoa hồng'],
        ]);
    }
}
