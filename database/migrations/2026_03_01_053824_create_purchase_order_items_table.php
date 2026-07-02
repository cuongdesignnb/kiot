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
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            // Keep plain IDs here because this file can run before parent tables on fresh setups.
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('qty')->default(1);
            $table->decimal('price', 15, 2)->default(0); // Đơn giá
            $table->decimal('discount', 15, 2)->default(0); // Giảm giá
            $table->decimal('total_value', 15, 2)->default(0); // Thành tiền

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
