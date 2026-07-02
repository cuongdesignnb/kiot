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
        Schema::create('product_combos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combo_product_id')->constrained('products')->cascadeOnDelete()->comment('Mã Sản phẩm Combo (Parent)');
            $table->foreignId('component_product_id')->constrained('products')->cascadeOnDelete()->comment('Mã Sản phẩm Thành phần (Child)');
            $table->integer('quantity')->default(1)->comment('Định mức số lượng cho phép');
            $table->timestamps();

            // Một Combo không được lưu trùng 1 thành phần 2 lần
            $table->unique(['combo_product_id', 'component_product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_combos');
    }
};
