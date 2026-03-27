<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('cost_price', 15, 2)->default(0)->after('price')
                  ->comment('Snapshot giá vốn tại thời điểm bán (từ serial.cost_price hoặc product.cost_price)');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('cost_price');
        });
    }
};
