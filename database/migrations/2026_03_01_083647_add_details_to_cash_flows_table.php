<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cash_flows', function (Blueprint $table) {
            $table->dateTime('time')->nullable()->after('amount');
            $table->string('category')->nullable()->after('time'); // Loại thu chi
            $table->string('target_type')->nullable()->after('category'); // Đối tượng (Khách hàng, NCC, ...)
            $table->unsignedBigInteger('target_id')->nullable()->after('target_type');
            $table->string('target_name')->nullable()->after('target_id');
            $table->boolean('accounting_result')->default(true)->after('target_name'); // Hạch toán KQKD
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_flows', function (Blueprint $table) {
            $table->dropColumn(['time', 'category', 'target_type', 'target_id', 'target_name', 'accounting_result']);
        });
    }
};
