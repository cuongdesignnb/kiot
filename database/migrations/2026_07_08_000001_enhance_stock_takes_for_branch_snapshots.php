<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_takes', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_takes', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('code')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('stock_takes', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('balanced_date');
            }
            if (! Schema::hasColumn('stock_takes', 'balanced_by')) {
                $table->unsignedBigInteger('balanced_by')->nullable()->after('created_by');
            }
            if (! Schema::hasColumn('stock_takes', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable()->after('balanced_by');
            }
            if (! Schema::hasColumn('stock_takes', 'cancelled_at')) {
                $table->dateTime('cancelled_at')->nullable()->after('cancelled_by');
            }
            if (! Schema::hasColumn('stock_takes', 'cancel_reason')) {
                $table->text('cancel_reason')->nullable()->after('cancelled_at');
            }
        });

        Schema::table('stock_take_items', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_take_items', 'system_stock_snapshot')) {
                $table->integer('system_stock_snapshot')->default(0)->after('system_stock');
            }
            if (! Schema::hasColumn('stock_take_items', 'checked')) {
                $table->boolean('checked')->default(false)->after('actual_stock');
            }
            if (! Schema::hasColumn('stock_take_items', 'cost_price_snapshot')) {
                $table->decimal('cost_price_snapshot', 15, 2)->default(0)->after('diff_value');
            }
            if (! Schema::hasColumn('stock_take_items', 'unit_name')) {
                $table->string('unit_name')->nullable()->after('cost_price_snapshot');
            }
            if (! Schema::hasColumn('stock_take_items', 'category_id')) {
                $table->foreignId('category_id')->nullable()->after('unit_name')->constrained('categories')->nullOnDelete();
            }
        });

        if (Schema::hasColumn('stock_take_items', 'actual_stock')) {
            $driver = DB::connection()->getDriverName();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                DB::statement('ALTER TABLE stock_take_items MODIFY actual_stock INT NULL DEFAULT NULL');
            } else {
                Schema::table('stock_take_items', function (Blueprint $table) {
                    $table->integer('actual_stock')->nullable()->default(null)->change();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('stock_take_items', function (Blueprint $table) {
            if (Schema::hasColumn('stock_take_items', 'actual_stock')) {
                $driver = DB::connection()->getDriverName();
                if (in_array($driver, ['mysql', 'mariadb'], true)) {
                    DB::statement('ALTER TABLE stock_take_items MODIFY actual_stock INT NOT NULL DEFAULT 0');
                } else {
                    $table->integer('actual_stock')->default(0)->nullable(false)->change();
                }
            }
            if (Schema::hasColumn('stock_take_items', 'category_id')) {
                $table->dropConstrainedForeignId('category_id');
            }
            foreach (['unit_name', 'cost_price_snapshot', 'checked', 'system_stock_snapshot'] as $column) {
                if (Schema::hasColumn('stock_take_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('stock_takes', function (Blueprint $table) {
            if (Schema::hasColumn('stock_takes', 'branch_id')) {
                $table->dropConstrainedForeignId('branch_id');
            }
            foreach (['cancel_reason', 'cancelled_at', 'cancelled_by', 'balanced_by', 'created_by'] as $column) {
                if (Schema::hasColumn('stock_takes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
