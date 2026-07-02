<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('other_fees', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->nullable(); // Mã thu khác (auto-generated)
            $table->string('name');                        // Tên loại thu
            $table->decimal('value', 15, 2)->default(0);   // Giá trị
            $table->enum('value_type', ['fixed', 'percent'])->default('fixed'); // VND or %
            $table->boolean('auto_apply')->default(false);  // Tự động áp dụng khi bán hàng
            $table->boolean('refund_on_return')->default(false); // Hoàn lại khi trả hàng
            $table->enum('scope', ['system', 'branch'])->default('system'); // Phạm vi áp dụng
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active'); // Trạng thái
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('other_fees');
    }
};
