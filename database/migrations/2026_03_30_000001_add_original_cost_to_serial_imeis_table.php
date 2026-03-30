<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('serial_imeis', function (Blueprint $table) {
            $table->decimal('original_cost', 18, 0)->default(0)
                  ->after('cost_price')
                  ->comment('Giá nhập gốc ban đầu (từ phiếu nhập hàng)');
        });

        // Backfill: lấy giá nhập từ purchase_items, hoặc dùng cost_price hiện tại nếu chưa có task sửa chữa
        DB::statement("
            UPDATE serial_imeis s
            LEFT JOIN purchase_items pi ON pi.purchase_id = s.purchase_id
                AND pi.product_id = s.product_id
            SET s.original_cost = COALESCE(pi.price, s.cost_price)
            WHERE s.original_cost = 0
        ");
    }

    public function down(): void
    {
        Schema::table('serial_imeis', function (Blueprint $table) {
            $table->dropColumn('original_cost');
        });
    }
};
