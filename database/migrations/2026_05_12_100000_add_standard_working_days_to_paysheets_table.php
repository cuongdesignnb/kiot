<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 24.12 — add `standard_working_days` to paysheets.
 *
 * Stores the per-paysheet "ngày công chuẩn" so the user can override the
 * calendar-derived default from WorkdaySetting. When set, SalaryCalculationService
 * uses this value as the denominator for the salary_main computation.
 *
 * Nullable + idempotent — legacy paysheets keep behaving as before
 * (falling back to getStandardWorkUnits()).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('paysheets', function (Blueprint $table) {
            if (!Schema::hasColumn('paysheets', 'standard_working_days')) {
                $table->decimal('standard_working_days', 5, 2)->nullable()->after('period_end');
            }
        });
    }

    public function down(): void
    {
        Schema::table('paysheets', function (Blueprint $table) {
            if (Schema::hasColumn('paysheets', 'standard_working_days')) {
                $table->dropColumn('standard_working_days');
            }
        });
    }
};
