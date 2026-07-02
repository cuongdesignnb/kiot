<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 24.6E — store fee_type + fee_value alongside the existing `fee`
 * (which keeps the canonical VND amount).
 *
 *   fee_type  : 'amount' | 'percent'  — how the user entered it
 *   fee_value : raw value the user typed (e.g. 700000 for amount, 10 for percent)
 *   fee       : the resolved VND amount (recomputed by the backend on store)
 *
 * Existing rows are left intact (fee_type stays NULL, treated as 'amount';
 * fee_value stays NULL, fallback to fee at display time).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            if (!Schema::hasColumn('returns', 'fee_type')) {
                $table->string('fee_type', 16)->nullable()->after('fee');
            }
            if (!Schema::hasColumn('returns', 'fee_value')) {
                $table->decimal('fee_value', 15, 2)->nullable()->after('fee_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            if (Schema::hasColumn('returns', 'fee_value')) {
                $table->dropColumn('fee_value');
            }
            if (Schema::hasColumn('returns', 'fee_type')) {
                $table->dropColumn('fee_type');
            }
        });
    }
};
