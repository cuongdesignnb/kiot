<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stock_take_items', 'unknown_serials')) {
            Schema::table('stock_take_items', function (Blueprint $table) {
                $table->json('unknown_serials')->nullable()->after('category_id');
            });
        }

        Schema::create('stock_take_item_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_take_item_id')->constrained('stock_take_items')->cascadeOnDelete();
            $table->unsignedBigInteger('serial_imei_id')->nullable();
            $table->string('serial_number_snapshot');
            $table->boolean('system_present')->default(true);
            $table->boolean('actual_present')->nullable();
            $table->string('status_snapshot')->nullable();
            $table->string('repair_status_snapshot')->nullable();
            $table->decimal('cost_price_snapshot', 15, 2)->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['stock_take_item_id', 'serial_number_snapshot'], 'stock_take_item_serials_item_number_unique');
            $table->index(['serial_imei_id', 'stock_take_item_id'], 'stock_take_item_serials_serial_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_take_item_serials');

        if (Schema::hasColumn('stock_take_items', 'unknown_serials')) {
            Schema::table('stock_take_items', function (Blueprint $table) {
                $table->dropColumn('unknown_serials');
            });
        }
    }
};
