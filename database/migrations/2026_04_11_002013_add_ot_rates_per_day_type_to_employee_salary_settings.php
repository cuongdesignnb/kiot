<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_salary_settings', function (Blueprint $table) {
            $table->decimal('saturday_ot_rate', 5, 0)->default(150)->after('overtime_rate');
            $table->decimal('sunday_ot_rate', 5, 0)->default(150)->after('saturday_ot_rate');
            $table->decimal('rest_day_ot_rate', 5, 0)->default(150)->after('sunday_ot_rate');
            $table->decimal('holiday_ot_rate', 5, 0)->default(150)->after('rest_day_ot_rate');
        });
    }

    public function down(): void
    {
        Schema::table('employee_salary_settings', function (Blueprint $table) {
            $table->dropColumn(['saturday_ot_rate', 'sunday_ot_rate', 'rest_day_ot_rate', 'holiday_ot_rate']);
        });
    }
};
