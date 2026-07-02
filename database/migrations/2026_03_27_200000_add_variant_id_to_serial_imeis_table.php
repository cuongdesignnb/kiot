<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('serial_imeis', 'variant_id')) {
            Schema::table('serial_imeis', function (Blueprint $table) {
                $table->unsignedBigInteger('variant_id')->nullable()->after('product_id')
                      ->comment('Biến thể cụ thể của serial này (RAM/Màn hình/...)');
                $table->foreign('variant_id')->references('id')->on('product_variants')->nullOnDelete();
                $table->index('variant_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('serial_imeis', 'variant_id')) {
            Schema::table('serial_imeis', function (Blueprint $table) {
                $table->dropForeign(['variant_id']);
                $table->dropColumn('variant_id');
            });
        }
    }
};
