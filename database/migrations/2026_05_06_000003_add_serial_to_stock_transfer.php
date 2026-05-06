<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 23.9 — Stock Transfer Serial/IMEI flow.
 *
 * 1. Thêm cột stock_transfer_items.serial_ids JSON nullable (snapshot serial khi
 *    chuyển kho hàng has_serial). Idempotent qua Schema::hasColumn.
 *
 * 2. Mở rộng ENUM serial_imeis.status thêm 'in_transit' (đang trên đường giữa 2
 *    chi nhánh) — không sellable. Idempotent qua information_schema check.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('stock_transfer_items', 'serial_ids')) {
            Schema::table('stock_transfer_items', function (Blueprint $table) {
                $table->json('serial_ids')->nullable()->after('product_id');
            });
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $col = DB::selectOne(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'serial_imeis'
               AND COLUMN_NAME = 'status'"
        );

        if ($col && !str_contains($col->COLUMN_TYPE, 'in_transit')) {
            DB::statement(
                "ALTER TABLE serial_imeis MODIFY COLUMN status
                 ENUM('in_stock','sold','returning','warranty','defective','returned','used_for_repair','dismantled','in_transit')
                 NOT NULL DEFAULT 'in_stock'"
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stock_transfer_items', 'serial_ids')) {
            Schema::table('stock_transfer_items', function (Blueprint $table) {
                $table->dropColumn('serial_ids');
            });
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $col = DB::selectOne(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'serial_imeis'
               AND COLUMN_NAME = 'status'"
        );

        if ($col && str_contains($col->COLUMN_TYPE, 'in_transit')) {
            DB::statement(
                "ALTER TABLE serial_imeis MODIFY COLUMN status
                 ENUM('in_stock','sold','returning','warranty','defective','returned','used_for_repair','dismantled')
                 NOT NULL DEFAULT 'in_stock'"
            );
        }
    }
};
