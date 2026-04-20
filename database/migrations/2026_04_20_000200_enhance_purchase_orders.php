<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('supplier_deposit', 15, 2)->default(0)->after('total_payment');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->integer('received_qty')->default(0)->after('qty');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_order_id')->nullable()->after('id');
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
            $table->dropColumn('purchase_order_id');
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('received_qty');
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn('supplier_deposit');
        });
    }
};
