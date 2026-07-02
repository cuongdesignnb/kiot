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
        Schema::create('stock_takes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('status')->default('draft'); // draft - Phiếu tạm, balanced - Đã cân bằng kho, cancelled - Đã hủy
            $table->string('user_name')->default('Admin'); // Người tạo
            $table->string('balancer_name')->nullable(); // Người cân bằng
            $table->dateTime('balanced_date')->nullable();

            $table->integer('total_actual_qty')->default(0); // Tổng thực tế
            $table->integer('total_diff_qty')->default(0); // Tổng chênh lệch
            $table->integer('total_diff_increase')->default(0); // Tổng lệch tăng
            $table->integer('total_diff_decrease')->default(0); // Tổng lệch giảm
            $table->decimal('total_diff_value', 15, 2)->default(0); // Giá trị lệch

            $table->text('note')->nullable(); // Ghi chú

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_takes');
    }
};
