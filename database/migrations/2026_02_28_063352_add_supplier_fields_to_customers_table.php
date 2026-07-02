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
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_customer')->default(true);
            $table->decimal('supplier_debt_amount', 15, 2)->default(0);
            $table->decimal('total_bought', 15, 2)->default(0);
            $table->string('status')->default('active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['is_customer', 'supplier_debt_amount', 'total_bought', 'status']);
        });
    }
};
