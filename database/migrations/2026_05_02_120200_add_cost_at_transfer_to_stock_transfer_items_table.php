<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('stock_transfer_items', 'cost_at_transfer')) {
            Schema::table('stock_transfer_items', function (Blueprint $table) {
                $table->decimal('cost_at_transfer', 15, 2)->nullable()
                    ->after('price')
                    ->comment('RR-12: BQ snapshot tại thời điểm transfer_out — dùng để cancel/receive khôi phục cost đúng khi BQ thay đổi giữa các pha');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stock_transfer_items', 'cost_at_transfer')) {
            Schema::table('stock_transfer_items', function (Blueprint $table) {
                $table->dropColumn('cost_at_transfer');
            });
        }
    }
};
