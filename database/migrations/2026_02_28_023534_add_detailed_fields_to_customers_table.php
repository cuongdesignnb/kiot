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
            $table->string('phone2')->nullable();
            $table->string('avatar')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->string('ward')->nullable();
            $table->string('customer_group')->nullable();
            $table->string('invoice_name')->nullable();
            $table->string('invoice_address')->nullable();
            $table->string('invoice_city')->nullable();
            $table->string('invoice_district')->nullable();
            $table->string('invoice_ward')->nullable();
            $table->string('id_card')->nullable();
            $table->string('passport')->nullable();
            $table->string('invoice_email')->nullable();
            $table->string('invoice_phone')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account')->nullable();
            $table->boolean('is_supplier')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'phone2',
                'avatar',
                'city',
                'district',
                'ward',
                'customer_group',
                'invoice_name',
                'invoice_address',
                'invoice_city',
                'invoice_district',
                'invoice_ward',
                'id_card',
                'passport',
                'invoice_email',
                'invoice_phone',
                'bank_name',
                'bank_account',
                'is_supplier'
            ]);
        });
    }
};
