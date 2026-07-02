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
        Schema::create('shifts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('branch_id')->nullable();  // Thuộc kho/chi nhánh nào
            $table->string('name');                                   // "Ca hành chính", "Ca tối"...
            $table->time('start_time');                               // "08:00"
            $table->time('end_time');                                 // "17:00"
            $table->time('checkin_start_time')->nullable();           // Cửa sổ check-in bắt đầu
            $table->time('checkin_end_time')->nullable();             // Cửa sổ check-in kết thúc
            $table->unsignedSmallInteger('allow_late_minutes')->default(0);   // Cho phép muộn X phút
            $table->unsignedSmallInteger('allow_early_minutes')->default(0);  // Cho phép sớm X phút
            $table->unsignedSmallInteger('rounding_minutes')->default(15);    // Làm tròn OT (15p)
            $table->boolean('is_overnight')->default(false);          // Ca đêm (qua ngày)
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
