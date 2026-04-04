<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('serial_imeis', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_return_id')->nullable()->after('purchase_id');
            $table->index('purchase_return_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('serial_imeis', function (Blueprint $table) {
            $table->dropIndex(['purchase_return_id']);
            $table->dropColumn('purchase_return_id');
        });
    }
};
