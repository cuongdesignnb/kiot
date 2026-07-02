<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 24.9 — Product warranty + maintenance configuration storage.
 *
 * products:
 *   - warranty_months          int nullable — primary/maximum policy duration
 *                              in months, kept for backward-compat with
 *                              WarrantyGenerationService fallbacks.
 *   - warranty_policies        json nullable — list of {name, duration_value,
 *                              duration_unit, is_default} entries.
 *   - maintenance_policies     json nullable — list of {name, duration_value,
 *                              duration_unit} entries.
 *
 * warranties (snapshot at sale time):
 *   - warranty_policy_snapshot     json nullable
 *   - maintenance_policy_snapshot  json nullable
 *   - next_maintenance_date        datetime nullable
 *
 * Idempotent: Schema::hasColumn checks. Existing rows untouched (NULL).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'warranty_months')) {
                $table->unsignedInteger('warranty_months')->nullable()->after('weight');
            }
            if (!Schema::hasColumn('products', 'warranty_policies')) {
                $table->json('warranty_policies')->nullable()->after('warranty_months');
            }
            if (!Schema::hasColumn('products', 'maintenance_policies')) {
                $table->json('maintenance_policies')->nullable()->after('warranty_policies');
            }
        });

        Schema::table('warranties', function (Blueprint $table) {
            if (!Schema::hasColumn('warranties', 'warranty_policy_snapshot')) {
                $table->json('warranty_policy_snapshot')->nullable()->after('maintenance_note');
            }
            if (!Schema::hasColumn('warranties', 'maintenance_policy_snapshot')) {
                $table->json('maintenance_policy_snapshot')->nullable()->after('warranty_policy_snapshot');
            }
            if (!Schema::hasColumn('warranties', 'next_maintenance_date')) {
                $table->dateTime('next_maintenance_date')->nullable()->after('warranty_end_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $table) {
            foreach (['next_maintenance_date', 'maintenance_policy_snapshot', 'warranty_policy_snapshot'] as $col) {
                if (Schema::hasColumn('warranties', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('products', function (Blueprint $table) {
            foreach (['maintenance_policies', 'warranty_policies', 'warranty_months'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
