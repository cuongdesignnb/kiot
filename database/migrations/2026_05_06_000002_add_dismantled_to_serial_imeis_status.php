<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Step 23.8E — Disassembly Hardening
 *
 * Mở rộng enum serial_imeis.status để bao gồm 'dismantled' — đánh dấu serial
 * máy gốc đã bị bóc tách linh kiện và không còn bán được.
 *
 * Idempotent: chỉ ALTER khi enum hiện tại chưa có 'dismantled'.
 * Schema-tolerant: skip với SQLite (varchar, không enforce enum).
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $col = DB::selectOne(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'serial_imeis'
               AND COLUMN_NAME = 'status'"
        );

        if ($col && !str_contains($col->COLUMN_TYPE, 'dismantled')) {
            DB::statement(
                "ALTER TABLE serial_imeis MODIFY COLUMN status
                 ENUM('in_stock','sold','returning','warranty','defective','returned','used_for_repair','dismantled')
                 NOT NULL DEFAULT 'in_stock'"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $col = DB::selectOne(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'serial_imeis'
               AND COLUMN_NAME = 'status'"
        );

        if ($col && str_contains($col->COLUMN_TYPE, 'dismantled')) {
            DB::statement(
                "ALTER TABLE serial_imeis MODIFY COLUMN status
                 ENUM('in_stock','sold','returning','warranty','defective','returned','used_for_repair')
                 NOT NULL DEFAULT 'in_stock'"
            );
        }
    }
};
