<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 24.0C — ActivityLog standardization.
 *
 * Thêm cột `user_agent` (string nullable, length 500) vào activity_logs để
 * audit kỹ hơn. Idempotent qua `Schema::hasColumn`. Không update logs cũ.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('activity_logs', 'user_agent')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->string('user_agent', 500)->nullable()->after('ip_address');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('activity_logs', 'user_agent')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                $table->dropColumn('user_agent');
            });
        }
    }
};
