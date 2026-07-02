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
        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable(); // Thuộc chi nhánh nào (thay vì warehouse_id cho hợp logic hiện tại)
            $table->string('name');                                  // Tên hiển thị (VD: "Máy CC Tầng 1")
            $table->string('device_id')->nullable()->unique();       // ID thiết bị (agent gửi lên)
            $table->string('model')->nullable();                     // Model máy (VD: "RJ X628C")
            $table->string('serial_number')->nullable();
            $table->string('ip_address');                            // IP trong LAN
            $table->unsignedInteger('tcp_port')->default(4370);
            $table->unsignedInteger('comm_key')->default(0);         // Mật khẩu giao tiếp
            $table->string('status')->default('active');             // active | inactive
            $table->text('notes')->nullable();
            $table->timestamp('last_sync_at')->nullable();           // Lần sync gần nhất
            $table->timestamps();

            $table->unique(['ip_address', 'tcp_port']);
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_devices');
    }
};
