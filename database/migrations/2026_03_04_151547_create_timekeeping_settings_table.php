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
        Schema::create('timekeeping_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('branch_id')->nullable()->unique();  // null = toàn hệ thống

            // Cài đặt cơ bản
            $table->decimal('standard_hours_per_day', 6, 2)->default(8);
            $table->boolean('use_shift_allowances')->default(true);   // Lấy allowance từ shift hay từ setting?

            // Grace periods (tha)
            $table->unsignedSmallInteger('late_grace_minutes')->default(0);   // Tha muộn X phút
            $table->unsignedSmallInteger('early_grace_minutes')->default(0);  // Tha về sớm X phút

            // Tính năng nâng cao
            $table->boolean('allow_multiple_shifts_one_inout')->default(false);
            $table->boolean('enforce_shift_checkin_window')->default(false);  // Bắt buộc check-in trong cửa sổ ca
            $table->unsignedSmallInteger('ot_rounding_minutes')->default(0);  // Làm tròn OT (15/30 phút)
            $table->unsignedSmallInteger('ot_after_minutes')->default(0);     // OT bắt đầu tính sau X phút

            $table->string('status')->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timekeeping_settings');
    }
};
