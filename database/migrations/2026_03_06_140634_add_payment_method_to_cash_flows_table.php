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
        Schema::table('cash_flows', function (Blueprint $table) {
            $table->string('payment_method')->default('cash')->after('accounting_result'); // cash, bank, ewallet
            $table->unsignedBigInteger('bank_account_id')->nullable()->after('payment_method');
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_flows', function (Blueprint $table) {
            $table->dropForeign(['bank_account_id']);
            $table->dropColumn(['payment_method', 'bank_account_id']);
        });
    }
};
