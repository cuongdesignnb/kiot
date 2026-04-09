<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('debt_offsets')) {
            Schema::create('debt_offsets', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->unsignedBigInteger('customer_id');
                $table->decimal('amount', 15, 2);
                $table->decimal('receivable_before', 15, 2)->default(0);
                $table->decimal('payable_before', 15, 2)->default(0);
                $table->decimal('receivable_after', 15, 2)->default(0);
                $table->decimal('payable_after', 15, 2)->default(0);
                $table->boolean('is_auto')->default(false);
                $table->text('note')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('status')->default('active');
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->text('cancel_reason')->nullable();
                $table->timestamps();

                $table->index('customer_id');
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_offsets');
    }
};
