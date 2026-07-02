<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_salary_settings', function (Blueprint $table) {
            $table->boolean('advanced_salary')->default(false)->after('salary_type');
            $table->decimal('holiday_rate', 5, 0)->default(200)->after('advanced_salary'); // % lương ngày nghỉ
            $table->decimal('tet_rate', 5, 0)->default(300)->after('holiday_rate'); // % lương ngày lễ tết
            $table->boolean('has_overtime')->default(false)->after('tet_rate');
            $table->decimal('overtime_rate', 5, 0)->default(150)->after('has_overtime'); // % lương làm thêm giờ
        });
    }

    public function down(): void
    {
        Schema::table('employee_salary_settings', function (Blueprint $table) {
            $table->dropColumn(['advanced_salary', 'holiday_rate', 'tet_rate', 'has_overtime', 'overtime_rate']);
        });
    }
};
