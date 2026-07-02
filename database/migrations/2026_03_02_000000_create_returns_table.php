<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('status')->default('Đã trả'); // Đã trả, Đã hủy
            $table->decimal('subtotal', 15, 2)->default(0); // Tổng tiền hàng trả
            $table->decimal('discount', 15, 2)->default(0); // Giảm giá phiếu trả
            $table->decimal('fee', 15, 2)->default(0); // Phí trả hàng
            $table->decimal('total', 15, 2)->default(0); // Cần trả khách (Tổng)
            $table->decimal('paid_to_customer', 15, 2)->default(0); // Đã trả khách
            $table->text('note')->nullable();

            $table->string('created_by_name')->nullable();
            $table->string('seller_name')->nullable();
            $table->string('sales_channel')->nullable();
            $table->string('price_book_name')->nullable();

            $table->timestamps();
        });

        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('returns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->integer('quantity')->default(1);
            $table->decimal('price', 15, 2)->default(0); // Giá trả hàng
            $table->decimal('discount', 15, 2)->default(0); // Giảm giá
            $table->decimal('import_price', 15, 2)->default(0); // Giá nhập lại
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_items');
        Schema::dropIfExists('returns');
    }
};
