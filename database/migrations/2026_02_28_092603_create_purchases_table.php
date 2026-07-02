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
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // Mã đơn nhập
            $table->foreignId('supplier_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total_amount', 15, 2)->default(0); // Tổng tiền hàng
            $table->decimal('discount', 15, 2)->default(0); // Giảm giá phiếu nhập
            $table->decimal('paid_amount', 15, 2)->default(0); // Tiền đã trả NCC
            $table->decimal('debt_amount', 15, 2)->default(0); // Tính vào công nợ (có thể tính toán từ total - discount - paid)
            $table->text('note')->nullable(); // Ghi chú
            $table->string('status')->default('completed'); // completed, saved
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
