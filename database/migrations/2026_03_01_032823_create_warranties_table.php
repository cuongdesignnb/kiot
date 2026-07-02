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
        Schema::create('warranties', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_code')->nullable();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('customer_name')->nullable();
            $table->string('serial_imei')->nullable();
            $table->integer('warranty_period')->default(0)->comment('In months');
            $table->dateTime('purchase_date')->nullable();
            $table->dateTime('warranty_end_date')->nullable();
            $table->boolean('has_reminder_off')->default(false);
            $table->text('maintenance_note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warranties');
    }
};
