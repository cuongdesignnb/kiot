<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Drop old tables if they exist
        Schema::dropIfExists('employee_salary_components');
        Schema::dropIfExists('employee_salary_settings');
        Schema::dropIfExists('salary_templates');

        // 1. Salary Templates - main table
        Schema::create('salary_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Tên mẫu lương

            // Section toggles
            $table->boolean('has_bonus')->default(false);
            $table->boolean('has_commission')->default(false);
            $table->boolean('has_allowance')->default(false);
            $table->boolean('has_deduction')->default(false);

            // Bonus config
            $table->string('bonus_type')->default('personal_revenue'); // personal_revenue, branch_revenue
            $table->string('bonus_calculation')->default('total_revenue'); // total_revenue, progressive

            $table->timestamps();
        });

        // 2. Bonus tiers per template
        Schema::create('salary_template_bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_template_id')->constrained()->cascadeOnDelete();
            $table->string('role_type')->default('sales'); // sales, cashier, technician, etc.
            $table->decimal('revenue_from', 18, 0)->default(0); // Doanh thu từ
            $table->decimal('bonus_value', 18, 0)->default(0); // Giá trị thưởng
            $table->boolean('bonus_is_percentage')->default(true); // true = %, false = fixed amount
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // 3. Commission tables (reusable)
        Schema::create('commission_tables', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Tên bảng hoa hồng
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // 4. Commission table tiers
        Schema::create('commission_table_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_table_id')->constrained()->cascadeOnDelete();
            $table->decimal('revenue_from', 18, 0)->default(0);
            $table->decimal('commission_value', 18, 0)->default(0);
            $table->boolean('is_percentage')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // 5. Commission settings per template
        Schema::create('salary_template_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_template_id')->constrained()->cascadeOnDelete();
            $table->string('role_type')->default('sales');
            $table->decimal('revenue_from', 18, 0)->default(0);
            $table->unsignedBigInteger('commission_table_id')->nullable();
            $table->decimal('commission_value', 18, 0)->default(0); // Fixed value if no table
            $table->boolean('commission_is_percentage')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('commission_table_id')->references('id')->on('commission_tables')->nullOnDelete();
        });

        // 6. Allowance items per template
        Schema::create('salary_template_allowances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_template_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // Tên phụ cấp: Ăn trưa, Đi lại, Điện thoại
            $table->string('allowance_type')->default('fixed_per_day'); // fixed_per_day, fixed_per_month, percentage
            $table->decimal('amount', 18, 0)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // 7. Deduction items per template
        Schema::create('salary_template_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_template_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // Tên giảm trừ
            $table->string('deduction_category')->default('late'); // late, early_leave, absence, violation
            $table->string('calculation_type')->default('per_occurrence'); // per_occurrence, per_minute, fixed_per_month
            $table->decimal('amount', 18, 0)->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // 8. Employee-Template assignment (replaces employee_salary_settings)
        Schema::create('employee_salary_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('salary_template_id')->nullable();
            $table->decimal('base_salary', 18, 0)->default(0); // Lương cơ bản nhân viên
            $table->string('salary_type')->default('fixed'); // fixed, hourly
            $table->timestamps();

            $table->unique('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_settings');
        Schema::dropIfExists('salary_template_deductions');
        Schema::dropIfExists('salary_template_allowances');
        Schema::dropIfExists('salary_template_commissions');
        Schema::dropIfExists('commission_table_tiers');
        Schema::dropIfExists('commission_tables');
        Schema::dropIfExists('salary_template_bonuses');
        Schema::dropIfExists('salary_templates');
    }
};
