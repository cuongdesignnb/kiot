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
        Schema::create('damages', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // Mã xuất hủy
            $table->foreignId('branch_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('status')->default('draft'); // draft - Phiếu tạm, completed - Hoàn thành, cancelled - Đã hủy
            $table->string('created_by_name')->default('Admin'); // Người tạo
            $table->string('destroyed_by_name')->default('Admin'); // Người xuất hủy
            $table->dateTime('destroyed_date')->nullable(); // Thời gian xuất hủy

            $table->integer('total_qty')->default(0); // Tổng số lượng hủy
            $table->decimal('total_value', 15, 2)->default(0); // Tổng giá trị hủy

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
        Schema::dropIfExists('damages');
    }
};
