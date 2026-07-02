<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            $table->string('action', 50); // purchase_create, task_complete, part_install, part_remove, etc.
            $table->string('description'); // Mô tả hành động
            $table->string('subject_type')->nullable(); // App\Models\Task, App\Models\Purchase, etc.
            $table->unsignedBigInteger('subject_id')->nullable(); // ID of the related model
            $table->json('properties')->nullable(); // Extra data (product name, serial, quantity, etc.)
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
