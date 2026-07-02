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
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('supplier_id')->nullable()->constrained('customers')->onDelete('set null'); // NCC
            $table->string('status')->default('draft'); // draft, confirmed, partial, completed

            $table->decimal('total_amount', 15, 2)->default(0); // Tổng tiền hàng
            $table->decimal('discount', 15, 2)->default(0); // Giảm giá
            $table->decimal('import_fee', 15, 2)->default(0); // Chi phí nhập hàng
            $table->decimal('other_import_fee', 15, 2)->default(0); // Chi phí nhập khác
            $table->decimal('total_payment', 15, 2)->default(0); // Cần trả NCC

            $table->date('expected_date')->nullable(); // Dự kiến ngày nhập hàng
            $table->text('note')->nullable(); // Ghi chú

            $table->string('created_by_name')->default('Admin');
            $table->string('ordered_by_name')->nullable(); // Người nhận đặt

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
