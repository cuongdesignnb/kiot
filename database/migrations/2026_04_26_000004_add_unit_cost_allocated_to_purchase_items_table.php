<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_items', 'unit_cost_allocated')) {
                // Đơn giá nhập đã phân bổ phí nhập (other_costs) theo tỉ lệ giá trị.
                // = (subtotal + allocated_fee) / quantity
                $table->decimal('unit_cost_allocated', 18, 2)->nullable()->after('subtotal');
            }
        });

        // Backfill: với phiếu nhập cũ chưa có phí phân bổ, unit_cost_allocated = price - discount/qty
        \DB::statement('UPDATE purchase_items
            SET unit_cost_allocated = CASE
                WHEN quantity > 0 THEN (subtotal * 1.0 / quantity)
                ELSE price
            END
            WHERE unit_cost_allocated IS NULL');
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_items', 'unit_cost_allocated')) {
                $table->dropColumn('unit_cost_allocated');
            }
        });
    }
};
