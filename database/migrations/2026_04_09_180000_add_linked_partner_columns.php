<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thêm linked_supplier_id vào customers
        if (Schema::hasTable('customers') && !Schema::hasColumn('customers', 'linked_supplier_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->unsignedBigInteger('linked_supplier_id')->nullable()->after('note');
            });
        }

        // Thêm linked_customer_id vào suppliers
        if (Schema::hasTable('suppliers') && !Schema::hasColumn('suppliers', 'linked_customer_id')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->unsignedBigInteger('linked_customer_id')->nullable()->after('note');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'linked_supplier_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('linked_supplier_id');
            });
        }

        if (Schema::hasTable('suppliers') && Schema::hasColumn('suppliers', 'linked_customer_id')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->dropColumn('linked_customer_id');
            });
        }
    }
};
