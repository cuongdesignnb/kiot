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
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('from_branch_id')->constrained('branches')->onDelete('cascade');
            $table->foreignId('to_branch_id')->constrained('branches')->onDelete('cascade');
            $table->integer('total_quantity')->default(0);
            $table->decimal('total_price', 15, 2)->default(0);
            $table->string('status')->default('draft'); // draft (Phiếu tạm), transferring (Đang chuyển), received (Đã nhận)
            $table->text('note')->nullable();
            $table->dateTime('sent_date')->nullable();
            $table->dateTime('receive_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
