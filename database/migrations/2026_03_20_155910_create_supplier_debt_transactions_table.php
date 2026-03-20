<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_debt_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->string('code')->nullable(); // PCPN003777, PN003777 etc
            $table->string('type'); // purchase, return, payment, adjustment, discount
            $table->decimal('amount', 15, 2)->default(0); // amount of this transaction
            $table->decimal('debt_remain', 15, 2)->default(0); // running debt balance after this
            $table->unsignedBigInteger('purchase_id')->nullable(); // reference to purchase
            $table->text('note')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->foreign('supplier_id')->references('id')->on('customers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_debt_transactions');
    }
};
