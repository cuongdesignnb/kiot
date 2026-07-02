<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waybills', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();

            $table->string('partner_type')->default('self_delivery'); // self_delivery | integrated
            $table->string('partner_name')->nullable();
            $table->string('carrier_service')->nullable();
            $table->string('tracking_code')->nullable();
            $table->string('external_ref')->nullable();

            $table->string('status')->default('pending');
            // pending, waiting_pickup, in_transit, delivered, returning, returned, canceled, failed

            $table->string('receiver_name')->nullable();
            $table->string('receiver_phone')->nullable();
            $table->string('receiver_address')->nullable();
            $table->string('receiver_ward')->nullable();
            $table->string('receiver_district')->nullable();
            $table->string('receiver_city')->nullable();

            $table->string('pickup_address')->nullable();

            $table->integer('weight')->default(500); // grams
            $table->integer('length')->default(10); // cm
            $table->integer('width')->default(10);
            $table->integer('height')->default(10);

            $table->decimal('delivery_fee', 15, 2)->default(0);
            $table->decimal('cod_amount', 15, 2)->default(0);
            $table->decimal('declared_value', 15, 2)->default(0);

            $table->text('delivery_note')->nullable();
            $table->text('cancel_reason')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('customer_delivery_addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->string('label')->nullable();
            $table->string('receiver_name')->nullable();
            $table->string('receiver_phone')->nullable();
            $table->string('address')->nullable();
            $table->string('ward')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->boolean('is_default')->default(false);

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_delivery_addresses');
        Schema::dropIfExists('waybills');
    }
};
