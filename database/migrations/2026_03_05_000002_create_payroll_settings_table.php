<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->unique()->constrained('branches')->nullOnDelete();
            $table->string('pay_cycle', 30)->default('monthly');
            $table->unsignedTinyInteger('start_day')->default(26);
            $table->unsignedTinyInteger('end_day')->default(25);
            $table->boolean('start_in_prev_month')->default(true);
            $table->unsignedTinyInteger('pay_day')->default(5);
            $table->boolean('default_recalculate_timekeeping')->default(true);
            $table->boolean('auto_generate_enabled')->default(false);
            $table->string('status', 30)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_settings');
    }
};
