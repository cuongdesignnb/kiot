<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_repair_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_repair_id')->constrained('device_repairs')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            $table->decimal('unit_cost', 18, 0)->default(0)->comment('Giá vốn BQ tại thời điểm xuất');
            $table->decimal('total_cost', 18, 0)->default(0)->comment('= quantity × unit_cost');
            $table->unsignedBigInteger('exported_by')->nullable();
            $table->foreign('exported_by')->references('id')->on('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['device_repair_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_repair_parts');
    }
};
