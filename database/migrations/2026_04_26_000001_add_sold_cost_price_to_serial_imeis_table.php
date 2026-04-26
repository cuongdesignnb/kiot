<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('serial_imeis', 'sold_cost_price')) {
            return;
        }

        Schema::table('serial_imeis', function (Blueprint $table) {
            $table->decimal('sold_cost_price', 18, 0)->nullable()
                ->after('original_cost')
                ->comment('Giá vốn snapshot tại thời điểm bán (đích danh từng IMEI)');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('serial_imeis', 'sold_cost_price')) {
            return;
        }
        Schema::table('serial_imeis', function (Blueprint $table) {
            $table->dropColumn('sold_cost_price');
        });
    }
};
