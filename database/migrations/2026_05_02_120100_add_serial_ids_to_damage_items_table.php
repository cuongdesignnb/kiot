<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('damage_items', 'serial_ids')) {
            Schema::table('damage_items', function (Blueprint $table) {
                $table->json('serial_ids')->nullable()
                    ->after('product_id')
                    ->comment('RR-09: danh sách serial_imei_id đã bị xuất hủy ở dòng này — dùng để rollback chính xác khi cancel Damage');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('damage_items', 'serial_ids')) {
            Schema::table('damage_items', function (Blueprint $table) {
                $table->dropColumn('serial_ids');
            });
        }
    }
};
