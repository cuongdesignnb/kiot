<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->decimal('total_amount', 15, 0)->default(0);
            $table->decimal('refund_amount', 15, 0)->default(0);
            $table->string('status')->default('completed'); // completed, cancelled
            $table->text('note')->nullable();
            $table->string('payment_method')->default('cash');
            $table->string('bank_account_info')->nullable();
            $table->timestamp('return_date')->nullable();
            $table->timestamps();

            $table->foreign('purchase_id')->references('id')->on('purchases');
            $table->foreign('supplier_id')->references('id')->on('customers');
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_return_id');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name');
            $table->string('product_code')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('price', 15, 0)->default(0);
            $table->decimal('subtotal', 15, 0)->default(0);
            $table->timestamps();

            $table->foreign('purchase_return_id')->references('id')->on('purchase_returns')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};
