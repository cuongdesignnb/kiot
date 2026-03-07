<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee_salary_settings', function (Blueprint $table) {
            $table->id();
            // Keep plain IDs here because this file can run before parent tables on fresh setups.
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('salary_template_id')->nullable();
            $table->string('type')->default('fixed'); // Loại lương chính: Tùy chỉnh (fixed/hourly) hoặc theo mẫu
            $table->decimal('base_salary', 15, 2)->default(0); // Lương chính (nếu tùy chỉnh)
            $table->boolean('has_bonus')->default(false); // Có áp dụng thưởng
            $table->boolean('has_commission')->default(false); // Có áp dụng hoa hồng
            $table->boolean('has_allowance')->default(false); // Có áp dụng phụ cấp
            $table->boolean('has_deduction')->default(false); // Có áp dụng giảm trừ
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_salary_settings');
    }
};
