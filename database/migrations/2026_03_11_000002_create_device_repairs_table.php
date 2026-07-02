<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_repairs', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->comment('Mã phiếu sửa (SC-0001)');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('serial_imei_id')->constrained('serial_imeis')->cascadeOnDelete();
            $table->decimal('original_cost', 18, 0)->default(0)->comment('Giá nhập gốc');
            $table->decimal('parts_cost', 18, 0)->default(0)->comment('Tổng giá linh kiện đã lắp');
            $table->decimal('total_cost', 18, 0)->default(0)->comment('= original_cost + parts_cost');
            $table->text('issue_description')->nullable()->comment('Mô tả lỗi');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->unsignedBigInteger('assigned_employee_id')->nullable();
            $table->foreign('assigned_employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['assigned_employee_id']);
            $table->index(['branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_repairs');
    }
};
