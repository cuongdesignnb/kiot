<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Make serial_imei_id nullable for external repairs (was NOT NULL)
            $table->unsignedBigInteger('serial_imei_id')->nullable()->change();
            // Make product_id nullable for external repairs (was NOT NULL)
            $table->unsignedBigInteger('product_id')->nullable()->change();

            if (!Schema::hasColumn('tasks', 'external')) {
                $table->boolean('external')->default(false)->after('status');
            }
            if (!Schema::hasColumn('tasks', 'sub_status')) {
                $table->string('sub_status', 30)->nullable()->after('external');
            }
            if (!Schema::hasColumn('tasks', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->after('sub_status')
                    ->constrained('customers')->nullOnDelete();
            }
            if (!Schema::hasColumn('tasks', 'customer_name')) {
                $table->string('customer_name')->nullable()->after('customer_id');
            }
            if (!Schema::hasColumn('tasks', 'customer_phone')) {
                $table->string('customer_phone', 30)->nullable()->after('customer_name');
            }
            if (!Schema::hasColumn('tasks', 'warranty_id')) {
                $table->foreignId('warranty_id')->nullable()->after('customer_phone')
                    ->constrained('warranties')->nullOnDelete();
            }
            if (!Schema::hasColumn('tasks', 'invoice_id')) {
                $table->foreignId('invoice_id')->nullable()->after('warranty_id')
                    ->constrained('invoices')->nullOnDelete();
            }
            if (!Schema::hasColumn('tasks', 'received_at')) {
                $table->timestamp('received_at')->nullable()->after('invoice_id');
            }
            if (!Schema::hasColumn('tasks', 'returned_at')) {
                $table->timestamp('returned_at')->nullable()->after('received_at');
            }
            if (!Schema::hasColumn('tasks', 'labor_fee')) {
                $table->decimal('labor_fee', 15, 2)->default(0)->after('returned_at');
            }
            if (!Schema::hasColumn('tasks', 'parts_total')) {
                $table->decimal('parts_total', 15, 2)->default(0)->after('labor_fee');
            }
            if (!Schema::hasColumn('tasks', 'total_amount')) {
                $table->decimal('total_amount', 15, 2)->default(0)->after('parts_total');
            }
            if (!Schema::hasColumn('tasks', 'paid_amount')) {
                $table->decimal('paid_amount', 15, 2)->default(0)->after('total_amount');
            }
            if (!Schema::hasColumn('tasks', 'debt_amount')) {
                $table->decimal('debt_amount', 15, 2)->default(0)->after('paid_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $cols = [
                'external', 'sub_status', 'customer_name', 'customer_phone',
                'received_at', 'returned_at', 'labor_fee', 'parts_total',
                'total_amount', 'paid_amount', 'debt_amount',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('tasks', $col)) {
                    $table->dropColumn($col);
                }
            }
            // FK columns need special handling
            if (Schema::hasColumn('tasks', 'customer_id')) {
                $table->dropForeign(['customer_id']);
                $table->dropColumn('customer_id');
            }
            if (Schema::hasColumn('tasks', 'warranty_id')) {
                $table->dropForeign(['warranty_id']);
                $table->dropColumn('warranty_id');
            }
            if (Schema::hasColumn('tasks', 'invoice_id')) {
                $table->dropForeign(['invoice_id']);
                $table->dropColumn('invoice_id');
            }
        });
    }
};
