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
        Schema::create('employee_salary_components', function (Blueprint $table) {
            $table->id();
            // Keep plain IDs here because this file can run before parent tables on fresh setups.
            $table->unsignedBigInteger('employee_id');
            $table->string('type'); // allowance, deduction
            $table->string('name'); // Ví dụ: Đi lại, Cơm trưa, Đi muộn
            $table->decimal('amount', 15, 2)->default(0); // Mức tiền (hoặc %)
            $table->boolean('is_percentage')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_salary_components');
    }
};
