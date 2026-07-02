<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1) Add serial_ids JSON column to task_parts
        if (!Schema::hasColumn('task_parts', 'serial_ids')) {
            Schema::table('task_parts', function (Blueprint $table) {
                $table->json('serial_ids')->nullable()->after('direction');
            });
        }

        // 2) Expand serial_imeis.status enum to include 'used_for_repair'
        //    Idempotent: check current enum definition first
        $col = DB::selectOne(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'serial_imeis'
               AND COLUMN_NAME = 'status'"
        );

        if ($col && !str_contains($col->COLUMN_TYPE, 'used_for_repair')) {
            DB::statement(
                "ALTER TABLE serial_imeis MODIFY COLUMN status
                 ENUM('in_stock','sold','returning','warranty','defective','returned','used_for_repair')
                 NOT NULL DEFAULT 'in_stock'"
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('task_parts', 'serial_ids')) {
            Schema::table('task_parts', function (Blueprint $table) {
                $table->dropColumn('serial_ids');
            });
        }

        // Revert enum (remove used_for_repair)
        $col = DB::selectOne(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'serial_imeis'
               AND COLUMN_NAME = 'status'"
        );

        if ($col && str_contains($col->COLUMN_TYPE, 'used_for_repair')) {
            DB::statement(
                "ALTER TABLE serial_imeis MODIFY COLUMN status
                 ENUM('in_stock','sold','returning','warranty','defective','returned')
                 NOT NULL DEFAULT 'in_stock'"
            );
        }
    }
};
