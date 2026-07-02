<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->boolean('late_penalty_enabled')->default(false)->after('late_half_day_threshold');
            $table->json('late_penalty_tiers')->nullable()->after('late_penalty_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->dropColumn(['late_penalty_enabled', 'late_penalty_tiers']);
        });
    }
};
