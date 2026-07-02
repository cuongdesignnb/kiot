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
        Schema::create('stock_take_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_take_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->integer('system_stock')->default(0); // Tồn kho lúc kiểm
            $table->integer('actual_stock')->default(0); // Tồn kho thực tế
            $table->integer('diff_qty')->default(0); // SL Lệch (actual - system)
            $table->decimal('diff_value', 15, 2)->default(0); // Giá trị lệch (diff_qty * cost_price)
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_take_items');
    }
};
