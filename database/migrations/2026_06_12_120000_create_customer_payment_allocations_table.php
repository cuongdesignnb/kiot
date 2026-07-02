<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_flow_id')->constrained('cash_flows')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['cash_flow_id', 'invoice_id'], 'uq_customer_payment_allocation');
            $table->index(['customer_id', 'invoice_id'], 'idx_customer_payment_allocation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_allocations');
    }
};
