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
        Schema::create('damage_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('damage_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');

            $table->integer('qty')->default(0); // SL hủy
            $table->decimal('cost_price', 15, 2)->default(0); // Giá vốn tại thời điểm hủy
            $table->decimal('total_value', 15, 2)->default(0); // Giá trị hủy = qty * cost_price

            $table->string('note')->nullable(); // Ghi chú từng dòng (nếu cần)

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('damage_items');
    }
};
