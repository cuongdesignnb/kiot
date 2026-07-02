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
        Schema::create('holidays', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('holiday_date')->unique();
            $table->string('name');                                 // "Quốc khánh 2/9"
            $table->decimal('multiplier', 6, 2)->default(1);       // Hệ số lương (1=thường, 2=gấp đôi)
            $table->boolean('paid_leave')->default(false);          // Nghỉ vẫn tính lương
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
