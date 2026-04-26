<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('return_items', 'cost_price')) {
            Schema::table('return_items', function (Blueprint $table) {
                $table->decimal('cost_price', 18, 0)->default(0)
                    ->after('import_price')
                    ->comment('Giá vốn snapshot khi trả (= invoice_item.cost_price gốc)');
            });
        }

        if (!Schema::hasColumn('return_items', 'invoice_item_id')) {
            Schema::table('return_items', function (Blueprint $table) {
                $table->foreignId('invoice_item_id')->nullable()
                    ->after('product_id')
                    ->constrained('invoice_items')->nullOnDelete()
                    ->comment('Tham chiếu dòng hóa đơn gốc để truy giá vốn lúc bán');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('return_items', 'invoice_item_id')) {
            Schema::table('return_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('invoice_item_id');
            });
        }
        if (Schema::hasColumn('return_items', 'cost_price')) {
            Schema::table('return_items', function (Blueprint $table) {
                $table->dropColumn('cost_price');
            });
        }
    }
};
