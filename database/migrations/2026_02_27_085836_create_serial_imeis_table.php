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
        Schema::create('serial_imeis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('serial_number')->unique()->comment('Số Serial / IMEI độc nhất');
            $table->enum('status', ['in_stock', 'sold', 'returning', 'warranty', 'defective'])
                ->default('in_stock')
                ->comment('Tồn kho|Đã bán|Đang trả|Đang bảo hành|Lỗi');
            // Sau này sẽ được map với phiếu nhập cụ thể hoặc kho để track vị trí
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serial_imeis');
    }
};
