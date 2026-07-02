<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'orders'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'customer_group_id')) {
                    $table->unsignedBigInteger('customer_group_id')->nullable()->after('customer_id');
                    $table->index('customer_group_id');
                }

                if (!Schema::hasColumn($tableName, 'customer_group_name')) {
                    $table->string('customer_group_name')->nullable()->after('customer_group_id');
                    $table->index('customer_group_name');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'orders'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'customer_group_name')) {
                    $table->dropIndex(['customer_group_name']);
                    $table->dropColumn('customer_group_name');
                }

                if (Schema::hasColumn($tableName, 'customer_group_id')) {
                    $table->dropIndex(['customer_group_id']);
                    $table->dropColumn('customer_group_id');
                }
            });
        }
    }
};
