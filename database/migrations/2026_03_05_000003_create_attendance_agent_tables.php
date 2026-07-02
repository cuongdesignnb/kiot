<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bảng lưu lịch sử sync từ C# AttendanceBridge agent
        Schema::create('attendance_agent_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 100)->index();
            $table->string('app_version', 20)->nullable();
            $table->string('sync_type', 50); // users, logs, full
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('result', 20); // ok, partial, failed
            $table->json('counts')->nullable(); // {fetched, created, updated, skipped, failed}
            $table->json('errors')->nullable();
            $table->timestamps();
        });

        // Bảng quản lý phiên bản AttendanceBridge app cho auto-update
        Schema::create('attendance_bridge_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20);
            $table->string('channel', 20)->default('stable'); // stable, beta
            $table->boolean('mandatory')->default(false);
            $table->string('min_supported', 20)->nullable();
            $table->timestamp('released_at');
            $table->text('notes')->nullable();
            $table->string('download_url', 500);
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['channel', 'is_active', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_bridge_versions');
        Schema::dropIfExists('attendance_agent_sync_logs');
    }
};
