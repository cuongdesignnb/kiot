<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1) Add sale_price to task_parts (snapshot giá bán linh kiện cho khách)
        if (!Schema::hasColumn('task_parts', 'sale_price')) {
            Schema::table('task_parts', function (Blueprint $table) {
                $table->decimal('sale_price', 15, 2)->nullable()->default(0)->after('unit_cost');
            });
        }

        // 2) Add source_type to invoices (distinguish repair vs normal sale)
        if (!Schema::hasColumn('invoices', 'source_type')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('source_type', 30)->nullable()->after('status');
            });
        }

        // 3) Add description + note to invoice_items (for service lines like labor fee)
        if (!Schema::hasColumn('invoice_items', 'description')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->string('description')->nullable();
            });
        }
        if (!Schema::hasColumn('invoice_items', 'note')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->string('note')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('task_parts', 'sale_price')) {
            Schema::table('task_parts', function (Blueprint $table) {
                $table->dropColumn('sale_price');
            });
        }
        if (Schema::hasColumn('invoices', 'source_type')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('source_type');
            });
        }
        if (Schema::hasColumn('invoice_items', 'description')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};
