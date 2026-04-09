<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_debts', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_debts', 'type')) {
                $table->string('type', 20)->default('sale')->after('debt_total');
            }
            if (!Schema::hasColumn('customer_debts', 'order_return_id')) {
                $table->unsignedBigInteger('order_return_id')->nullable()->after('order_id');
            }
        });

        // Add indexes separately (ignore if already exist)
        try {
            Schema::table('customer_debts', function (Blueprint $table) {
                $table->index(['customer_id', 'type'], 'customer_debts_cid_type_idx');
            });
        } catch (\Exception $e) {}

        try {
            Schema::table('customer_debts', function (Blueprint $table) {
                $table->index(['customer_id', 'recorded_at'], 'customer_debts_cid_recorded_idx');
            });
        } catch (\Exception $e) {}

        // Backfill type cho data cũ
        DB::statement("UPDATE customer_debts SET type = CASE
            WHEN amount > 0 THEN 'sale'
            WHEN amount < 0 THEN 'payment'
            ELSE 'adjustment'
        END WHERE type = 'sale'");
    }

    public function down(): void
    {
        Schema::table('customer_debts', function (Blueprint $table) {
            $table->dropColumn(['type', 'order_return_id']);
        });
    }
};
