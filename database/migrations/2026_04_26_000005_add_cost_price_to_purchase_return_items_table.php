<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_return_items', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_return_items', 'cost_price')) {
                // Giá vốn xuất kho khi trả NCC = unit_cost_allocated của purchase_item gốc
                $table->decimal('cost_price', 18, 2)->nullable()->after('subtotal');
            }
            if (!Schema::hasColumn('purchase_return_items', 'purchase_item_id')) {
                $table->unsignedBigInteger('purchase_item_id')->nullable()->after('product_id');
                $table->foreign('purchase_item_id')->references('id')->on('purchase_items')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_return_items', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_return_items', 'purchase_item_id')) {
                try { $table->dropForeign(['purchase_item_id']); } catch (\Throwable $e) {}
                $table->dropColumn('purchase_item_id');
            }
            if (Schema::hasColumn('purchase_return_items', 'cost_price')) {
                $table->dropColumn('cost_price');
            }
        });
    }
};
