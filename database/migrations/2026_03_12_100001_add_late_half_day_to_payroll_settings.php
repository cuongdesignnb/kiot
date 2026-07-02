<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->boolean('late_half_day_enabled')->default(false)->after('auto_generate_enabled');
            $table->unsignedSmallInteger('late_half_day_threshold')->default(120)->after('late_half_day_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->dropColumn(['late_half_day_enabled', 'late_half_day_threshold']);
        });
    }
};
