<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'inventory_total_cost')) {
                $table->decimal('inventory_total_cost', 20, 2)->default(0)
                    ->after('cost_price')
                    ->comment('Tổng giá trị tồn kho (ledger BQ di động). cost_price = inventory_total_cost / stock_quantity.');
            }
        });

        // Backfill: với sản phẩm đã có cost_price + stock, đặt total = cost × qty
        DB::statement("
            UPDATE products
            SET inventory_total_cost = ROUND(COALESCE(cost_price, 0) * COALESCE(stock_quantity, 0), 2)
            WHERE inventory_total_cost = 0
        ");
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'inventory_total_cost')) {
                $table->dropColumn('inventory_total_cost');
            }
        });
    }
};
