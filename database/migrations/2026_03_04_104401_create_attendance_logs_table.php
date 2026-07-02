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
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attendance_device_id');      // FK → attendance_devices
            $table->unsignedBigInteger('employee_id')->nullable();   // FK → employees (null = chưa map)
            $table->string('device_user_id');                        // ID user trên máy chấm công
            $table->dateTime('punched_at');                          // Thời điểm quẹt
            $table->string('event_type')->nullable();                // in / out (nếu máy phân biệt)
            $table->json('raw')->nullable();                         // Dữ liệu thô từ máy
            $table->timestamps();

            // UNIQUE KEY — đảm bảo idempotent (push nhiều lần không trùng)
            $table->unique(['attendance_device_id', 'device_user_id', 'punched_at'], 'attendance_log_unique');

            $table->index('employee_id');
            $table->index('punched_at');
            $table->foreign('attendance_device_id')->references('id')->on('attendance_devices')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
