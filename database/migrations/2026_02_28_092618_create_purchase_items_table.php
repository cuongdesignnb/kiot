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
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name'); // Tên hàng (lưu cứng phòng khi SP bị xóa/đổi tên)
            $table->string('product_code'); // Mã hàng
            $table->integer('quantity');
            $table->decimal('price', 15, 2); // Đơn giá nhập
            $table->decimal('discount', 15, 2)->default(0); // Giảm giá
            $table->decimal('subtotal', 15, 2); // Thành tiền (quantity * price - discount)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};
