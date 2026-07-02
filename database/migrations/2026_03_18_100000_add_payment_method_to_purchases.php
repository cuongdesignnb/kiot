<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            if (!Schema::hasColumn('purchases', 'payment_method')) {
                $table->string('payment_method')->default('cash')->after('status');
            }
            if (!Schema::hasColumn('purchases', 'bank_account_info')) {
                $table->string('bank_account_info')->nullable()->after('payment_method');
            }
        });

        // Add employee_id and sale_time to invoices for POS employee tracking
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'employee_id')) {
                $table->foreignId('employee_id')->nullable()->after('id')
                    ->constrained('employees')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'sale_time')) {
                $table->timestamp('sale_time')->nullable()->after('customer_paid');
            }
            if (!Schema::hasColumn('invoices', 'payment_method')) {
                $table->string('payment_method')->default('cash')->after('sale_time');
            }
            if (!Schema::hasColumn('invoices', 'bank_account_info')) {
                $table->string('bank_account_info')->nullable()->after('payment_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            if (Schema::hasColumn('purchases', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
            if (Schema::hasColumn('purchases', 'bank_account_info')) {
                $table->dropColumn('bank_account_info');
            }
        });
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'employee_id')) {
                $table->dropForeign(['employee_id']);
                $table->dropColumn('employee_id');
            }
            if (Schema::hasColumn('invoices', 'sale_time')) {
                $table->dropColumn('sale_time');
            }
            if (Schema::hasColumn('invoices', 'payment_method')) {
                $table->dropColumn('payment_method');
            }
            if (Schema::hasColumn('invoices', 'bank_account_info')) {
                $table->dropColumn('bank_account_info');
            }
        });
    }
};
