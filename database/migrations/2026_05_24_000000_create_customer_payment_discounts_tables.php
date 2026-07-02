<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_payment_discounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->dateTime('discount_at');
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('allocate_to_invoices')->default(true);
            $table->string('status')->default('active'); // active | cancelled
            $table->text('note')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status'], 'idx_discount_cust_status');
            $table->index('discount_at', 'idx_discount_at');
        });

        Schema::create('customer_payment_discount_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_payment_discount_id')
                ->constrained('customer_payment_discounts', 'id', 'fk_alloc_discount_id')
                ->restrictOnDelete();
            $table->foreignId('customer_id')
                ->constrained('customers', 'id', 'fk_alloc_customer_id')
                ->restrictOnDelete();
            $table->foreignId('invoice_id')
                ->constrained('invoices', 'id', 'fk_alloc_invoice_id')
                ->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index(['customer_id', 'invoice_id'], 'idx_alloc_cust_inv');
            $table->index('customer_payment_discount_id', 'idx_alloc_discount_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_discount_allocations');
        Schema::dropIfExists('customer_payment_discounts');
    }
};
