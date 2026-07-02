<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workday_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->unique()->constrained('branches')->nullOnDelete();
            $table->json('week_days')->nullable();
            $table->string('status', 30)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workday_settings');
    }
};
