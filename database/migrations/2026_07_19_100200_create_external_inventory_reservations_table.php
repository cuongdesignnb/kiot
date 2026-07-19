<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50);
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status'], 'external_reservations_product_status_index');
            $table->index(['order_id', 'status'], 'external_reservations_order_status_index');
            $table->index(['expires_at', 'status'], 'external_reservations_expiry_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_inventory_reservations');
    }
};
