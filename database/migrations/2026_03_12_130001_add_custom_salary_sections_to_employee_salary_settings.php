<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_salary_settings', function (Blueprint $table) {
            $table->boolean('has_bonus')->default(false)->after('overtime_rate');
            $table->boolean('has_commission')->default(false)->after('has_bonus');
            $table->boolean('has_allowance')->default(false)->after('has_commission');
            $table->boolean('has_deduction')->default(false)->after('has_allowance');
            $table->string('bonus_type')->default('personal_revenue')->after('has_deduction');
            $table->string('bonus_calculation')->default('total_revenue')->after('bonus_type');
            $table->json('custom_bonuses')->nullable()->after('bonus_calculation');
            $table->json('custom_commissions')->nullable()->after('custom_bonuses');
            $table->json('custom_allowances')->nullable()->after('custom_commissions');
            $table->json('custom_deductions')->nullable()->after('custom_allowances');
        });
    }

    public function down(): void
    {
        Schema::table('employee_salary_settings', function (Blueprint $table) {
            $table->dropColumn([
                'has_bonus', 'has_commission', 'has_allowance', 'has_deduction',
                'bonus_type', 'bonus_calculation',
                'custom_bonuses', 'custom_commissions', 'custom_allowances', 'custom_deductions',
            ]);
        });
    }
};
