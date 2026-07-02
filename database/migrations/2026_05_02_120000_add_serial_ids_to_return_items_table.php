<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('return_items', 'serial_ids')) {
            Schema::table('return_items', function (Blueprint $table) {
                $table->json('serial_ids')->nullable()
                    ->after('invoice_item_id')
                    ->comment('RR-08: danh sách serial_imei_id đã được trả trong dòng này — dùng để rollback chính xác khi hủy phiếu');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('return_items', 'serial_ids')) {
            Schema::table('return_items', function (Blueprint $table) {
                $table->dropColumn('serial_ids');
            });
        }
    }
};
