<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('branch_id')->nullable()->constrained()->onDelete('set null');
            $table->string('created_by_name')->nullable(); // Người tạo
            $table->string('assigned_to_name')->nullable(); // Người nhận đặt (phụ trách)
            $table->string('sales_channel')->default('Trực tiếp'); // Kênh bán
            $table->string('price_book_name')->default('Bảng giá chung'); // Bảng giá
            $table->string('status')->default('draft'); // Phiếu tạm, Đã xác nhận, Đang giao hàng, Hoàn thành, Đã hủy
            $table->decimal('total_price', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('other_fees', 15, 2)->default(0);
            $table->decimal('total_payment', 15, 2)->default(0); // Khách cần trả (total_price - discount + other_fees)
            $table->decimal('amount_paid', 15, 2)->default(0); // Khách đã trả
            $table->text('note')->nullable(); // Ghi chú đơn hàng

            // Giao hàng
            $table->boolean('is_delivery')->default(false);
            $table->string('delivery_partner')->nullable();
            $table->string('tracking_code')->nullable();
            $table->decimal('delivery_fee', 15, 2)->default(0); // Phí trả ĐTGH
            $table->decimal('cod_amount', 15, 2)->default(0); // Thu hộ tiền (COD)
            $table->string('receiver_name')->nullable();
            $table->string('receiver_phone')->nullable();
            $table->string('receiver_address')->nullable();
            $table->string('receiver_ward')->nullable();
            $table->string('receiver_district')->nullable();
            $table->string('receiver_city')->nullable();
            $table->decimal('weight', 8, 2)->default(0); // Trọng lượng
            $table->string('length')->nullable();
            $table->string('width')->nullable();
            $table->string('height')->nullable();
            $table->string('delivery_service')->nullable(); // Dịch vụ (Giao thường, giao nhanh)
            $table->timestamp('expected_delivery_date')->nullable(); // Thời gian giao hàng (dự kiến)
            $table->text('delivery_note')->nullable(); // Ghi chú cho bưu tá

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
