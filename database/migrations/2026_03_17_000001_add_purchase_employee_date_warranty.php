<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add employee_id + purchase_date to purchases
        Schema::table('purchases', function (Blueprint $table) {
            if (!Schema::hasColumn('purchases', 'employee_id')) {
                $table->foreignId('employee_id')->nullable()->after('user_id')
                    ->constrained('employees')->nullOnDelete();
            }
            if (!Schema::hasColumn('purchases', 'purchase_date')) {
                $table->datetime('purchase_date')->nullable()->after('status');
            }
        });

        // Add warranty_months to purchase_items
        Schema::table('purchase_items', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_items', 'warranty_months')) {
                $table->integer('warranty_months')->default(0)->after('subtotal');
            }
            if (!Schema::hasColumn('purchase_items', 'warranty_expires_at')) {
                $table->date('warranty_expires_at')->nullable()->after('warranty_months');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'employee_id')) {
                $table->dropForeign(['employee_id']);
                $table->dropColumn('employee_id');
            }
            if (Schema::hasColumn('purchases', 'purchase_date')) {
                $table->dropColumn('purchase_date');
            }
        });
        Schema::table('purchase_items', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_items', 'warranty_months')) {
                $table->dropColumn('warranty_months');
            }
            if (Schema::hasColumn('purchase_items', 'warranty_expires_at')) {
                $table->dropColumn('warranty_expires_at');
            }
        });
    }
};
