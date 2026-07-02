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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique()->comment('Mã Hàng hóa (Tự tạo hoặc nhập tay)');
            $table->string('barcode')->nullable()->unique()->comment('Mã Vạch');
            $table->string('name');

            // 4 loại hình sản phẩm cốt lõi
            $table->enum('type', ['standard', 'service', 'combo', 'manufactured'])
                ->default('standard')
                ->comment('Loại: Hàng hóa, Dịch vụ, Combo, Hàng sản xuất');

            // Liên kết
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();

            // Các chuẩn giá và định mức
            $table->decimal('cost_price', 15, 2)->default(0)->comment('Giá vốn (Tính MAP/FIFO)');
            $table->decimal('retail_price', 15, 2)->default(0)->comment('Giá bán lẻ');

            $table->integer('stock_quantity')->default(0)->comment('Tổng tồn kho hiện tại');
            $table->integer('min_stock')->default(0)->comment('Định mức tồn kho tối thiểu');
            $table->integer('max_stock')->nullable()->comment('Định mức tồn kho tối đa');

            // Cờ (Flags) xử lý nghiệp vụ
            $table->boolean('has_serial')->default(false)->comment('Hàng hoá Quản lý bằng Serial/IMEI');
            $table->boolean('is_active')->default(true)->comment('Đang kinh doanh');
            $table->boolean('allow_point_accumulation')->default(true)->comment('Tích điểm');
            $table->boolean('sell_directly')->default(true)->comment('Bán trực tiếp');

            // Hình ảnh và ghi chú
            $table->string('image')->nullable();
            $table->text('description')->nullable();

            // Thuộc tính vật lý
            $table->string('weight')->nullable()->comment('Trọng lượng');
            $table->string('location')->nullable()->comment('Vị trí kho');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
